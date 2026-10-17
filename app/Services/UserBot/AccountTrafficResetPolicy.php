<?php

namespace App\Services\UserBot;

use App\Services\RuntimeSetting;
use Illuminate\Support\Facades\DB;

final class AccountTrafficResetPolicy
{
    public function hasPeriodicReset(array|object $account): bool
    {
        $id = (int) data_get($account, 'plan_id', 0);
        $userId = (int) data_get($account, 'id', 0);
        // A bot session can outlive a plan change. Prefer the current account
        // rather than an old snapshot's plan name or plan ID.
        if ($userId) $id = (int) DB::table('v2_user')->where('id', $userId)->value('plan_id');
        if (!$id) return false;
        $plan = DB::table('v2_plan')->where('id', $id)->first(['reset_traffic_method']);
        if (!$plan) return false;
        $method = $plan->reset_traffic_method ?? RuntimeSetting::get('reset_traffic_method', 2);
        return in_array((int) $method, [0, 1, 3, 4], true);
    }
}
