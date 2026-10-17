<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TrafficResetHistory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountOperationsTest extends TestCase
{
    private int $accountId;
    private int $planId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('panel_users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email'); $table->string('password');
            $table->integer('role'); $table->boolean('banned')->default(false); $table->string('code')->nullable(); $table->timestamps();
        });
        (require database_path('migrations/2025_01_01_000000_create_speedbox_core_tables.php'))->up();
        Schema::table('v2_user', function (Blueprint $table) {
            $table->string('account_plan_name')->nullable(); $table->string('account_group_type')->nullable();
            $table->unsignedInteger('account_tariff_group_id')->nullable(); $table->string('account_plan_key')->nullable();
            $table->unsignedInteger('reset_count')->default(0); $table->unsignedInteger('last_reset_at')->nullable();
            $table->unsignedInteger('next_reset_at')->nullable(); $table->unsignedInteger('traffic_warning_version')->default(0);
        });
        Schema::create('panel_tariff_groups', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('group_type'); });
        Schema::create('panel_plan', function (Blueprint $table) {
            $table->id(); $table->unsignedInteger('tariff_group_id'); $table->unsignedInteger('v2_plan_id');
            $table->string('plan_key'); $table->boolean('enable'); $table->unsignedInteger('device_limit')->nullable();
        });
        Schema::create('user_bot_sessions', function (Blueprint $table) {
            $table->id(); $table->boolean('is_logged_in'); $table->string('account_email')->nullable();
            $table->string('account_sub_link')->nullable(); $table->json('account_snapshot')->nullable();
            $table->string('state')->nullable(); $table->json('state_data')->nullable(); $table->timestamps();
        });
        Schema::create('panel_transactions', function (Blueprint $table) { $table->id(); $table->unsignedInteger('reference_id'); $table->integer('amount'); });
        Schema::create('v2_stat_user', function (Blueprint $table) {
            $table->id(); $table->unsignedInteger('user_id'); $table->decimal('server_rate', 10, 2);
            $table->bigInteger('u'); $table->bigInteger('d'); $table->unsignedInteger('record_at');
            $table->string('record_type'); $table->unsignedInteger('created_at'); $table->unsignedInteger('updated_at');
        });
        (require database_path('migrations/2026_10_10_000003_create_connection_admission_leases.php'))->up();
        (require database_path('migrations/2026_10_11_000001_create_account_operation_history.php'))->up();
        $this->planId = DB::table('v2_plan')->insertGetId(['name' => 'Basic', 'group_id' => 1, 'transfer_enable' => 100,
            'speed_limit' => 10, 'device_limit' => 3, 'reset_traffic_method' => 2, 'created_at' => time(), 'updated_at' => time()]);
        DB::table('panel_tariff_groups')->insert(['id' => 1, 'name' => 'Basic tariff', 'group_type' => 'time']);
        DB::table('panel_plan')->insert(['id' => 1, 'tariff_group_id' => 1, 'v2_plan_id' => $this->planId, 'plan_key' => '1m', 'enable' => true, 'device_limit' => 2]);
        $this->accountId = DB::table('v2_user')->insertGetId([
            'email' => 'demo@owner.com', 'password' => Hash::make('original-password'),
            'uuid' => '11111111-1111-4111-8111-111111111111', 'token' => str_repeat('a', 32),
            'plan_id' => $this->planId, 'group_id' => 1, 'u' => 100, 'd' => -20, 'transfer_enable' => 1000,
            'expired_at' => time() + 86400, 'account_plan_name' => 'Basic tariff', 'account_group_type' => 'time',
            'account_tariff_group_id' => 1, 'account_plan_key' => '1m', 'reset_count' => 3,
            'device_limit' => 2, 'created_at' => time(), 'updated_at' => time(),
        ]);
        DB::table('user_bot_sessions')->insert(['is_logged_in' => true, 'account_email' => 'demo@owner.com',
            'account_sub_link' => 'https://panel.test/s/' . str_repeat('a', 32),
            'account_snapshot' => json_encode(['id' => $this->accountId, 'email' => 'demo@owner.com']), 'created_at' => now(), 'updated_at' => now()]);
        $this->admin = new User;
        $this->admin->forceFill(['name' => 'Admin', 'email' => 'admin@panel.test', 'password' => 'password', 'role' => 1, 'code' => 'admin'])->save();
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function path(string $suffix = ''): string { return '/api/admin/users/' . $this->accountId . $suffix; }

    public function test_edit_preserves_usage_and_owner_and_never_returns_password(): void
    {
        $before = DB::table('v2_user')->where('id', $this->accountId)->first();
        $this->getJson($this->path('/details'))->assertOk()->assertJsonMissingPath('data.password');
        $this->patchJson($this->path(), ['email' => 'renamed@owner.com', 'remarks' => 'reviewed', 'password' => ''])
            ->assertOk()->assertJsonMissingPath('data.password');
        $this->assertDatabaseHas('v2_user', ['id' => $this->accountId, 'u' => 100, 'd' => -20, 'expired_at' => $before->expired_at, 'password' => $before->password]);
        $this->assertDatabaseHas('user_bot_sessions', ['account_email' => 'renamed@owner.com', 'is_logged_in' => true]);
        $this->patchJson($this->path(), ['email' => 'renamed@other.com'])->assertUnprocessable();
        $this->patchJson($this->path(), ['password' => 'new-secret-password'])->assertOk();
        $this->assertTrue(Hash::check('new-secret-password', DB::table('v2_user')->where('id', $this->accountId)->value('password')));
    }

    public function test_reset_requires_extra_credit_acknowledgement_and_preserves_quota_expiry_and_reports(): void
    {
        $before = DB::table('v2_user')->where('id', $this->accountId)->first();
        DB::table('v2_stat_user')->insert(['user_id' => $this->accountId, 'server_rate' => 1, 'u' => 30, 'd' => 50,
            'record_at' => time(), 'record_type' => 'd', 'created_at' => time(), 'updated_at' => time()]);
        $this->postJson($this->path('/reset-traffic'), ['confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseCount('v2_traffic_reset_logs', 0);
        $this->postJson($this->path('/reset-traffic'), ['confirmed' => true, 'acknowledge_extra' => true, 'reason' => 'customer request'])->assertOk();
        $this->assertDatabaseHas('v2_user', ['id' => $this->accountId, 'u' => 0, 'd' => 0, 'reset_count' => 4,
            'transfer_enable' => 1000, 'expired_at' => $before->expired_at, 'plan_id' => $this->planId, 'traffic_warning_version' => 1]);
        $this->assertDatabaseHas('v2_stat_user', ['user_id' => $this->accountId, 'u' => 30, 'd' => 50]);
        $log = DB::table('v2_traffic_reset_logs')->first();
        $this->assertSame(-20, (int) $log->old_download);
        $this->assertSame(80, (int) $log->old_total);
        $metadata = json_decode($log->metadata, true);
        $this->assertSame('customer request', $metadata['reason']);
        $this->assertSame($this->admin->id, $metadata['admin_id']);
        $this->getJson($this->path('/reset-history'))->assertOk()->assertJsonPath('data.0.metadata.reason', 'customer request');
    }

    public function test_expired_and_banned_accounts_cannot_be_manually_reset(): void
    {
        DB::table('v2_user')->where('id', $this->accountId)->update(['expired_at' => time() - 1]);
        $this->postJson($this->path('/reset-traffic'), ['confirmed' => true, 'acknowledge_extra' => true])->assertUnprocessable();
        DB::table('v2_user')->where('id', $this->accountId)->update(['expired_at' => null, 'banned' => 1]);
        $this->postJson($this->path('/reset-traffic'), ['confirmed' => true, 'acknowledge_extra' => true])->assertUnprocessable();
        $this->assertDatabaseCount('v2_traffic_reset_logs', 0);
    }

    public function test_zero_expiry_is_preserved_and_resettable_for_normal_and_admin_accounts(): void
    {
        foreach ([0, 1] as $adminFlag) {
            DB::table('v2_user')->where('id', $this->accountId)->update([
                'expired_at'=>0, 'is_admin'=>$adminFlag, 'u'=>100, 'd'=>-20,
            ]);
            $this->patchJson($this->path(), ['expired_at'=>0, 'remarks'=>'unlimited expiry'])->assertOk();
            $this->assertDatabaseHas('v2_user', ['id'=>$this->accountId, 'expired_at'=>0, 'u'=>100, 'd'=>-20]);
            $this->postJson($this->path('/reset-traffic'), ['confirmed'=>true, 'acknowledge_extra'=>true])->assertOk();
            $this->assertDatabaseHas('v2_user', ['id'=>$this->accountId, 'expired_at'=>0, 'u'=>0, 'd'=>0, 'transfer_enable'=>1000]);
        }
    }

    public function test_plan_edit_keeps_consumption_and_expiry_and_resolves_tariff_limits(): void
    {
        $before = DB::table('v2_user')->where('id', $this->accountId)->first();
        $newPlan = DB::table('v2_plan')->insertGetId(['name' => 'Other', 'group_id' => 2, 'transfer_enable' => 200,
            'speed_limit' => 30, 'device_limit' => 8, 'reset_traffic_method' => 2, 'created_at' => time(), 'updated_at' => time()]);
        DB::table('panel_tariff_groups')->insert(['id' => 2, 'name' => 'Other tariff', 'group_type' => 'time']);
        DB::table('panel_plan')->insert(['id' => 2, 'tariff_group_id' => 2, 'v2_plan_id' => $newPlan, 'plan_key' => '3m', 'enable' => true, 'device_limit' => 4]);
        $this->patchJson($this->path(), ['panel_plan_id' => 2])->assertOk();
        $this->assertDatabaseHas('v2_user', ['id' => $this->accountId, 'plan_id' => $newPlan, 'group_id' => 2,
            'account_tariff_group_id' => 2, 'account_plan_key' => '3m', 'account_plan_name' => 'Other tariff',
            'u' => 100, 'd' => -20, 'transfer_enable' => 1000, 'expired_at' => $before->expired_at, 'device_limit' => 4, 'speed_limit' => 30]);
        $this->assertDatabaseCount('v2_traffic_reset_logs', 0);
        $this->patchJson($this->path(), ['panel_plan_id' => 1, 'device_limit' => 7])->assertOk();
        $this->assertDatabaseHas('v2_user', ['id' => $this->accountId, 'device_limit' => 7]);
    }

    public function test_secret_reset_rebinds_bot_and_delete_retains_financial_and_reset_history(): void
    {
        $before = DB::table('v2_user')->where('id', $this->accountId)->first();
        $this->postJson($this->path('/reset-secret'), ['confirmed' => true])->assertOk();
        $after = DB::table('v2_user')->where('id', $this->accountId)->first();
        $this->assertNotSame($before->uuid, $after->uuid);
        $this->assertNotSame($before->token, $after->token);
        $this->assertSame($before->expired_at, $after->expired_at);
        $this->assertSame($before->d, $after->d);
        $this->assertDatabaseHas('user_bot_sessions', ['account_sub_link' => 'https://panel.test/s/' . $after->token, 'is_logged_in' => true]);
        DB::table('panel_transactions')->insert(['reference_id' => $this->accountId, 'amount' => 500]);
        app(TrafficResetHistory::class)->record($after, ['u' => 0, 'd' => 0], 'manual', 'manual');
        $this->deleteJson($this->path(), ['confirmed' => true])->assertOk();
        $this->assertDatabaseMissing('v2_user', ['id' => $this->accountId]);
        $this->assertDatabaseHas('user_bot_sessions', ['is_logged_in' => false, 'account_email' => null, 'account_sub_link' => null]);
        $this->assertDatabaseCount('panel_transactions', 1);
        $this->assertDatabaseCount('v2_traffic_reset_logs', 1);
        $this->assertDatabaseHas('speedbox_account_operations', ['user_id' => $this->accountId, 'action' => 'delete']);
    }

    public function test_online_counts_deduplicate_ips_and_ignore_expired_leases_and_partners_are_denied(): void
    {
        foreach ([[1, '203.0.113.1', time() + 60], [2, '203.0.113.1', time() + 60], [1, '203.0.113.2', time() - 1]] as [$node, $ip, $expiry]) {
            DB::table('speedbox_connection_leases')->insert(['user_id' => $this->accountId, 'node_id' => $node,
                'source_ip' => $ip, 'lease_id' => 'lease', 'expires_at' => $expiry]);
        }
        $this->postJson('/api/admin/users/online', ['ids' => [$this->accountId]])->assertOk()->assertJsonPath('data.0.online_count', 1);
        $this->admin->forceFill(['role' => 2])->save();
        Sanctum::actingAs($this->admin, ['*']);
        $this->getJson($this->path('/details'))->assertForbidden();
        $this->patchJson($this->path(), ['remarks' => 'forbidden'])->assertForbidden();
        $this->postJson($this->path('/reset-secret'), ['confirmed' => true])->assertForbidden();
        $this->postJson($this->path('/reset-traffic'), ['confirmed' => true, 'acknowledge_extra' => true])->assertForbidden();
        $this->deleteJson($this->path(), ['confirmed' => true])->assertForbidden();
    }
}
