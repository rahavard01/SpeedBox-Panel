<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use App\Support\JalaliCalendar;
use Illuminate\Support\Facades\DB;

class SubscriptionTrafficReset
{
    public function next(object $user, ?object $plan = null, ?int $after = null): ?int
    {
        if (($user->account_group_type ?? '') === 'volume' || !($user->expired_at ?? 0) || !($user->plan_id ?? null)) return null;
        $plan ??= DB::table('v2_plan')->where('id', $user->plan_id)->first();
        if (!$plan) return null;
        $method = $plan->reset_traffic_method ?? RuntimeSetting::get('reset_traffic_method', 2);
        $from = CarbonImmutable::createFromTimestamp($after ?? time(), 'Asia/Tehran');
        $expiry = CarbonImmutable::createFromTimestamp((int) $user->expired_at, 'Asia/Tehran');
        [$year, $month] = JalaliCalendar::parts($from);
        [, $expiryMonth, $expiryDay] = JalaliCalendar::parts($expiry);
        $method = (int) $method;
        if (!in_array($method, [0, 1, 3, 4], true)) return null;
        if ($method === 0) {
            return JalaliCalendar::date($month === 12 ? $year + 1 : $year, $month === 12 ? 1 : $month + 1)->timestamp;
        }
        if ($method === 3) return JalaliCalendar::date($year + 1, 1)->timestamp;
        if ($method === 4) $month = $expiryMonth;
        $target = JalaliCalendar::date($year, $month, min($expiryDay, JalaliCalendar::monthLength($year, $month)))
            ->setTime($expiry->hour, $expiry->minute, $expiry->second);
        if ($target->lte($from)) {
            if ($method === 4 || $month === 12) { $year++; $month = $method === 4 ? $expiryMonth : 1; }
            else $month++;
            $target = JalaliCalendar::date($year, $month, min($expiryDay, JalaliCalendar::monthLength($year, $month)))
                ->setTime($expiry->hour, $expiry->minute, $expiry->second);
        }
        return $target->timestamp;
    }

    public function tick(): void
    {
        DB::table('v2_user')->where('banned', 0)->whereNotNull('plan_id')->where('expired_at', '>', time())
            ->where(fn ($query) => $query->whereNull('account_group_type')->orWhere('account_group_type', '<>', 'volume'))
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('v2_plan')->whereColumn('v2_plan.id', 'v2_user.plan_id')
                    ->where(function ($plan) {
                        $plan->whereIn('reset_traffic_method', [0, 1, 3, 4]);
                        if ((int) RuntimeSetting::get('reset_traffic_method', 2) !== 2) $plan->orWhereNull('reset_traffic_method');
                    });
            })
            ->where(fn ($query) => $query->whereNull('next_reset_at')->orWhere('next_reset_at', '<=', time()))
            ->orderBy('id')->chunkById(200, function ($users) {
                foreach ($users as $snapshot) {
                    DB::transaction(function () use ($snapshot) {
                        $user = DB::table('v2_user')->where('id', $snapshot->id)->lockForUpdate()->first();
                        if (!$user || $user->banned || (int) $user->expired_at <= time()) return;
                        $next = $this->next($user);
                        if ($next === null) {
                            if ($user->next_reset_at !== null) DB::table('v2_user')->where('id', $user->id)->update(['next_reset_at' => null]);
                            return;
                        }
                        $update = ['next_reset_at' => $next];
                        if ($user->next_reset_at !== null && (int) $user->next_reset_at <= time()) {
                            $update += ['u' => 0, 'd' => 0, 'last_reset_at' => time(), 'reset_count' => (int) $user->reset_count + 1, 'traffic_warning_version' => (int) ($user->traffic_warning_version ?? 0) + 1, 'updated_at' => time()];
                        }
                        DB::table('v2_user')->where('id', $user->id)->update($update);
                        if (array_key_exists('u', $update)) {
                            app(TrafficResetHistory::class)->record($user, $update, 'periodic', 'cron', ['reason' => 'بازنشانی دوره‌ای شمسی']);
                        }
                    });
                }
            });
    }
}

