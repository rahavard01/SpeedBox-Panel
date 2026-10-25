<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\UserBotWebhookController;
use App\Models\UserBotOrder;
use App\Models\UserBotPlan;
use App\Models\UserBotSession;
use App\Services\PanelAccountActionService;
use App\Services\CryptoRateService;
use App\Services\UserBot\UserBotAccountResolver;
use App\Services\UserBot\UserBotOrderProcessor;
use App\Services\UserBot\UserBotTrafficPricing;
use Illuminate\Support\Carbon;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserBotTrafficPricingTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        $this->database = new Capsule(new Container());
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $container = $this->database->getContainer();
        Container::setInstance($container);
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('db.schema', $this->database->schema());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Model::clearBootedModels();
        $response = Mockery::mock(ResponseFactory::class);
        $response->shouldReceive('json')->andReturnUsing(fn ($data) => new JsonResponse($data));
        $container->instance(ResponseFactory::class, $response);
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);

        // Translate only the production MySQL email predicate for SQLite.
        $connection = $this->database->getConnection();
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar {
            protected function whereRaw(Builder $query, $where)
            {
                if ($where['sql'] === '`email` COLLATE utf8_general_ci = CONVERT(? USING utf8) COLLATE utf8_general_ci') {
                    return 'LOWER(email) = LOWER(?)';
                }
                return parent::whereRaw($query, $where);
            }
        });

        $schema = $this->database->schema();
        $schema->create('panel_tariff_groups', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('name');
            $t->string('group_type');
            $t->integer('enable')->default(1);
            $t->integer('is_default')->default(0);
            $t->integer('sort_order')->default(0);
            $t->integer('min_traffic_gb')->default(10);
            $t->string('partner_access_mode')->default('all_partners');
        });
        $schema->create('panel_plan', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('tariff_group_id');
            $t->integer('v2_plan_id');
            $t->string('plan_key');
            $t->integer('enable')->default(1);
        });
        $schema->create('panel_users', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('role')->default(2);
            $t->string('code')->default('rep');
            $t->string('partner_plan_type')->default('both');
        });
        $schema->create('panel_tariff_group_partner_access', function (Blueprint $t) {
            $t->integer('tariff_group_id');
            $t->integer('partner_id');
        });
        $schema->create('v2_plan', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('reset_traffic_method')->default(2);
        });
        $schema->create('v2_user', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('email');
            $t->integer('plan_id');
            $t->integer('account_tariff_group_id')->nullable();
            $t->string('account_group_type')->nullable();
            $t->string('account_plan_key')->nullable();
            $t->bigInteger('expired_at');
            $t->bigInteger('transfer_enable')->default(10737418240);
            $t->bigInteger('u')->default(0);
            $t->bigInteger('d')->default(0);
        });
        $schema->create('user_bot_settings', function (Blueprint $t) {
            $t->increments('id');
            $t->string('owner_type');
            $t->integer('owner_id');
            $t->string('webhook_secret');
            $t->string('bot_token');
            $t->boolean('is_enabled')->default(true);
            $t->boolean('card_payment_enabled')->default(true);
            $t->boolean('crypto_payment_enabled')->default(false);
            $t->boolean('card_sms_auto_approve_enabled')->default(false);
            $t->boolean('force_channel_join_enabled')->default(false);
            $t->string('card_number')->default('test-card');
            $t->string('card_holder')->default('test-owner');
            $t->timestamps();
        });
        $schema->create('user_bot_sessions', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('setting_id');
            $t->string('telegram_chat_id');
            $t->string('telegram_user_id')->nullable();
            $t->string('telegram_username')->nullable();
            $t->boolean('is_logged_in')->default(true);
            $t->string('account_email');
            $t->string('account_sub_link');
            $t->text('account_snapshot');
            $t->string('state')->nullable();
            $t->text('state_data')->nullable();
            $t->timestamps();
        });
        $schema->create('user_bot_plans', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('setting_id');
            $t->string('plan_key');
            $t->string('group_name')->nullable();
            $t->string('title');
            $t->string('description')->nullable();
            $t->integer('price');
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });
        $schema->create('user_bot_orders', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('setting_id');
            $t->integer('session_id')->nullable();
            $t->string('order_type');
            $t->string('plan_key');
            $t->string('account_email');
            $t->integer('traffic_gb');
            $t->integer('amount');
            $t->string('status')->default('pending');
            $t->string('order_code')->nullable();
            $t->string('payment_method')->nullable();
            $t->string('crypto_asset')->nullable();
            $t->string('crypto_network')->nullable();
            $t->string('crypto_wallet_address')->nullable();
            $t->decimal('crypto_amount', 18, 6)->nullable();
            $t->decimal('crypto_base_amount', 18, 6)->nullable();
            $t->decimal('crypto_unique_extra', 18, 8)->nullable();
            $t->timestamp('crypto_expires_at')->nullable();
            $t->bigInteger('bsc_start_block')->nullable();
            $t->bigInteger('bsc_last_checked_block')->nullable();
            $t->text('crypto_meta')->nullable();
            $t->string('card_sms_match_key')->nullable();
            $t->text('payload')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });
        $schema->create('crypto_payment_options', function (Blueprint $t) {
            $t->increments('id');
            $t->string('context');
            $t->string('owner_type');
            $t->integer('owner_id');
            $t->string('asset');
            $t->string('network');
            $t->string('wallet_address');
            $t->boolean('enabled')->default(true);
            $t->integer('sort_order')->default(0);
        });

        foreach ([1 => 'time', 2 => 'time', 3 => 'volume', 4 => 'volume'] as $id => $type) {
            DB::table('panel_tariff_groups')->insert([
                'id' => $id, 'name' => 'Group '.$id, 'group_type' => $type,
                'is_default' => in_array($id, [1, 3], true) ? 1 : 0,
            ]);
            foreach ($type === 'time' ? ['1m', 'gig'] : ['volume_gig'] as $key) {
                DB::table('panel_plan')->insert(['tariff_group_id' => $id, 'v2_plan_id' => $id * 10, 'plan_key' => $key]);
            }
            DB::table('v2_plan')->insert(['id' => $id * 10]);
        }
        DB::table('panel_users')->insert(['id' => 7]);
        DB::table('user_bot_settings')->insert([
            'id' => 1, 'owner_type' => 'partner', 'owner_id' => 7,
            'webhook_secret' => 'test-secret', 'bot_token' => 'test-token',
        ]);
        DB::table('v2_user')->insert([
            'id' => 1, 'email' => 'customer@rep.com', 'plan_id' => 20,
            'account_tariff_group_id' => 2, 'account_group_type' => 'time',
            'account_plan_key' => '1m', 'expired_at' => time() + 86400,
        ]);
        UserBotSession::create([
            'setting_id' => 1, 'telegram_chat_id' => '55', 'is_logged_in' => true,
            'account_email' => 'customer@rep.com', 'account_sub_link' => 'test-subscription',
            'account_snapshot' => (array) DB::table('v2_user')->first(),
        ]);
        $this->salesPlan('1_traffic_gig', 2000);
        $this->salesPlan('2_traffic_gig', 5000);

        $resolver = Mockery::mock(UserBotAccountResolver::class);
        $resolver->shouldReceive('findCurrent')->andReturnUsing(fn () => [
            'email' => 'customer@rep.com', 'sub_link' => 'test-subscription',
            'snapshot' => (array) DB::table('v2_user')->first(),
        ]);
        $resolver->shouldReceive('hasVipPlanName')->andReturn(true);
        $container->instance(UserBotAccountResolver::class, $resolver);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            $this->database->getConnection()->disconnect();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            Container::setInstance(null);
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    private function salesPlan(string $key, int $price): void
    {
        UserBotPlan::create([
            'setting_id' => 1, 'plan_key' => $key, 'price' => $price,
            'title' => $key, 'group_name' => $key, 'is_enabled' => true,
        ]);
    }

    private function send(string $text): void
    {
        $request = Request::create('/', 'POST', ['message' => [
            'chat' => ['id' => 55], 'from' => ['id' => 55], 'text' => $text,
        ]]);
        $response = (new UserBotWebhookController())->handle($request, 'partner', 7, 'test-secret');
        self::assertSame(200, $response->getStatusCode());
    }

    private function session(): UserBotSession
    {
        return UserBotSession::first();
    }

    public function test_quote_and_card_payment_use_own_sales_price_despite_other_tariff_sorting_first(): void
    {
        $this->send('➕ خرید ترافیک');
        self::assertSame('2_traffic_gig', $this->session()->state_data['traffic_plan']['plan_key']);
        self::assertSame(5000, $this->session()->state_data['traffic_plan']['price']);
        Http::assertSent(fn ($r) => str_contains($r['text'], '5,000'));
        $this->send('10');
        self::assertSame(50000, $this->session()->state_data['amount']);
        $this->send('💳 کارت به کارت');
        self::assertSame('buy_waiting_receipt', $this->session()->state);
        Http::assertSent(fn ($r) => str_contains($r['text'], '50,000'));
    }

    public function test_other_tariff_is_not_used_when_own_sales_price_is_missing(): void
    {
        UserBotPlan::where('plan_key', '2_traffic_gig')->delete();
        $this->send('➕ خرید ترافیک');
        self::assertNull($this->session()->state_data);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'تعرفه فروش ترافیک برای طرح اکانت شما فعال نیست'));
    }

    public function test_crypto_order_uses_the_same_matching_sales_tariff_and_amount(): void
    {
        DB::table('user_bot_settings')->update(['crypto_payment_enabled' => true]);
        DB::table('crypto_payment_options')->insert([
            'context' => 'user_bot', 'owner_type' => 'partner', 'owner_id' => 7,
            'asset' => 'USDT', 'network' => 'TRC20', 'wallet_address' => 'test-wallet',
        ]);
        $rates = Mockery::mock(CryptoRateService::class);
        $rates->shouldReceive('tomanRate')->once()->with('USDT')->andReturn(100000.0);
        app()->instance(CryptoRateService::class, $rates);
        $this->send('➕ خرید ترافیک');
        $this->send('10');
        $this->send('💲 ارز دیجیتال');
        self::assertSame('buy_select_crypto_option', $this->session()->state);
        $this->send('💲 USDT - TRC20');
        $order = UserBotOrder::first();
        self::assertSame('2_traffic_gig', $order->plan_key);
        self::assertSame(50000, $order->amount);
        self::assertSame('0.500000', $order->crypto_base_amount);
        self::assertSame('crypto_pending', $order->status);
    }

    public function test_disabled_own_sales_tariff_is_not_replaced_by_another_group(): void
    {
        UserBotPlan::where('plan_key', '2_traffic_gig')->update(['is_enabled' => false]);
        $this->send('➕ خرید ترافیک');
        self::assertNull($this->session()->state_data);
    }

    public function test_owner_access_remains_enforced(): void
    {
        DB::table('panel_tariff_groups')->where('id', 2)->update(['partner_access_mode' => 'selected_partners']);
        $this->send('➕ خرید ترافیک');
        self::assertNull($this->session()->state_data);
    }

    public function test_legacy_global_sales_tariff_does_not_price_a_nondefault_account(): void
    {
        UserBotPlan::query()->delete();
        $this->salesPlan('traffic_gig', 2000);
        $this->send('➕ خرید ترافیک');
        self::assertNull($this->session()->state_data);
    }

    public function test_legacy_global_sales_tariff_still_prices_its_default_account(): void
    {
        UserBotPlan::query()->delete();
        $this->salesPlan('traffic_gig', 2000);
        DB::table('v2_user')->update(['account_tariff_group_id' => 1, 'plan_id' => 10]);
        $this->send('➕ خرید ترافیک');
        self::assertSame(2000, $this->session()->state_data['traffic_plan']['price']);
    }

    public function test_unique_legacy_account_mapping_finds_its_own_sales_tariff(): void
    {
        DB::table('v2_user')->update(['account_tariff_group_id' => null]);
        $this->send('➕ خرید ترافیک');
        self::assertSame('2_traffic_gig', $this->session()->state_data['traffic_plan']['plan_key']);
    }

    public function test_explicit_group_sales_price_takes_priority_over_a_legacy_default_price(): void
    {
        UserBotPlan::query()->delete();
        $this->salesPlan('traffic_gig', 1000);
        $this->salesPlan('1_traffic_gig', 2000);
        DB::table('v2_user')->update(['account_tariff_group_id' => 1, 'plan_id' => 10]);
        $this->send('➕ خرید ترافیک');
        self::assertSame('1_traffic_gig', $this->session()->state_data['traffic_plan']['plan_key']);
        self::assertSame(2000, $this->session()->state_data['traffic_plan']['price']);
    }

    public function test_ambiguous_legacy_mapping_is_not_guessed(): void
    {
        DB::table('v2_user')->update(['account_tariff_group_id' => null]);
        DB::table('panel_plan')->insert(['tariff_group_id' => 1, 'v2_plan_id' => 20, 'plan_key' => '1m']);
        $this->send('➕ خرید ترافیک');
        self::assertNull($this->session()->state_data);
    }

    public function test_disabled_account_group_is_not_quoted(): void
    {
        DB::table('panel_tariff_groups')->where('id', 2)->update(['enable' => 0]);
        $this->send('➕ خرید ترافیک');
        self::assertNull($this->session()->state_data);
    }

    public function test_alternative_prefixed_gig_key_is_supported(): void
    {
        UserBotPlan::where('plan_key', '2_traffic_gig')->update(['plan_key' => '2_gig']);
        $this->send('➕ خرید ترافیک');
        self::assertSame('2_gig', $this->session()->state_data['traffic_plan']['plan_key']);
    }

    public function test_multiple_volume_tariffs_remain_selectable(): void
    {
        DB::table('v2_user')->update([
            'account_tariff_group_id' => 3, 'account_group_type' => 'volume',
            'account_plan_key' => 'volume_gig', 'plan_id' => 30,
        ]);
        $this->salesPlan('3_volume_gig', 3000);
        $this->salesPlan('4_volume_gig', 4000);
        $this->send('➕ خرید ترافیک');
        self::assertSame('traffic_select_group', $this->session()->state);
        self::assertCount(2, $this->session()->state_data['plans']);
        $this->send('📁 4_volume_gig');
        $this->send('10');
        self::assertSame(40000, $this->session()->state_data['amount']);
        self::assertSame('4_volume_gig', $this->session()->state_data['selected_plan']['plan_key']);
    }

    public static function staleStates(): array
    {
        return [
            ['traffic_waiting_gb', '10', 'traffic_plan'],
            ['buy_select_payment_method', '💳 کارت به کارت', 'selected_plan'],
            ['buy_waiting_receipt', 'receipt', 'selected_plan'],
        ];
    }

    public function test_single_volume_tariff_still_uses_its_configured_price(): void
    {
        DB::table('v2_user')->update([
            'account_tariff_group_id' => 3, 'account_group_type' => 'volume',
            'account_plan_key' => 'volume_gig', 'plan_id' => 30,
        ]);
        $this->salesPlan('3_volume_gig', 3000);
        $this->send('➕ خرید ترافیک');
        self::assertSame('traffic_waiting_gb', $this->session()->state);
        $this->send('10');
        self::assertSame(30000, $this->session()->state_data['amount']);
    }

    #[DataProvider('staleStates')]
    public function test_stale_wrong_tariff_is_blocked_before_payment_or_receipt(string $state, string $message, string $key): void
    {
        $this->session()->update(['state' => $state, 'state_data' => [
            'order_type' => 'buy_traffic', 'amount' => 20000,
            $key => ['plan_key' => '1_traffic_gig', 'price' => 2000],
        ]]);
        $this->send($message);
        self::assertNull($this->session()->state);
        self::assertNull($this->session()->state_data);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'مطابقت ندارد'));
    }

    public function test_account_change_between_quote_and_payment_requires_a_new_quote(): void
    {
        $this->send('➕ خرید ترافیک');
        DB::table('v2_user')->update(['account_tariff_group_id' => 1, 'plan_id' => 10]);
        $this->send('10');
        self::assertNull($this->session()->state_data);
    }

    public function test_paid_order_with_wrong_tariff_never_calls_the_charging_service(): void
    {
        $service = Mockery::mock(PanelAccountActionService::class);
        $service->shouldNotReceive('topupTrafficForPanelUser');
        app()->instance(PanelAccountActionService::class, $service);
        $order = new UserBotOrder([
            'setting_id' => 1, 'order_type' => 'buy_traffic',
            'account_email' => 'customer@rep.com', 'plan_key' => '1_traffic_gig',
            'traffic_gb' => 10, 'amount' => 20000,
        ]);
        $result = (new UserBotOrderProcessor())->approve($order);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('مطابقت ندارد', $result['message']);
    }

    public function test_correct_paid_order_keeps_the_sales_amount_and_forwards_its_tariff(): void
    {
        $this->assertCorrectPaidOrder();
    }

    private function assertCorrectPaidOrder(): void
    {
        $service = Mockery::mock(PanelAccountActionService::class);
        $service->shouldReceive('topupTrafficForPanelUser')->once()
            ->with(Mockery::type('object'), 'customer@rep.com', 10, 2, 'telegram_bot', true)
            ->andReturn(['ok' => true, 'traffic_gb' => 10]);
        app()->instance(PanelAccountActionService::class, $service);
        $order = UserBotOrder::create([
            'setting_id' => 1, 'order_type' => 'buy_traffic',
            'account_email' => 'customer@rep.com', 'plan_key' => '2_traffic_gig',
            'traffic_gb' => 10, 'amount' => 50000,
        ]);
        self::assertTrue((new UserBotOrderProcessor())->approve($order)['ok']);
        self::assertSame('done', $order->fresh()->status);
        self::assertSame(50000, $order->fresh()->amount);
    }

    public function test_historical_sales_price_is_preserved_after_owner_changes_the_price(): void
    {
        UserBotPlan::where('plan_key', '2_traffic_gig')->update(['price' => 7000]);
        $this->assertCorrectPaidOrder();
    }
}
