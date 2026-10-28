<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\V2User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\PanelAnnouncement;


class PanelController extends Controller
{
    private function emailBelongsToPanelCode(string $email, string $panelCode): bool
    {
        return \App\Services\PanelAccountOwnership::matches($email, $panelCode);
    }

    public function getProfile(Request $request)
    {
        try {
            $user = $request->user();
            $uid = $user->id ?? null;

            if (!$user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthorized',
                ], 401);
            }

            $notices = app(\App\Services\PartnerNotificationState::class)->snapshot((int) $uid);

            return response()->json([
                'status' => 'ok',
                'user'   => [
                    'id'                => $user->id,
                    'name'              => $user->name,
                    'email'             => $user->email,
                    'code'              => $user->code ?? null,
                    'partner_plan_type' => $user->partner_plan_type ?? 'time',
                ],
                'pending_counts' => [
                    'announcements' => $notices['announcement_count'],
                    'tickets' => $notices['count'],
                ],
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('api/user/panel failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Server error',
            ], 500);
        }
    }

    public function getWallet(Request $request)
    {
        $user = $request->user(); // کاربر احراز هویت‌شده
        return response()->json([
            'status' => 'success',
            'credit' => (int)($user->credit ?? 0), // اگه ستون اسمش چیز دیگه‌ایه همینجا عوضش کن
        ]);
    }

    public function listUsers(Request $request)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $code = trim((string) ($me->code ?? '')); // همون کدی که بالای پنل توی دایره نشون می‌دیم
        if ($code === '') {
            return response()->json(['status' => 'ok', 'data' => []], 200);
        }

        // اگر ستون created_at دارید، همین را نگه دارید. اگر ندارید، موقتاً با id سورت کنید.
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

        $rows = V2User::query()
            ->leftJoinSub($plans, 'pp', function ($join) {
                $join->on('v2_user.plan_id', '=', 'pp.v2_plan_id');
            })
            ->where(function ($q) use ($code) {
                $q->whereRaw('LOWER(SUBSTRING_INDEX(v2_user.email, "@", 1)) = ?', [strtolower($code)])
                ->orWhereRaw('LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(v2_user.email, "@", -1), ".", 1)) = ?', [strtolower($code)]);
            })
            ->select(
                'v2_user.*',
                DB::raw('COALESCE(v2_user.account_plan_name, pp.account_plan_name) as account_plan_name'),
                DB::raw("CASE WHEN v2_user.account_group_type = 'volume' OR pp.account_is_volume = 1 THEN 1 ELSE 0 END as account_is_volume"),
                DB::raw('COALESCE(v2_user.account_tariff_group_id, pp.account_tariff_group_id) as account_tariff_group_id'),
                DB::raw("COALESCE(v2_user.account_group_type, CASE WHEN pp.account_is_volume = 1 THEN 'volume' ELSE 'time' END) as account_group_type")
            )
            ->orderBy('v2_user.created_at', 'asc')
            ->get();

        return response()->json([
            'status' => 'ok',
            'data'   => $rows,
        ], 200);
    }

    public function trafficRecords(Request $request)
    {
        $me = $request->user();

        $data = $request->validate([
            "email" => ["required", "email"],
            "page" => ["nullable", "integer", "min:1"],
            "per_page" => ["nullable", "integer", "min:1", "max:100"],
        ]);

        if ((int)($me->role ?? 2) !== 1) {
            $code = trim((string)($me->code ?? ""));
            if (!$this->emailBelongsToPanelCode($data["email"], $code)) {
                return response()->json(["status" => "error", "message" => "Forbidden"], 403);
            }
        }

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
        $me = $request->user();

        if (!$me) {
            return response()->json(["status" => "error", "message" => "Unauthorized"], 401);
        }

        $data = $request->validate([
            "email" => ["required", "email"],
            "page" => ["nullable", "integer", "min:1"],
            "per_page" => ["nullable", "integer", "min:1", "max:100"],
        ]);

        if ((int)($me->role ?? 2) !== 1) {
            $code = trim((string)($me->code ?? ""));

            if (!$this->emailBelongsToPanelCode($data["email"], $code)) {
                return response()->json(["status" => "error", "message" => "Forbidden"], 403);
            }
        }

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

                "plan_key"          => $planKey,
                "plan_label"        => $planLabel,
                "tariff_group_id"   => $meta["tariff_group_id"] ?? null,
                "tariff_group_name" => $meta["tariff_group_name"] ?? null,
                "traffic_gb"        => $trafficGb,

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

    public function findByToken(Request $request)
    {
        $me = $request->user();

        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $token = trim((string)$request->query('token', ''));

        if ($token === '') {
            return response()->json(['status' => 'ok', 'data' => []]);
        }

        $user = DB::table('v2_user')->where('token', $token)->first();

        if (!$user) {
            return response()->json(['status' => 'ok', 'data' => []]);
        }

        if ((int)($me->role ?? 2) !== 1) {
            $code = trim((string)($me->code ?? ''));

            if (!$this->emailBelongsToPanelCode((string)$user->email, $code)) {
                return response()->json(['status' => 'ok', 'data' => []]);
            }
        }

        return response()->json(['status' => 'ok', 'data' => [$user]]);
    }

    public function findByEmail(Request $request)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $email = trim((string) $request->query('email', ''));
        if ($email === '') {
            return response()->json(['status' => 'ok', 'data' => []], 200);
        }

        // دسترسی نماینده فقط به اکانت‌های متعلق به کد خودش است.
        if ((int)($me->role ?? 2) !== 1) {
            $code = $me->code ?? '';
            if (!$this->emailBelongsToPanelCode($email, $code)) {
                // دسترسی ندارد → خروجی خالی (مثل قبل)
                return response()->json(['status' => 'ok', 'data' => []], 200);
            }
        }

        $u = \DB::table('v2_user')
            ->where('email', $email)
            ->select('email', 'token')
            ->first();

        if (!$u || empty($u->token)) {
            return response()->json(['status' => 'ok', 'data' => []], 200);
        }

        $u->subscribe_url = \App\Utils\Helper::getSubscribeUrl((string) $u->token);

        return response()->json(['status' => 'ok', 'data' => [$u]], 200);
    }


    public function updateBan(Request $request)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'email'  => 'required|email',
            'banned' => 'required|in:0,1',
        ]);

        // دسترسی نماینده فقط به اکانت‌های متعلق به کد خودش است.
        $role = (int)($me->role ?? 2);
        if ($role !== 1) {
            $code = trim((string)($me->code ?? ''));
            // کد خالی نیز اجازه دسترسی به اکانت دیگری نمی‌دهد.
            if (!$this->emailBelongsToPanelCode($validated['email'], $code)) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }
        }

        $affected = DB::table('v2_user')
            ->where('email', $validated['email'])
            ->update(['banned' => (int) $validated['banned']]);

        if ($affected === 0) {
            return response()->json(['status' => 'error', 'message' => 'Not found'], 404);
        }

        return response()->json(['status' => 'ok']);
    }

    public function changePassword(\Illuminate\Http\Request $request)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $v = Validator::make($request->all(), [
            'current_password'           => 'required|string',
            'new_password'               => 'required|string|min:4|confirmed', // نیازمند new_password_confirmation
        ], [
            'new_password.min'           => 'رمز جدید باید حداقل ۴ کاراکتر باشد',
            'new_password.confirmed'     => 'تأیید رمز جدید مطابقت ندارد',
            'current_password.required'  => 'رمز فعلی را وارد کنید',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $email = $me->email ?? null;
        if (!$email) {
            return response()->json(['status' => 'error', 'message' => 'ایمیل کاربر نامشخص است'], 400);
        }

        $panelUser = DB::table('panel_users')->where('email', $email)->first();
        if (!$panelUser) {
            return response()->json(['status' => 'error', 'message' => 'کاربر یافت نشد'], 404);
        }

        if (!Hash::check($request->input('current_password'), $panelUser->password)) {
            return response()->json(['status' => 'error', 'message' => 'رمز عبور فعلی اشتباه است'], 422);
        }

        DB::table('panel_users')->where('id', $panelUser->id)->update([
            'password'   => Hash::make($request->input('new_password')),
            'updated_at' => now(),
        ]);

        // اختیاری: همگام‌سازی با جدول users اگر وجود دارد
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('users')) {
                DB::table('users')->where('email', $email)->update([
                    'password'   => Hash::make($request->input('new_password')),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {}

        return response()->json(['status' => 'success', 'message' => 'رمز عبور با موفقیت تغییر کرد']);
    }

}

