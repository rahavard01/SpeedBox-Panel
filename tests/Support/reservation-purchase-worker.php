<?php

// Isolated test process; never exposes an HTTP route or uses production credentials.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing')) throw new RuntimeException('Testing environment required.');
$barrier = $argv[1];
$deadline = microtime(true) + 15;
while (!is_file($barrier)) {
    if (microtime(true) > $deadline) throw new RuntimeException('Test barrier timed out.');
    usleep(10000);
}
$owner = Illuminate\Support\Facades\DB::table('panel_users')->find(1);
$result = app(App\Services\PanelAccountActionService::class)->renewAccountForPanelUser(
    $owner, 'race@rep.com', '3m', 1, requestId: ($argv[2] ?? '') ?: null
);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
