<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\V2UserController;
use App\Services\PanelAccountActionService;
use App\Services\RuntimeSetting;
use App\Services\SubscriptionOrderPolicy;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RenewalExpiryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2025_01_01_000000_create_speedbox_core_tables.php'))->up();
        (require database_path('migrations/2026_10_09_000002_add_system_configuration_support.php'))->up();
        (require database_path('migrations/2026_10_11_000001_create_account_operation_history.php'))->up();
        Schema::table('v2_user', function (Blueprint $table) {
            $table->string('account_plan_name')->nullable();
            $table->string('account_group_type')->nullable();
            $table->integer('account_tariff_group_id')->nullable();
            $table->string('account_plan_key')->nullable();
            $table->integer('traffic_warning_version')->default(0);
        });
        Schema::create('panel_users', function (Blueprint $table) {
            $table->id();
            $table->integer('role');
            $table->string('email');
            $table->string('code');
            $table->string('credit');
            $table->string('partner_plan_type');
            $table->boolean('enable_personalized_price')->default(false);
        });
        Schema::create('panel_tariff_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('group_type');
            $table->boolean('enable');
            $table->boolean('is_default');
            $table->integer('sort_order')->default(0);
            $table->string('partner_access_mode');
        });
        Schema::create('panel_plan', function (Blueprint $table) {
            $table->id();
            $table->integer('tariff_group_id');
            $table->integer('v2_plan_id');
            $table->string('plan_key');
            $table->boolean('enable');
            $table->string('default_price');
            $table->integer('device_limit')->nullable();
        });
        Schema::create('panel_transactions', function (Blueprint $table) {
            $table->id();
            foreach (['panel_user_id', 'quantity', 'reference_id', 'performed_by_id', 'performed_by_role'] as $column) $table->integer($column)->nullable();
            foreach (['type', 'direction', 'amount', 'balance_before', 'balance_after', 'currency', 'status',
                'plan_key_after', 'plan_key_before', 'reference_type', 'idempotency_key'] as $column) $table->string($column)->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
        });
        DB::table('panel_users')->insert([
            ['id' => 1, 'role' => 2, 'email' => 'rep@example.test', 'code' => 'rep', 'credit' => '10000', 'partner_plan_type' => 'time'],
            ['id' => 2, 'role' => 1, 'email' => 'admin@example.test', 'code' => 'admin', 'credit' => '10000', 'partner_plan_type' => 'both'],
        ]);
        DB::table('panel_tariff_groups')->insert(['id' => 1, 'name' => 'Fixture', 'group_type' => 'time',
            'enable' => 1, 'is_default' => 1, 'partner_access_mode' => 'all_partners']);
        foreach ([10 => 250, 20 => 500] as $id => $quota) {
            DB::table('v2_plan')->insert(['id' => $id, 'group_id' => 1, 'name' => 'Fixture '.$id,
                'transfer_enable' => $quota, 'reset_traffic_method' => 2, 'created_at' => time(), 'updated_at' => time()]);
        }
        foreach (['1m', '3m', '12m'] as $period) {
            DB::table('panel_plan')->insert(['tariff_group_id' => 1, 'v2_plan_id' => 20,
                'plan_key' => $period, 'enable' => 1, 'default_price' => '1000']);
        }
    }

    #[DataProvider('renewalCases')]
    public function test_all_entry_points_use_the_current_package_to_choose_the_origin(
        string $entry, ?int $method, int $globalMethod, bool $exhausted, bool $expired,
        bool $fromNow, int $resetSetting, int $targetMethod, string $period
    ): void {
        RuntimeSetting::set('reset_traffic_method', $globalMethod);
        RuntimeSetting::set('renew_order_event_id', $resetSetting);
        DB::table('v2_plan')->where('id', 10)->update(['reset_traffic_method' => $method]);
        DB::table('v2_plan')->where('id', 20)->update(['reset_traffic_method' => $targetMethod]);
        $oldExpiry = time() + ($expired ? -3600 : 180 * 86400);
        $used = ($exhausted ? 250 : 249) * 1073741824;
        DB::table('v2_user')->insert(['id' => 100, 'email' => 'alice@rep.com', 'password' => 'fixture',
            'uuid' => '12345678-1234-1234-1234-123456789012', 'token' => str_repeat('u', 32),
            'plan_id' => 10, 'group_id' => 1, 'transfer_enable' => 250 * 1073741824, 'u' => $used, 'd' => 0,
            'expired_at' => $oldExpiry, 'account_group_type' => 'time', 'account_tariff_group_id' => 1,
            'account_plan_key' => '12m', 'created_at' => time(), 'updated_at' => time()]);
        $actorId = $entry === 'admin' ? 2 : 1;
        $owner = DB::table('panel_users')->find($actorId);
        $actor = new GenericUser((array) $owner);
        auth()->setUser($actor);
        $before = time();
        if ($entry === 'bot') {
            $result = app(PanelAccountActionService::class)->renewAccountForPanelUser($owner, 'alice@rep.com', $period, 1, 'telegram_bot');
            $this->assertTrue($result['ok'], json_encode($result));
        } else {
            $request = Request::create('/fixture', 'POST', ['email' => 'alice@rep.com', 'period' => $period, 'tariff_group_id' => 1]);
            $request->setUserResolver(static fn () => $actor);
            $controller = $entry === 'admin' ? UserAdminController::class : V2UserController::class;
            $response = app($controller)->extend($request);
            $result = $response->getData(true);
            $this->assertSame(200, $response->getStatusCode(), json_encode($result));
        }
        $after = time();
        $days = ['1m' => 30, '3m' => 90, '12m' => 365][$period];
        $account = DB::table('v2_user')->find(100);
        $origin = (int) $account->expired_at - $days * 86400;
        if ($fromNow) {
            $this->assertGreaterThanOrEqual($before, $origin);
            $this->assertLessThanOrEqual($after, $origin);
        } else {
            $this->assertSame($oldExpiry, $origin);
        }
        // The expiry rule must not force a reset when the existing renewal setting preserves usage.
        $this->assertSame(($expired || $resetSetting === 1) ? 0 : $used, (int) $account->u);
        $this->assertSame(0, (int) $account->d);
        $this->assertSame(500 * 1073741824, (int) $account->transfer_enable);
        $this->assertSame(20, (int) $account->plan_id);
        $this->assertSame(1, DB::table('panel_transactions')->where('status', 'success')->count());
        if ($entry !== 'admin') $this->assertSame('9000', DB::table('panel_users')->where('id', 1)->value('credit'));
    }

    public static function renewalCases(): array
    {
        $cases = [
            'exhausted without periodic reset' => [2, 0, true, false, true, 1, 2, '1m'],
            'remaining traffic preserves time' => [2, 2, false, false, false, 1, 2, '1m'],
            'monthly reset preserves time despite exhaustion' => [0, 2, true, false, false, 1, 2, '1m'],
            'inherited no reset' => [null, 2, true, false, true, 1, 2, '1m'],
            'inherited monthly reset' => [null, 0, true, false, false, 1, 2, '1m'],
            'expired forces fresh time and existing expiry reset' => [2, 2, false, true, true, 0, 2, '1m'],
            'usage preservation remains respected' => [2, 2, true, false, true, 0, 2, '1m'],
            'changing selected reset policy does not change origin classification' => [2, 2, true, false, true, 1, 0, '3m'],
            'annual purchase uses its own duration' => [2, 2, true, false, true, 1, 2, '12m'],
        ];
        $out = [];
        foreach (['partner', 'admin', 'bot'] as $entry) {
            foreach ($cases as $label => $case) $out[$entry.': '.$label] = [$entry, ...$case];
        }
        return $out;
    }

    public function test_signed_extra_traffic_zero_quota_unknown_plans_and_volume_are_not_misclassified(): void
    {
        $now = 1800000000;
        $expiry = $now + 86400;
        $account = (object) ['plan_id' => 10, 'expired_at' => $expiry, 'transfer_enable' => 250, 'u' => 300, 'd' => -100];
        $policy = app(SubscriptionOrderPolicy::class);
        $this->assertSame($expiry, $policy->renewalBaseTimestamp($account, $now));
        $account->d = -50;
        $this->assertSame($now, $policy->renewalBaseTimestamp($account, $now));
        foreach ([0, 1, 3, 4] as $method) {
            DB::table('v2_plan')->where('id', 10)->update(['reset_traffic_method' => $method]);
            $this->assertSame($expiry, $policy->renewalBaseTimestamp($account, $now));
        }
        $account->plan_id = 999;
        $this->assertSame($expiry, $policy->renewalBaseTimestamp($account, $now));
        $account->plan_id = 10;
        $account->transfer_enable = 0;
        $this->assertSame($expiry, $policy->renewalBaseTimestamp($account, $now));
        $account->transfer_enable = 250;
        $account->account_group_type = 'volume';
        DB::table('v2_plan')->where('id', 10)->update(['reset_traffic_method' => 2]);
        $this->assertSame($expiry, $policy->renewalBaseTimestamp($account, $now));
    }
}
