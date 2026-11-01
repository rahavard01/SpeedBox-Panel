<?php

namespace Tests\Feature;

use App\Services\{PanelAccountActionService, RuntimeSetting, SubscriptionReservationService};
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class SubscriptionReservationTest extends ReservationTestCase
{
    private function account(int $method = 2, ?int $expiry = null): object
    {
        DB::table('v2_plan')->whereIn('id', [10, 20])->update(['reset_traffic_method' => $method]);
        DB::table('v2_user')->insert(['id' => 100, 'email' => 'alice@rep.com', 'password' => 'fixture',
            'uuid' => '12345678-1234-1234-1234-123456789012', 'token' => str_repeat('u', 32),
            'plan_id' => 10, 'group_id' => 1, 'transfer_enable' => 250 * 1073741824, 'u' => 100, 'd' => -20,
            'expired_at' => $expiry ?? time() + 86400, 'account_group_type' => 'time', 'account_tariff_group_id' => 1,
            'account_plan_key' => '1m', 'created_at' => time(), 'updated_at' => time()]);
        return DB::table('v2_user')->find(100);
    }

    private function buy(): array
    {
        return app(PanelAccountActionService::class)->renewAccountForPanelUser(DB::table('panel_users')->find(1), 'alice@rep.com', '3m', 1);
    }

    #[DataProvider('crossResetReservations')]
    public function test_reserved_plans_can_change_reset_type_only_at_activation(int $currentMethod, int $nextMethod, int $mode): void
    {
        $before = $this->account($currentMethod);
        RuntimeSetting::set('renew_order_event_id', $mode);
        DB::table('v2_plan')->where('id', 20)->update(['reset_traffic_method' => $nextMethod]);
        DB::table('panel_tariff_groups')->where('id', 1)->update(['name' => 'یک ماهه ۶ گیگ']);
        $result = $this->buy();
        $this->assertTrue($result['ok'], $result['message'] ?? 'Purchase failed');
        $this->assertTrue($result['reserved']);
        $this->assertSame('یک ماهه ۶ گیگ', $result['reservation']['title']);
        $after = DB::table('v2_user')->find(100);
        $this->assertSame(10, (int) $after->plan_id);
        $this->assertSame($currentMethod, (int) $after->account_reset_traffic_method);
        $this->assertSame((int) $before->expired_at, (int) $after->expired_at);
        $resetNow = in_array($currentMethod, [0, 1, 3, 4], true) && $mode === 3;
        $this->assertSame($resetNow ? 0 : 100, (int) $after->u);
        $this->assertSame('9000', DB::table('panel_users')->find(1)->credit);
        $service = app(SubscriptionReservationService::class);
        // Existing reservations display their frozen group name, even after tariff edits.
        DB::table('panel_tariff_groups')->where('id', 1)->update(['name' => 'Renamed']);
        $this->assertSame('یک ماهه ۶ گیگ', $service->current(100)['title']);
        DB::table('v2_user')->where('id', 100)->update(['u' => 250 * 1073741824, 'd' => 0]);
        if (in_array($currentMethod, [0, 1, 3, 4], true)) {
            $this->assertFalse($service->activateIfDue(100));
            DB::table('v2_user')->where('id', 100)->update(['expired_at' => time() - 1]);
        }
        $this->assertTrue($service->activateIfDue(100));
        $activated = DB::table('v2_user')->find(100);
        $this->assertSame(20, (int) $activated->plan_id);
        $this->assertSame($nextMethod, (int) $activated->account_reset_traffic_method);
        $this->assertSame(0, (int) $activated->u);
        $this->assertSame($nextMethod !== 2, $activated->next_reset_at !== null);
        $this->assertFalse($service->activateIfDue(100));
        $this->assertSame(1, DB::table('panel_transactions')->count());
    }

    public static function crossResetReservations(): array
    {
        return [[2, 0, 0], [2, 0, 1], [2, 0, 2], [2, 0, 3], [2, 3, 2], [2, 3, 3],
            [0, 2, 2], [0, 2, 3], [3, 2, 2], [3, 2, 3]];
    }

    #[DataProvider('instantModes')]
    public function test_instant_reset_type_changes_are_rejected_before_charging(int $mode): void
    {
        $before = $this->account(0);
        RuntimeSetting::set('renew_order_event_id', $mode);
        DB::table('v2_plan')->where('id', 20)->update(['reset_traffic_method' => 2]);
        $result = $this->buy();
        $this->assertFalse($result['ok']);
        $this->assertSame('10000', DB::table('panel_users')->find(1)->credit);
        $this->assertSame(0, DB::table('panel_transactions')->count());
        $this->assertSame(0, DB::table('speedbox_subscription_reservations')->count());
        $after = DB::table('v2_user')->find(100);
        $this->assertSame((int) $before->expired_at, (int) $after->expired_at);
        $this->assertSame(100, (int) $after->u);
    }

    public static function instantModes(): array { return [[0], [1]]; }

    public function test_reservation_is_paid_once_and_activates_a_frozen_plan_after_quota_exhaustion(): void
    {
        $before = $this->account();
        $result = $this->buy();
        $this->assertTrue($result['ok']); $this->assertTrue($result['reserved']);
        $this->assertSame('پس از پایان ترافیک یا اعتبار طرح فعلی، طرح رزرو فعال خواهد شد.', $result['reservation']['activation_label']);
        $this->assertSame($before->u, DB::table('v2_user')->find(100)->u);
        $this->assertSame('9000', DB::table('panel_users')->find(1)->credit);
        $this->assertFalse($this->buy()['ok']);
        $this->assertSame(1, DB::table('panel_transactions')->count());
        $this->assertSame(0, DB::table('speedbox_subscription_cycles')->count());
        DB::table('panel_plan')->update(['enable' => 0, 'default_price' => '9999']);
        DB::table('panel_tariff_groups')->update(['enable' => 0, 'name' => 'Changed']);
        DB::table('v2_plan')->where('id', 20)->update(['transfer_enable' => 1, 'reset_traffic_method' => 0]);
        DB::table('v2_user')->where('id', 100)->update(['u' => 250 * 1073741824, 'd' => 0]);
        $start = time();
        $service = app(SubscriptionReservationService::class);
        $this->assertTrue($service->activateIfDue(100));
        $this->assertFalse($service->activateIfDue(100));
        $after = DB::table('v2_user')->find(100);
        $this->assertSame(500 * 1073741824, (int) $after->transfer_enable);
        $this->assertSame(2, (int) $after->account_reset_traffic_method);
        $this->assertSame('Fixture', $after->account_plan_name);
        $this->assertSame(0, (int) $after->u); $this->assertSame(0, (int) $after->d);
        $this->assertGreaterThanOrEqual($start + 90 * 86400, (int) $after->expired_at);
        $this->assertSame('9000', DB::table('panel_users')->find(1)->credit);
        $this->assertSame(1, DB::table('speedbox_account_operations')->count());
        $this->assertSame('1000', (string) DB::table('speedbox_subscription_cycles')->first()->paid_amount);
        $this->assertSame($before->uuid, $after->uuid); $this->assertSame($before->token, $after->token);
    }

    #[DataProvider('periodicModes')]
    public function test_periodic_modes_respect_current_quota_and_whole_validity(int $mode, bool $reserve, bool $reset): void
    {
        $before = $this->account(0, time() + 365 * 86400);
        RuntimeSetting::set('renew_order_event_id', $mode);
        $result = $this->buy();
        $this->assertTrue($result['ok'], json_encode($result)); $this->assertSame($reserve, $result['reserved']);
        $after = DB::table('v2_user')->find(100);
        $this->assertSame($reset ? 0 : 100, (int) $after->u);
        $this->assertSame($reset ? 0 : -20, (int) $after->d);
        $this->assertSame($reserve ? (int) $before->expired_at : (int) $before->expired_at + 90 * 86400, (int) $after->expired_at);
        $this->assertSame(($reserve ? 250 : 500) * 1073741824, (int) $after->transfer_enable);
        if ($reserve) {
            $this->assertSame('پس از پایان اعتبار طرح فعلی، طرح رزرو فعال خواهد شد.', $result['reservation']['activation_label']);
            DB::table('v2_user')->where('id', 100)->update(['u' => 999 * 1073741824]);
            $this->assertFalse(app(SubscriptionReservationService::class)->activateIfDue(100));
            RuntimeSetting::set('renew_order_event_id', 0);
            DB::table('v2_user')->where('id', 100)->update(['expired_at' => time() - 1]);
            $this->assertTrue(app(SubscriptionReservationService::class)->activateIfDue(100));
            $this->assertSame(0, (int) DB::table('v2_user')->find(100)->u);
        }
    }

    public static function periodicModes(): array { return [[0, false, false], [1, false, true], [2, true, false], [3, true, true]]; }

    public function test_first_use_without_expiry_is_active_and_a_banned_account_is_not_auto_activated(): void
    {
        $this->account();
        DB::table('v2_user')->where('id', 100)->update(['expired_at' => null]);
        $this->assertTrue($this->buy()['reserved']);
        DB::table('v2_user')->where('id', 100)->update(['banned' => 1, 'expired_at' => time() - 1]);
        $this->assertFalse(app(SubscriptionReservationService::class)->activateIfDue(100));
        $this->assertSame(1, (int) DB::table('v2_user')->find(100)->banned);
    }

    public function test_no_reset_extra_traffic_is_blocked_before_any_charge(): void
    {
        $this->account();
        $result = app(PanelAccountActionService::class)->topupTrafficForPanelUser(DB::table('panel_users')->find(1), 'alice@rep.com', 10, 1);
        $this->assertFalse($result['ok']);
        $this->assertSame('10000', DB::table('panel_users')->find(1)->credit);
        $this->assertSame(0, DB::table('panel_transactions')->count());
    }

    public function test_volume_purchase_reserves_requested_quota_then_starts_its_own_thirty_days(): void
    {
        $this->account();
        DB::table('panel_users')->where('id', 1)->update(['partner_plan_type' => 'both']);
        DB::table('panel_tariff_groups')->insert(['id' => 2, 'name' => 'Volume', 'group_type' => 'volume', 'enable' => 1, 'is_default' => 0, 'partner_access_mode' => 'all_partners']);
        DB::table('panel_plan')->insert(['tariff_group_id' => 2, 'v2_plan_id' => 20, 'plan_key' => 'volume_gig', 'enable' => 1, 'default_price' => '100']);
        DB::table('v2_user')->where('id', 100)->update(['account_group_type' => 'volume', 'account_plan_key' => 'volume_gig', 'account_tariff_group_id' => 2]);
        $result = app(PanelAccountActionService::class)->topupTrafficForPanelUser(DB::table('panel_users')->find(1), 'alice@rep.com', 20, 2);
        $this->assertTrue($result['ok'], json_encode($result)); $this->assertTrue($result['reserved']);
        DB::table('v2_user')->where('id', 100)->update(['expired_at' => time() - 1]);
        $this->assertTrue(app(SubscriptionReservationService::class)->activateIfDue(100));
        $after = DB::table('v2_user')->find(100);
        $this->assertSame(20 * 1073741824, (int) $after->transfer_enable);
        $this->assertEqualsWithDelta(time() + 30 * 86400, $after->expired_at, 2);
    }
    public function test_network_retry_reuses_one_charge_and_rejects_reusing_the_key_for_another_purchase(): void
    {
        $this->account(0);
        RuntimeSetting::set('renew_order_event_id', 1);
        $owner = DB::table('panel_users')->find(1);
        $service = app(PanelAccountActionService::class);
        $id = 'cba2ef50-6a77-466a-b2f0-f12b76536195';
        $first = $service->renewAccountForPanelUser($owner, 'alice@rep.com', '3m', 1, requestId: $id);
        $second = $service->renewAccountForPanelUser($owner, 'alice@rep.com', '3m', 1, requestId: $id);
        $this->assertTrue($first['ok']); $this->assertSame($first, $second);
        $this->assertSame('9000', DB::table('panel_users')->find(1)->credit);
        $this->assertSame(1, DB::table('panel_transactions')->count());
        $this->assertFalse($service->renewAccountForPanelUser($owner, 'alice@rep.com', '1m', 1, requestId: $id)['ok']);
    }

    private function botOrder(array $attributes = []): \App\Models\UserBotOrder
    {
        return \App\Models\UserBotOrder::create(array_merge([
            'setting_id' => 1, 'session_id' => 1, 'order_type' => 'renew_account', 'plan_key' => '1_3m',
            'account_email' => 'alice@rep.com', 'amount' => 2000, 'payment_method' => 'card',
            'status' => 'card_waiting_payment', 'card_sms_expires_at' => now()->addMinutes(5),
        ], $attributes));
    }

    private function botSchema(): void
    {
        \Illuminate\Support\Facades\Schema::create('user_bot_settings', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id(); $t->integer('owner_id'); $t->string('owner_type'); $t->string('bot_token'); $t->boolean('is_enabled'); $t->timestamps();
        });
        \Illuminate\Support\Facades\Schema::create('user_bot_sessions', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id(); $t->integer('setting_id'); $t->string('account_email'); $t->boolean('is_logged_in'); $t->string('telegram_chat_id'); $t->timestamps();
        });
        \Illuminate\Support\Facades\Schema::create('user_bot_orders', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id(); $t->integer('setting_id'); $t->integer('session_id'); $t->string('order_type'); $t->string('plan_key');
            $t->string('account_email'); $t->integer('traffic_gb')->nullable(); $t->integer('amount'); $t->string('payment_method');
            $t->string('status'); $t->json('payload')->nullable(); $t->timestamp('card_sms_expires_at')->nullable(); $t->timestamp('crypto_expires_at')->nullable();
            $t->timestamp('approved_at')->nullable(); $t->string('card_sms_match_key')->nullable(); $t->timestamps();
        });
        DB::table('user_bot_settings')->insert(['id' => 1, 'owner_id' => 1, 'owner_type' => 'partner', 'bot_token' => 'fake', 'is_enabled' => 1]);
        DB::table('user_bot_sessions')->insert(['id' => 1, 'setting_id' => 1, 'account_email' => 'alice@rep.com', 'is_logged_in' => 1, 'telegram_chat_id' => '123']);
    }

    public function test_pending_payment_holds_the_slot_across_sessions_and_preserves_sms_grace(): void
    {
        $this->account(); $this->botSchema(); $first = $this->botOrder();
        $this->assertFalse($this->buy()['ok']);
        try { $this->botOrder(['session_id' => 2]); $this->fail('Second payment intent accepted'); }
        catch (\DomainException $e) { $this->assertStringContainsString('انتظار', $e->getMessage()); }
        $first->update(['card_sms_expires_at' => now()->subMinutes(5)]);
        $this->assertFalse($this->buy()['ok']);
        $first->update(['card_sms_expires_at' => now()->subMinutes(13)]);
        $this->assertTrue($this->buy()['ok']);
        $this->assertSame(1, DB::table('panel_transactions')->count());
    }

    public function test_paid_order_is_atomic_idempotent_and_keeps_its_quote_after_sale_is_disabled(): void
    {
        $this->account(); $this->botSchema(); $order = $this->botOrder(['status' => 'card_retry_pending']);
        DB::table('panel_plan')->update(['enable' => 0, 'default_price' => '9999']);
        DB::table('panel_tariff_groups')->update(['enable' => 0]);
        $processor = app(\App\Services\UserBot\UserBotOrderProcessor::class);
        $first = $processor->approve($order); $again = $processor->approve($order);
        $this->assertTrue($first['ok'], json_encode($first)); $this->assertTrue($first['reserved']);
        $this->assertSame($first, $again); $this->assertSame(1, DB::table('panel_transactions')->count());
        $this->assertSame('9000', DB::table('panel_users')->find(1)->credit);
        DB::table('v2_user')->where('id', 100)->update(['expired_at' => time() - 1]);
        $this->assertTrue(app(SubscriptionReservationService::class)->activateIfDue(100));
        $this->assertSame(1, DB::table('speedbox_reservation_notifications')->count());
        $this->assertFalse(app(SubscriptionReservationService::class)->activateIfDue(100));
        $this->assertSame(1, DB::table('speedbox_reservation_notifications')->count());
        $tx = DB::table('panel_transactions')->first();
        $this->assertSame(1, (int) $tx->performed_by_id);
        $this->assertFalse(json_decode($tx->meta, true)['reservation_pending']);
        $this->assertNull(DB::table('speedbox_account_operations')->first()->operator_id);
    }

    public function test_late_and_retried_node_reports_never_charge_the_next_plan(): void
    {
        $this->account();
        (require database_path('migrations/2026_10_08_000001_create_speedbox_infrastructure_tables.php'))->up();
        $node = new \App\Models\ProxyServer(); $node->forceFill(['id' => 1, 'name' => 'Fixture', 'rate' => 1, 'type' => 'vless']);
        DB::table('v2_server')->insert(['id' => 1, 'name' => 'Fixture', 'type' => 'vless', 'rate' => 1, 'host' => 'example.test', 'port' => '443', 'server_port' => 443]);
        $this->assertTrue($this->buy()['reserved']);
        $batch = ['id' => str_repeat('a', 32), 'user_id' => 100, 'generation' => 0, 'u' => 250 * 1073741824, 'd' => 0];
        $service = app(\App\Services\NodeControlService::class);
        $service->report($node, ['traffic_v2' => [$batch]]);
        $after = DB::table('v2_user')->find(100);
        $this->assertSame(0, (int) $after->u); $this->assertSame(1, (int) $after->subscription_generation);
        $service->report($node, ['traffic_v2' => [$batch]]);
        $late = array_merge($batch, ['id' => str_repeat('b', 32), 'u' => 1000]);
        $service->report($node, ['traffic_v2' => [$late]]);
        $this->assertSame(0, (int) DB::table('v2_user')->find(100)->u);
        $fresh = array_merge($batch, ['id' => str_repeat('c', 32), 'generation' => 1, 'u' => 123]);
        $service->report($node, ['traffic_v2' => [$fresh]]);
        $service->report($node, ['traffic_v2' => [$fresh]]);
        $this->assertSame(123, (int) DB::table('v2_user')->find(100)->u);
        $this->assertSame(1, DB::table('speedbox_account_operations')->count());
    }

    public function test_cancellation_refunds_only_once_and_does_not_touch_the_active_plan(): void
    {
        $before = $this->account(); $result = $this->buy();
        $service = app(SubscriptionReservationService::class); $admin = DB::table('panel_users')->find(2);
        $service->cancel(100, $result['reservation_id'], $admin, true);
        $service->cancel(100, $result['reservation_id'], $admin, true);
        $this->assertNull($service->current(100));
        $this->assertSame('10000', DB::table('panel_users')->find(1)->credit);
        $this->assertSame(1, DB::table('panel_transactions')->where('type', 'reservation_refund')->count());
        $this->assertSame($before->u, DB::table('v2_user')->find(100)->u);
        $this->assertSame($before->expired_at, DB::table('v2_user')->find(100)->expired_at);
    }

    public function test_a_reserved_package_with_an_immediate_consumption_reset_is_fully_refunded(): void
    {
        $this->account(0); RuntimeSetting::set('renew_order_event_id', 3); $result = $this->buy();
        $service = app(SubscriptionReservationService::class); $representative = DB::table('panel_users')->find(1);
        $this->assertTrue($service->current(100)['can_refund']);
        $service->cancel(100, $result['reservation_id'], $representative, true);
        $service->cancel(100, $result['reservation_id'], $representative, true);
        $this->assertNull($service->current(100));
        $this->assertSame('10000', DB::table('panel_users')->find(1)->credit);
        $this->assertSame(0, (int) DB::table('v2_user')->find(100)->u);
        $this->assertSame(1, DB::table('panel_transactions')->where('type', 'reservation_refund')->count());
    }

    public function test_representative_can_cancel_a_bot_reservation_and_refund_its_original_wallet(): void
    {
        $this->account(); $this->botSchema(); $order = $this->botOrder();
        $result = app(\App\Services\UserBot\UserBotOrderProcessor::class)->approve($order);
        $this->assertTrue($result['reserved']);
        app(SubscriptionReservationService::class)->cancel(100, DB::table('speedbox_subscription_reservations')->value('id'), DB::table('panel_users')->find(1), true);
        $this->assertSame('10000', DB::table('panel_users')->find(1)->credit);
        $rows = DB::table('panel_transactions')->orderBy('id')->get();
        app(\App\Services\SubscriptionReservationHistory::class)->attach($rows);
        $this->assertSame('telegram_bot', $rows[0]->reservation_history[0]['performed_by_role']);
        $this->assertEquals(2, $rows[0]->reservation_history[1]['performed_by_role']);
        $this->assertSame('reservation_refund', $rows[1]->type);
        $this->assertSame(1, DB::table('speedbox_reservation_notifications')->count());
    }

    public function test_telegram_failure_never_rolls_back_activation_and_can_be_retried(): void
    {
        $this->account(); $this->botSchema(); $order = $this->botOrder();
        $result = app(\App\Services\UserBot\UserBotOrderProcessor::class)->approve($order);
        $this->assertTrue($result['reserved']);
        DB::table('v2_user')->where('id', 100)->update(['expired_at' => time() - 1]);
        $this->assertTrue(app(SubscriptionReservationService::class)->activateIfDue(100));
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::sequence()->push(['ok' => false], 400)->push(['ok' => true, 'result' => ['message_id' => 1]])]);
        app(\App\Services\ReservationNotificationRunner::class)->tick();
        $this->assertSame('activated', DB::table('speedbox_subscription_reservations')->first()->state);
        $this->assertNull(DB::table('speedbox_reservation_notifications')->first()->sent_at);
        DB::table('speedbox_reservation_notifications')->update(['retry_at' => now()->subMinute()]);
        app(\App\Services\ReservationNotificationRunner::class)->tick();
        $this->assertNotNull(DB::table('speedbox_reservation_notifications')->first()->sent_at);
        $this->assertSame('9000', DB::table('panel_users')->find(1)->credit);
    }

    public function test_old_agent_prevents_payment_until_the_managed_runtime_is_updated(): void
    {
        $this->account(); (require database_path('migrations/2026_10_08_000001_create_speedbox_infrastructure_tables.php'))->up();
        DB::table('v2_server')->insert(['id' => 1, 'name' => 'Fixture', 'type' => 'vless', 'rate' => 1, 'host' => 'example.test', 'port' => '443', 'server_port' => 443, 'enabled' => 1, 'group_ids' => '[1]']);
        $this->assertFalse($this->buy()['ok']); $this->assertSame(0, DB::table('panel_transactions')->count());
        \Illuminate\Support\Facades\Cache::put('speedbox:node:1:capabilities', ['subscription-generation-v1']);
        $this->assertTrue($this->buy()['ok']);
    }

    public function test_card_and_crypto_receipts_describe_a_reservation_instead_of_an_immediate_renewal(): void
    {
        $this->account(); $this->botSchema(); $order = $this->botOrder();
        $result = app(\App\Services\UserBot\UserBotOrderProcessor::class)->approve($order);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $order = $order->fresh();
        $method = new \ReflectionMethod(\App\Services\UserBotCryptoOrderVerifier::class, 'notifyBuyer');
        $method->invoke(app(\App\Services\UserBotCryptoOrderVerifier::class), $order, 'paid', $result);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'طرح رزرو شما با موفقیت ثبت شد') && !str_contains($request['text'], 'اکانت زیر با موفقیت تمدید شد'));
        $method = new \ReflectionMethod(\App\Services\UserBot\UserBotOrderDeliveryService::class, 'sendBuyerResult');
        $method->invoke(app(\App\Services\UserBot\UserBotOrderDeliveryService::class), new \App\Services\UserBot\UserBotTelegram('fake'), \App\Models\UserBotSetting::find(1), $order, $result);
        $this->assertCount(2, \Illuminate\Support\Facades\Http::recorded());
    }

    public function test_legacy_sales_key_uses_the_same_default_tariff_at_payment_and_fulfillment(): void
    {
        $this->account(); $this->botSchema();
        DB::table('panel_tariff_groups')->insert(['id' => 2, 'name' => 'Current VIP', 'group_type' => 'time', 'enable' => 1, 'is_default' => 0, 'partner_access_mode' => 'all_partners']);
        DB::table('v2_user')->where('id', 100)->update(['account_tariff_group_id' => 2]);
        $order = $this->botOrder(['plan_key' => '3m']);
        $this->assertSame(1, $order->payload['subscription_purchase_snapshot']['update']['account_tariff_group_id']);
        $result = app(\App\Services\UserBot\UserBotOrderProcessor::class)->approve($order);
        $this->assertTrue($result['ok']); $this->assertTrue($result['reserved']);
    }

    public function test_a_paid_pending_order_keeps_its_technical_plan_when_the_sales_tariff_is_removed(): void
    {
        $this->account(); $this->botSchema(); $order = $this->botOrder(['status' => 'card_retry_pending']);
        DB::table('panel_plan')->delete(); DB::table('panel_tariff_groups')->delete();
        try {
            app(\App\Http\Controllers\Api\Admin\InfrastructureController::class)->deletePlan(20);
            $this->fail('A technical plan referenced by a paid pending order was deleted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertNotNull(DB::table('v2_plan')->find(20));
        $result = app(\App\Services\UserBot\UserBotOrderProcessor::class)->approve($order);
        $this->assertTrue($result['ok'], json_encode($result)); $this->assertTrue($result['reserved']);
    }

}

