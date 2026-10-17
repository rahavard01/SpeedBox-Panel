<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DashboardPanelController extends Controller
{
    public function cards(Request $req)
    {
        // ===== پایه =====
        $cfg = config('dashboard');
        $T_USERS    = $cfg['tables']['users']           ?? 'v2_user';
        $T_PUSERS   = $cfg['tables']['panel_users']     ?? 'panel_users';
        $T_TRX      = $cfg['tables']['transactions']    ?? 'panel_transactions';
        $T_RECEIPTS = $cfg['tables']['wallet_receipts'] ?? 'panel_wallet_receipts';

        $TYPE_PURCHASE = $cfg['transaction_types']['purchase'] ?? 'account_purchase';
        $TYPE_RENEWAL  = $cfg['transaction_types']['renewal']  ?? 'account_extend';
        $TYPE_TOPUPS   = ['wallet_topup_card', 'wallet_topup_crypto'];

        $STATUS_SUCCESS = $cfg['statuses']['success'] ?? 'success';
        $STATUS_SUBMIT  = $cfg['statuses']['pending_receipt'] ?? 'submitted';

        $tz = $cfg['tz'] ?? 'Asia/Tehran';
        $presenceWindow = (int)($cfg['presence_window_sec'] ?? 60);

        // کاربر فعلی پنل
        /** @var \App\Models\PanelUser|\Illuminate\Contracts\Auth\Authenticatable $me */
        $me   = $req->user();
        $pid  = (int)($me->id ?? 0);
        $code = (string)($me->code ?? '');

        // ---- مرزبندی زمانی (بر اساس تهران) و تبدیل به UTC برای مقایسه SQL ----
        $nowTeh = Carbon::now($tz);

        $tStart  = $nowTeh->copy()->startOfDay()->utc();
        $tEnd    = $nowTeh->copy()->endOfDay()->utc();

        $yStart  = $nowTeh->copy()->subDay()->startOfDay()->utc();
        $yEnd    = $nowTeh->copy()->startOfDay()->subSecond()->utc();

        [$jy, $jm] = $this->gregorianToJalali(
            (int) $nowTeh->format('Y'),
            (int) $nowTeh->format('m'),
            (int) $nowTeh->format('d')
        );

        [$gy, $gm, $gd] = $this->jalaliToGregorian($jy, $jm, 1);

        $mStartTeh = Carbon::create($gy, $gm, $gd, 0, 0, 0, $tz);
        $mEndTeh   = $nowTeh->copy();

        $prevJy = $jy;
        $prevJm = $jm - 1;

        if ($prevJm < 1) {
            $prevJm = 12;
            $prevJy--;
        }

        [$pgy, $pgm, $pgd] = $this->jalaliToGregorian($prevJy, $prevJm, 1);

        $pmStartTeh = Carbon::create($pgy, $pgm, $pgd, 0, 0, 0, $tz);
        $pmEndTeh   = $mStartTeh->copy()->subSecond();

        $mStart  = $mStartTeh->copy()->utc();
        $mEnd    = $mEndTeh->copy()->utc();
        $pmStart = $pmStartTeh->copy()->utc();
        $pmEnd   = $pmEndTeh->copy()->utc();

        // ---- هِلپرها ----
        $pct = function (int|float $cur, int|float $prev): float {
            if ($prev == 0) return $cur > 0 ? 100.0 : 0.0;
            return round((($cur - $prev) / $prev) * 100, 2);
        };

        // ستون زمان ایجاد
        $createdCol = 'created_at';
        $colRef     = DB::getQueryGrammar()->wrap($createdCol);

        // ساخت offset تهران به‌صورت داینامیک (DST-safe)
        $offMin = Carbon::now($tz)->utcOffset(); // مثال: +210 برای +03:30
        $sign   = $offMin >= 0 ? '+' : '-';
        $hh     = str_pad(intval(abs($offMin) / 60), 2, '0', STR_PAD_LEFT);
        $mm     = str_pad(abs($offMin) % 60, 2, '0', STR_PAD_LEFT);
        $offStr = "{$sign}{$hh}:{$mm}"; // مانند +03:30

        // مقایسه created_at با فرض "زمان تهران" → تبدیل صریح به UTC و سپس مقایسه با epoch
        $betweenTs = function ($q, Carbon $fromUtc, $toUtc) use ($colRef, $offStr) {
            $epochUtc = "TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', CONVERT_TZ($colRef, '{$offStr}', '+00:00'))";
            return $q->whereRaw("$epochUtc BETWEEN ? AND ?", [$fromUtc->timestamp, $toUtc->timestamp]);
        };

        // --- تراکنش‌ها (فقط مربوط به همین پنل) ---
        $sumUserOps = function (string $type, Carbon $fromUtc, Carbon $toUtc) use ($T_TRX, $STATUS_SUCCESS, $betweenTs, $pid, $code) {
            try {
                return (int) DB::table($T_TRX)
                    ->where('type', $type)
                    ->where('status', $STATUS_SUCCESS)
                    // اسکوپ: یا با id خود پنل، یا با code داخل متا
                    ->where(function ($q) use ($pid, $code) {
                        $q->where('panel_user_id', $pid)
                          ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.panel_code')) = ?", [$code]);
                    })
                    ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                    ->sum(DB::raw("COALESCE(JSON_LENGTH(JSON_EXTRACT(meta,'$.user_ids')), quantity, 1)"));
            } catch (\Throwable $e) {
                return (int) DB::table($T_TRX)
                    ->where('type', $type)
                    ->where('status', $STATUS_SUCCESS)
                    ->where(function ($q) use ($pid, $code) {
                        $q->where('panel_user_id', $pid)
                          ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.panel_code')) = ?", [$code]);
                    })
                    ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                    ->count();
            }
        };

        $sumTopups = function (Carbon $fromUtc, Carbon $toUtc) use ($T_TRX, $STATUS_SUCCESS, $betweenTs, $pid, $code, $TYPE_TOPUPS) {
            return (int) DB::table($T_TRX)
                ->whereIn('type', $TYPE_TOPUPS)
                ->where('status', $STATUS_SUCCESS)
                ->where(function ($q) use ($pid, $code) {
                    $q->where('panel_user_id', $pid)
                      ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.panel_code')) = ?", [$code]);
                })
                ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                ->sum('amount');
        };

        // --- وضعیت کاربران تحت مدیریت این پنل ---
        $emailLike = '%@' . $code . '.com';

        $usersTotal = (int) DB::table($T_USERS)
            ->where('email', 'LIKE', $emailLike)
            ->count();

        $nowUtc = Carbon::now('UTC')->timestamp;

        $online = app(\App\Services\AccountOnlineStatus::class)->summary($code);
        $usersOnline = $online['users'];

        $usersExpired = (int) DB::table($T_USERS)
            ->where('email', 'LIKE', $emailLike)
            ->whereNotNull('expired_at')->where('expired_at', '<>', 0)
            ->where('expired_at', '<=', $nowUtc)
            ->count();

        // --- KPIها ---
        $nu_today = $sumUserOps($TYPE_PURCHASE, $tStart, $tEnd);
        $nu_yest  = $sumUserOps($TYPE_PURCHASE, $yStart, $yEnd);
        $nu_m     = $sumUserOps($TYPE_PURCHASE, $mStart, $mEnd);
        $nu_pm    = $sumUserOps($TYPE_PURCHASE, $pmStart, $pmEnd);

        $re_today = $sumUserOps($TYPE_RENEWAL, $tStart, $tEnd);
        $re_yest  = $sumUserOps($TYPE_RENEWAL, $yStart, $yEnd);
        $re_m     = $sumUserOps($TYPE_RENEWAL, $mStart, $mEnd);
        $re_pm    = $sumUserOps($TYPE_RENEWAL, $pmStart, $pmEnd);

        $top_m    = $sumTopups($mStart, $mEnd);
        $top_pm   = $sumTopups($pmStart, $pmEnd);

        // --- پاسخ ---
        return response()->json([
            'panelStatus' => [
                'usersTotal'   => $usersTotal,
                'usersOnline'  => $usersOnline,
                'onlineIps'    => $online['ips'],
                'usersExpired' => $usersExpired,
            ],

            'wallet' => [
                'thisMonthToman' => $top_m,
                'vsPrevMonthPct' => $pct($top_m, $top_pm),
            ],

            // هیچ «در انتظار تأیید»ی برای پنل نمایش نمی‌دهیم
            'pending' => [
                'deposits'        => 0,
                'partnerRequests' => 0,
            ],

            'newUsers' => [
                'today'          => $nu_today,
                'vsYesterdayPct' => $pct($nu_today, $nu_yest),
                'thisMonth'      => $nu_m,
                'vsPrevMonthPct' => $pct($nu_m, $nu_pm),
            ],

            'renewals' => [
                'today'          => $re_today,
                'vsYesterdayPct' => $pct($re_today, $re_yest),
                'thisMonth'      => $re_m,
                'vsPrevMonthPct' => $pct($re_m, $re_pm),
            ],

            'meta' => [
                'generatedAt' => $nowTeh->toIso8601String(),
                'ranges' => [
                    'today'     => ['from'=>$tStart->toIso8601String(),'to'=>$tEnd->toIso8601String()],
                    'yesterday' => ['from'=>$yStart->toIso8601String(),'to'=>$yEnd->toIso8601String()],
                    'month'     => ['from'=>$mStart->toIso8601String(),'to'=>$mEnd->toIso8601String()],
                    'prevMonth' => ['from'=>$pmStart->toIso8601String(),'to'=>$pmEnd->toIso8601String()],
                ],
                'pollSeconds' => $presenceWindow,
            ],
        ]);
    }

    public function details(Request $request)
    {
        $data = $request->validate([
            'metric' => ['required', 'in:wallet,new_users,renewals'],
            'period' => ['required', 'in:today,current_month'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $me = $request->user();
        $panelId = (int) ($me->id ?? 0);
        $panelCode = (string) ($me->code ?? '');
        [$from, $to] = $this->dashboardRange($data['period']);

        $types = match ($data['metric']) {
            'wallet' => ['wallet_topup_card', 'wallet_topup_crypto'],
            'new_users' => ['account_purchase'],
            'renewals' => ['account_extend'],
        };

        $rows = DB::table('panel_transactions')
            ->whereIn('type', $types)
            ->where('status', 'success')
            ->where(function ($query) use ($panelId, $panelCode) {
                $query->where('panel_user_id', $panelId)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.panel_code')) = ?", [$panelCode]);
            })
            ->whereBetween('created_at', [
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'type',
                'amount',
                'quantity',
                'plan_key_after',
                'reference_id',
                'meta',
                'status',
                'created_at',
            ]);

        if ($data['metric'] === 'wallet') {
            $items = $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'amount' => (int) $row->amount,
                'method' => $this->decodeMeta($row->meta)['method']
                    ?? ($row->type === 'wallet_topup_crypto' ? 'crypto' : 'card'),
                'status' => $row->status,
                'created_at' => $row->created_at,
            ]);
        } else {
            $userIds = $rows->flatMap(function ($row) {
                $meta = $this->decodeMeta($row->meta);
                $ids = array_values(array_filter(array_map('intval', (array) ($meta['user_ids'] ?? []))));
                if (!$ids && $row->reference_id) {
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

            $items = $rows->flatMap(function ($row) use ($users, $tariffGroups, $panelCode) {
                $meta = $this->decodeMeta($row->meta);
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
                    'panel_code' => $panelCode,
                    'plan_label' => $this->dashboardPlanLabel($row->plan_key_after),
                    'tariff_group_name' => $meta['tariff_group_name']
                        ?? ($tariffGroups[(int) ($meta['tariff_group_id'] ?? 0)] ?? null),
                    'traffic_gb' => $meta['volume_gb'] ?? null,
                    'created_at' => $row->created_at,
                ])->all();
            })->values();
        }

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
            'metric' => ['required', 'in:wallet,new_users,renewals'],
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $me = $request->user();
        $panelId = (int) ($me->id ?? 0);
        $panelCode = (string) ($me->code ?? '');
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
            'wallet' => ['wallet_topup_card', 'wallet_topup_crypto'],
            'new_users' => ['account_purchase'],
            'renewals' => ['account_extend'],
        };

        $earliest = collect($ranges)->last()['start'];
        $transactions = DB::table('panel_transactions')
            ->whereIn('type', $types)
            ->where('status', 'success')
            ->where(function ($query) use ($panelId, $panelCode) {
                $query->where('panel_user_id', $panelId)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.panel_code')) = ?", [$panelCode]);
            })
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

            $total = $data['metric'] === 'wallet'
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
