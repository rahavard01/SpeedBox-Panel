<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardAdminController extends Controller
{
    public function cards(Request $req)
    {
        // ===== Config & constants =====
        $cfg = config('dashboard');
        $T_USERS    = $cfg['tables']['users'] ?? 'v2_user';
        $T_PUSERS   = $cfg['tables']['panel_users'] ?? 'panel_users';
        $T_TRX      = $cfg['tables']['transactions'] ?? 'panel_transactions';
        $T_RECEIPTS = $cfg['tables']['wallet_receipts'] ?? 'panel_wallet_receipts';
        $T_REFS     = $cfg['tables']['referrals'] ?? 'panel_referrals';

        $TYPE_PURCHASE = $cfg['transaction_types']['purchase'] ?? 'account_purchase';
        $TYPE_RENEWAL  = $cfg['transaction_types']['renewal'] ?? 'account_extend';

        $STATUS_SUCCESS = $cfg['statuses']['success'] ?? 'success';
        $STATUS_SUBMIT  = $cfg['statuses']['pending_receipt'] ?? 'submitted';
        $STATUS_REF     = $cfg['statuses']['pending_referral'] ?? 'pending';

        $tz = $cfg['tz'] ?? 'Asia/Tehran';
        $presenceWindow = (int)($cfg['presence_window_sec'] ?? 60);

        // ===== Tehran-based boundaries, then convert to UTC for SQL =====
        $nowTeh = Carbon::now($tz);
        $today0 = $nowTeh->copy()->startOfDay();
        $today1 = $nowTeh->copy()->endOfDay();

        $y0 = $today0->copy()->subDay();
        $y1 = $today0->copy()->subSecond();

        [$jy, $jm] = $this->gregorianToJalali(
            (int) $nowTeh->format('Y'),
            (int) $nowTeh->format('m'),
            (int) $nowTeh->format('d')
        );

        [$gy, $gm, $gd] = $this->jalaliToGregorian($jy, $jm, 1);

        $m0 = Carbon::create($gy, $gm, $gd, 0, 0, 0, $tz);
        $m1 = $nowTeh->copy();

        $prevJy = $jy;
        $prevJm = $jm - 1;

        if ($prevJm < 1) {
            $prevJm = 12;
            $prevJy--;
        }

        [$pgy, $pgm, $pgd] = $this->jalaliToGregorian($prevJy, $prevJm, 1);

        $pm0 = Carbon::create($pgy, $pgm, $pgd, 0, 0, 0, $tz);
        $pm1 = $m0->copy()->subSecond();

        // Server-owned ranges (ignore FE to avoid mismatch)
        $tStart  = $today0->copy()->utc();
        $tEnd    = $today1->copy()->utc();
        $yStart  = $y0->copy()->utc();
        $yEnd    = $y1->copy()->utc();
        $mStart  = $m0->copy()->utc();
        $mEnd    = $m1->copy()->utc();
        $pmStart = $pm0->copy()->utc();
        $pmEnd   = $pm1->copy()->utc();

        // ===== Helpers =====
        $createdCol = 'created_at';
        $colRef     = DB::getQueryGrammar()->wrap($createdCol);

        // Build a fixed offset string for Tehran (DST-safe; Iran has no DST now)
        $offMin = Carbon::now($tz)->utcOffset(); // e.g., +210 for +03:30
        $sign   = $offMin >= 0 ? '+' : '-';
        $hh     = str_pad(intval(abs($offMin) / 60), 2, '0', STR_PAD_LEFT);
        $mm     = str_pad(abs($offMin) % 60, 2, '0', STR_PAD_LEFT);
        $offStr = "{$sign}{$hh}:{$mm}"; // like +03:30

        // Compare created_at (stored as Tehran local time) after converting to UTC epoch
        $betweenTs = function ($q, Carbon $fromUtc, Carbon $toUtc) use ($colRef, $offStr) {
            $epochUtc = "TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', CONVERT_TZ($colRef, '{$offStr}', '+00:00'))";
            return $q->whereRaw("$epochUtc BETWEEN ? AND ?", [$fromUtc->timestamp, $toUtc->timestamp]);
        };

        $pct = function (int|float $cur, int|float $prev): float {
            if ($prev == 0) return $cur > 0 ? 100.0 : 0.0;
            return round((($cur - $prev) / $prev) * 100, 2);
        };

        // Count affected users from transactions (meta.user_ids OR quantity)
        $sumUserOps = function (string $type, Carbon $fromUtc, Carbon $toUtc) use ($T_TRX, $STATUS_SUCCESS, $betweenTs) {
            try {
                return (int) DB::table($T_TRX)
                    ->where('type', $type)
                    ->where('status', $STATUS_SUCCESS)
                    ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                    ->sum(DB::raw("
                        COALESCE(
                            JSON_LENGTH(JSON_EXTRACT(meta, '$.user_ids')),
                            quantity,
                            1
                        )
                    "));
            } catch (\Throwable $e) {
                // Fallback if JSON functions unavailable
                return (int) DB::table($T_TRX)
                    ->where('type', $type)
                    ->where('status', $STATUS_SUCCESS)
                    ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                    ->count();
            }
        };

        $sumDeposits = function (Carbon $fromUtc, Carbon $toUtc) use ($T_TRX, $STATUS_SUCCESS, $betweenTs) {
            return (int) DB::table($T_TRX)
                ->whereIn('type', ['wallet_topup_card', 'wallet_topup_crypto'])
                ->where('status', $STATUS_SUCCESS)
                ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                ->sum('amount');
        };

        // ---- Metrics ----
        $nu_today = $sumUserOps($TYPE_PURCHASE, $tStart, $tEnd);
        $nu_yest  = $sumUserOps($TYPE_PURCHASE, $yStart, $yEnd);
        $nu_m     = $sumUserOps($TYPE_PURCHASE, $mStart, $mEnd);
        $nu_pm    = $sumUserOps($TYPE_PURCHASE, $pmStart, $pmEnd);

        $re_today = $sumUserOps($TYPE_RENEWAL, $tStart, $tEnd);
        $re_yest  = $sumUserOps($TYPE_RENEWAL, $yStart, $yEnd);
        $re_m     = $sumUserOps($TYPE_RENEWAL, $mStart, $mEnd);
        $re_pm    = $sumUserOps($TYPE_RENEWAL, $pmStart, $pmEnd);

        $dep_m  = $sumDeposits($mStart, $mEnd);
        $dep_pm = $sumDeposits($pmStart, $pmEnd);

        // ---- SPEEDBOX active users (Windows vs Android) ----
        $nowUtc = Carbon::now('UTC');

        // Pending counters (cached briefly)
        $pending = Cache::remember('dash:pending', 10, function () use ($T_RECEIPTS, $T_REFS, $STATUS_SUBMIT, $STATUS_REF) {
            return [
                'deposits'        => (int) DB::table($T_RECEIPTS)->where('status', $STATUS_SUBMIT)->count(),
                'partnerRequests' => (int) DB::table($T_REFS)->where('status', $STATUS_REF)->count(),
            ];
        });

        // Panel status (cached briefly)
        $panelStatus = Cache::remember('dash:panel', 10, function () use ($T_PUSERS, $T_USERS, $presenceWindow) {
            $nowUtc = Carbon::now('UTC');
            $usersTotal    = (int) DB::table($T_USERS)->count();
            $partnersTotal = (int) DB::table($T_PUSERS)->where('role', 2)->count();
            $usersExpired  = (int) DB::table($T_USERS)->whereNotNull('expired_at')->where('expired_at', '<>', 0)->where('expired_at', '<=', $nowUtc->timestamp)->count();
            return compact('partnersTotal', 'usersTotal', 'usersExpired');
        });

        $online = app(\App\Services\AccountOnlineStatus::class)->summary();
        $panelStatus['usersOnline'] = $online['users'];
        $panelStatus['onlineIps'] = $online['ips'];

        // ---- Response ----
        return response()->json([
            'panelStatus' => $panelStatus,

            'newUsers' => [
                'today'          => $nu_today,
                'yesterday'      => $nu_yest,
                'vsYesterdayPct' => $pct($nu_today, $nu_yest),
                'thisMonth'      => $nu_m,
                'prevMonth'      => $nu_pm,
                'vsPrevMonthPct' => $pct($nu_m, $nu_pm),
            ],

            'renewals' => [
                'today'          => $re_today,
                'yesterday'      => $re_yest,
                'vsYesterdayPct' => $pct($re_today, $re_yest),
                'thisMonth'      => $re_m,
                'prevMonth'      => $re_pm,
                'vsPrevMonthPct' => $pct($re_m, $re_pm),
            ],

            'deposits' => [
                'thisMonthToman' => $dep_m,
                'prevMonthToman' => $dep_pm,
                'vsPrevMonthPct' => $pct($dep_m, $dep_pm),
            ],
            // کارت Windows
            // کارت Android

            'pending' => $pending,

            'meta' => [
                'generatedAt' => $nowTeh->toIso8601String(),
                'ranges' => [
                    'today'     => ['from'=>$tStart->toIso8601String(),'to'=>$tEnd->toIso8601String()],
                    'yesterday' => ['from'=>$yStart->toIso8601String(),'to'=>$yEnd->toIso8601String()],
                    'month'     => ['from'=>$mStart->toIso8601String(),'to'=>$mEnd->toIso8601String()],
                    'prevMonth' => ['from'=>$pmStart->toIso8601String(),'to'=>$pmEnd->toIso8601String()],
                ],
                'pollSeconds' => 60,
            ],
        ]);
    }

    public function details(Request $request)
    {
        $data = $request->validate([
            'metric' => ['required', 'in:deposits,new_users,renewals'],
            'period' => ['required', 'in:today,current_month'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        [$from, $to] = $this->dashboardRange($data['period']);
        $types = match ($data['metric']) {
            'deposits' => ['wallet_topup_card', 'wallet_topup_crypto'],
            'new_users' => ['account_purchase'],
            'renewals' => ['account_extend'],
        };

        $rows = DB::table('panel_transactions as t')
            ->leftJoin('panel_users as p', 'p.id', '=', 't.panel_user_id')
            ->whereIn('t.type', $types)
            ->where('t.status', 'success')
            ->whereBetween('t.created_at', [
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
            ])
            ->orderByDesc('t.created_at')
            ->orderByDesc('t.id')
            ->get([
                't.id',
                't.type',
                't.amount',
                't.status',
                't.quantity',
                't.plan_key_after',
                't.reference_id',
                't.meta',
                't.created_at',
                'p.name as panel_name',
                'p.email as panel_email',
                'p.code as panel_code',
            ]);

        $userIds = $rows->flatMap(function ($row) {
            $meta = $this->decodeMeta($row->meta);
            $ids = array_values(array_filter(array_map('intval', (array) ($meta['user_ids'] ?? []))));
            if (!$ids && in_array($row->type, ['account_purchase', 'account_extend'], true) && $row->reference_id) {
                $ids[] = (int) $row->reference_id;
            }
            return $ids;
        })->unique()->values();

        $users = $userIds->isEmpty()
            ? collect()
            : DB::table('v2_user')->whereIn('id', $userIds)->pluck('email', 'id');

        $tariffGroupIds = $rows->map(function ($row) {
            $meta = $this->decodeMeta($row->meta);
            return isset($meta['tariff_group_id']) ? (int) $meta['tariff_group_id'] : null;
        })->filter()->unique()->values();

        $tariffGroups = $tariffGroupIds->isEmpty()
            ? collect()
            : DB::table('panel_tariff_groups')->whereIn('id', $tariffGroupIds)->pluck('name', 'id');

        $items = $rows->flatMap(function ($row) use ($data, $users, $tariffGroups) {
            $meta = $this->decodeMeta($row->meta);

            if ($data['metric'] === 'deposits') {
                return [[
                    'id' => (int) $row->id,
                    'username' => $row->panel_name ?: $row->panel_email,
                    'panel_code' => $row->panel_code,
                    'method' => $meta['method'] ?? ($row->type === 'wallet_topup_crypto' ? 'crypto' : 'card'),
                    'amount' => (int) $row->amount,
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                ]];
            }

            $ids = array_values(array_filter(array_map('intval', (array) ($meta['user_ids'] ?? []))));
            if (!$ids && $row->reference_id) {
                $ids[] = (int) $row->reference_id;
            }
            if (!$ids) {
                $ids = array_fill(0, max(1, (int) $row->quantity), 0);
            }

            return collect($ids)->map(fn ($userId, $index) => [
                'id' => $row->id . '-' . $index,
                'username' => $userId ? ($users[$userId] ?? null) : null,
                'panel_code' => $row->panel_code,
                'plan' => $row->plan_key_after,
                'plan_label' => $this->dashboardPlanLabel($row->plan_key_after),
                'tariff_group_name' => $meta['tariff_group_name']
                    ?? ($tariffGroups[(int) ($meta['tariff_group_id'] ?? 0)] ?? null),
                'traffic_gb' => $meta['volume_gb'] ?? null,
                'created_at' => $row->created_at,
            ])->all();
        })->values();

        $perPage = (int) ($data['per_page'] ?? 20);
        $page = (int) ($data['page'] ?? 1);
        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return response()->json([
            'data' => $items->slice(($page - 1) * $perPage, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function monthlyTrend(Request $request)
    {
        $data = $request->validate([
            'metric' => ['required', 'in:deposits,new_users,renewals'],
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $months = (int) ($data['months'] ?? 12);
        $now = Carbon::now('Asia/Tehran');
        [$jy, $jm] = $this->gregorianToJalali((int) $now->format('Y'), (int) $now->format('m'), (int) $now->format('d'));
        $ranges = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $jm - $i;
            $year = $jy;
            while ($month < 1) {
                $month += 12;
                $year--;
            }
            [$gy, $gm, $gd] = $this->jalaliToGregorian($year, $month, 1);
            $start = Carbon::create($gy, $gm, $gd, 0, 0, 0, 'Asia/Tehran');
            if ($month === 12) {
                [$ngy, $ngm, $ngd] = $this->jalaliToGregorian($year + 1, 1, 1);
            } else {
                [$ngy, $ngm, $ngd] = $this->jalaliToGregorian($year, $month + 1, 1);
            }
            $end = $i === 0
                ? $now->copy()
                : Carbon::create($ngy, $ngm, $ngd, 0, 0, 0, 'Asia/Tehran')->subSecond();
            $ranges[] = compact('year', 'month', 'start', 'end');
        }

        $types = match ($data['metric']) {
            'deposits' => ['wallet_topup_card', 'wallet_topup_crypto'],
            'new_users' => ['account_purchase'],
            'renewals' => ['account_extend'],
        };

        $earliest = collect($ranges)->last()['start'];
        $transactions = DB::table('panel_transactions')
            ->whereIn('type', $types)
            ->where('status', 'success')
            ->whereBetween('created_at', [
                $earliest->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ])
            ->get(['amount', 'quantity', 'meta', 'created_at']);

        $items = collect($ranges)->map(function ($range) use ($transactions, $data) {
            $rows = $transactions->filter(function ($row) use ($range) {
                $created = Carbon::parse($row->created_at, 'Asia/Tehran');
                return $created->betweenIncluded($range['start'], $range['end']);
            });

            $total = $data['metric'] === 'deposits'
                ? (int) $rows->sum('amount')
                : (int) $rows->sum(function ($row) {
                    $meta = $this->decodeMeta($row->meta);
                    $ids = (array) ($meta['user_ids'] ?? []);
                    return $ids ? count($ids) : max(1, (int) $row->quantity);
                });

            return [
                'month' => sprintf('%04d/%02d', $range['year'], $range['month']),
                'total' => $total,
                'is_current' => false,
            ];
        })->values();

        if ($items->isNotEmpty()) {
            $items[0] = array_merge($items[0], ['is_current' => true]);
        }

        return response()->json(['data' => $items]);
    }

    private function dashboardRange(string $period): array
    {
        $now = Carbon::now('Asia/Tehran');
        if ($period === 'today') {
            return [$now->copy()->startOfDay(), $now->copy()];
        }
        [$jy, $jm] = $this->gregorianToJalali((int) $now->format('Y'), (int) $now->format('m'), (int) $now->format('d'));
        [$gy, $gm, $gd] = $this->jalaliToGregorian($jy, $jm, 1);
        return [Carbon::create($gy, $gm, $gd, 0, 0, 0, 'Asia/Tehran'), $now->copy()];
    }

    private function decodeMeta($meta): array
    {
        if (is_array($meta)) return $meta;
        if (!is_string($meta) || $meta === '') return [];
        $decoded = json_decode($meta, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function dashboardPlanLabel($planKey): ?string
    {
        $planKey = (string) ($planKey ?? '');
        if (preg_match('/^\d+_(.+)$/', $planKey, $matches)) {
            $planKey = $matches[1];
        }

        return [
            'test' => 'تست',
            '1m' => 'یک ماهه',
            '1m_noexp' => 'یک ماهه (بدون انقضا)',
            '3m' => 'سه ماهه',
            '6m' => 'شش ماهه',
            '12m' => 'یک‌ساله',
            'volume_gig' => 'حجمی',
        ][$planKey] ?? ($planKey !== '' ? $planKey : null);
    }

    private function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gDm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;

        $days = 355666
            + (365 * $gy)
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400)
            + $gd
            + $gDm[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;

        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    private function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;

        $days = -355668
            + (365 * $jy)
            + (intdiv($jy, 33) * 8)
            + intdiv(($jy % 33) + 3, 4)
            + $jd
            + (($jm < 7) ? (($jm - 1) * 31) : ((($jm - 7) * 30) + 186));

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;

            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;

        $salA = [
            0,
            31,
            (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28,
            31, 30, 31, 30, 31, 31, 30, 31, 30, 31,
        ];

        for ($gm = 1; $gm <= 12 && $gd > $salA[$gm]; $gm++) {
            $gd -= $salA[$gm];
        }

        return [$gy, $gm, $gd];
    }

}
