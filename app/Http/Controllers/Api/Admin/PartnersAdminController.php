<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;
use App\Services\TelegramNotifier;
use App\Services\TransactionLogger;
use App\Models\PanelWalletReceipt;
use App\Services\WalletReceiptService;

class PartnersAdminController extends Controller
{
    /**
     * GET /api/admin/partners
     * params:
     *   - q: optional (search by name/email/code)
     *   - per_page: optional (default 500; فرانت پیجینیشن خودش رو دارد)
     */
    public function index(Request $request)
    {
        $q        = trim((string) $request->get('q', ''));
        $perPage  = max(1, min((int) $request->get('per_page', 500), 2000));

        $rows = DB::table('panel_users as u')
            ->leftJoin('panel_users as r', 'r.id', '=', 'u.referred_by_id') // referrer
            ->select([
                'u.id',
                DB::raw('COALESCE(NULLIF(TRIM(u.name), ""), u.email) as name'),
                'u.email',
                DB::raw('u.code as panel_code'),
                DB::raw('u.partner_code_locked as code_locked'),
                'u.role',
                'u.banned',
                'u.credit',
                'u.enable_personalized_price',
                'u.partner_plan_type',
                DB::raw('u.referred_by_id as referrer_id'),
                'u.created_at',
                DB::raw('r.name as referrer_name'),
                DB::raw('r.code as referrer_code'),
                DB::raw("u.ref_commission_rate as commission_percent"),
                // استراتژی قیمت: شخصی ← پیش‌فرض
                DB::raw("
                CASE
                    WHEN u.enable_personalized_price = 1 THEN 'custom'
                    ELSE 'default'
                END as strategy
                "),
                // دسترسی: 1=admin | 2=user  (در پروژه شما 3=staff هم بود؛ اما طبق خواسته‌ی فعلی: 1/2)
                DB::raw("
                    CASE
                        WHEN u.role = 1 THEN 'admin'
                        ELSE 'user'
                    END as access
                "),
                // وضعیت: 0=normal | 1=blocked
                DB::raw("
                    CASE
                        WHEN u.banned = 1 THEN 'blocked'
                        ELSE 'normal'
                    END as status
                "),
            ])
            ->when($q !== '', function($qq) use ($q) {
                $like = '%'.str_replace(['%','_'], ['\\%','\\_'], $q).'%';
                $qq->where(function($w) use ($like) {
                    $w->where('u.name', 'like', $like)
                      ->orWhere('u.email', 'like', $like)
                      ->orWhere('u.code', 'like', $like);
                });
            })
            ->orderByDesc('u.created_at')
            ->limit($perPage)
            ->get();

        // خروجی هم‌خوان با الگوی بقیه‌ی Adminها
        return response()->json([
            'ok'   => true,
            'data' => $rows,
        ]);
    }

    public function destroy($id)
    {
        // اگر SoftDelete داری از مدل استفاده کن؛ در غیر اینصورت حذف سخت با DB:
        // use App\Models\User;
        // $user = User::findOrFail($id);
        // $user->delete();

        $deleted = \DB::table('panel_users')->where('id', $id)->delete();
        if ($deleted) {
            return response()->json(['ok' => true]);
        }
        return response()->json(['ok' => false], 404);
    }

    public function updateProfile(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            // رکورد فعلی
            $u = DB::table('panel_users')
                ->select('id','name','email','code','referred_by_id','ref_commission_rate','partner_plan_type','role','partner_code_locked')
                ->where('id', $id)
                ->lockForUpdate()->first();

            if (!$u) {
                return response()->json(['ok' => false, 'msg' => 'Not found'], 404);
            }

            app(\App\Services\PartnerCodeLock::class)->remember($u);
            if ($request->has('panel_code')) $request->merge(['panel_code' => trim((string) $request->input('panel_code'))]);

            // فقط چیزهایی که ممکن است از تب "مشخصات" بیایند
            $request->validate([
                'name'               => ['sometimes','nullable','string','max:190'],
                'email'              => ['sometimes','nullable','email','max:190', Rule::unique('panel_users','email')->ignore($id)],
                'panel_code'         => ['sometimes','required','string','max:50',  Rule::unique('panel_users','code')->ignore($id)],
                'referrer_id'        => ['sometimes','nullable','integer','exists:panel_users,id'],
                'commission_percent' => ['sometimes','nullable','numeric','min:0','max:100'],
                'password'           => ['sometimes','nullable','string','min:4','max:190'],
                'partner_plan_type'  => ['sometimes','nullable','in:time,volume,both'],
            ]);

            $nextReferrerId = $request->has('referrer_id')
                ? (($request->input('referrer_id') === '' || is_null($request->input('referrer_id'))) ? null : (int)$request->input('referrer_id'))
                : ($u->referred_by_id ? (int)$u->referred_by_id : null);

            $nextCommission = $request->has('commission_percent')
                ? (($request->input('commission_percent') === '' || is_null($request->input('commission_percent'))) ? null : (float)$request->input('commission_percent'))
                : ($u->ref_commission_rate !== null ? (float)$u->ref_commission_rate : null);

            if ($nextCommission !== null && $nextReferrerId === null) {
                return response()->json(['ok'=>false,'msg'=>'برای ثبت کمیسیون، معرف را انتخاب کنید'], 422);
            }

            if ($nextReferrerId !== null) {
                $refRole = DB::table('panel_users')->where('id', $nextReferrerId)->value('role');

                if ((int)$refRole === 1) {
                    return response()->json(['ok'=>false,'msg'=>'ادمین نمی‌تواند به‌عنوان معرف انتخاب شود'], 422);
                }
            }

            $updates = [];

            // name
            if ($request->has('name')) {
                $val = $request->input('name');
                if ($val !== $u->name) $updates['name'] = $val;
            }

            // email
            if ($request->has('email')) {
                $val = $request->input('email');
                if ($val !== $u->email) $updates['email'] = $val;
            }

            // panel_code -> code
            if ($request->has('panel_code')) {
                $val = $request->input('panel_code');
                if ($val !== $u->code) {
                    if (app(\App\Services\PartnerCodeLock::class)->locked($u)) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['panel_code' => 'کد پنل پس از ساخت اولین اکانت قابل تغییر نیست']);
                    }
                    if (DB::table('panel_users')->where('id', '<>', $id)->whereRaw('LOWER(TRIM(code)) = ?', [strtolower($val)])->exists()) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['panel_code' => 'این کد پنل قبلاً استفاده شده است']);
                    }
                    $updates['code'] = $val;
                }
            }

            // referrer_id -> referred_by_id (قابل null)
            if ($request->has('referrer_id')) {
                $raw = $request->input('referrer_id');
                $val = ($raw === '' || is_null($raw)) ? null : (int)$raw;
                if ($val === (int)$id) {
                    return response()->json(['ok'=>false,'msg'=>'Referrer cannot be the same user'], 422);
                }
                if ($val != $u->referred_by_id) $updates['referred_by_id'] = $val;
            }

            // commission_percent -> ref_commission_rate (قابل null)
            if ($request->has('commission_percent')) {
                $raw = $request->input('commission_percent');
                $val = ($raw === '' || is_null($raw)) ? null : (float)$raw;
                $cur = $u->ref_commission_rate;

                $changed = (is_null($val) && !is_null($cur)) ||
                        (!is_null($val) && (float)$cur !== (float)$val);

                if ($changed) $updates['ref_commission_rate'] = $val;
            }

            if ($request->has('partner_plan_type')) {
                $val = $request->input('partner_plan_type') ?: 'time';
                if ($val !== ($u->partner_plan_type ?? 'time')) {
                    $updates['partner_plan_type'] = $val;
                }
            }

            // password (فقط اگر مقدار داده شده و خالی نیست) — هرگز نمایش داده نمی‌شود
            if ($request->has('password')) {
                $pass = $request->input('password');
                if (is_string($pass) && $pass !== '') {
                    $updates['password'] = Hash::make($pass);
                }
                // اگر خالی بود، اصلاً دست نمی‌زنیم
            }

            if (empty($updates)) {
                // هیچ تغییری لازم نیست
                return response()->json(['ok' => true, 'changed' => []]);
            }

            $updates['updated_at'] = now();
            DB::table('panel_users')->where('id', $id)->update($updates);

            return response()->json(['ok' => true, 'changed' => array_keys($updates)]);
        });
    }

    public function pricingPreview($id)
    {
        if ((int) $id > 0) {
            $u = DB::table('panel_users')
                ->select([
                    'id',
                    'traffic_price',
                    'enable_personalized_price',
                    'personalized_price_test',
                    'personalized_price_1',
                    'personalized_price_3',
                    'personalized_price_6',
                    'personalized_price_12',
                    'partner_plan_type',
                ])
                ->where('id', $id)
                ->first();

            if (!$u) {
                return response()->json(['ok' => false, 'message' => 'Panel user not found'], 404);
            }
        } else {
            $u = (object) [
                'id' => 0,
                'traffic_price' => null,
                'enable_personalized_price' => 0,
                'personalized_price_test' => null,
                'personalized_price_1' => null,
                'personalized_price_3' => null,
                'personalized_price_6' => null,
                'personalized_price_12' => null,
                'partner_plan_type' => 'time',
            ];
        }

        $timeGroups = DB::table('panel_tariff_groups')
            ->where('enable', 1)
            ->where('group_type', 'time')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $volumeGroups = DB::table('panel_tariff_groups')
            ->where('enable', 1)
            ->where('group_type', 'volume')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($timeGroups->isEmpty() && $volumeGroups->isEmpty()) {
            return response()->json([
                'ok' => false,
                'message' => 'هیچ پلن تعرفه‌ای تعریف نشده است',
            ], 422);
        }

        $allGroupIds = $timeGroups->pluck('id')->merge($volumeGroups->pluck('id'))->values();

        $plans = DB::table('panel_plan')
            ->whereIn('tariff_group_id', $allGroupIds)
            ->whereIn('plan_key', ['gig', 'test', '1m', '3m', '6m', '12m', 'volume_gig'])
            ->get()
            ->groupBy('tariff_group_id');

        $customRows = DB::table('panel_user_tariff_prices')
            ->where('panel_user_id', (int) $id)
            ->get()
            ->groupBy('tariff_group_id');

        $legacyDefaultTimeGroupId = (int) ($timeGroups->firstWhere('is_default', 1)->id ?? $timeGroups->first()->id ?? 0);
        $legacyDefaultVolumeGroupId = (int) ($volumeGroups->firstWhere('is_default', 1)->id ?? $volumeGroups->first()->id ?? 0);

        $resultGroups = $timeGroups->map(function ($group) use ($plans, $customRows, $u, $legacyDefaultTimeGroupId) {
            $defaultRows = ($plans[$group->id] ?? collect())->keyBy('plan_key');
            $customGroupRows = ($customRows[$group->id] ?? collect())->keyBy('plan_key');

            $defaults = [
                'overage_per_gb' => $this->priceFromPlan($defaultRows, 'gig'),
                'test' => $this->priceFromPlan($defaultRows, 'test'),
                'm1' => $this->priceFromPlan($defaultRows, '1m'),
                'm3' => $this->priceFromPlan($defaultRows, '3m'),
                'm6' => $this->priceFromPlan($defaultRows, '6m'),
                'm12' => $this->priceFromPlan($defaultRows, '12m'),
            ];

            $personalized = [
                'overage_per_gb' => $this->priceFromCustom($customGroupRows, 'gig'),
                'test' => $this->priceFromCustom($customGroupRows, 'test'),
                'm1' => $this->priceFromCustom($customGroupRows, '1m'),
                'm3' => $this->priceFromCustom($customGroupRows, '3m'),
                'm6' => $this->priceFromCustom($customGroupRows, '6m'),
                'm12' => $this->priceFromCustom($customGroupRows, '12m'),
            ];

            if ((int) $group->id === $legacyDefaultTimeGroupId) {
                $personalized['overage_per_gb'] = $personalized['overage_per_gb'] ?? (is_null($u->traffic_price) ? null : (int) $u->traffic_price);
                $personalized['test'] = $personalized['test'] ?? (is_null($u->personalized_price_test) ? null : (int) $u->personalized_price_test);
                $personalized['m1'] = $personalized['m1'] ?? (is_null($u->personalized_price_1) ? null : (int) $u->personalized_price_1);
                $personalized['m3'] = $personalized['m3'] ?? (is_null($u->personalized_price_3) ? null : (int) $u->personalized_price_3);
                $personalized['m6'] = $personalized['m6'] ?? (is_null($u->personalized_price_6) ? null : (int) $u->personalized_price_6);
                $personalized['m12'] = $personalized['m12'] ?? (is_null($u->personalized_price_12) ? null : (int) $u->personalized_price_12);
            }

            return [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'is_default' => (int) $group->is_default,
                'group_type' => 'time',
                'default' => $defaults,
                'personalized' => $personalized,
            ];
        })->values();

        $resultVolumeGroups = $volumeGroups->map(function ($group) use ($plans, $customRows, $u, $legacyDefaultVolumeGroupId) {
            $defaultRows = ($plans[$group->id] ?? collect())->keyBy('plan_key');
            $customGroupRows = ($customRows[$group->id] ?? collect())->keyBy('plan_key');

            $defaults = [
                'overage_per_gb' => $this->priceFromPlan($defaultRows, 'volume_gig'),
                'test' => null,
                'm1' => null,
                'm3' => null,
                'm6' => null,
                'm12' => null,
            ];

            $personalized = [
                'overage_per_gb' => $this->priceFromCustom($customGroupRows, 'volume_gig'),
                'test' => null,
                'm1' => null,
                'm3' => null,
                'm6' => null,
                'm12' => null,
            ];

            if ((int) $group->id === $legacyDefaultVolumeGroupId) {
                $personalized['overage_per_gb'] = $personalized['overage_per_gb'] ?? (is_null($u->traffic_price) ? null : (int) $u->traffic_price);
            }

            return [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'is_default' => (int) $group->is_default,
                'group_type' => 'volume',
                'default' => $defaults,
                'personalized' => $personalized,
            ];
        })->values();

        $firstTime = $resultGroups->firstWhere('is_default', 1) ?: $resultGroups->first();
        $firstVolume = $resultVolumeGroups->firstWhere('is_default', 1) ?: $resultVolumeGroups->first();

        return response()->json([
            'ok' => true,
            'enable_personalized_price' => (int) ($u->enable_personalized_price ?? 0),

            // طرح‌های زمانی
            'groups' => $resultGroups,

            // طرح‌های حجمی جدید
            'volume_groups' => $resultVolumeGroups,

            // سازگاری با کدهای قبلی
            'volume_default' => [
                'overage_per_gb' => $firstVolume['default']['overage_per_gb'] ?? null,
            ],
            'volume_personalized' => [
                'overage_per_gb' => $firstVolume['personalized']['overage_per_gb'] ?? null,
            ],
            'default' => $firstTime['default'] ?? null,
            'personalized' => $firstTime['personalized'] ?? null,
        ]);
    }

    public function updatePricing(Request $request, $id)
    {
        $u = DB::table('panel_users')->where('id', $id)->first();

        if (!$u) {
            return response()->json(['ok' => false, 'message' => 'Panel user not found'], 404);
        }

        $partnerPlanType = $u->partner_plan_type ?? 'time';
        $isVolumePartner = $partnerPlanType === 'volume';
        $isBothPartner = $partnerPlanType === 'both';

        $strategy = trim((string) $request->input('price_strategy', ''));

        if (!in_array($strategy, ['default', 'custom', 'like_referrer'], true)) {
            return response()->json(['ok' => false, 'message' => 'Invalid price_strategy'], 422);
        }

        $wasCustom = (int) ($u->enable_personalized_price ?? 0) === 1;
        $wantCustom = $strategy === 'custom';
        $wantReferrer = $strategy === 'like_referrer';

        if ($wantReferrer) {
            if (empty($u->referred_by_id)) {
                return response()->json(['ok' => false, 'message' => 'REFERRER_REQUIRED'], 422);
            }

            $ref = DB::table('panel_users')->where('id', (int) $u->referred_by_id)->first();

            if (!$ref) {
                return response()->json(['ok' => false, 'message' => 'REFERRER_NOT_FOUND'], 422);
            }

            DB::transaction(function () use ($id, $ref) {
                $refIsCustom = (int) ($ref->enable_personalized_price ?? 0) === 1;

                DB::table('panel_users')->where('id', $id)->update([
                    'enable_personalized_price' => $refIsCustom ? 1 : 0,
                    'traffic_price' => $refIsCustom ? $ref->traffic_price : null,
                    'personalized_price_test' => $refIsCustom ? $ref->personalized_price_test : null,
                    'personalized_price_1' => $refIsCustom ? $ref->personalized_price_1 : null,
                    'personalized_price_3' => $refIsCustom ? $ref->personalized_price_3 : null,
                    'personalized_price_6' => $refIsCustom ? $ref->personalized_price_6 : null,
                    'personalized_price_12' => $refIsCustom ? $ref->personalized_price_12 : null,
                    'updated_at' => now(),
                ]);

                DB::table('panel_user_tariff_prices')
                    ->where('panel_user_id', (int) $id)
                    ->delete();
                if ($refIsCustom) {
                    $rows = DB::table('panel_user_tariff_prices')
                        ->where('panel_user_id', (int) $ref->id)
                        ->get();

                    foreach ($rows as $row) {
                        DB::table('panel_user_tariff_prices')->insert([
                            'panel_user_id' => (int) $id,
                            'tariff_group_id' => $row->tariff_group_id,
                            'plan_key' => $row->plan_key,
                            'price' => $row->price,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

            return response()->json(['ok' => true]);
        }

        if (!$wasCustom && !$wantCustom) {
            return response()->json(['ok' => true, 'changed' => []]);
        }

        $allowedGroupTypes = $isBothPartner
            ? ['time', 'volume']
            : [$isVolumePartner ? 'volume' : 'time'];

        $expectedGroupType = $isVolumePartner ? 'volume' : 'time';

        $defaultGroupId = (int) DB::table('panel_tariff_groups')
            ->where('group_type', $expectedGroupType)
            ->where('is_default', 1)
            ->value('id');

        if ($defaultGroupId <= 0) {
            $defaultGroupId = (int) DB::table('panel_tariff_groups')
                ->where('group_type', $expectedGroupType)
                ->orderBy('id')
                ->value('id');
        }

        if ($defaultGroupId <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'هیچ پلن تعرفه‌ای تعریف نشده است',
            ], 422);
        }

        if ($wasCustom && !$wantCustom) {
            $defaultTrafficPlanKey = $isVolumePartner ? 'volume_gig' : 'gig';

            $gigDefault = DB::table('panel_plan')
                ->where('tariff_group_id', $defaultGroupId)
                ->where('plan_key', $defaultTrafficPlanKey)
                ->where('enable', 1)
                ->value('default_price');

            if ($gigDefault === null || $gigDefault === '') {
                return response()->json(['ok' => false, 'message' => 'GIG_DEFAULT_NOT_FOUND'], 422);
            }

            DB::transaction(function () use ($id, $gigDefault) {
                DB::table('panel_users')->where('id', $id)->update([
                    'enable_personalized_price' => 0,
                    'traffic_price' => (int) $gigDefault,
                    'updated_at' => now(),
                ]);

                DB::table('panel_user_tariff_prices')
                    ->where('panel_user_id', (int) $id)
                    ->delete();
            });

            return response()->json([
                'ok' => true,
                'changed' => ['enable_personalized_price', 'traffic_price', 'panel_user_tariff_prices'],
            ]);
        }

        if ($wantCustom) {
            $groupsPayload = $request->input('groups');

            if (is_array($groupsPayload) && !empty($groupsPayload)) {
                $validatedGroups = [];

                foreach ($groupsPayload as $group) {
                    $groupId = (int) ($group['id'] ?? 0);

                    if ($groupId <= 0) {
                        return response()->json(['ok' => false, 'message' => 'INVALID_TARIFF_GROUP'], 422);
                    }

                    $groupRow = DB::table('panel_tariff_groups')
                        ->where('id', $groupId)
                        ->whereIn('group_type', $allowedGroupTypes)
                        ->first();

                    if (!$groupRow) {
                        return response()->json(['ok' => false, 'message' => 'TARIFF_GROUP_NOT_FOUND'], 422);
                    }

                    $groupType = (string) ($groupRow->group_type ?? 'time');


                    $prices = $group['prices'] ?? [];

                    $required = $groupType === 'volume'
                        ? [
                            'overage_per_gb' => 'volume_gig',
                        ]
                        : [
                            'overage_per_gb' => 'gig',
                            'test' => 'test',
                            'm1' => '1m',
                            'm3' => '3m',
                            'm6' => '6m',
                            'm12' => '12m',
                        ];

                    foreach ($required as $frontendKey => $planKey) {
                        if (!array_key_exists($frontendKey, $prices)) {
                            return response()->json(['ok' => false, 'message' => 'MISSING_PRICE_' . $frontendKey], 422);
                        }

                        if ($prices[$frontendKey] === null || $prices[$frontendKey] === '') {
                            return response()->json(['ok' => false, 'message' => 'MISSING_PRICE_' . $frontendKey], 422);
                        }

                        if (!is_numeric($prices[$frontendKey]) || (int) $prices[$frontendKey] < 0) {
                            return response()->json(['ok' => false, 'message' => 'INVALID_PRICE_' . $frontendKey], 422);
                        }
                    }

                    $validatedGroups[] = [
                        'id' => $groupId,
                        'group_type' => $groupType,
                        'prices' => $prices,
                    ];
                }

                DB::transaction(function () use ($id, $validatedGroups, $defaultGroupId, $isVolumePartner) {
                    DB::table('panel_users')->where('id', $id)->update([
                        'enable_personalized_price' => 1,
                        'updated_at' => now(),
                    ]);

                    DB::table('panel_user_tariff_prices')
                        ->where('panel_user_id', (int) $id)
                        ->delete();

                    foreach ($validatedGroups as $group) {
                        $groupType = (string) ($group['group_type'] ?? 'time');

                        $map = $groupType === 'volume'
                            ? [
                                'overage_per_gb' => 'volume_gig',
                            ]
                            : [
                                'overage_per_gb' => 'gig',
                                'test' => 'test',
                                'm1' => '1m',
                                'm3' => '3m',
                                'm6' => '6m',
                                'm12' => '12m',
                            ];

                        foreach ($map as $frontendKey => $planKey) {
                            DB::table('panel_user_tariff_prices')->insert([
                                'panel_user_id' => (int) $id,
                                'tariff_group_id' => (int) $group['id'],
                                'plan_key' => $planKey,
                                'price' => (int) $group['prices'][$frontendKey],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        if ((int) $group['id'] === (int) $defaultGroupId) {
                            $updateData = [
                                'traffic_price' => (int) $group['prices']['overage_per_gb'],
                                'updated_at' => now(),
                            ];

                            if ($groupType !== 'volume') {
                                $updateData['personalized_price_test'] = (int) $group['prices']['test'];
                                $updateData['personalized_price_1'] = (int) $group['prices']['m1'];
                                $updateData['personalized_price_3'] = (int) $group['prices']['m3'];
                                $updateData['personalized_price_6'] = (int) $group['prices']['m6'];
                                $updateData['personalized_price_12'] = (int) $group['prices']['m12'];
                            }

                            DB::table('panel_users')->where('id', $id)->update($updateData);
                        }
                    }
                });

                return response()->json([
                    'ok' => true,
                    'changed' => ['enable_personalized_price', 'panel_user_tariff_prices'],
                ]);
            }

            $rules = [
                'traffic_price' => ['required', 'integer', 'min:0'],
                'personalized_price_test' => ['required', 'integer', 'min:0'],
                'personalized_price_1' => ['required', 'integer', 'min:0'],
                'personalized_price_3' => ['required', 'integer', 'min:0'],
                'personalized_price_6' => ['required', 'integer', 'min:0'],
                'personalized_price_12' => ['required', 'integer', 'min:0'],
            ];

            $data = $request->validate($rules);

            DB::transaction(function () use ($id, $data, $defaultGroupId) {
                DB::table('panel_users')->where('id', $id)->update([
                    'enable_personalized_price' => 1,
                    'traffic_price' => (int) $data['traffic_price'],
                    'personalized_price_test' => (int) $data['personalized_price_test'],
                    'personalized_price_1' => (int) $data['personalized_price_1'],
                    'personalized_price_3' => (int) $data['personalized_price_3'],
                    'personalized_price_6' => (int) $data['personalized_price_6'],
                    'personalized_price_12' => (int) $data['personalized_price_12'],
                    'updated_at' => now(),
                ]);

                DB::table('panel_user_tariff_prices')
                    ->where('panel_user_id', (int) $id)
                    ->where('tariff_group_id', $defaultGroupId)
                    ->delete();

                $insertRows = [
                    ['plan_key' => 'gig', 'price' => (int) $data['traffic_price']],
                    ['plan_key' => 'test', 'price' => (int) $data['personalized_price_test']],
                    ['plan_key' => '1m', 'price' => (int) $data['personalized_price_1']],
                    ['plan_key' => '3m', 'price' => (int) $data['personalized_price_3']],
                    ['plan_key' => '6m', 'price' => (int) $data['personalized_price_6']],
                    ['plan_key' => '12m', 'price' => (int) $data['personalized_price_12']],
                ];

                foreach ($insertRows as $row) {
                    DB::table('panel_user_tariff_prices')->insert([
                        'panel_user_id' => (int) $id,
                        'tariff_group_id' => $defaultGroupId,
                        'plan_key' => $row['plan_key'],
                        'price' => $row['price'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

            return response()->json([
                'ok' => true,
                'changed' => [
                    'enable_personalized_price',
                    'traffic_price',
                    'personalized_price_test',
                    'personalized_price_1',
                    'personalized_price_3',
                    'personalized_price_6',
                    'personalized_price_12',
                    'panel_user_tariff_prices',
                ],
            ]);
        }

        return response()->json(['ok' => true, 'changed' => []]);
    }

    private function priceFromPlan($rows, string $planKey): ?int
    {
        $row = $rows[$planKey] ?? null;

        if (!$row || $row->default_price === null || $row->default_price === '') {
            return null;
        }

        return (int) $row->default_price;
    }

    private function priceFromCustom($rows, string $planKey): ?int
    {
        $row = $rows[$planKey] ?? null;

        if (!$row || $row->price === null || $row->price === '') {
            return null;
        }

        return (int) $row->price;
    }

    public function statusFlags($id)
    {
        $partner = DB::table('panel_users')->select(['id','code','banned'])->where('id', $id)->first();
        if (!$partner) {
            return response()->json(['ok'=>false, 'message'=>'Panel user not found'], 404);
        }

        $code = trim((string)$partner->code);

        // اگر کد خالی بود، هیچ کاربری منتسب نمی‌شود
        if ($code === '') {
            return response()->json([
                'ok'               => true,
                'partner_banned'   => ((int)$partner->banned === 1),
                'all_users_banned' => false,
                'counts'           => ['total'=>0, 'banned'=>0],
            ]);
        }

        // کوئری کاربران منتسب به این نماینده:
        // - ایمیل: هرجا code در ایمیل باشد (case-insensitive)
        // - remarks: هرجا code باشد
        $codeLower = strtolower($code);
        $base = DB::table('v2_user')->where(function($q) use ($code, $codeLower){
            $q->whereRaw('LOWER(email) LIKE ?', ['%'.$codeLower.'%'])
            ->orWhere('remarks', 'LIKE', '%'.$code.'%');
        });

        $total  = (clone $base)->count();
        $banned = (clone $base)->where('banned', 1)->count();

        return response()->json([
            'ok'                => true,
            'partner_banned'    => ((int)$partner->banned === 1),
            'all_users_banned'  => ($total > 0 && $banned === $total),
            'counts'            => ['total'=>$total, 'banned'=>$banned],
        ]);
    }

    public function banPartner(Request $request, $id)
    {
        // telegram_user_id را هم بگیر
        $partner = DB::table('panel_users')
            ->select(['id','telegram_user_id'])
            ->where('id', $id)
            ->first();

        if (!$partner) return response()->json(['ok'=>false, 'message'=>'Panel user not found'], 404);

        $banned = filter_var($request->input('banned'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($banned === null) return response()->json(['ok'=>false, 'message'=>'Invalid banned'], 422);

        DB::table('panel_users')->where('id', $id)->update([
            'banned'    => $banned ? 1 : 0,
            'updated_at'=> now(),
        ]);

        // 🔔 پیام تلگرام به خودِ نماینده
        try {
            if (!empty($partner->telegram_user_id)) {
                $tg = new TelegramNotifier();
                $text = $banned
                    ? "❌ دسترسی شما به پنل کاربری مسدود گردید."
                    : "✅ محدودیت دسترسی شما به پنل کاربری برطرف گردید.";
                $tg->sendTextTo((string)$partner->telegram_user_id, $text);
            }
        } catch (\Throwable $e) {
            // ساکت: اگر تلگرام قطع بود، API نباید خطا بده
        }

        return response()->json(['ok'=>true, 'banned'=> (bool)$banned]);
    }

    public function banPartnerUsers(Request $request, $id)
    {
        // telegram_user_id را هم بگیر
        $partner = DB::table('panel_users')
            ->select(['id','code','telegram_user_id'])
            ->where('id', $id)
            ->first();
        if (!$partner) return response()->json(['ok'=>false, 'message'=>'Panel user not found'], 404);

        $banned = filter_var($request->input('banned'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($banned === null) return response()->json(['ok'=>false, 'message'=>'Invalid banned'], 422);

        $code = trim((string)$partner->code);
        if ($code === '') {
            // اگر کد پنل ندارد، عملاً کاربری برای این تاگل شناسایی نمی‌شود
            return response()->json(['ok'=>true, 'affected'=>['set_banned'=>0, 'set_unbanned'=>0]]);
        }

        $codeLower = strtolower($code);

        // نتیجهٔ ترنزاکشن را به صورت آرایه برگردان، نه Response
        $result = DB::transaction(function () use ($id, $code, $codeLower, $banned) {
            // همه کاربران منتسب به این نماینده:
            $userRows = DB::table('v2_user')
                ->select('id','banned')
                ->where(function($q) use ($code, $codeLower){
                    $q->whereRaw('LOWER(email) LIKE ?', ['%'.$codeLower.'%'])
                    ->orWhere('remarks', 'LIKE', '%'.$code.'%');
                })
                ->get();

            if ($userRows->isEmpty()) {
                return ['ok'=>true, 'affected'=>['set_banned'=>0, 'set_unbanned'=>0]];
            }

            $affected = ['set_banned'=>0, 'set_unbanned'=>0];

            if ($banned) {
                // مسدود کردن گروهی: فقط آزادها را ببند
                $toBan = $userRows->where('banned', 0)->pluck('id')->all();
                if (!empty($toBan)) {
                    DB::table('v2_user')->whereIn('id', $toBan)->update(['banned'=>1]);
                    $affected['set_banned'] = count($toBan);

                    // ثبت/به‌روزرسانی رکوردهای فعال
                    $rows = array_map(fn($uid)=>[
                        'partner_id' => $id,
                        'v2_user_id' => $uid,
                        'active'     => 1,
                        'created_at' => now(),
                        'cleared_at' => null,
                    ], $toBan);

                    DB::table('panel_partner_user_bulk_bans')->upsert(
                        $rows,
                        ['partner_id','v2_user_id','active'],
                        ['active','cleared_at']
                    );
                }
            } else {
                // آزادسازی گروهی: فقط آنهایی که با تاگل گروهی قبلاً بسته شده‌اند
                $bulkIds = DB::table('panel_partner_user_bulk_bans')
                    ->where('partner_id', $id)
                    ->where('active', 1)
                    ->pluck('v2_user_id')
                    ->all();

                if (!empty($bulkIds)) {
                    DB::table('v2_user')->whereIn('id', $bulkIds)->update(['banned'=>0]);
                    $affected['set_unbanned'] = count($bulkIds);

                    DB::table('panel_partner_user_bulk_bans')
                        ->where('partner_id', $id)
                        ->whereIn('v2_user_id', $bulkIds)
                        ->where('active', 1)
                        ->delete();
                }
            }

            return ['ok'=>true, 'affected'=>$affected];
        });

        // 🔔 ارسال پیام بعد از کامیت
        DB::afterCommit(function () use ($partner, $banned) {
            try {
                if (!empty($partner->telegram_user_id)) {
                    $tg = new TelegramNotifier();
                    $text = $banned
                        ? "❌ کلیه کاربران شما مسدود گردیدند."
                        : "✅ کلیه کاربران شما از حالت مسدود خارج شدند.";
                    $tg->sendTextTo((string)$partner->telegram_user_id, $text);
                }
            } catch (\Throwable $e) {
                // ساکت
            }
        });

        return response()->json($result);
    }

    public function walletAdjust(Request $request, $id)
    {
        // partner + chat id
        $partner = DB::table('panel_users')
            ->select(['id','credit','telegram_user_id'])
            ->where('id', $id)
            ->first();
        if (!$partner) return response()->json(['ok'=>false, 'message'=>'Panel user not found'], 404);

        $op     = trim((string)$request->input('op', ''));
        $amount = (int)$request->input('amount', 0);
        if (!in_array($op, ['increase','decrease'], true) || $amount <= 0) {
            return response()->json(['ok'=>false, 'message'=>'Invalid op/amount'], 422);
        }

        // =========================
        // ↑ افزایش اعتبار (شارژ)  |
        // =========================
        if ($op === 'increase') {
            $adminId = (int) (optional($request->user())->id ?? 0);

            // before
            $before = (int) DB::table('panel_users')->where('id', $id)->value('credit');

            // 1) رسید دستیِ "در انتظار بررسی" بساز
            //    (verify همین را مثل واریزی کارتی پردازش می‌کند)
            $wrId = DB::table('panel_wallet_receipts')->insertGetId([
                'user_id'        => (int)$id,
                'amount'         => (int)$amount,
                'method'         => 'manual',
                'disk'           => 'public',
                'path'           => 'manual://admin',
                'original_name'  => 'ADMIN-MANUAL',
                'mime'           => 'text/plain',
                'size'           => 0,
                'status'         => 'submitted',          // مهم
                'commission_paid'      => 0,
                'commission_tx_id'     => null,
                'commission_notified_at' => null,
                'notified_at'          => null,           // تا توست شارژ بیاید
                'meta'                 => json_encode(['via' => 'admin_manual']),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            // 2) دقیقاً همان جریان "تأیید واریزی" را اجرا کن
            app(WalletReceiptService::class)->approve((int)$wrId, $adminId, (int)$amount);

            // after
            $after = (int) DB::table('panel_users')->where('id', $id)->value('credit');

            // خروجی
            return response()->json(['ok'=>true, 'before'=>$before, 'after'=>$after, 'op'=>'increase']);
        }

        // =========================
        // ↓ کاهش اعتبار (اصلاحیه) |
        // =========================
        $logger = app(TransactionLogger::class);

        $result = DB::transaction(function () use ($id, $amount, $logger) {
            // قفل و محاسبه
            $before = (int) DB::table('panel_users')->where('id', $id)->lockForUpdate()->value('credit');
            $after  = $before - $amount;

            // بروزرسانی موجودی
            DB::table('panel_users')->where('id', $id)->update([
                'credit'     => $after,
                'updated_at' => now(),
            ]);

            // تراکنش کاهشی (اصلاحیه)
            $trxId = $logger->start([
                'panel_user_id'  => (int)$id,
                'type'           => 'wallet_adjust',
                'direction'      => 'debit',
                'amount'         => (int)$amount,
                'balance_before' => (int)$before,
                'balance_after'  => (int)$after,
                'reference_type' => 'admin_manual',
                'reference_id'   => (int) (optional(request()->user())->id ?? 0),
                'currency'       => 'IRT',
            ]);
            $logger->finalize($trxId, [
                'status'     => 'success',
                'extra_meta' => ['title' => 'اصلاحیه', 'source' => 'admin'],
            ]);

            // رسید برای توست «اصلاحیه» در پنل کاربر
            DB::table('panel_wallet_receipts')->insert([
                'user_id'        => (int)$id,
                'amount'         => (int)$amount,     // مثبت ذخیره می‌شود
                'method'         => 'adjust',
                'disk'           => 'public',
                'path'           => 'adjust://admin',
                'original_name'  => 'ADMIN-ADJUST',
                'mime'           => 'text/plain',
                'size'           => 0,
                'status'         => 'verified',
                'commission_paid'      => 1,
                'commission_tx_id'     => null,
                'commission_notified_at' => null,
                'notified_at'    => null,             // تا توست اصلاحیه بیاید
                'meta'           => json_encode(['via' => 'admin_manual']),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            return ['ok'=>true, 'before'=>$before, 'after'=>$after, 'op'=>'decrease'];
        });

        // تلگرام اصلاحیه برای خود نماینده (در افزایش نیازی نیست؛ verify خودش پیام می‌دهد)
        DB::afterCommit(function () use ($partner, $result) {
            if (($result['ok'] ?? false) && ($result['op'] ?? '') === 'decrease') {
                try {
                    if (!empty($partner->telegram_user_id)) {
                        $tg = new TelegramNotifier();
                        $amount = (int)(($result['before'] ?? 0) - ($result['after'] ?? 0));
                        $fmtAmount = number_format($amount);
                        $fmtAfter  = number_format((int)($result['after'] ?? 0));
                        $tg->sendTextTo((string)$partner->telegram_user_id,
                            "❌ اصلاحیه: مبلغ {$fmtAmount} تومان از کیف پول شما کسر گردید.\n💰 موجودی فعلی: {$fmtAfter} تومان"
                        );
                    }
                } catch (\Throwable $e) {}
            }
        });

        return response()->json($result);
    }

    public function store(Request $request)
    {
        $request->merge(['panel_code' => trim((string) $request->input('panel_code'))]);
        // === اعتبارسنجی ورودی‌ها
        $data = $request->validate([
            'name'               => ['required','string','max:190'],
            'email'              => ['required','email','max:190','unique:panel_users,email'],
            'panel_code'         => ['required','string','max:50','unique:panel_users,code'],
            'password'           => ['required','string','min:4','max:190'],
            'role'               => ['required','in:1,2'], // 1=admin, 2=user
            'partner_plan_type'  => ['nullable','in:time,volume,both'],
            'referrer_id'        => ['nullable','integer','exists:panel_users,id'],
            'commission_percent' => ['nullable','numeric','min:0','max:100'],

            // استراتژی قیمت
            'pricing_strategy'                      => ['required','in:default,referrer,like_referrer,custom'],
            'pricing_payload.personalized_price_test'   => ['required_if:pricing_strategy,custom','integer','min:0'],
            'pricing_payload.personalized_price_1'      => ['required_if:pricing_strategy,custom','integer','min:0'],
            'pricing_payload.personalized_price_3'      => ['required_if:pricing_strategy,custom','integer','min:0'],
            'pricing_payload.personalized_price_6'      => ['required_if:pricing_strategy,custom','integer','min:0'],
            'pricing_payload.personalized_price_12'     => ['required_if:pricing_strategy,custom','integer','min:0'],
            'pricing_payload.traffic_price'             => ['required_if:pricing_strategy,custom','integer','min:0'],
            'pricing_groups_payload' => ['nullable', 'array'],
            'pricing_groups_payload.*.id' => ['required_with:pricing_groups_payload', 'integer', 'exists:panel_tariff_groups,id'],
            'pricing_groups_payload.*.prices' => ['required_with:pricing_groups_payload', 'array'],
            'pricing_groups_payload.*.prices.overage_per_gb' => ['required_with:pricing_groups_payload', 'integer', 'min:0'],
            'pricing_groups_payload.*.prices.test' => ['nullable', 'integer', 'min:0'],
            'pricing_groups_payload.*.prices.m1' => ['nullable', 'integer', 'min:0'],
            'pricing_groups_payload.*.prices.m3' => ['nullable', 'integer', 'min:0'],
            'pricing_groups_payload.*.prices.m6' => ['nullable', 'integer', 'min:0'],
            'pricing_groups_payload.*.prices.m12' => ['nullable', 'integer', 'min:0'],       
        ], [
            'email.unique'         => 'ایمیل تکراری است',
            'panel_code.unique'    => 'کد پنل تکراری است',
            'pricing_strategy.required' => 'انتخاب استراتژی قیمت الزامی است',
        ]);

        if (DB::table('panel_users')->whereRaw('LOWER(TRIM(code)) = ?', [strtolower($data['panel_code'])])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['panel_code' => 'این کد پنل قبلاً استفاده شده است']);
        }

        // اگر کمیسیون پر شده ولی معرف انتخاب نشده → خطا
        if (!is_null($data['commission_percent'] ?? null) && empty($data['referrer_id'])) {
            return response()->json(['ok'=>false,'message'=>'برای ثبت کمیسیون، معرف را انتخاب کنید'], 422);
        }

        // اگر مشابه معرف انتخاب شده ولی معرف نداریم → خطا
        $ps = $data['pricing_strategy'];
        if (($ps === 'referrer' || $ps === 'like_referrer') && empty($data['referrer_id'])) {
            return response()->json(['ok'=>false,'message'=>'برای «مشابه معرف»، انتخاب معرف الزامی است'], 422);
        }

        if (!empty($data['referrer_id'])) {
            $refRole = DB::table('panel_users')->where('id', (int)$data['referrer_id'])->value('role');

            if ((int)$refRole === 1) {
                return response()->json(['ok'=>false,'message'=>'ادمین نمی‌تواند به‌عنوان معرف انتخاب شود'], 422);
            }
        }

        // یکنواخت‌سازی نام استراتژی
        if ($ps === 'referrer') $ps = 'like_referrer';

        $partnerPlanType = $data['partner_plan_type'] ?? 'time';
        $isVolumePartner = $partnerPlanType === 'volume';
        $isBothPartner = $partnerPlanType === 'both';

        if ($ps === 'custom' && !empty($data['pricing_groups_payload']) && is_array($data['pricing_groups_payload'])) {
            $requiredPriceKeys = $isVolumePartner
                ? ['overage_per_gb']
                : ['overage_per_gb', 'test', 'm1', 'm3', 'm6', 'm12'];

            foreach ($data['pricing_groups_payload'] as $groupPayload) {
                $groupId = (int) ($groupPayload['id'] ?? 0);

                $groupRow = DB::table('panel_tariff_groups')
                    ->where('id', $groupId)
                    ->first();

                $groupType = (string) ($groupRow->group_type ?? 'time');

                $requiredPriceKeys = $groupType === 'volume'
                    ? ['overage_per_gb']
                    : ['overage_per_gb', 'test', 'm1', 'm3', 'm6', 'm12'];

                $prices = $groupPayload['prices'] ?? [];

                foreach ($requiredPriceKeys as $priceKey) {
                    if (!array_key_exists($priceKey, $prices) || $prices[$priceKey] === null || $prices[$priceKey] === '') {
                        return response()->json([
                            'ok' => false,
                            'message' => 'MISSING_PRICE_' . $priceKey,
                        ], 422);
                    }
                }
            }
        }

        // Helper: گرفتن قیمت پیش‌فرض ترافیک بر اساس نوع نماینده
        $getDefaultTrafficPrice = function (bool $isVolumePartner) {
            $groupType = $isVolumePartner ? 'volume' : 'time';
            $planKey = $isVolumePartner ? 'volume_gig' : 'gig';

            $groupId = (int) DB::table('panel_tariff_groups')
                ->where('group_type', $groupType)
                ->where('is_default', 1)
                ->value('id');

            if ($groupId <= 0) {
                $groupId = (int) DB::table('panel_tariff_groups')
                    ->where('group_type', $groupType)
                    ->orderBy('id')
                    ->value('id');
            }

            if ($groupId <= 0) {
                return null;
            }

            $row = DB::table('panel_plan')
                ->where('tariff_group_id', $groupId)
                ->where('plan_key', $planKey)
                ->where('enable', 1)
                ->select(['default_price as price'])
                ->first();

            return $row && $row->price !== null && $row->price !== '' ? (int) $row->price : null;
        };

        // آماده‌سازی مقادیر قیمت
        $enablePersonal = 0;
        $pt=null; $p1=$p3=$p6=$p12=$tgb = null;

        if ($ps === 'default') {
            $enablePersonal = 0;
            // مثل رفتار updatePricing: اگر لازم داری ترافیک را هم از دیفالت پر کن:
            $tgb = $getDefaultTrafficPrice($isVolumePartner);

        } elseif ($ps === 'like_referrer') {
            $ref = \DB::table('panel_users')->where('id', (int)$data['referrer_id'])->first();
            if ($ref) {
                $enablePersonal = (int)($ref->enable_personalized_price ?? 0);
                if ($enablePersonal === 1) {
                    $pt  = (int)($ref->personalized_price_test ?? 0);
                    $p1  = (int)($ref->personalized_price_1  ?? 0);
                    $p3  = (int)($ref->personalized_price_3  ?? 0);
                    $p6  = (int)($ref->personalized_price_6  ?? 0);
                    $p12 = (int)($ref->personalized_price_12 ?? 0);
                    $tgb = (int)($ref->traffic_price        ?? 0);
                } else {
                    $enablePersonal = 0;
                    $pt = $p1 = $p3 = $p6 = $p12 = $tgb = null;
                }
            }

        } else { // custom
            $pp = $data['pricing_payload'] ?? [];
            $enablePersonal = 1;
            $pt  = (int)($pp['personalized_price_test'] ?? 0);
            $p1  = (int)($pp['personalized_price_1']    ?? 0);
            $p3  = (int)($pp['personalized_price_3']    ?? 0);
            $p6  = (int)($pp['personalized_price_6']    ?? 0);
            $p12 = (int)($pp['personalized_price_12']   ?? 0);
            $tgb = (int)($pp['traffic_price']           ?? 0);
        }

        // ساخت رکورد
        $now = now();
        $insert = [
            'name'                     => $data['name'],
            'email'                    => $data['email'],
            'code'                     => $data['panel_code'],
            'role'                     => (int)$data['role'],
            'partner_plan_type'        => $data['partner_plan_type'] ?? 'time',
            'password'                 => \Hash::make($data['password']),
            'referred_by_id'           => $data['referrer_id'] === '' ? null : ($data['referrer_id'] ?? null),
            'ref_commission_rate'      => $data['commission_percent'] ?? null,

            'enable_personalized_price'=> $enablePersonal,
            'personalized_price_test'  => $pt,
            'personalized_price_1'     => $p1,
            'personalized_price_3'     => $p3,
            'personalized_price_6'     => $p6,
            'personalized_price_12'    => $p12,
            'traffic_price'            => $tgb,

            'banned'                   => 0,
            'credit'                   => 0,
            'created_at'               => $now,
            'updated_at'               => $now,
        ];

        $newId = \DB::table('panel_users')->insertGetId($insert);

        if ($ps === 'like_referrer' && !empty($data['referrer_id']) && $enablePersonal === 1) {
            $refRows = DB::table('panel_user_tariff_prices')
                ->where('panel_user_id', (int) $data['referrer_id'])
                ->get();

            $rows = [];

            foreach ($refRows as $row) {
                $rows[] = [
                    'panel_user_id' => (int) $newId,
                    'tariff_group_id' => (int) $row->tariff_group_id,
                    'plan_key' => $row->plan_key,
                    'price' => (int) $row->price,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($rows)) {
                DB::table('panel_user_tariff_prices')->insert($rows);
            }
        }

        if ($ps === 'custom' && !empty($data['pricing_groups_payload']) && is_array($data['pricing_groups_payload'])) {

            $rows = [];

            foreach ($data['pricing_groups_payload'] as $groupPayload) {
                $groupId = (int) ($groupPayload['id'] ?? 0);
                $prices = $groupPayload['prices'] ?? [];
                
                $groupRow = DB::table('panel_tariff_groups')
                    ->where('id', $groupId)
                    ->first();

                $groupType = (string) ($groupRow->group_type ?? 'time');

                $map = $groupType === 'volume'
                    ? [
                        'overage_per_gb' => 'volume_gig',
                    ]
                    : [
                        'overage_per_gb' => 'gig',
                        'test' => 'test',
                        'm1' => '1m',
                        'm3' => '3m',
                        'm6' => '6m',
                        'm12' => '12m',
                    ];                

                foreach ($map as $frontendKey => $planKey) {
                    $rows[] = [
                        'panel_user_id' => (int) $newId,
                        'tariff_group_id' => $groupId,
                        'plan_key' => $planKey,
                        'price' => (int) ($prices[$frontendKey] ?? 0),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (!empty($rows)) {
                DB::table('panel_user_tariff_prices')->insert($rows);
            }
        }
        
        return response()->json(['ok'=>true, 'id'=>$newId], 201);
    }

}

