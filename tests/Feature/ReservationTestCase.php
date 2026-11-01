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

abstract class ReservationTestCase extends TestCase
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
        (require database_path('migrations/2026_10_29_000001_create_subscription_reservations.php'))->up();
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

}
