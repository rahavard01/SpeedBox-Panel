<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationMigrationTest extends TestCase
{
    public static function interruptedStages(): array
    {
        return array_map(fn ($stage) => [$stage], range(0, 6));
    }

    #[DataProvider('interruptedStages')]
    public function test_resume_keeps_existing_accounts_and_paid_reservations(int $stage): void
    {
        Schema::create('v2_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('u')->default(0);
        });
        DB::table('v2_user')->insert(['id' => 7, 'u' => 987654]);
        $migration = require database_path('migrations/2026_10_29_000001_create_subscription_reservations.php');
        $migration->up();
        $tables = ['speedbox_subscription_reservations', 'speedbox_reservation_notifications',
            'speedbox_traffic_batches', 'speedbox_account_purchase_requests'];
        // Simulate persisted DDL after interruption at each statement boundary.
        foreach (array_reverse(array_slice($tables, max(0, $stage - 2))) as $table) Schema::drop($table);
        if ($stage < 2) Schema::table('v2_user', fn (Blueprint $table) => $table->dropColumn('account_reset_traffic_method'));
        if ($stage < 1) Schema::table('v2_user', fn (Blueprint $table) => $table->dropColumn('subscription_generation'));
        if ($stage >= 1) DB::table('v2_user')->where('id', 7)->update(['subscription_generation' => 9]);
        if ($stage >= 2) DB::table('v2_user')->where('id', 7)->update(['account_reset_traffic_method' => 2]);
        if ($stage >= 3) DB::table($tables[0])->insert([
            'user_id' => 7, 'reserved_user_id' => 7, 'transaction_id' => 31, 'panel_user_id' => 4,
            'plan_id' => 2, 'tariff_group_id' => 3, 'state' => 'reserved', 'trigger_on_quota' => true,
            'snapshot' => '{"paid":"keep"}',
        ]);
        $migration->up();
        $migration->up();
        foreach ($tables as $table) $this->assertTrue(Schema::hasTable($table));
        $user = DB::table('v2_user')->find(7);
        $this->assertSame(987654, (int) $user->u);
        $this->assertSame($stage >= 1 ? 9 : 0, (int) $user->subscription_generation);
        $this->assertSame($stage >= 2 ? 2 : null, $user->account_reset_traffic_method === null ? null : (int) $user->account_reset_traffic_method);
        $this->assertSame($stage >= 3 ? 1 : 0, DB::table($tables[0])->count());
        if ($stage >= 3) $this->assertSame(['paid' => 'keep'], json_decode(DB::table($tables[0])->value('snapshot'), true));
        DB::table($tables[1])->insert(['reservation_id' => 42, 'session_id' => 8, 'message' => 'retry default']);
        $this->assertNotNull(DB::table($tables[1])->value('retry_at'));
    }
}
