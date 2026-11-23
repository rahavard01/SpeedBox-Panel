<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TariffConnectionPolicy
{
    // Keep the XBoard device_limit contract. null inherits; 0 is unlimited.
    public function resolve(int $planId, ?int $groupId, ?string $key): ?int
    {
        $tariff = $groupId && $key ? DB::table('panel_plan')
            ->where('tariff_group_id', $groupId)->where('plan_key', $key)->lockForUpdate()->first() : null;
        $limit = $tariff?->device_limit ?? DB::table('v2_plan')->where('id', $planId)->value('device_limit');
        if(DB::table('v2_plan')->where('id',$planId)->value('service_type')==='wireguard') return max(1,min(16,(int)($limit?:1)));
        return $limit === null ? null : (int) $limit;
    }

    public function validate(array $item): void
    {
        if(!empty($item['v2_plan_id']) && DB::table('v2_plan')->where('id',$item['v2_plan_id'])->value('service_type')==='wireguard' && isset($item['device_limit']) && ((int)$item['device_limit']<1 || (int)$item['device_limit']>16))throw new \DomainException('تعداد پروفایل وایرگارد باید بین ۱ و ۱۶ باشد؛ خالی یعنی ارث‌بری از پلن.');
        Validator::make($item, ['device_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647']],
            [], ['device_limit' => 'محدودیت اتصال'])->validate();
    }

    public function saved(int $groupId, string $key, array $item): void
    {
        if (array_key_exists('device_limit', $item)) {
            DB::table('panel_plan')->where('tariff_group_id', $groupId)->where('plan_key', $key)
                ->update(['device_limit' => $item['device_limit'] === null || $item['device_limit'] === '' ? null : (int) $item['device_limit']]);
        }
    }

    public function applyPlanDefault(int $planId, ?int $limit): void
    {
        DB::table('v2_user')->where('plan_id', $planId)->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('panel_plan')
                ->whereColumn('panel_plan.tariff_group_id', 'v2_user.account_tariff_group_id')
                ->whereColumn('panel_plan.plan_key', 'v2_user.account_plan_key')->whereNotNull('panel_plan.device_limit');
        })->update(['device_limit' => $limit]);
    }
}
