<?php

namespace Tests\Feature;

use App\Services\PanelAccountActionService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class ReservationCancellationConcurrencyTest extends ReservationTestCase
{
    public function test_concurrent_cancellation_and_activation_have_only_one_outcome_and_at_most_one_refund(): void
    {
        if (DB::getDriverName() === 'sqlite' && config('database.connections.sqlite.database') === ':memory:') {
            $this->markTestSkipped('Run with a temporary file database for real child-process concurrency.');
        }
        for ($round = 0; $round < 3; $round++) {
            $id = 100 + $round;
            $email = 'race'.$round.'@rep.com';
            DB::table('v2_user')->insert(['id' => $id, 'email' => $email, 'password' => 'fixture',
                'uuid' => '12345678-1234-1234-1234-'.str_pad((string) $id, 12, '0', STR_PAD_LEFT), 'token' => str_repeat((string) $round, 32),
                'plan_id' => 10, 'group_id' => 1, 'transfer_enable' => 250 * 1073741824,
                'expired_at' => time() + 86400, 'account_group_type' => 'time', 'account_tariff_group_id' => 1,
                'account_plan_key' => '1m', 'created_at' => time(), 'updated_at' => time()]);
            $owner = DB::table('panel_users')->find(1);
            $result = app(PanelAccountActionService::class)->renewAccountForPanelUser($owner, $email, '3m', 1);
            $this->assertTrue($result['ok']);
            DB::table('v2_user')->where('id', $id)->update(['expired_at' => time() - 1]);
            $barrier = sys_get_temp_dir().'/speedbox-cancel-race-'.bin2hex(random_bytes(8));
            $workers = [];
            try {
                foreach (['cancel', 'cancel', 'activate'] as $action) {
                    $worker = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/reservation-lifecycle-worker.php'),
                        $barrier, $action, (string) $id, (string) $result['reservation_id']], base_path(), ['APP_ENV' => 'testing']);
                    $worker->setTimeout(30); $worker->start(); $workers[] = $worker;
                }
                touch($barrier);
                foreach ($workers as $worker) {
                    $worker->wait();
                    $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                    $this->assertIsArray(json_decode($worker->getOutput(), true), $worker->getOutput());
                }
                $reservation = DB::table('speedbox_subscription_reservations')->find($result['reservation_id']);
                $cancelled = $reservation->state === 'cancelled';
                $this->assertContains($reservation->state, ['cancelled', 'activated']);
                $this->assertSame($cancelled ? (string) $owner->credit : bcsub((string) $owner->credit, '1000', 0), DB::table('panel_users')->find(1)->credit);
                $this->assertSame($cancelled ? 1 : 0, DB::table('panel_transactions')->where('type', 'reservation_refund')->where('reference_id', $id)->count());
                $this->assertSame(1, DB::table('speedbox_account_operations')->where('user_id', $id)->count());
            } finally {
                foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
                if (is_file($barrier)) unlink($barrier);
            }
        }
    }
}
