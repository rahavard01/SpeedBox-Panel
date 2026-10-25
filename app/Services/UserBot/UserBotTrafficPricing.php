<?php

namespace App\Services\UserBot;

use App\Services\AccountTariffResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserBotTrafficPricing
{
    public function forAccount(Collection $plans, object $account): Collection
    {
        $group = $this->accountGroup($account);

        if (!$group) {
            return $plans->take(0);
        }

        return $plans->filter(
            fn ($plan) => $this->matchesGroup($group, (string) $plan->plan_key)
        )->sortBy(
            fn ($plan) => (string) $plan->plan_key === 'traffic_gig' ? 1 : 0
        )->values();
    }

    public function matchesAccount(object $account, string $planKey): bool
    {
        $group = $this->accountGroup($account);

        return $group && $this->matchesGroup($group, $planKey);
    }

    private function accountGroup(object $account): ?object
    {
        $group = app(AccountTariffResolver::class)->group($account);

        return $group && (int) $group->enable === 1
            && in_array($group->group_type, ['time', 'volume'], true)
            ? $group
            : null;
    }

    private function matchesGroup(object $group, string $planKey): bool
    {
        $planKey = strtolower(trim($planKey));

        // Volume purchases deliberately allow choosing another volume tariff.
        if ($group->group_type === 'volume') {
            return (bool) preg_match('/^(?:[1-9]\d*_)?volume_gig$/', $planKey);
        }

        if (preg_match('/^([1-9]\d*)_(?:gig|traffic_gig)$/', $planKey, $match)) {
            return (int) $match[1] === (int) $group->id;
        }

        if ($planKey !== 'traffic_gig') {
            return false;
        }

        // An old unprefixed sales tariff belongs only to the legacy default
        // group. It must never price an account from a different group.
        $defaultId = DB::table('panel_tariff_groups')
            ->where('group_type', 'time')
            ->where('enable', 1)
            ->where('is_default', 1)
            ->value('id');

        if (!$defaultId) {
            $defaultId = DB::table('panel_tariff_groups')
                ->where('group_type', 'time')
                ->where('enable', 1)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');
        }

        return (int) $defaultId === (int) $group->id;
    }
}
