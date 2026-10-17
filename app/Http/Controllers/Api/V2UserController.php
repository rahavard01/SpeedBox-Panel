<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Http\Controllers\Controller;
use App\Models\PanelPlan;
use App\Services\V2UserCreator;
use App\Services\PanelPricingService;
use App\Services\TransactionLogger;
use App\Services\PanelAccountActionService;
use App\Services\PartnerTariffAccessService;

/**
 * NOTE: این نسخه مشکل «کسر اعتبار اما عدم ساخت یوزر» را با
 * ۱) قرار دادن کسر اعتبار و ساخت یوزرها داخل یک تراکنش واحد (DB::transaction)
 * ۲) خطا دادن وقتی حتی یک یوزر هم ساخته نشود (rollback کامل) 
 * حل می‌کند. همچنین برای ساخت‌های بزرگ، فایل اکسل را به‌صورت فایل ذخیره و دانلود مستقیم برمی‌گرداند.
 */
class V2UserController extends Controller
{
    private function emailBelongsToPanelCode(string $email, string $panelCode): bool
    {
        return \App\Services\PanelAccountOwnership::matches($email, $panelCode);
    }

    // اگر تعداد ساخت زیاد باشد به‌جای استریم، فایل را ذخیره و لینک دانلود مستقیم می‌دهیم
    private const EXCEL_STREAM_THRESHOLD = 50;

    public function create(Request $request, V2UserCreator $creator, PanelPricingService $pricing)
    {
        // --- hardening: allow long-running batch builds (e.g., 100 users) ---
        @ini_set('max_execution_time', '900');
        @set_time_limit(900);
        @ini_set('memory_limit', '512M');
        if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', 1); }
        ignore_user_abort(true);

        $data = $request->validate([
            'custom_username' => ['nullable', 'string', 'max:32'],
            'panel_code'     => ['required','string'],
            'plan_key'       => ['required','string'],   // 'test'|'1m'|'3m'|'6m'|'12m'|'1m_noexp'
            'count'          => ['nullable','integer','min:1'],
            'no_expire'      => ['sometimes','boolean'],
            'download_excel' => ['sometimes','boolean'],
            'tariff_group_id' => ['nullable','integer','exists:panel_tariff_groups,id'],
            'volume_gb'      => ['nullable','integer','min:1'],
        ]);

        $count = (int)($data['count'] ?? 1);
        $requestedTariffGroupId = (int) ($data['tariff_group_id'] ?? 0);

        $requestedGroup = DB::table('panel_tariff_groups')
            ->where('id', $requestedTariffGroupId)
            ->where('enable', 1)
            ->first();

        $lookupPlanKey = ($data['plan_key'] === '1m_noexp') ? '1m' : $data['plan_key'];

        $isVolumeRequest = $lookupPlanKey === 'volume_gig'
            || (($requestedGroup->group_type ?? '') === 'volume');

        if ($isVolumeRequest) {
            $lookupPlanKey = 'volume_gig';
            $data['plan_key'] = 'volume_gig';
            $noExpire = true;
            $tariffGroupId = $this->resolveTariffGroupId($requestedTariffGroupId, 'volume');
        } else {
            $noExpire = $request->boolean('no_expire') || $data['plan_key'] === '1m_noexp';
            $tariffGroupId = $this->resolveTariffGroupId($requestedTariffGroupId, 'time');
        }

        $wantExcel = $request->boolean('download_excel') && $count > 1;



        // === تعیین صاحب کیف‌پول ===
        $me = $request->user();
        if (!$me) {
            return response()->json(['status'=>'error','message'=>'Unauthorized'], 401);
        }
        $panelUser = DB::table('panel_users')->where('email', strtolower($me->email))->first();
        if (!$panelUser) {
            return response()->json(['status'=>'error','message'=>'Panel user not found'], 404);
        }
        $panelUserId = (int)$panelUser->id;

        $realPanelCode = trim((string)($panelUser->code ?? ''));

        if ($realPanelCode === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'کد پنل شما نامشخص است',
            ], 422);
        }

        $data['panel_code'] = $realPanelCode;

        if (!app(PartnerTariffAccessService::class)->canSeeTariffGroup($panelUser, (int)$tariffGroupId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'این طرح برای شما فعال نیست',
            ], 403);
        }

        if (!app(PartnerTariffAccessService::class)->canCreateType($panelUser, $isVolumeRequest ? 'volume' : 'time')) {
            return response()->json(['status' => 'error', 'message' => 'این نوع پلن برای شما فعال نیست'], 403);
        }

        $partnerPlanType = $panelUser->partner_plan_type ?? 'time';
        $isVolumePartner = $partnerPlanType === 'volume';
        $isBothPartner = $partnerPlanType === 'both';

        if ($isVolumeRequest) {
            $count = max(1, (int)($data['count'] ?? 1));
        }

        // پیدا کردن v2_plan_id از panel_plan
        $pp = PanelPlan::where('enable', 1)
            ->where('tariff_group_id', $tariffGroupId)
            ->where('plan_key', $lookupPlanKey)
            ->first();

        if (!$pp || !$pp->v2_plan_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'پلن انتخابی با پلن ثبت شده مطابقت ندارد',
            ], 422);
        }
 
        if ($isVolumeRequest) {
            $minimum = \App\Services\VolumeTrafficPolicy::minimum($tariffGroupId);
            if ((int) ($data['volume_gb'] ?? 1) < $minimum) {
                return response()->json(['status' => 'error', 'message' => \App\Services\VolumeTrafficPolicy::message($minimum)], 422);
            }
        }

        $tariffGroup = DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->first();

        $accountSnapshot = [
            'account_plan_name'       => (string) ($tariffGroup->name ?? ''),
            'account_group_type'      => $isVolumeRequest ? 'volume' : 'time',
            'account_tariff_group_id' => (int) $tariffGroupId,
            'account_plan_key'        => (string) $lookupPlanKey,
        ];        

        $planKeyForPricing = (string) $data['plan_key'];
        $customTrafficGb   = null;

        if ($isVolumeRequest) {
            $customTrafficGb = max(1, (int)($data['volume_gb'] ?? 1));
            $quantity = $customTrafficGb * max(1, (int)$count);
        } else {
            $quantity = max(1, (int)$count);
        }

        $logger  = app(TransactionLogger::class);

        $created = [];
        $errors  = [];
        $trxId   = null;
        $charge  = null;

        // --- همه چیز داخل یک تراکنش واحد: اگر حتی ۱ یوزر هم ساخته نشود → rollback و هیچ کسر اعتباری رخ ندهد.
        DB::beginTransaction();
        try {
            // 1) کسر اعتبار + ایجاد لاگ PENDING
            $charge = $pricing->ensureBalanceAndCharge($panelUserId, $planKeyForPricing, $quantity, $tariffGroupId);
            if (empty($charge['ok'])) {
                if (($charge['reason'] ?? null) === 'INSUFFICIENT_CREDIT') {
                    DB::rollBack();
                    return response()->json(['status'=>'error','message'=>'اعتبار شما کافی نیست'], 422);
                }
                DB::rollBack();
                return response()->json(['status'=>'error','message'=>$charge['reason'] ?? 'Price error'], 422);
            }
            $trxId = $charge['transaction_id'] ?? null;
            if ($trxId) {
                DB::table('panel_transactions')->where('id', $trxId)->update([
                    'type'           => 'account_purchase',
                    'plan_key_after' => (string)$data['plan_key'],
                ]);
            }

            // 2) ساخت کاربران
            $createMethod = isset($data['custom_username']) ? 'createUsersWithUsername' : 'createUsers';
            $createArguments = [
                'panelCode' => $data['panel_code'],
                'v2PlanId' => (int) $pp->v2_plan_id,
                'count' => (int) $count,
                'planKey' => $lookupPlanKey,
                'noExpire' => $noExpire,
                'customTrafficGb' => $customTrafficGb,
                'accountSnapshot' => $accountSnapshot,
                'panelOwnerId' => $panelUserId,
            ];
            if (isset($data['custom_username'])) $createArguments['customUsername'] = $data['custom_username'];
            $result = $creator->{$createMethod}(...$createArguments);
            $created = $result['created'] ?? [];
            $errors  = $result['errors']  ?? [];

            $createdCount = is_array($created) ? count($created) : 0;
            if ($createdCount === 0) {
                // هیچ یوزری ساخته نشد → شکست نرم اما rollback کامل
                throw new \RuntimeException('no users created');
            }

            // اگر کمتر از مقدار درخواستی ساخته شد، اختلاف را در متای تراکنش می‌آوریم و بعد از Commit، برگشتِ مابه‌التفاوت می‌دهیم.
            // (Refund پس از commit انجام می‌شود تا race نداشته باشد.)
            $extraMeta = [
                'plan_key_after' => (string) $data['plan_key'],
                'unit_price'     => $charge['unit_price']  ?? null,
                'total_price'    => $charge['total_price'] ?? null,
                'panel_code'     => $data['panel_code'],
                'no_expire'      => $noExpire,
                'requested_qty'  => $quantity,
                'created_qty'    => $createdCount,
                'errors'         => $errors,
                'tariff_group_id' => $tariffGroupId,
                'tariff_group_name' => $charge['tariff_group_name'] ?? null,    
                'is_volume_partner' => $isVolumePartner,
                'volume_gb'         => $customTrafficGb,  
                'account_count' => $count,
                'total_volume_gb' => $quantity,                                          
            ];

            if ($trxId) {
                // reference_id اگر تنها یکی ساخته شده باشد
                $refId = ($createdCount === 1 && isset($created[0]['id'])) ? (int)$created[0]['id'] : null;
                $userIds = array_values(array_filter(array_map(function($it){
                    return isset($it['id']) ? (int)$it['id'] : null;
                }, $created)));
                $logger->finalize($trxId, [
                    'reference_type' => 'v2_user',
                    'reference_id'   => $refId,
                    'user_ids'       => $userIds,
                    'extra_meta'     => $extraMeta,
                ]);
            }

            DB::commit();

            // 3) مابه‌التفاوت قیمت در صورت ساخت کمتر (خارج از تراکنش اصلی برای سبک‌سازی مسیر)
            if ($trxId && $createdCount < $count) {
                $unit = (float) ($charge['unit_price'] ?? 0);

                if ($isVolumeRequest) {
                    $refund = max(0, $unit * $customTrafficGb * ($count - $createdCount));
                } else {
                    $refund = max(0, $unit * ($count - $createdCount));
                }
                if ($refund > 0) {
                    DB::table('panel_users')->where('id', $panelUserId)->increment('credit', $refund);
                    DB::table('panel_transactions')->where('id', $trxId)->update([
                        'amount'   => (float)($charge['total_price'] ?? 0) - $refund,
                        'quantity' => $isVolumeRequest ? ($createdCount * $customTrafficGb) : $createdCount,
                        'meta'     => json_encode(array_merge($extraMeta, ['partial_refund' => $refund]), JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            // اگر transaction id داریم، fail لاگ شود
            if (!empty($trxId)) {
                try { $logger->fail($trxId, 'user creation failed: '.$e->getMessage()); } catch (\Throwable $e2) {}
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json(['status' => 'error', 'message' => $e->getMessage(), 'errors' => $e->errors()], 422);
            }
            return response()->json(['status'=>'error','message'=>'ساخت کاربر ناموفق بود'], 500);
        }

        // ===== افزودن subscribe_url با تکیه بر token بازگشتی از سرویس =====
        $created = array_map(function ($item) {
            if (!is_array($item)) return $item;
            $token = $item['token'] ?? ($item['user']['token'] ?? null);
            if ($token) $item['subscribe_url'] = \App\Utils\Helper::getSubscribeUrl((string) $token);
            return $item;
        }, is_array($created) ? $created : []);

        // ===== اکسل =====
        $wantExcel = $wantExcel && !empty($created);
        if ($wantExcel) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Users');

            // هدر
            $sheet->setCellValue('A1', 'User');
            $sheet->setCellValue('B1', 'Link');
            $sheet->getStyle('A1:B1')->getFont()->setBold(true);
            $sheet->getColumnDimension('A')->setWidth(28);
            $sheet->getColumnDimension('B')->setWidth(66);

            // ردیف‌ها
            $row = 2;
            foreach ($created as $item) {
                $rawEmail    = is_array($item) ? ($item['email'] ?? '') : '';
                $displayUser = preg_replace('/\.com$/i', '', str_replace('@', '-', $rawEmail));
                $sheet->setCellValue("A{$row}", $displayUser);

                $link = is_array($item) ? ($item['subscribe_url'] ?? '') : '';
                $sheet->setCellValue("B{$row}", $link);
                if ($link) $sheet->getCell("B{$row}")->getHyperlink()->setUrl($link);

                $row++;
            }

            $writer = new Xlsx($spreadsheet);
            $filename = 'users_'.date('Ymd_His').'.xlsx';

            // برای تعداد بالا: ذخیره و دانلود مستقیم
            if ($count >= self::EXCEL_STREAM_THRESHOLD) {
                if (!Storage::disk('public')->exists('exports')) {
                    Storage::disk('public')->makeDirectory('exports');
                }
                $absPath = storage_path('app/public/exports/'.$filename);
                $writer->save($absPath);
                return response()->download($absPath, $filename, [
                    'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma'        => 'no-cache',
                    'X-Created-Count' => (string) count($created),
                    'X-Errors-Count'  => (string) count($errors),
                ])->deleteFileAfterSend(true);
            }

            // تعداد کم: استریم مستقیم
            if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { @ob_end_clean(); } }
            return response()->streamDownload(function () use ($writer) {
                header('X-Accel-Buffering: no');
                $writer->save('php://output');
            }, $filename, [
                'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma'        => 'no-cache',
                'X-Created-Count' => (string) count($created),
                'X-Errors-Count'  => (string) count($errors),
            ]);
        }

        // پاسخ پیش‌فرض JSON
        return response()->json([
            'status'        => 'success',
            'created'       => $created,
            'errors'        => $errors,
            'transactionId' => $trxId,
        ]);
    }

    public function plans(Request $request)
    {
        $me = $request->user();

        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $panelUser = DB::table('panel_users')
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($me->email))])
            ->first();

        if (!$panelUser) {
            return response()->json(['status' => 'error', 'message' => 'Panel user not found'], 404);
        }

        $partnerPlanType = $panelUser->partner_plan_type ?? 'time';
        $isVolumePartner = $partnerPlanType === 'volume';
        $isBothPartner = $partnerPlanType === 'both';

        $allowedGroupTypes = $isBothPartner
            ? ['time', 'volume']
            : [$isVolumePartner ? 'volume' : 'time'];

        $groupsQuery = DB::table('panel_tariff_groups')
            ->where('enable', 1)
            ->whereIn('group_type', $allowedGroupTypes);

        app(PartnerTariffAccessService::class)->applyVisibleGroups($groupsQuery, $panelUser, 'panel_tariff_groups');

        $groups = $groupsQuery
            ->orderByRaw("CASE WHEN group_type = 'time' THEN 0 WHEN group_type = 'volume' THEN 1 ELSE 2 END")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $planKeys = $isBothPartner
            ? ['volume_gig', 'test', '1m', '3m', '6m', '12m']
            : ($isVolumePartner ? ['volume_gig'] : ['test', '1m', '3m', '6m', '12m']);

        $groupIds = $groups->pluck('id')->values()->all();

        $plans = DB::table('panel_plan')
            ->whereIn('tariff_group_id', $groupIds)
            ->whereIn('plan_key', $planKeys)
            ->where('enable', 1)
            ->get()
            ->groupBy('tariff_group_id');

        $planGroups = $groups->map(function ($group) use ($plans, $isVolumePartner) {
            $rows = ($plans[$group->id] ?? collect())->keyBy('plan_key');

            if ((string) $group->group_type === 'volume') {
                $enabled = [
                    'volume_gig' => isset($rows['volume_gig']),
                    'test' => false,
                    '1m' => false,
                    '1m_noexp' => false,
                    '3m' => false,
                    '6m' => false,
                    '12m' => false,
                ];
            } else {
                $enabled = [];
                foreach (['test', '1m', '3m', '6m', '12m'] as $key) {
                    $enabled[$key] = isset($rows[$key]);
                }
                $enabled['1m_noexp'] = (bool) ($enabled['1m'] ?? false);
            }

            return [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'is_default' => (int) $group->is_default,
                'min_traffic_gb' => (string) $group->group_type === 'volume' ? (int) ($group->min_traffic_gb ?? 10) : 1,
                'group_type' => (string) $group->group_type,
                'plan_enabled' => $enabled,
            ];
        })->values();

        $defaultGroup = $planGroups->firstWhere('is_default', 1) ?: $planGroups->first();

        return response()->json([
            'status' => 'success',
            'plan_groups' => $planGroups,
            'plan_enabled' => $defaultGroup['plan_enabled'] ?? [],
        ]);
    }

    public function extend(Request $request)
    {
        $me = $request->user();

        $validated = $request->validate([
            'email'  => 'required|email',
            'period' => 'required|in:1m,3m,6m,12m',
            'tariff_group_id' => ['nullable','integer','exists:panel_tariff_groups,id'],
        ]);

        $email  = strtolower($validated['email']);
        $period = $validated['period'];
        $tariffGroupId = $this->resolveTariffGroupId((int) ($validated['tariff_group_id'] ?? 0), 'time');

        // امنیت tenant
        if ((int)($me->role ?? 2) !== 1) {
            $panelCode = $me->code ?? '';
            if (!$this->emailBelongsToPanelCode($email, $panelCode)) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }
        }

        $user = DB::table('v2_user')->where('email', $email)->first();
        if (!$user) return response()->json(['status' => 'error', 'message' => 'کاربر یافت نشد'], 404);

        $panelUser = DB::table('panel_users')->where('email', strtolower($me->email))->first();
        if (!$panelUser) return response()->json(['status' => 'error', 'message' => 'Panel user not found'], 404);

        if (!app(PartnerTariffAccessService::class)->canSeeTariffGroup($panelUser, (int)$tariffGroupId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'این طرح برای شما فعال نیست',
            ], 403);
        }

        /** @var PanelPricingService $pricing */
        $pricing = app(PanelPricingService::class);
        /** @var TransactionLogger $logger */
        $logger  = app(TransactionLogger::class);

        $trxId = null;

        try {
            DB::beginTransaction();

            DB::table('panel_users')->where('id', $panelUser->id)->lockForUpdate()->first();
            $user = DB::table('v2_user')->where('id', $user->id)->lockForUpdate()->first();
            if (!$user) throw new \RuntimeException('کاربر یافت نشد.');
            $start = $pricing->ensureBalanceAndCharge((int)$panelUser->id, $period, 1, $tariffGroupId, (int) $user->id);
            if (empty($start['ok'])) {
                DB::rollBack();
                return response()->json(['status'  => 'error','message' => $start['message'] ?? 'اعتبار شما کافی نیست '], 422);
            }
            $trxId = $start['transaction_id'] ?? null;
            if ($trxId) {
                DB::table('panel_transactions')->where('id', $trxId)->update(['type' => 'account_extend']);
            }

            $panelPlan = DB::table('panel_plan')
                ->where('tariff_group_id', $tariffGroupId)
                ->where('plan_key', $period)
                ->where('enable', 1)
                ->first();
            if (!$panelPlan || empty($panelPlan->v2_plan_id)) throw new \RuntimeException('Plan not found');
            $v2PlanId = $panelPlan->v2_plan_id;
            $v2Plan   = DB::table('v2_plan')->where('id', $v2PlanId)->first();

            $daysMap = ['1m' => 30, '3m' => 90, '6m' => 180, '12m' => 365];
            $addDays = $daysMap[$period];

            $nowTs       = time();
            $oldExpTs    = (int)($user->expired_at ?? 0);
            $hasTimeLeft = $oldExpTs > $nowTs;

            $usedBytes  = (int)($user->u ?? 0) + (int)($user->d ?? 0);
            $quotaBytes = (int)($user->transfer_enable ?? 0);
            $quotaExhausted = ($quotaBytes > 0) && ($usedBytes >= $quotaBytes);

            $beforePlanKey = null;
            if (!empty($user->plan_id)) {
                $beforePlanKey = DB::table('panel_plan')->where('v2_plan_id', $user->plan_id)->value('plan_key');
            }
            $currentPlanIs1m = ($beforePlanKey === '1m');

            //$shouldResetUsage = (!$hasTimeLeft) || ($hasTimeLeft && $quotaExhausted && $currentPlanIs1m);
            $shouldResetUsage = !$hasTimeLeft;

            $baseTs   = $shouldResetUsage ? $nowTs : $oldExpTs;
            $newExpTs = Carbon::createFromTimestamp($baseTs)->addDays($addDays)->timestamp;

            $update = [
                'expired_at' => $newExpTs,
                'updated_at' => $nowTs,
                'plan_id'    => $v2PlanId,
                'u'          => 0,
                'd'          => 0,
                'account_plan_name'       => (string) DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->value('name'),
                'account_group_type'      => 'time',
                'account_tariff_group_id' => (int) $tariffGroupId,
                'account_plan_key'        => $period,
            ];
            if ($v2Plan && isset($v2Plan->transfer_enable)) {
                $gb = (int)preg_replace('/\D+/', '', (string)$v2Plan->transfer_enable);
                $update['transfer_enable'] = $gb * 1024 * 1024 * 1024;
            }
            $update['device_limit'] = app(\App\Services\TariffConnectionPolicy::class)->resolve((int) $update['plan_id'], (int) $update['account_tariff_group_id'], $update['account_plan_key']);
            if ($v2Plan && isset($v2Plan->speed_limit))  $update['speed_limit']  = $v2Plan->speed_limit;
            if ($v2Plan && isset($v2Plan->group_id))     $update['group_id']     = $v2Plan->group_id;

            $update = app(\App\Services\SubscriptionOrderPolicy::class)->prepare($user, $update);
            $newExpTs = $update['expired_at'];
            DB::table('v2_user')->where('email', $email)->update($update);

            if ($trxId) {
                $logger->finalize($trxId, [
                    'reference_type' => 'v2_user',
                    'reference_id'   => $user->id ?? null,
                    'user_ids'       => [$user->id ?? null],
                    'extra_meta'     => [
                        'unit_price'      => $start['unit_price']  ?? null,
                        'expired_before'  => $oldExpTs,
                        'expired_after'   => $newExpTs,
                        'plan_key_after'  => $period,
                        'tariff_group_id' => $tariffGroupId,
                        'tariff_group_name' => $start['tariff_group_name'] ?? null,                        
                    ],
                ]);
            }

            DB::commit();
            return response()->json(['status' => 'ok','expired_at' => $newExpTs]);
        } catch (\Throwable $e) {
            DB::rollBack();
            if (!empty($trxId)) {
                try { $logger->fail($trxId, ['reason' => 'extend_failed','error'  => $e->getMessage(),'email'  => $email,'plan'   => $period]); } catch (\Throwable $e2) {}
            }
            return response()->json(['status' => 'error', 'message' => $e instanceof \DomainException ? $e->getMessage() : 'تمدید انجام نشد'], $e instanceof \DomainException ? 422 : 500);
        }
    }

    public function topup(Request $request)
    {
        $me = $request->user();

        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
            'gb' => ['required', 'integer', 'min:1'],
            'tariff_group_id' => ['nullable', 'integer', 'exists:panel_tariff_groups,id'],
        ]);

        $panelUser = DB::table('panel_users')->where('id', $me->id)->first();

        if (!$panelUser && !empty($me->email)) {
            $panelUser = DB::table('panel_users')->where('email', strtolower($me->email))->first();
        }

        if (!$panelUser) {
            return response()->json(['status' => 'error', 'message' => 'Panel user not found'], 404);
        }

        $result = app(PanelAccountActionService::class)->topupTrafficForPanelUser(
            $panelUser,
            strtolower(trim($data['email'])),
            (int) $data['gb'],
            isset($data['tariff_group_id']) ? (int) $data['tariff_group_id'] : null
        );

        if (empty($result['ok'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['message'] ?? 'خرید ترافیک انجام نشد',
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'd_after' => $result['d_after'] ?? null,
            'expired_at' => $result['expired_at'] ?? null,
            'transaction_id' => $result['transaction_id'] ?? null,
        ]);
    }

    private function resolveTariffGroupId(int $tariffGroupId = 0, string $groupType = 'time'): int
    {
        $groupType = $groupType === 'volume' ? 'volume' : 'time';

        if ($tariffGroupId > 0) {
            $exists = DB::table('panel_tariff_groups')
                ->where('id', $tariffGroupId)
                ->where('group_type', $groupType)
                ->where('enable', 1)
                ->exists();

            if ($exists) {
                return $tariffGroupId;
            }
        }

        $defaultId = DB::table('panel_tariff_groups')
            ->where('group_type', $groupType)
            ->where('is_default', 1)
            ->where('enable', 1)
            ->value('id');

        if ($defaultId) {
            return (int) $defaultId;
        }

        return (int) DB::table('panel_tariff_groups')
            ->where('group_type', $groupType)
            ->where('enable', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');
    }

}



