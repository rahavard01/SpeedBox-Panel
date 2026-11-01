<?php

namespace Tests\Feature;

use App\Services\RuntimeSetting;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class ReservationConcurrencyTest extends ReservationTestCase
{
    public function test_concurrent_purchase_cannot_charge_or_reserve_twice(): void
    {
        if (DB::getDriverName() === 'sqlite' && config('database.connections.sqlite.database') === ':memory:') {
            $this->markTestSkipped('Run separately with a temporary file database for real child-process concurrency.');
        }
        DB::table('v2_user')->insert(['id' => 100, 'email' => 'race@rep.com', 'password' => 'fixture',
            'uuid' => '12345678-1234-1234-1234-123456789012', 'token' => str_repeat('r', 32),
            'plan_id' => 10, 'group_id' => 1, 'transfer_enable' => 250 * 1073741824,
            'expired_at' => time() + 86400, 'account_group_type' => 'time', 'account_tariff_group_id' => 1,
            'account_plan_key' => '1m', 'created_at' => time(), 'updated_at' => time()]);
        $barrier = sys_get_temp_dir().'/speedbox-reservation-race-'.bin2hex(random_bytes(10));
        $workers = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $worker = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/reservation-purchase-worker.php'), $barrier], base_path(), ['APP_ENV' => 'testing']);
                $worker->setTimeout(30); $worker->start(); $workers[] = $worker;
            }
            touch($barrier);
            $successes = 0;
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $successes += !empty($result['ok']) ? 1 : 0;
            }
            $this->assertSame(1, $successes);
            $this->assertSame('9000', DB::table('panel_users')->find(1)->credit);
            $this->assertSame(1, DB::table('panel_transactions')->count());
            $this->assertSame(1, DB::table('speedbox_subscription_reservations')->where('state', 'reserved')->count());
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            if (is_file($barrier)) unlink($barrier);
        }
    }
}
