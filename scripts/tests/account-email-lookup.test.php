<?php

// Run against an isolated MySQL test instance. Never reads the project .env;
// all account rows live in a connection-local temporary table.
$root = dirname(__DIR__, 2);
require getenv('PARTNER_TEST_AUTOLOAD') ?: $root . '/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root) {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require_once $path;
    }
});
$socket = getenv('LOOKUP_TEST_MYSQL_SOCKET');
$port = getenv('LOOKUP_TEST_MYSQL_PORT');
if (!$socket && !$port) throw new RuntimeException('LOOKUP_TEST_MYSQL_SOCKET or LOOKUP_TEST_MYSQL_PORT must point to an isolated MySQL test instance');
$app = new Illuminate\Foundation\Application($root);
$app->instance('config', new Illuminate\Config\Repository(['database' => ['default' => 'mysql', 'connections' => ['mysql' => [
    'driver' => 'mysql', 'unix_socket' => $socket ?: '', 'host' => '127.0.0.1', 'port' => $port ?: 3306,
    'database' => 'mysql', 'username' => 'root', 'password' => '',
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
    'options' => [PDO::ATTR_EMULATE_PREPARES => false],
]]]]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->register(Illuminate\Events\EventServiceProvider::class);
$app->register(Illuminate\Database\DatabaseServiceProvider::class);
$db = Illuminate\Support\Facades\DB::connection();
$service = new App\Services\PanelAccountActionService;
$lookup = new ReflectionMethod($service, 'findV2UserByEmailSafe');
$checks = 0;
$check = function ($value, $message) use (&$checks) { if (!$value) throw new RuntimeException($message); $checks++; };
foreach (['utf8_general_ci', 'utf8_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb4_bin', 'utf8mb4_0900_ai_ci'] as $collation) {
    $charset = str_starts_with($collation, 'utf8mb4') ? 'utf8mb4' : 'utf8';
    $db->statement("CREATE TEMPORARY TABLE v2_user (id INT PRIMARY KEY, email VARCHAR(191)) CHARACTER SET {$charset} COLLATE {$collation}");
    $db->table('v2_user')->insert([['id' => 1, 'email' => 'YHDRHJUP@TestRep.Com'], ['id' => 2, 'email' => 'نام@testrep.com']]);
    foreach (['utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb4_0900_ai_ci'] as $connectionCollation) {
        $db->statement("SET NAMES utf8mb4 COLLATE {$connectionCollation}");
        $check($lookup->invoke($service, '  yhdrhjup@testrep.com  ')?->id === 1, 'Lookup is normalized and case insensitive: ' . $collation);
        $check($lookup->invoke($service, 'نام@testrep.com')?->id === 2, 'Unicode account lookup: ' . $collation);
        $check($lookup->invoke($service, 'missing@testrep.com') === null, 'Missing account stays missing');
        $check($lookup->invoke($service, "missing' OR 1=1 -- @testrep.com") === null, 'Input remains a bound value');
    }
    $check($lookup->invoke($service, '   ') === null, 'Blank input is rejected');
    if ($charset === 'utf8mb4') {
        try {
            $db->table('v2_user')->whereRaw('`email` COLLATE utf8_general_ci = CONVERT(? USING utf8) COLLATE utf8_general_ci', ['yhdrhjup@testrep.com'])->first();
            throw new RuntimeException('Original query unexpectedly succeeded');
        } catch (Illuminate\Database\QueryException $error) {
            $check((int) ($error->errorInfo[1] ?? 0) === 1253, 'Reproduced the reported MySQL error');
        }
    }
    $db->statement('DROP TEMPORARY TABLE v2_user');
}
echo "Actual MySQL utf8/utf8mb4 email lookup and original error reproduction: {$checks} checks passed.\n";
