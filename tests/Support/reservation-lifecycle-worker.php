<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing')) throw new RuntimeException('Testing environment required.');
[$script, $barrier, $action, $userId, $reservationId] = $argv;
$deadline = microtime(true) + 15;
while (!is_file($barrier)) {
    if (microtime(true) > $deadline) throw new RuntimeException('Test barrier timed out.');
    usleep(10000);
}
$service = app(App\Services\SubscriptionReservationService::class);
// SQLite has no InnoDB-style row-lock wait. Retry only transient fixture
// BUSY/LOCKED errors after Laravel has rolled the transaction back. All real
// lifecycle/refund assertions still run against the resulting committed state.
while (true) {
    try {
        if ($action === 'activate') $result = $service->activateIfDue((int) $userId);
        else {
            $owner = Illuminate\Support\Facades\DB::table('panel_users')->find(1);
            $service->cancel((int) $userId, (int) $reservationId, $owner, true);
            $result = true;
        }
        echo json_encode(['ok' => $result], JSON_THROW_ON_ERROR);
        break;
    } catch (DomainException $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
        break;
    } catch (Illuminate\Database\QueryException $e) {
        if (Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite'
            || !in_array((int) ($e->errorInfo[1] ?? 0), [5, 6], true)
            || microtime(true) >= $deadline) throw $e;
        usleep(random_int(10000, 30000));
    }
}
