<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\PanelPricingService;
use App\Services\PartnerTariffAccessService;

class TariffsController extends Controller
{
    public function show(Request $request, PanelPricingService $pricing, PartnerTariffAccessService $access)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $panelUser = DB::table('panel_users')->where('email', strtolower($me->email))->first();
        if (!$panelUser) {
            return response()->json(['status' => 'error', 'message' => 'Panel user not found'], 404);
        }

        $plansOrder = [
            ['key' => 'test', 'title' => 'اکانت تست', 'duration_days' => 3],
            ['key' => '1m', 'title' => 'اکانت یک‌ماهه', 'duration_days' => 30],
            ['key' => '3m', 'title' => 'اکانت سه‌ماهه', 'duration_days' => 90],
            ['key' => '6m', 'title' => 'اکانت شش‌ماهه', 'duration_days' => 180],
            ['key' => '12m', 'title' => 'اکانت یک‌ساله', 'duration_days' => 365],
        ];
        $partnerPlanType = $panelUser->partner_plan_type ?? 'time';
        $isVolumePartner = $partnerPlanType === 'volume';
        $isBothPartner = $partnerPlanType === 'both';

        $allowedGroupTypes = $isBothPartner
            ? ['time', 'volume']
            : [$isVolumePartner ? 'volume' : 'time'];

        $groupsQuery = DB::table('panel_tariff_groups')
            ->where('enable', 1)
            ->whereIn('group_type', $allowedGroupTypes);

        $access->applyVisibleGroups($groupsQuery, $panelUser, 'panel_tariff_groups');

        $groups = $groupsQuery
            ->orderByRaw("CASE WHEN group_type = 'time' THEN 0 WHEN group_type = 'volume' THEN 1 ELSE 2 END")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $tariffGroups = $groups->map(function ($group) use ($plansOrder, $pricing, $panelUser) {
            $planRows = DB::table('panel_plan')
                ->where('tariff_group_id', $group->id)
                ->whereIn('plan_key', array_column($plansOrder, 'key'))
                ->where('enable', 1)
                ->get()
                ->keyBy('plan_key');

            $plansOut = [];

            foreach ($plansOrder as $pl) {
                $row = $planRows->get($pl['key']);

                if (!$row) {
                    continue;
                }

                $priceStr = $pricing->quoteUnitPrice((int) $panelUser->id, $pl['key'], (int) $group->id);
                $final = is_null($priceStr) ? null : (int) $priceStr;

                $plansOut[] = [
                    'key' => $pl['key'],
                    'title' => $pl['title'],
                    'duration_days' => $pl['duration_days'],
                    'final_price' => $final,
                    'details' => $row->details ?? null,
                ];
            }

            $trafficPlanKey = ((string)($group->group_type ?? 'time') === 'volume') ? 'volume_gig' : 'gig';
            $trafficPriceStr = $pricing->quoteUnitPrice((int) $panelUser->id, $trafficPlanKey, (int) $group->id);

            return [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'group_type' => (string)($group->group_type ?? 'time'),
                'is_default' => (int) $group->is_default,
                'min_traffic_gb' => (string) $group->group_type === 'volume' ? (int) ($group->min_traffic_gb ?? 10) : 1,
                'traffic' => [
                    'price_per_gb' => is_null($trafficPriceStr) ? null : (int) $trafficPriceStr,
                    'details' => DB::table('panel_plan')
                        ->where('tariff_group_id', $group->id)
                        ->where('plan_key', $trafficPlanKey)
                        ->value('details'),
                ],
                'plans' => $plansOut,
            ];
        })->values();

        $defaultGroup = $tariffGroups->firstWhere('is_default', 1) ?: $tariffGroups->first();

        return response()->json([
            'status' => 'ok',

            // خروجی جدید چندطرحی
            'groups' => $tariffGroups,

            // خروجی قدیمی برای سازگاری با UI فعلی
            'traffic' => $defaultGroup['traffic'] ?? ['price_per_gb' => null],
            'plans' => $defaultGroup['plans'] ?? [],
        ]);
    }
}