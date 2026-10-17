<?php

// Preview by default. --apply must run after the deployment database backup.
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (array_diff(array_slice($argv, 1), ['--apply'])) throw new RuntimeException('Unknown option.');
if (Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql') {
    throw new RuntimeException('Expected the configured MySQL database; stopped.');
}
$result = app(App\Services\TrafficResetBaselineRepair::class)->run(in_array('--apply', $argv, true));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
echo $result['mode'] === 'apply' ? "RESET BASELINE REPAIRED\n" : "RESET BASELINE PREVIEW ONLY\n";
