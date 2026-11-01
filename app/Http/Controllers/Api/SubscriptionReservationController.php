<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{PanelAccountOwnership, SubscriptionReservationPresenter, RuntimeSetting};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SubscriptionReservationController extends Controller
{
    public function cancel(Request $request, int $id)
    {
        $actor = $request->user();
        abort_unless($actor && in_array((int) $actor->role, [1, 2], true), 403);
        $user = DB::table('v2_user')->find($id);
        abort_unless($user, 404, 'اکانت یافت نشد.');
        abort_unless((int) $actor->role === 1 || PanelAccountOwnership::matches((string) $user->email, (string) $actor->code), 403);
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'reservation_id' => ['required', 'integer', 'min:1'], 'refund' => ['required', 'boolean']]);
        try {
            app(\App\Services\SubscriptionReservationService::class)->cancel($id, (int) $data['reservation_id'], $actor, true);
        } catch (\DomainException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['message' => 'طرح رزرو لغو شد.']);
    }

    public function show(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $owner = $request->user();
        abort_unless($owner, 401);
        abort_unless((int) $owner->role === 1 || PanelAccountOwnership::matches($data['email'], (string) $owner->code), 403);
        $user = DB::table('v2_user')->where('email', $data['email'])->first();
        abort_unless($user, 404, 'اکانت یافت نشد.');
        app(SubscriptionReservationPresenter::class)->attach([$user]);
        $query = DB::table('panel_plan as pp')->join('v2_plan as vp', 'vp.id', '=', 'pp.v2_plan_id')
            ->join('panel_tariff_groups as tg', 'tg.id', '=', 'pp.tariff_group_id')->where('pp.enable', 1)->where('tg.enable', 1);
        app(\App\Services\PartnerTariffAccessService::class)->applyVisibleGroups($query, $owner, 'tg');
        $methods = $query->get(['pp.tariff_group_id', 'pp.plan_key', 'vp.reset_traffic_method']);
        $map = [];
        foreach ($methods as $method) $map[$method->tariff_group_id][$method->plan_key] = in_array((int) ($method->reset_traffic_method ?? RuntimeSetting::get('reset_traffic_method', 2)), [0, 1, 3, 4], true);
        return response()->json(['data' => [
            'reservation' => $user->reservation, 'account_has_periodic_reset' => $user->account_has_periodic_reset,
            'account_ended' => $user->account_ended, 'renewal_mode' => (int) RuntimeSetting::get('renew_order_event_id', 2),
            'renewal_plan_methods' => $map,
        ]])->header('Cache-Control', 'no-store');
    }
}

