<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\{BillingController, SubscriptionReservationController};
use App\Services\{PanelAccountActionService, RuntimeSetting, SubscriptionReservationHistory, SubscriptionReservationService, SubscriptionReservationStatistics};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReservationLifecycleTest extends ReservationTestCase
{
    private function buy(int $method = 2): array
    {
        DB::table('v2_plan')->whereIn('id', [10, 20])->update(['reset_traffic_method' => $method]);
        DB::table('v2_user')->insert(['id' => 100, 'email' => 'alice@rep.com', 'password' => 'fixture',
            'uuid' => '12345678-1234-1234-1234-123456789012', 'token' => str_repeat('u', 32),
            'plan_id' => 10, 'group_id' => 1, 'transfer_enable' => 250 * 1073741824, 'u' => 100, 'd' => 0,
            'expired_at' => time() + 86400, 'account_group_type' => 'time', 'account_tariff_group_id' => 1,
            'account_plan_key' => '1m', 'created_at' => time(), 'updated_at' => time()]);
        $result = app(PanelAccountActionService::class)->renewAccountForPanelUser(DB::table('panel_users')->find(1), 'alice@rep.com', '3m', 1);
        $this->assertTrue($result['ok'], $result['message'] ?? 'Purchase failed');
        return $result;
    }

    private function history(): \Illuminate\Support\Collection
    {
        $rows = DB::table('panel_transactions')->orderBy('id')->get();
        app(SubscriptionReservationHistory::class)->attach($rows);
        return $rows;
    }

    public function test_automatic_activation_adds_a_line_to_the_original_purchase_and_keeps_its_actor_and_date(): void
    {
        $this->buy();
        $date = now()->subDays(4)->toDateTimeString();
        DB::table('panel_transactions')->update(['created_at' => $date]);
        $pending = $this->history()->first();
        $this->assertSame(['reserved'], array_column($pending->reservation_history, 'state'));
        DB::table('v2_user')->where('id', 100)->update(['expired_at' => time() - 1]);
        $this->assertTrue(app(SubscriptionReservationService::class)->activateIfDue(100));
        $this->assertFalse(app(SubscriptionReservationService::class)->activateIfDue(100));
        $rows = $this->history();
        $this->assertCount(1, $rows);
        $this->assertSame(['reserved', 'activated'], array_column($rows[0]->reservation_history, 'state'));
        $this->assertEquals(2, $rows[0]->reservation_history[0]['performed_by_role']);
        $this->assertSame($date, $rows[0]->reservation_history[0]['created_at']);
        $this->assertSame('system', $rows[0]->reservation_history[1]['performed_by_role']);
        $this->assertSame(DB::table('speedbox_subscription_reservations')->first()->activated_at, $rows[0]->reservation_history[1]['created_at']);
        $this->assertSame($date, $rows[0]->created_at);
    }

    public function test_owner_cancellation_returns_the_original_payers_money_once_and_records_the_canceller(): void
    {
        RuntimeSetting::set('renew_order_event_id', 3);
        $result = $this->buy(0);
        $service = app(SubscriptionReservationService::class);
        $actor = DB::table('panel_users')->find(1);
        $service->cancel(100, $result['reservation_id'], $actor, true);
        $service->cancel(100, $result['reservation_id'], $actor, true);
        $this->assertSame('10000', DB::table('panel_users')->find(1)->credit);
        $rows = $this->history();
        $this->assertCount(2, $rows); // Original debit and the actual wallet credit only.
        $this->assertSame(['reserved', 'cancelled'], array_column($rows[0]->reservation_history, 'state'));
        $this->assertEquals(2, $rows[0]->reservation_history[1]['performed_by_role']);
        $this->assertNotEmpty($rows[0]->reservation_history[1]['created_at']);
        $this->assertSame('reservation_refund', $rows[1]->type);
        $this->assertSame([], $rows[1]->reservation_history);
        $this->assertFalse($service->activateIfDue(100));
    }

    public function test_another_representative_cannot_cancel_or_receive_the_refund(): void
    {
        $result = $this->buy();
        $actor = (object) ['id' => 3, 'role' => 2, 'code' => 'other', 'email' => 'other@example.test'];
        try {
            app(SubscriptionReservationService::class)->cancel(100, $result['reservation_id'], $actor, true);
            $this->fail('Foreign cancellation was accepted');
        } catch (\DomainException $e) { $this->assertStringContainsString('اجازه', $e->getMessage()); }
        $this->assertSame('reserved', DB::table('speedbox_subscription_reservations')->first()->state);
        $this->assertSame('9000', DB::table('panel_users')->find(1)->credit);
        $this->assertSame(1, DB::table('panel_transactions')->count());
        $request = Request::create('/api/users/100/reservation/cancel', 'POST', ['confirmed' => true, 'reservation_id' => $result['reservation_id'], 'refund' => true]);
        $request->setUserResolver(fn () => $actor);
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        app(SubscriptionReservationController::class)->cancel($request, 100);
    }

    public function test_the_cancellation_endpoint_always_refunds_even_if_the_client_sends_false(): void
    {
        $result = $this->buy();
        $request = Request::create('/api/users/100/reservation/cancel', 'POST', ['confirmed' => true, 'reservation_id' => $result['reservation_id'], 'refund' => false]);
        $request->setUserResolver(fn () => DB::table('panel_users')->find(1));
        $this->assertSame(200, app(SubscriptionReservationController::class)->cancel($request, 100)->getStatusCode());
        $this->assertSame('10000', DB::table('panel_users')->find(1)->credit);
    }

    public function test_an_activated_reservation_cannot_be_cancelled_or_refunded(): void
    {
        $result = $this->buy();
        DB::table('v2_user')->where('id', 100)->update(['expired_at' => time() - 1]);
        $service = app(SubscriptionReservationService::class);
        $service->activateIfDue(100);
        try {
            $service->cancel(100, $result['reservation_id'], DB::table('panel_users')->find(1), true);
            $this->fail('Activated plan was cancelled');
        } catch (\DomainException $e) { $this->assertStringContainsString('وضعیت رزرو', $e->getMessage()); }
        $this->assertSame('9000', DB::table('panel_users')->find(1)->credit);
        $this->assertSame(1, DB::table('panel_transactions')->count());
    }

    public function test_cancelled_purchase_is_excluded_from_its_original_reporting_day_without_a_negative_today(): void
    {
        $result = $this->buy();
        $date = now()->subDays(4)->toDateTimeString();
        DB::table('panel_transactions')->update(['created_at' => $date]);
        $count = fn ($day) => DB::table('panel_transactions')->where('type', 'account_extend')->where('status', 'success')
            ->tap(fn ($q) => SubscriptionReservationStatistics::excludeCancelled($q))->whereDate('created_at', $day)->count();
        $this->assertSame(1, $count(substr($date, 0, 10)));
        app(SubscriptionReservationService::class)->cancel(100, $result['reservation_id'], DB::table('panel_users')->find(1), true);
        $this->assertSame(0, $count(substr($date, 0, 10)));
        $this->assertSame(0, $count(now()->toDateString()));
        $this->assertSame($date, DB::table('panel_transactions')->first()->created_at);
        $this->assertSame(now()->toDateString(), substr(DB::table('panel_transactions')->where('type', 'reservation_refund')->value('created_at'), 0, 10));
        $aliased = DB::table('panel_transactions as t')->where('t.type', 'account_extend')->tap(fn ($q) => SubscriptionReservationStatistics::excludeCancelled($q, 't.id'))->count();
        $this->assertSame(0, $aliased);
    }

    public function test_legacy_cancellation_recovers_its_date_and_admin_actor_from_the_operation_log(): void
    {
        $result = $this->buy();
        app(SubscriptionReservationService::class)->cancel(100, $result['reservation_id'], DB::table('panel_users')->find(2), true);
        $tx = DB::table('panel_transactions')->first();
        $meta = json_decode($tx->meta, true);
        unset($meta['reservation_cancelled_at'], $meta['reservation_cancelled_by_role'], $meta['reservation_cancelled_by_id']);
        DB::table('panel_transactions')->where('id', $tx->id)->update(['meta' => json_encode($meta)]);
        $op = DB::table('speedbox_account_operations')->where('action', 'reservation_cancel')->first();
        $meta = json_decode($op->metadata, true); unset($meta['operator_role']);
        DB::table('speedbox_account_operations')->where('id', $op->id)->update(['metadata' => json_encode($meta)]);
        $line = $this->history()->first()->reservation_history[1];
        $this->assertSame($op->created_at, $line['created_at']);
        $this->assertEquals(1, $line['performed_by_role']);
    }

    public function test_billing_preserves_one_purchase_and_exposes_both_lifecycle_lines(): void
    {
        $result = $this->buy();
        app(SubscriptionReservationService::class)->cancel(100, $result['reservation_id'], DB::table('panel_users')->find(1), true);
        $request = Request::create('/api/user/transactions');
        $request->setUserResolver(fn () => DB::table('panel_users')->find(1));
        foreach (['self', 'all'] as $method) {
            $rows = app(BillingController::class)->$method($request)->getData(true);
            $this->assertCount(2, $rows);
            $purchase = collect($rows)->firstWhere('type', 'account_extend');
            $this->assertCount(2, $purchase['reservation_history']);
            $this->assertSame('cancelled', $purchase['reservation_state']);
            $this->assertSame(-1000, $purchase['amount']);
            $this->assertSame([], collect($rows)->firstWhere('type', 'reservation_refund')['reservation_history']);
        }
    }
    public function test_admin_and_representative_dashboard_details_and_monthly_totals_exclude_cancelled_bookings(): void
    {
        \Illuminate\Support\Facades\Schema::table('panel_users', fn ($table) => $table->string('name')->nullable());
        DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value);
        $result = $this->buy();
        $request = Request::create('/dashboard/details', 'GET', ['metric' => 'renewals', 'period' => 'current_month', 'months' => 1]);
        $request->setUserResolver(fn () => DB::table('panel_users')->find(1));
        foreach ([\App\Http\Controllers\Api\Admin\DashboardAdminController::class, \App\Http\Controllers\Api\DashboardPanelController::class] as $class) {
            $this->assertSame(1, app($class)->details($request)->getData(true)['meta']['total']);
            $this->assertSame(1, app($class)->monthlyTrend($request)->getData(true)['data'][0]['total']);
        }
        app(SubscriptionReservationService::class)->cancel(100, $result['reservation_id'], DB::table('panel_users')->find(1), true);
        foreach ([\App\Http\Controllers\Api\Admin\DashboardAdminController::class, \App\Http\Controllers\Api\DashboardPanelController::class] as $class) {
            $this->assertSame(0, app($class)->details($request)->getData(true)['meta']['total']);
            $this->assertSame(0, app($class)->monthlyTrend($request)->getData(true)['data'][0]['total']);
        }
    }

}
