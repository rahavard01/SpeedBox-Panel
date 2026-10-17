<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PartnerTariffAccessService
{
    public function canCreateType(object $owner, string $groupType): bool
    {
        if ((int) ($owner->role ?? 2) === 1) return true;
        $type = (string) ($owner->partner_plan_type ?? 'time');
        return $type === 'both' || $type === $groupType;
    }

    public function canSeeTariffGroup(?object $panelUser, int $tariffGroupId): bool
    {
        if (!$panelUser || $tariffGroupId <= 0) {
            return false;
        }

        if ((int)($panelUser->role ?? 2) === 1) {
            return true;
        }

        $group = DB::table('panel_tariff_groups')
            ->where('id', $tariffGroupId)
            ->where('enable', 1)
            ->first();

        if (!$group) {
            return false;
        }

        $mode = (string)($group->partner_access_mode ?? 'all_partners');

        if ($mode === 'all_partners' || $mode === '') {
            return true;
        }

        return DB::table('panel_tariff_group_partner_access')
            ->where('tariff_group_id', $tariffGroupId)
            ->where('partner_id', (int)$panelUser->id)
            ->exists();
    }

    public function applyVisibleGroups(Builder $query, ?object $panelUser, string $groupAlias = 'panel_tariff_groups'): Builder
    {
        if (!$panelUser) {
            return $query->whereRaw('1 = 0');
        }

        if ((int)($panelUser->role ?? 2) === 1) {
            return $query;
        }

        $partnerId = (int)($panelUser->id ?? 0);

        return $query->where(function ($q) use ($groupAlias, $partnerId) {
            $q->where($groupAlias . '.partner_access_mode', 'all_partners')
                ->orWhereNull($groupAlias . '.partner_access_mode')
                ->orWhereExists(function ($sub) use ($groupAlias, $partnerId) {
                    $sub->select(DB::raw(1))
                        ->from('panel_tariff_group_partner_access as access')
                        ->whereColumn('access.tariff_group_id', $groupAlias . '.id')
                        ->where('access.partner_id', $partnerId);
                });
        });
    }
}