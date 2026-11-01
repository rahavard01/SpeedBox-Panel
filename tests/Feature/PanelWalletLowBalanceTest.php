<?php

namespace Tests\Feature;

use App\Models\PanelBotSetting;
use App\Services\PanelWalletLowBalanceRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PanelWalletLowBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://panel.example.test', 'services.telegram.bot_token' => null]);
        Http::preventStrayRequests();
        Schema::create('panel_users', function (Blueprint $table) {
            $table->id(); $table->integer('role')->default(2); $table->boolean('banned')->default(false);
            $table->string('code'); $table->bigInteger('credit'); $table->string('telegram_user_id')->nullable();
        });
        Schema::create('panel_transactions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('panel_user_id'); $table->string('status'); $table->bigInteger('balance_after');
            $table->string('direction')->default('debit');
        });
        Schema::create('panel_bot_settings', function (Blueprint $table) {
            $table->id(); $table->boolean('enabled')->default(false); $table->text('bot_token')->nullable();
            $table->text('message_template')->nullable(); $table->timestamps();
        });
        (require database_path('migrations/2026_10_30_000001_create_panel_wallet_low_balance_warnings.php'))->up();
        PanelBotSetting::create(['enabled' => true, 'bot_token' => '123456:fixture-token']);
        DB::table('panel_users')->insert(['id' => 1, 'code' => 'rep', 'credit' => 500000, 'telegram_user_id' => '101']);
        $this->fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);
    }

    private function fake(array|callable $callback): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function tick(): int { return app(PanelWalletLowBalanceRunner::class)->tick(); }
    private function balance(int $amount): void { DB::table('panel_users')->where('id', 1)->update(['credit' => $amount]); }
    private function transaction(int $after, string $status = 'success'): int
    {
        return DB::table('panel_transactions')->insertGetId(['panel_user_id' => 1, 'status' => $status, 'balance_after' => $after]);
    }

    public function test_threshold_is_inclusive_and_success_does_not_repeat(): void
    {
        $this->balance(500001); $this->assertSame(0, $this->tick()); Http::assertNothingSent();
        $this->balance(500000); $this->assertSame(1, $this->tick());
        $this->balance(120000); $this->assertSame(0, $this->tick()); $this->assertSame(0, $this->tick());
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $keyboard = json_decode($request['reply_markup'], true)['inline_keyboard'][0][0];
            return $request['chat_id'] === '101' && str_contains($request['text'], '۵۰۰,۰۰۰ تومان')
                && str_contains($request['text'], '👤 کد پنل: rep') && str_contains($request['text'], '⚠️ اعتبار کیف پول شما رو به پایان است')
                && $keyboard === ['text' => '💳 شارژ کیف پول', 'url' => 'https://panel.example.test/panel/wallet/topup'];
        });
    }

    public function test_observed_recharge_rearms_only_above_threshold(): void
    {
        $this->tick(); $this->balance(500000); $this->tick(); Http::assertSentCount(1);
        $this->balance(900000); $this->tick();
        $this->balance(490000); $this->assertSame(1, $this->tick()); Http::assertSentCount(2);
    }

    public function test_recharge_and_debit_between_ticks_also_rearm(): void
    {
        $this->tick();
        $this->transaction(1500000); $this->transaction(450000); $this->balance(450000);
        $this->assertSame(1, $this->tick()); $this->assertSame(0, $this->tick()); Http::assertSentCount(2);
    }

    public function test_recharge_remaining_below_threshold_does_not_rearm(): void
    {
        $this->balance(100000); $this->tick(); $this->transaction(300000); $this->balance(300000);
        $this->assertSame(0, $this->tick()); Http::assertSentCount(1);
    }

    public function test_late_finalization_of_an_old_transaction_does_not_rearm(): void
    {
        $id = $this->transaction(900000, 'pending'); $this->tick();
        DB::table('panel_transactions')->where('id', $id)->update(['status' => 'success']);
        $this->assertSame(0, $this->tick()); Http::assertSentCount(1);
    }

    public function test_missing_telegram_connection_and_admins_are_skipped(): void
    {
        DB::table('panel_users')->where('id', 1)->update(['telegram_user_id' => null]);
        DB::table('panel_users')->insert(['id' => 2, 'role' => 1, 'code' => 'admin', 'credit' => 0, 'telegram_user_id' => '202']);
        $this->assertSame(0, $this->tick()); Http::assertNothingSent();
        DB::table('panel_users')->where('id', 1)->update(['telegram_user_id' => '0']);
        $this->assertSame(0, $this->tick()); Http::assertNothingSent();
        DB::table('panel_users')->where('id', 1)->update(['telegram_user_id' => '101']);
        $this->assertSame(1, $this->tick()); Http::assertSentCount(1);
    }

    public function test_topup_pending_before_warning_but_approved_later_rearms(): void
    {
        $id = $this->transaction(1500000, 'pending');
        DB::table('panel_transactions')->where('id', $id)->update(['direction' => 'credit']);
        $this->tick();
        DB::table('panel_transactions')->where('id', $id)->update(['status' => 'success']);
        $this->transaction(490000); $this->balance(490000);
        $this->assertSame(1, $this->tick()); $this->assertSame(0, $this->tick()); Http::assertSentCount(2);
    }

    public function test_banned_representatives_and_disabled_bot_are_skipped(): void
    {
        DB::table('panel_users')->where('id', 1)->update(['banned' => true]);
        $this->assertSame(0, $this->tick());
        DB::table('panel_users')->where('id', 1)->update(['banned' => false]);
        PanelBotSetting::first()->update(['enabled' => false]);
        $this->assertSame(0, $this->tick()); Http::assertNothingSent();
    }

    public function test_http_failure_is_retried_without_changing_credit_or_sending_each_tick(): void
    {
        $this->fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 500)->push(['ok' => true])]);
        $this->assertSame(0, $this->tick()); $this->assertSame(0, $this->tick()); Http::assertSentCount(1);
        $this->assertNull(DB::table('panel_wallet_low_balance_warnings')->value('sent_at'));
        $this->assertSame(500000, (int) DB::table('panel_users')->value('credit'));
        $this->travel(6)->minutes(); $this->assertSame(1, $this->tick()); $this->tick(); Http::assertSentCount(2);
    }

    public function test_telegram_ok_false_is_not_marked_as_success(): void
    {
        $this->fake(['api.telegram.org/*' => Http::response(['ok' => false], 200)]);
        $this->assertSame(0, $this->tick()); $this->assertNull(DB::table('panel_wallet_low_balance_warnings')->value('sent_at'));
    }

    public function test_connection_failure_cannot_modify_wallet_or_escape_the_runner(): void
    {
        $this->fake(fn () => throw new ConnectionException('Fixture connection failure'));
        $this->assertSame(0, $this->tick());
        $this->assertSame(500000, (int) DB::table('panel_users')->value('credit'));
        $this->assertSame(0, $this->tick());
    }

    public function test_overlapping_tick_cannot_send_a_second_message(): void
    {
        $this->fake(function () {
            $this->assertSame(0, $this->tick());
            return Http::response(['ok' => true]);
        });
        $this->assertSame(1, $this->tick()); Http::assertSentCount(1);
    }

    public function test_migration_rolls_back_and_can_be_applied_again(): void
    {
        $migration = require database_path('migrations/2026_10_30_000001_create_panel_wallet_low_balance_warnings.php');
        $migration->down(); $this->assertSame(0, $this->tick());
        $migration->up(); $this->assertSame(1, $this->tick());
    }
}
