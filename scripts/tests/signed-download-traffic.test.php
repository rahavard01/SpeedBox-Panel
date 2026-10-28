<?php

// Run only against an isolated MySQL test instance; never reads project .env.
$root = dirname(__DIR__, 2);
require getenv('PARTNER_TEST_AUTOLOAD') ?: $root . '/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root) {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require_once $path;
    }
});

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;

$port = getenv('LOOKUP_TEST_MYSQL_PORT');
if (!$port) throw new RuntimeException('LOOKUP_TEST_MYSQL_PORT must point to an isolated MySQL test instance');
$app = new Illuminate\Foundation\Application($root);
$app->instance('config', new Illuminate\Config\Repository(['database' => ['default' => 'mysql', 'connections' => ['mysql' => [
    'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port, 'database' => 'mysql',
    'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '', 'strict' => true, 'options' => [PDO::ATTR_EMULATE_PREPARES => false],
]]]]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->register(Illuminate\Events\EventServiceProvider::class);
$app->register(Illuminate\Database\DatabaseServiceProvider::class);
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory {
    public function __construct() {}
});
$db = DB::connection();
$name = 'signed_traffic_test_' . bin2hex(random_bytes(6));
$db->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->statement("USE `{$name}`");
$db->setDatabaseName($name);
$checks = 0;
$check = function ($value, $message) use (&$checks) { if (!$value) throw new RuntimeException($message); $checks++; };
$migration = require $root . '/database/migrations/2026_10_11_000003_allow_signed_account_traffic.php';
$core = require $root . '/database/migrations/2025_01_01_000000_create_speedbox_core_tables.php';
$type = fn () => strtolower(DB::selectOne("SHOW COLUMNS FROM v2_user WHERE Field = 'd'")->Type);
$admin = (object) ['id' => 1, 'email' => 'admin@test.com', 'role' => 1];
$partner = (object) ['id' => 2, 'email' => 'partner@test.com', 'role' => 2, 'code' => 'testrep', 'partner_plan_type' => 'time'];
$email = 'account@testrep.com';
$gb = 1073741824;

try {
    $migration->up();
    $check(!Schema::hasTable('v2_user'), 'Missing table is untouched');
    DB::statement('CREATE TABLE v2_user (id INT PRIMARY KEY)');
    $migration->up();
    $check(!Schema::hasColumn('v2_user', 'd'), 'Missing column is untouched');
    Schema::drop('v2_user');

    // Reproduce the newly-created unsigned schema and its reported error.
    DB::statement('CREATE TABLE v2_user (id INT PRIMARY KEY, email VARCHAR(64), d BIGINT UNSIGNED NOT NULL DEFAULT 0, u BIGINT UNSIGNED NOT NULL DEFAULT 0, transfer_enable BIGINT UNSIGNED NOT NULL DEFAULT 0, updated_at INT UNSIGNED, traffic_warning_version INT NOT NULL DEFAULT 0) CHARACTER SET utf8 COLLATE utf8_general_ci');
    DB::table('v2_user')->insert(['id' => 11, 'email' => $email, 'd' => 0, 'u' => 123, 'transfer_enable' => 10 * $gb, 'updated_at' => 123]);
    try {
        DB::table('v2_user')->where('id', 11)->update(['d' => -$gb]);
        throw new RuntimeException('Unsigned download unexpectedly accepted negative value');
    } catch (Illuminate\Database\QueryException $error) {
        $check((int) ($error->errorInfo[1] ?? 0) === 1264, 'Reproduces original MySQL error 1264');
    }
    DB::table('v2_user')->insert(['id' => 12, 'email' => 'used@testrep.com', 'd' => 4 * $gb, 'u' => 456, 'transfer_enable' => 20 * $gb, 'updated_at' => 456]);
    $before = DB::table('v2_user')->orderBy('id')->get()->toJson();
    $collation = DB::selectOne("SHOW TABLE STATUS WHERE Name = 'v2_user'")->Collation;
    // A d-only conversion is insufficient: unsigned u breaks quota SQL after purchase.
    DB::statement('ALTER TABLE v2_user MODIFY d BIGINT NOT NULL DEFAULT 0');
    DB::table('v2_user')->where('id', 11)->update(['d' => -$gb]);
    try {
        DB::table('v2_user')->whereRaw('(u + d) < transfer_enable')->get();
        throw new RuntimeException('Mixed signed/unsigned quota query unexpectedly succeeded');
    } catch (Illuminate\Database\QueryException $error) {
        $check((int) ($error->errorInfo[1] ?? 0) === 1690, 'Reproduces mixed signed/unsigned quota error 1690');
    }
    DB::table('v2_user')->where('id', 11)->update(['d' => 0]);
    DB::statement('ALTER TABLE v2_user MODIFY d BIGINT UNSIGNED NOT NULL DEFAULT 0');
    $migration->up();
    $check(!str_contains($type(), 'unsigned'), 'Existing unsigned column becomes signed');
    $check(DB::table('v2_user')->orderBy('id')->get()->toJson() === $before, 'All account data is preserved');
    $check(DB::selectOne("SHOW TABLE STATUS WHERE Name = 'v2_user'")->Collation === $collation, 'Old utf8 table collation is preserved');
    $check(!str_contains(strtolower(DB::selectOne("SHOW COLUMNS FROM v2_user WHERE Field = 'u'")->Type), 'unsigned'), 'Upload counter also uses signed arithmetic');
    $column = DB::selectOne("SHOW COLUMNS FROM v2_user WHERE Field = 'd'");
    $check($column->Null === 'NO' && (string) $column->Default === '0', 'Nullability and default are preserved');

    DB::statement('CREATE TABLE panel_users (id INT PRIMARY KEY, email VARCHAR(64), credit BIGINT NOT NULL DEFAULT 100)');
    DB::table('panel_users')->insert([['id' => 1, 'email' => $admin->email], ['id' => 2, 'email' => $partner->email]]);
    DB::statement('CREATE TABLE panel_tariff_groups (id INT PRIMARY KEY, name VARCHAR(64), group_type VARCHAR(16), enable INT, is_default INT, sort_order INT)');
    DB::table('panel_tariff_groups')->insert(['id' => 1, 'name' => 'Time', 'group_type' => 'time', 'enable' => 1, 'is_default' => 1, 'sort_order' => 1]);
    // Modern purchases require the account's saved tariff, including legacy
    // utf8 accounts; a global default is deliberately not a fallback.
    DB::statement('ALTER TABLE v2_user ADD account_tariff_group_id INT NULL');
    DB::table('v2_user')->update(['account_tariff_group_id' => 1]);
    // Price and logging are fixtures; the real production topup and DB transaction run.
    $app->instance(App\Services\TransactionLogger::class, new class extends App\Services\TransactionLogger {
        public function start(array $data): int { return 0; }
    });
    $app->instance(App\Services\PanelPricingService::class, new class extends App\Services\PanelPricingService {
        public function ensureBalanceAndCharge(int $panelUserId, string $planKey, int $quantity = 1, ?int $tariffGroupId = null, ?int $subscriptionUserId = null): array {
            DB::table('panel_users')->where('id', $panelUserId)->decrement('credit', $quantity);
            return ['ok' => true, 'transaction_id' => null];
        }
    });
    foreach (['admin', 'partner'] as $mode) {
        foreach ([0, 4 * $gb, -$gb] as $initial) {
            DB::table('v2_user')->where('id', 11)->update(['d' => $initial]);
            if ($mode === 'admin') {
                $request = Request::create('/topup', 'POST', ['email' => $email, 'gb' => 1, 'tariff_group_id' => 1]);
                $request->setUserResolver(fn () => $admin);
                $response = (new App\Http\Controllers\Api\Admin\UserAdminController)->topup($request);
                $check($response->getStatusCode() === 200 && $response->getData(true)['status'] === 'ok', 'Actual admin topup succeeds');
            } else {
                $result = (new App\Services\PanelAccountActionService)->topupTrafficForPanelUser($partner, $email, 1, 1);
                $check($result['ok'] === true, 'Actual representative topup succeeds: ' . ($result['error'] ?? ''));
            }
            $row = DB::table('v2_user')->where('id', 11)->first();
            $check((int) $row->d === $initial - $gb, 'Topup preserves original signed-download behavior');
            $check((int) $row->u === 123 && (int) $row->transfer_enable === 10 * $gb, 'Topup leaves upload and plan quota unchanged');
            $check(DB::table('v2_user')->where('id', 11)->whereRaw('(u + d) < transfer_enable')->exists(), 'Purchased account remains eligible for node access');
        }
    }
    $check((int) DB::table('panel_users')->where('id', 2)->value('credit') === 97, 'Representative is charged once per successful purchase');
    $before = DB::table('v2_user')->orderBy('id')->get()->toJson();
    DB::enableQueryLog(); DB::flushQueryLog();
    $migration->up();
    $check(!array_filter(DB::getQueryLog(), fn ($query) => str_contains(strtolower($query['query']), 'alter table')), 'Already signed legacy schema requires no ALTER');
    $migration->down();
    $check(DB::table('v2_user')->orderBy('id')->get()->toJson() === $before, 'Rerun and rollback preserve negative balances');
    Schema::drop('v2_user');

    DB::statement('CREATE TABLE v2_user (id INT PRIMARY KEY, d BIGINT UNSIGNED NOT NULL DEFAULT 0)');
    DB::table('v2_user')->insert(['id' => 1, 'd' => '9223372036854775808']);
    try {
        $migration->up();
        throw new RuntimeException('Overflow guard did not reject conversion');
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'exceeds its range'), 'Overflowing existing data is explicitly rejected');
    }
    $check(str_contains($type(), 'unsigned') && (string) DB::table('v2_user')->value('d') === '9223372036854775808', 'Rejected conversion keeps schema and data intact');
    Schema::drop('v2_user');

    $core->up();
    $check(!str_contains($type(), 'unsigned'), 'Fresh installation creates signed download column');
    $check(!str_contains(strtolower(DB::selectOne("SHOW COLUMNS FROM v2_user WHERE Field = 'u'")->Type), 'unsigned'), 'Fresh installation creates signed upload column');
    DB::table('v2_user')->insert(['email' => $email, 'password' => 'test', 'uuid' => '00000000-0000-0000-0000-000000000001', 'token' => 'test', 'd' => -$gb, 'created_at' => 123, 'updated_at' => 123]);
    $migration->up(); $core->up();
    $check((int) DB::table('v2_user')->value('d') === -$gb, 'Fresh installation and repeated migrations support negative download');
    echo "Actual MySQL signed migration, data preservation, fresh install and admin/representative topup: {$checks} checks passed.\n";
} finally {
    $db->statement('USE mysql');
    $db->statement("DROP DATABASE `{$name}`");
}
