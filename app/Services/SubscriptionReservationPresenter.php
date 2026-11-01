<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SubscriptionReservationPresenter
{
    /** Batched for the management tables; never run a query per displayed account. */
    public function attach(iterable $users): void
    {
        $users = collect($users);
        if ($users->isEmpty()) return;
        $plans = DB::table('v2_plan')->whereIn('id', $users->pluck('plan_id')->filter()->unique()->all())->get(['id', 'reset_traffic_method'])->keyBy('id');
        $rows = Schema::hasTable('speedbox_subscription_reservations')
            ? DB::table('speedbox_subscription_reservations')->whereIn('reserved_user_id', $users->pluck('id')->all())->get()->keyBy('user_id') : collect();
        foreach ($users as $user) {
            $isVolume = ($user->account_group_type ?? '') === 'volume' || (!isset($user->account_group_type) && app(AccountTariffResolver::class)->isVolume($user));
            $method = $isVolume ? 2
                : (int) ($user->account_reset_traffic_method ?? $plans[$user->plan_id]->reset_traffic_method ?? RuntimeSetting::get('reset_traffic_method', 2));
            $user->account_has_periodic_reset = in_array($method, [0, 1, 3, 4], true);
            $user->account_reset_method = $method;
            $user->account_ended = !SubscriptionValidity::unexpired($user->expired_at ?? null)
                || (!$user->account_has_periodic_reset && (int) $user->transfer_enable > 0 && (int) $user->u + (int) $user->d >= (int) $user->transfer_enable);
            $user->reservation = isset($rows[$user->id]) ? app(SubscriptionReservationService::class)->present($rows[$user->id]) : null;
        }
    }
}
