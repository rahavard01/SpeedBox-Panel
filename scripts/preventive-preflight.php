<?php

// Read-only deployment check. Zero expiry is supported for every account role.
$root = getenv('SPEEDBOX_PREFLIGHT_ROOT') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
try {
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection();
    if ($db->getDriverName() !== 'mysql') throw new RuntimeException('Expected configured MySQL; stop and review runtime configuration.');
    $zero = $db->table('v2_user')->where('expired_at', 0)->count();
    echo "database_driver=mysql\nzero_expiry_accounts=$zero\n";
    echo "zero_expiry_policy=unlimited_for_all_accounts\n";
    echo "PREVENTIVE PREFLIGHT OK\n";
} catch (Throwable $error) {
    // Do not print credentials or connection strings from database exceptions.
    $message = $error instanceof Illuminate\Database\QueryException || $error instanceof PDOException
        ? 'Database read failed; inspect database configuration locally.' : $error->getMessage();
    fwrite(STDERR, "PREVENTIVE PREFLIGHT STOPPED: $message\n");
    exit(1);
}
