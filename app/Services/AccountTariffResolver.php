<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Tariff identity is stable across renames; purchase snapshots remain historical. */
class AccountTariffResolver
{
    public function group(object $account): ?object
    {
        $id=(int)($account->account_tariff_group_id??0);
        if ($id>0) return DB::table('panel_tariff_groups')->where('id',$id)->first();
        if (empty($account->plan_id)) return null;
        $links = DB::table('panel_plan')->where('v2_plan_id', (int) $account->plan_id)
            ->get(['v2_plan_id', 'tariff_group_id', 'plan_key']);
        $groups = DB::table('panel_tariff_groups')->whereIn('id', $links->pluck('tariff_group_id')->unique()->all())
            ->get()->keyBy('id')->all();
        return $this->legacyGroup($account, $links, $groups);
    }

    public function name(object $account): string
    {
        $group = $this->group($account);
        return trim((string) ($group->name ?? $account->account_plan_name ?? ''));
    }

    /** Resolve in batches for table reads; do not modify stored purchase snapshots. */
    public function present(iterable $accounts): void
    {
        $accounts = collect($accounts);
        if ($accounts->isEmpty()) return;
        $groups = DB::table('panel_tariff_groups')->get()->keyBy('id')->all();
        $legacy = $accounts->filter(fn ($account) => (int) ($account->account_tariff_group_id ?? 0) <= 0);
        $links = $legacy->isEmpty() ? collect() : DB::table('panel_plan')
            ->whereIn('v2_plan_id', $legacy->pluck('plan_id')->filter()->unique()->all())
            ->get(['v2_plan_id', 'tariff_group_id', 'plan_key'])->groupBy('v2_plan_id');
        foreach ($accounts as $account) {
            $id = (int) ($account->account_tariff_group_id ?? 0);
            $group = $id > 0 ? ($groups[$id] ?? null)
                : $this->legacyGroup($account, $links[$account->plan_id ?? 0] ?? [], $groups);
            if (!$group) continue;
            $account->account_plan_name = (string) $group->name;
            $account->account_tariff_group_id = (int) $group->id;
            $account->account_group_type ??= $group->group_type;
        }
    }

    private function legacyGroup(object $account, iterable $links, array $groups): ?object
    {
        $matches = [];
        foreach ($links as $link) {
            if ((int) $link->v2_plan_id !== (int) ($account->plan_id ?? 0)) continue;
            $key = (string) ($account->account_plan_key ?? '');
            if ($key === '1m_noexp') $key = '1m';
            if ($key !== '' && $link->plan_key !== $key) continue;
            $group = $groups[$link->tariff_group_id] ?? null;
            if (!$group) continue;
            if (!empty($account->service_type) && ($group->service_type ?? 'v2ray') !== $account->service_type) continue;
            if (!empty($account->account_group_type) && $group->group_type !== $account->account_group_type) continue;
            $matches[(int) $group->id] = $group;
        }
        return count($matches) === 1 ? reset($matches) : null;
    }

    public function isVolume(object $account): bool
    {
        return ($this->group($account)->group_type??$account->account_group_type??'time')==='volume';
    }
}
