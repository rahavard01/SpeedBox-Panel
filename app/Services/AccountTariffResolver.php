<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Account snapshots are authoritative; legacy mappings must identify exactly one group. */
class AccountTariffResolver
{
    public function group(object $account): ?object
    {
        $id=(int)($account->account_tariff_group_id??0);
        if ($id>0) return DB::table('panel_tariff_groups')->where('id',$id)->first();
        if (empty($account->plan_id)) return null;
        $query=DB::table('panel_plan')->where('v2_plan_id',(int)$account->plan_id);
        if (!empty($account->account_plan_key)) $query->where('plan_key',$account->account_plan_key);
        $ids=$query->distinct()->pluck('tariff_group_id');
        if ($ids->count()!==1) return null;
        return DB::table('panel_tariff_groups')->where('id',$ids->first())->first();
    }

    public function isVolume(object $account): bool
    {
        return ($this->group($account)->group_type??$account->account_group_type??'time')==='volume';
    }
}
