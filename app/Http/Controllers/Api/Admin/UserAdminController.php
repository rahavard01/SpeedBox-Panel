<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Models\PanelPlan;
use App\Models\V2User;
use App\Services\V2UserCreator;
use App\Services\TransactionLogger;

/**
 * Admin-side: بدون کسر اعتبار.
 * - تراکنش بلندمدت برای ساخت گروهی حذف شده تا تایم‌اوت به حداقل برسد.
 * - اگر حتی یک یوزر هم ساخته نشود، 500 برگردانده می‌شود تا فرانت «توست» منطقی نشان دهد.
 */
class UserAdminController extends Controller
{
    private const EXCEL_STREAM_THRESHOLD = 50;

    public function index(Request $request)
    {
        $plans = DB::table('panel_plan as pp')
            ->join('panel_tariff_groups as tg', 'tg.id', '=', 'pp.tariff_group_id')
            ->select(
                'pp.v2_plan_id',
                DB::raw('MIN(tg.name) as account_plan_name'),
                DB::raw("MAX(CASE WHEN tg.group_type = 'volume' OR pp.plan_key = 'volume_gig' THEN 1 ELSE 0 END) as account_is_volume"),
                DB::raw('MIN(pp.tariff_group_id) as account_tariff_group_id')
            )
            ->whereNotNull('pp.v2_plan_id')
            ->groupBy('pp.v2_plan_id');

        $q = V2User::query()
            ->leftJoinSub($plans, 'pp', function ($join) {
                $join->on('v2_user.plan_id', '=', 'pp.v2_plan_id');
            })
            ->select(
                'v2_user.*',
                DB::raw('COALESCE(v2_user.account_plan_name, pp.account_plan_name) as account_plan_name'),
                DB::raw("CASE WHEN v2_user.account_group_type = 'volume' OR pp.account_is_volume = 1 THEN 1 ELSE 0 END as account_is_volume"),
                DB::raw('COALESCE(v2_user.account_tariff_group_id, pp.account_tariff_group_id) as account_tariff_group_id'),
                DB::raw("COALESCE(v2_user.account_group_type, CASE WHEN pp.account_is_volume = 1 THEN 'volume' ELSE 'time' END) as account_group_type")
            )
            ->orderByDesc('v2_user.id');

        $users = $q->get();
        $counts = app(\App\Services\AccountOnlineStatus::class)->counts($users->pluck('id')->all());
        foreach ($users as $user) $user->online_count = $counts[$user->id] ?? 0;
        return response()->json(['data' => $users]);
    }

    public function trafficRecords(Request $request)
    {
        $data = $request->validate([
            "email" => ["required", "email"],
            "page" => ["nullable", "integer", "min:1"],
            "per_page" => ["nullable", "integer", "min:1", "max:100"],
        ]);

        $perPage = (int) ($data["per_page"] ?? 20);

        $base = DB::table("v2_stat_user as s")
            ->join("v2_user as u", "u.id", "=", "s.user_id")
            ->where("u.email", $data["email"])
            ->selectRaw("
                DATE(FROM_UNIXTIME(s.record_at + 12600)) as day,
                SUM(s.u) as upload,
                SUM(s.d) as download,
                SUM(s.u + s.d) as total,
                MAX(s.server_rate) as server_rate
            ")
            ->groupBy("day");

        $rows = DB::query()
            ->fromSub($base, "x")
            ->orderByDesc("day")
            ->paginate($perPage);

        return response()->json([
            "status" => "ok",
            "data" => $rows->items(),
            "meta" => [
                "current_page" => $rows->currentPage(),
                "last_page" => $rows->lastPage(),
                "per_page" => $rows->perPage(),
                "total" => $rows->total(),
            ],
        ]);
    }

    public function transactionRecords(Request $request)
    {
        $data = $request->validate([
            "email" => ["required", "email"],
            "page" => ["nullable", "integer", "min:1"],
            "per_page" => ["nullable", "integer", "min:1", "max:100"],
        ]);

        $perPage = (int) ($data["per_page"] ?? 20);

        $user = DB::table("v2_user")
            ->where("email", $data["email"])
            ->first();

        if (!$user) {
            return response()->json([
                "status" => "error",
                "message" => "کاربر یافت نشد",
            ], 404);
        }

        $userId = (int) $user->id;

        $rows = DB::table("panel_transactions as t")
            ->where(function ($q) use ($userId) {
                $q->where(function ($qq) use ($userId) {
                    $qq->where("t.reference_type", "v2_user")
                        ->where("t.reference_id", $userId);
                })
                ->orWhereRaw("JSON_CONTAINS(t.meta, ?, '$.user_ids')", [json_encode($userId)]);
            })
            ->select([
                "t.id",
                "t.created_at",
                "t.type",
                "t.direction",
                "t.amount",
                "t.status",
                "t.plan_key_after",
                "t.performed_by_role",
                "t.meta",
            ])
            ->orderByDesc("t.created_at")
            ->orderByDesc("t.id")
            ->paginate($perPage);

        $planMap = [
            "test"     => "تست",
            "1m"       => "یک ماهه",
            "1m_noexp" => "یک ماهه (بدون انقضا)",
            "3m"       => "سه ماهه",
            "6m"       => "شش ماهه",
            "12m"      => "یک‌ساله",
        ];

        $items = collect($rows->items())->map(function ($r) use ($planMap) {
            $meta = [];

            if (!empty($r->meta)) {
                try {
                    $decoded = is_string($r->meta) ? json_decode($r->meta, true) : $r->meta;
                    $meta = is_array($decoded) ? $decoded : [];
                } catch (\Throwable $e) {
                    $meta = [];
                }
            }

            $base = (int) ($r->amount ?? 0);
            $dir = strtolower((string) ($r->direction ?? ""));
            $signed = in_array($dir, ["debit", "out", "minus"], true) ? -abs($base) : abs($base);

            $txnType = strtolower((string) ($r->type ?? ""));

            $showPlan = in_array($txnType, [
                "account_purchase", "buy_account", "purchase",
                "account_extend", "extend_account", "renew", "renewal",
            ], true);

            $planKeyRaw = $r->plan_key_after
                ?? ($meta["plan_key_after"] ?? ($meta["plan_key"] ?? null));

            $planKey = ($showPlan && $planKeyRaw) ? (string) $planKeyRaw : null;

            if (is_string($planKey) && preg_match('/^\d+_(.+)$/', $planKey, $m)) {
                $planKey = $m[1];
            }

            $planLabel = $planKey ? ($planMap[$planKey] ?? $planKey) : null;

            $trafficGb = null;

            if (!empty($meta["quantity"])) {
                if (is_string($meta["quantity"]) && preg_match('/(\d+)\s*GB/i', $meta["quantity"], $m)) {
                    $trafficGb = (int) $m[1];
                } elseif (is_numeric($meta["quantity"])) {
                    $trafficGb = (int) $meta["quantity"];
                }
            } elseif (isset($meta["gb"])) {
                $trafficGb = (int) $meta["gb"];
            } elseif (isset($meta["volume_gb"])) {
                $trafficGb = (int) $meta["volume_gb"];
            }

            return [
                "id"                => (int) $r->id,
                "created_at"        => $r->created_at,
                "type"              => $r->type ?? "-",
                "amount"            => $signed,
                "status"            => $r->status ?? "unknown",
                "performed_by_role" => \App\Services\TransactionOperator::displayRole($r->performed_by_role ?? null, $meta),

                // دقیقاً برای ساخت متن مثل صفحه صورتحساب
                "plan_key"          => $planKey,
                "plan_label"        => $planLabel,
                "tariff_group_id"   => $meta["tariff_group_id"] ?? null,
                "tariff_group_name" => $meta["tariff_group_name"] ?? null,
                "traffic_gb"        => $trafficGb,

                // برای تشخیص شارژ کریپتو/کارت مثل صورتحساب
                "crypto_asset"      => $meta["crypto_asset"] ?? ($meta["asset"] ?? null),
                "crypto_network"    => $meta["crypto_network"] ?? ($meta["network"] ?? null),
                "crypto_amount"     => $meta["crypto_amount"] ?? null,
                "crypto_tx_hash"    => $meta["crypto_tx_hash"] ?? ($meta["tx_hash"] ?? null),
                "payment_method"    => $meta["payment_method"] ?? ($meta["method"] ?? null),
            ];
        })->values();

        return response()->json([
            "status" => "ok",
            "data" => $items,
            "meta" => [
                "current_page" => $rows->currentPage(),
                "last_page" => $rows->lastPage(),
                "per_page" => $rows->perPage(),
                "total" => $rows->total(),
            ],
        ]);
    }

    public function create(Request $request, V2UserCreator $creator)
    {
        @ini_set('max_execution_time', '900');
        @set_time_limit(900);
        @ini_set('memory_limit', '512M');
        ignore_user_abort(true);

        $data = $request->validate([
            'custom_username' => ['nullable', 'string', 'max:32'],
            'panel_code'     => ['required','string'],
            'plan_key'       => ['required','string'],
            'count'          => ['nullable','integer','min:1'],
            'no_expire'      => ['sometimes','boolean'],
            'volume_gb'      => ['nullable','integer','min:1'],
            'download_excel' => ['sometimes','boolean'],
            'tariff_group_id' => ['nullable','integer','exists:panel_tariff_groups,id'],
        ]);

        $count = (int)($data['count'] ?? 1);
        $isVolumeCreate = ($data['plan_key'] === 'volume_gig');

        $tariffGroupId = $this->resolveTariffGroupId(
            (int) ($data['tariff_group_id'] ?? 0),
            $isVolumeCreate ? 'volume' : 'time'
        );
        $lookupPlanKey = ($data['plan_key'] === '1m_noexp') ? '1m' : $data['plan_key'];
        $noExpire = $request->boolean('no_expire') || $data['plan_key'] === '1m_noexp' || $isVolumeCreate;
        $volumeGb = $isVolumeCreate ? max(1, (int)($data['volume_gb'] ?? 1)) : null;
        $wantExcel = $request->boolean('download_excel') && $count > 1;

        $pp = PanelPlan::where('enable', 1)
            ->where('tariff_group_id', $tariffGroupId)
            ->where('plan_key', $lookupPlanKey)
            ->first();

        if (!$pp || !$pp->v2_plan_id) {
            return response()->json(['status'=>'error','message'=>'پلن انتخابی با پلن ثبت شده مطابقت ندارد'], 422);
        }

        if ($isVolumeCreate) {
            $minimum = \App\Services\VolumeTrafficPolicy::minimum($tariffGroupId);
            if ($volumeGb < $minimum) {
                return response()->json(['status' => 'error', 'message' => \App\Services\VolumeTrafficPolicy::message($minimum)], 422);
            }
        }

        $tariffGroup = DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->first();

        $accountSnapshot = [
            'account_plan_name'       => (string) ($tariffGroup->name ?? ''),
            'account_group_type'      => $isVolumeCreate ? 'volume' : 'time',
            'account_tariff_group_id' => (int) $tariffGroupId,
            'account_plan_key'        => (string) $lookupPlanKey,
        ];

        // ساخت بدون تراکنش بلندمدت (تاخیر کمتر، احتمال تایم‌اوت کمتر)
        $createMethod = isset($data['custom_username']) ? 'createUsersWithUsername' : 'createUsers';
        $createArguments = [
            'panelCode' => $data['panel_code'],
            'v2PlanId' => (int) $pp->v2_plan_id,
            'count' => (int) $count,
            'planKey' => $lookupPlanKey,
            'noExpire' => $noExpire,
            'customTrafficGb' => $volumeGb,
            'accountSnapshot' => $accountSnapshot,
        ];
        if (isset($data['custom_username'])) $createArguments['customUsername'] = $data['custom_username'];
        $result = $creator->{$createMethod}(...$createArguments);
        $created = $result['created'] ?? [];
        $errors  = $result['errors']  ?? [];

        if (count($created) === 0) {
            return response()->json(['status'=>'error','message'=>'ساخت کاربر ناموفق بود'], 500);
        }

        $created = array_map(function ($item) {
            if (!is_array($item)) return $item;
            $token = $item['token'] ?? ($item['user']['token'] ?? null);
            if ($token) $item['subscribe_url'] = \App\Utils\Helper::getSubscribeUrl((string) $token);
            return $item;
        }, is_array($created) ? $created : []);

        // اکسل
        if ($wantExcel) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Users');

            $sheet->setCellValue('A1', 'User');
            $sheet->setCellValue('B1', 'Link');
            $sheet->getStyle('A1:B1')->getFont()->setBold(true);
            $sheet->getColumnDimension('A')->setWidth(28);
            $sheet->getColumnDimension('B')->setWidth(66);

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

            if ($count >= self::EXCEL_STREAM_THRESHOLD) {
                if (!Storage::disk('public')->exists('exports')) Storage::disk('public')->makeDirectory('exports');
                $absPath = storage_path('app/public/exports/'.$filename);
                $writer->save($absPath);
                return response()->download($absPath, $filename, [
                    'Content-Type'    => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Cache-Control'   => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma'          => 'no-cache',
                    'X-Created-Count' => (string) count($created),
                    'X-Errors-Count'  => (string) count($errors),
                ])->deleteFileAfterSend(true);
            }

            if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { @ob_end_clean(); } }
            return response()->streamDownload(function () use ($writer) {
                header('X-Accel-Buffering: no');
                $writer->save('php://output');
            }, $filename, [
                'Content-Type'    => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control'   => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma'          => 'no-cache',
                'X-Created-Count' => (string) count($created),
                'X-Errors-Count'  => (string) count($errors),
            ]);
        }

        // لاگ ادمین (اختیاری)
        try {
            $me = $request->user();
            $panelUserId = (int) DB::table('panel_users')->where('email', strtolower((string)($me->email ?? '')))->value('id');
            $creditNow = (float) DB::table('panel_users')->where('id', $panelUserId)->value('credit');
            $userIds = array_values(array_filter(array_map(function($it){
                return isset($it['id']) ? (int)$it['id'] : null;
            }, $created)));
            /** @var \App\Services\TransactionLogger $logger */
            $logger = app(TransactionLogger::class);
            $trxId = $logger->start([
                'panel_user_id'   => $panelUserId ?: 0,
                'type'            => 'account_purchase',
                'direction'       => 'debit',
                'amount'          => 0,
                'balance_before'  => $creditNow,
                'balance_after'   => $creditNow,
                'quantity'        => $isVolumeCreate ? max(1, (int)($volumeGb ?? 1)) : max(count($userIds), 1),
                'plan_key_after'  => (string) $data['plan_key'],
                'performed_by_id' => (int)($me->id ?? 0),
                'performed_by_role' => (int)($me->role ?? 1),
                'meta'            => [
                    'source' => 'admin_panel',
                    'panel_code' => $data['panel_code'] ?? null,
                    'plan_key_after' => (string) $data['plan_key'],
                    'tariff_group_id' => $tariffGroupId,
                    'tariff_group_name' => DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->value('name'),
                    'is_volume_partner' => $isVolumeCreate,
                    'volume_gb' => $isVolumeCreate ? (int)($volumeGb ?? 1) : null,
                    'account_count' => $count,
                    'total_volume_gb' => $isVolumeCreate ? ((int)($volumeGb ?? 1) * $count) : null,
                ],
            ]);
            if ($trxId) {
                $logger->finalize($trxId, [
                    'reference_type' => 'v2_user',
                    'reference_id'   => (count($userIds) === 1) ? $userIds[0] : null,
                    'user_ids'       => $userIds,
                    'performed_by_id' => (int)($me->id ?? 0),
                    'performed_by_role' => (int)($me->role ?? 1),
                    'extra_meta'     => [
                        'source' => 'admin_panel',
                        'plan_key_after' => (string) $data['plan_key'],
                        'tariff_group_id' => $tariffGroupId,
                        'tariff_group_name' => DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->value('name'),
                        'is_volume_partner' => $isVolumeCreate,
                        'volume_gb' => $isVolumeCreate ? (int)($volumeGb ?? 1) : null,
                        'account_count' => $count,
                        'total_volume_gb' => $isVolumeCreate ? ((int)($volumeGb ?? 1) * $count) : null,
                        'errors' => $errors,
                    ],
                ]);
            }
        } catch (\Throwable $e) {}

        return response()->json([
            'status'        => 'success',
            'created'       => $created,
            'errors'        => $errors,
            'transactionId' => null,
        ]);
    }

    public function extend(Request $request)
    {
        $validated = $request->validate([
            'email'  => 'required|email',
            'period' => 'required|in:1m,3m,6m,12m',
            'tariff_group_id' => ['nullable','integer','exists:panel_tariff_groups,id'],
        ]);

        $email  = strtolower($validated['email']);
        $period = $validated['period'];
        $tariffGroupId = $this->resolveTariffGroupId((int) ($validated['tariff_group_id'] ?? 0));

        $user = DB::table('v2_user')->where('email', $email)->first();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'کاربر یافت نشد'], 404);
        }

        try {
            DB::beginTransaction();
            $user = DB::table('v2_user')->where('id', $user->id)->lockForUpdate()->first();
            if (!$user) throw new \RuntimeException('کاربر یافت نشد.');
            $panelPlan = DB::table('panel_plan')
                ->where('tariff_group_id', $tariffGroupId)
                ->where('plan_key', $period)
                ->where('enable', 1)
                ->first();
            if (!$panelPlan || empty($panelPlan->v2_plan_id)) {
                throw new \RuntimeException('Plan not found');
            }
            $v2PlanId = $panelPlan->v2_plan_id;
            $v2Plan   = DB::table('v2_plan')->where('id', $v2PlanId)->first();

            $daysMap = ['1m' => 30, '3m' => 90, '6m' => 180, '12m' => 365];
            $addDays = $daysMap[$period];

            $oldExpTs    = (int)($user->expired_at ?? 0);
            $nowTs       = time();
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

            $baseTs   = app(\App\Services\SubscriptionOrderPolicy::class)->renewalBaseTimestamp($user, $nowTs);
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
            app(\App\Services\TrafficResetHistory::class)->renewal($user, $update);

            // لاگ ادمین (اختیاری)
            try {
                $me = $request->user();
                $panelUserId = (int) DB::table('panel_users')->where('email', strtolower((string)($me->email ?? '')))->value('id');
                $creditNow   = (float) DB::table('panel_users')->where('id', $panelUserId)->value('credit');

                /** @var \App\Services\TransactionLogger $logger */
                $logger = app(TransactionLogger::class);
                $trxId  = $logger->start([
                    'panel_user_id'   => $panelUserId ?: 0,
                    'type'            => 'account_extend',
                    'direction'       => 'debit',
                    'amount'          => 0,
                    'balance_before'  => $creditNow,
                    'balance_after'   => $creditNow,
                    'quantity'        => 1,
                    'meta'            => ['panel_code' => $me->code ?? null],
                ]);
                if ($trxId) {
                    $logger->finalize($trxId, [
                        'reference_type'  => 'v2_user',
                        'reference_id'    => (int)$user->id,
                        'user_ids'        => [(int)$user->id],
                        'extra_meta'      => [
                            'plan_key_before' => $beforePlanKey ?? null,
                            'plan_key_after'  => $period,
                            'tariff_group_id' => $tariffGroupId,
                            'tariff_group_name' => DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->value('name'),
                        ],
                    ]);
                }
            } catch (\Throwable $e) {}

            DB::commit();
            return response()->json([
                'status'     => 'ok',
                'expired_at' => $newExpTs,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e instanceof \DomainException ? $e->getMessage() : 'تمدید انجام نشد'], $e instanceof \DomainException ? 422 : 500);
        }
    }

    public function topup(Request $request)
    {
        $me = $request->user();

        $email = strtolower(trim((string)$request->input('email', '')));
        $gb    = (int) $request->input('gb', 0);
        $tariffGroupId = (int) $request->input('tariff_group_id', 0);

        if (!$email || $gb < 1) {
            return response()->json(['status'=>'error','message'=>'ورودی نامعتبر است'], 422);
        }

        $user = DB::table('v2_user')->where('email', $email)->first();

        if (!$user) {
            return response()->json(['status'=>'error','message'=>'کاربر یافت نشد'], 404);
        }

        $bytes = $gb * 1024 * 1024 * 1024;

        return DB::transaction(function () use ($request, $me, $user, $email, $gb, $bytes, $tariffGroupId) {
            $dBefore = (int)($user->d ?? 0);
            $dAfter  = $dBefore - $bytes;

            $nowTs = time();

            $isVolumeAccount = ((string)($user->account_group_type ?? '') === 'volume');

            if (!$isVolumeAccount && !empty($user->plan_id)) {
                $isVolumeAccount = DB::table('panel_plan')
                    ->where('plan_key', 'volume_gig')
                    ->where('v2_plan_id', (int) $user->plan_id)
                    ->exists();
            }

            $accountTariffGroupId = (int)($user->account_tariff_group_id ?? 0);

            $resolvedTariffGroupId = $this->resolveTariffGroupId(
                $tariffGroupId > 0 ? $tariffGroupId : $accountTariffGroupId,
                $isVolumeAccount ? 'volume' : 'time'
            );

            $updateData = [
                'd'          => $dAfter,
                'updated_at' => $nowTs,
            ];

            if ($isVolumeAccount) {
                $minimum = \App\Services\VolumeTrafficPolicy::minimum($resolvedTariffGroupId);
                if ($gb < $minimum) {
                    return response()->json(['status' => 'error', 'message' => \App\Services\VolumeTrafficPolicy::message($minimum)], 422);
                }

                $isExpiredVolumeAccount = !\App\Services\SubscriptionValidity::unexpired($user->expired_at ?? null, $nowTs);

                if ($isExpiredVolumeAccount) {
                    // دوره قبلی تمام شده؛ مانده ترافیک قبلی نباید منتقل شود.
                    $dAfter = 0;

                    $updateData['u'] = 0;
                    $updateData['d'] = 0;
                    $updateData['transfer_enable'] = $bytes;
                }

                $updateData['expired_at'] = $nowTs + (30 * 86400);

                $panelPlan = DB::table('panel_plan')
                    ->where('tariff_group_id', $resolvedTariffGroupId)
                    ->where('plan_key', 'volume_gig')
                    ->where('enable', 1)
                    ->first();

                if (!$panelPlan || empty($panelPlan->v2_plan_id)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'پلن حجمی انتخاب‌شده معتبر نیست',
                    ], 422);
                }

                $v2Plan = DB::table('v2_plan')
                    ->where('id', $panelPlan->v2_plan_id)
                    ->first();

                $updateData['plan_id'] = (int) $panelPlan->v2_plan_id;

                $tariffGroup = DB::table('panel_tariff_groups')->where('id', $resolvedTariffGroupId)->first();

                $updateData['account_plan_name'] = (string) ($tariffGroup->name ?? '');
                $updateData['account_group_type'] = 'volume';
                $updateData['account_tariff_group_id'] = (int) $resolvedTariffGroupId;
                $updateData['account_plan_key'] = 'volume_gig';

                $updateData['device_limit'] = app(\App\Services\TariffConnectionPolicy::class)->resolve((int) $updateData['plan_id'], (int) $updateData['account_tariff_group_id'], $updateData['account_plan_key']);

                if ($v2Plan && isset($v2Plan->speed_limit)) {
                    $updateData['speed_limit'] = $v2Plan->speed_limit;
                }

                if ($v2Plan && isset($v2Plan->group_id)) {
                    $updateData['group_id'] = $v2Plan->group_id;
                }
            }

            DB::table('v2_user')->where('id', $user->id)->update($updateData);

            try {
                $panelUserId = 0;

                if (!empty($me->id)) {
                    $panelUserId = (int) DB::table('panel_users')->where('id', (int)$me->id)->value('id');
                }

                if (!$panelUserId && !empty($me->email)) {
                    $panelUserId = (int) DB::table('panel_users')
                        ->where('email', strtolower((string)$me->email))
                        ->value('id');
                }

                $creditNow = (float) DB::table('panel_users')->where('id', $panelUserId)->value('credit');

                $logger = app(TransactionLogger::class);

                $trxId = $logger->start([
                    'panel_user_id'   => $panelUserId ?: 0,
                    'type'            => 'traffic_purchase',
                    'direction'       => 'debit',
                    'amount'          => 0,
                    'balance_before'  => $creditNow,
                    'balance_after'   => $creditNow,
                    'quantity'        => $gb,
                    'plan_key_after'  => $isVolumeAccount ? 'volume_gig' : 'gig',
                    'performed_by_id' => (int)($me->id ?? 0),
                    'performed_by_role' => (int)($me->role ?? 1),
                    'meta'            => [
                        'source' => 'admin_panel',
                        'quantity' => "{$gb}GB",
                        'gb' => $gb,
                        'plan_key_after' => $isVolumeAccount ? 'volume_gig' : 'gig',
                        'tariff_group_id' => $resolvedTariffGroupId,
                        'tariff_group_name' => DB::table('panel_tariff_groups')
                            ->where('id', $resolvedTariffGroupId)
                            ->value('name'),
                        'is_volume_mode' => $isVolumeAccount,
                    ],
                ]);

                if ($trxId) {
                    $logger->finalize($trxId, [
                        'reference_type' => 'v2_user',
                        'reference_id'   => (int)$user->id,
                        'user_ids'       => [(int)$user->id],
                        'performed_by_id' => (int)($me->id ?? 0),
                        'performed_by_role' => (int)($me->role ?? 1),
                        'extra_meta'     => [
                            'source' => 'admin_panel',
                            'quantity' => "{$gb}GB",
                            'gb' => $gb,
                            'plan_key_after' => $isVolumeAccount ? 'volume_gig' : 'gig',
                            'tariff_group_id' => $resolvedTariffGroupId,
                            'tariff_group_name' => DB::table('panel_tariff_groups')
                                ->where('id', $resolvedTariffGroupId)
                                ->value('name'),
                            'is_volume_mode' => $isVolumeAccount,
                        ],
                    ]);
                }
            } catch (\Throwable $e) {}

            return response()->json([
                'status'     => 'ok',
                'd_after'    => $dAfter,
                'expired_at' => $updateData['expired_at'] ?? ($user->expired_at ?? null),
            ], 200);
        });
    }

    private function resolveTariffGroupId(int $tariffGroupId = 0, string $groupType = 'time'): int
    {
        if ($tariffGroupId > 0) {
            $exists = DB::table('panel_tariff_groups')
                ->where('id', $tariffGroupId)
                ->where('enable', 1)
                ->where('group_type', $groupType)
                ->exists();

            if ($exists) {
                return $tariffGroupId;
            }
        }

        $defaultId = DB::table('panel_tariff_groups')
            ->where('is_default', 1)
            ->where('enable', 1)
            ->where('group_type', $groupType)
            ->value('id');

        if ($defaultId) {
            return (int) $defaultId;
        }

        return (int) DB::table('panel_tariff_groups')
            ->where('enable', 1)
            ->where('group_type', $groupType)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');
    }
}


