<?php

namespace App\Services\UserBot;

use App\Models\UserBotOrder;
use App\Services\{AccountTariffResolver, PanelAccountOwnership, SubscriptionReservationService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class UserBotReservationGuard
{
    public function prepare(UserBotOrder $order): void
    {
        if (!in_array($order->order_type, ['renew_account', 'buy_traffic'], true)
            || !Schema::hasTable('speedbox_subscription_reservations')) return;
        $setting = DB::table('user_bot_settings')->find($order->setting_id);
        $owner = $setting ? DB::table('panel_users')->find($setting->owner_id) : null;
        if (!$owner) throw new \DomainException('صاحب ربات پیدا نشد.');
        if (($setting->owner_type ?? '') === 'admin') $owner->role = 1;
        $user = DB::table('v2_user')->where('email', $order->account_email)->lockForUpdate()->first();
        if (!$user || ((int) $owner->role !== 1 && !PanelAccountOwnership::matches($user->email, $owner->code))) throw new \DomainException('اکانت در دسترس نیست.');
        $service = app(SubscriptionReservationService::class);
        $volume = app(AccountTariffResolver::class)->isVolume($user);
        if ($order->order_type === 'buy_traffic' && !$volume) {
            if (!app(UserBotTrafficPricing::class)->matchesAccount($user, (string) $order->plan_key)) throw new \DomainException('طرح اکانت تغییر کرده است؛ دوباره خرید ترافیک را انتخاب کنید.');
            if (!$service->periodic($user)) throw new \DomainException('خرید ترافیک اضافه برای این طرح فعال نیست؛ از گزینه تمدید اکانت استفاده کنید.');
            return;
        }
        $service->assertAvailable($user);
        $service->ensureGenerationSupport($user);
        [$group, $key] = app(UserBotOrderProcessor::class)->parsePlanKey((string) $order->plan_key);
        $snapshot = $service->snapshot($owner, $group, $key, $order->traffic_gb);
        $service->assertCompatible($user, $snapshot);
        $service->ensureGenerationSupport((object) array_merge((array) $user, $snapshot['update']));
        $order->payload = array_merge($order->payload ?: [], ['subscription_purchase_snapshot' => $snapshot]);
    }
}
