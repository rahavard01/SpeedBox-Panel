<?php

// Isolated real-MySQL integration test; never loads the project .env or live DB.
$root = dirname(__DIR__, 2);
$fallback = getenv('COMPATIBILITY_TEST_PROJECT_ROOT') ?: $root;
require getenv('PARTNER_TEST_AUTOLOAD') ?: $root . '/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root, $fallback) {
    if (!str_starts_with($class, 'App\\')) return;
    $relative = '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    foreach (array_unique([$root, $fallback]) as $directory) {
        if (is_file($directory . $relative)) { require_once $directory . $relative; return; }
    }
});

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

$port = getenv('LOOKUP_TEST_MYSQL_PORT');
if (!$port) throw new RuntimeException('LOOKUP_TEST_MYSQL_PORT must point to an isolated MySQL test instance');
$app = new Illuminate\Foundation\Application($root);
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['url' => 'https://new.test', 'locale' => 'en', 'fallback_locale' => 'en'],
    'database' => ['default' => 'mysql', 'connections' => ['mysql' => [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port, 'database' => 'mysql',
        'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '', 'strict' => true, 'options' => [PDO::ATTR_EMULATE_PREPARES => false],
    ]]],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
    'auth' => ['defaults' => ['guard' => 'test'], 'guards' => ['test' => ['driver' => 'test']]],
]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
foreach ([Illuminate\Events\EventServiceProvider::class, Illuminate\Database\DatabaseServiceProvider::class,
    Illuminate\Cache\CacheServiceProvider::class, Illuminate\Hashing\HashServiceProvider::class,
    Illuminate\Auth\AuthServiceProvider::class, Illuminate\Filesystem\FilesystemServiceProvider::class,
    Illuminate\Translation\TranslationServiceProvider::class, Illuminate\Validation\ValidationServiceProvider::class] as $provider) $app->register($provider);
$app->boot();
$app->instance('request', Request::create('http://test/'));
$app['auth']->viaRequest('test', fn () => null);
Request::macro('validate', function (array $rules) use ($app) { return $app['validator']->make($this->all(), $rules)->validate(); });
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory {
    public function __construct() {}
});
$files = [];
foreach (array_unique([$fallback, $root]) as $directory) {
    foreach (glob($directory . '/database/migrations/*.php') as $file) $files[basename($file)] = $file;
}
ksort($files);
$runMigration = function (string $file): void {
    $class = Illuminate\Support\Str::studly(implode('_', array_slice(explode('_', basename($file, '.php')), 4)));
    if (class_exists($class, false)) { (new $class)->up(); return; }
    $migration = require $file;
    if (is_object($migration)) $migration->up();
    else (new $class)->up();
};
$checks = 0; $migrations = 0;
$check = function ($value, $message) use (&$checks) { if (!$value) throw new RuntimeException($message); $checks++; };
$db = DB::connection(); $gb = 1073741824;

foreach (['fresh', 'legacy'] as $mode) {
    $name = 'xboard_compat_test_' . bin2hex(random_bytes(6));
    $db->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->statement("USE `{$name}`"); $db->setDatabaseName($name); Cache::flush();
    try {
        if ($mode === 'legacy') {
            // Existing XBoard signed counters and legacy utf8_general_ci table.
            (require $files['2025_01_01_000000_create_speedbox_core_tables.php'])->up();
            DB::statement('ALTER TABLE v2_user CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci');
            $legacyCollation = DB::selectOne("SHOW TABLE STATUS WHERE Name = 'v2_user'")->Collation;
            DB::table('v2_user')->insert(['email' => 'legacy@testrep.com', 'password' => 'existing-password',
                'uuid' => '11111111-1111-4111-8111-111111111111', 'token' => 'existing-token',
                'u' => 123, 'd' => -$gb, 'transfer_enable' => 20 * $gb, 'expired_at' => time() + 864000,
                'created_at' => 123, 'updated_at' => 456]);
            $legacy = DB::table('v2_user')->first();
            DB::table('v2_settings')->insert(['name' => 'subscribe_url', 'value' => 'https://existing.test']);
            DB::table('v2_settings')->insert(['name' => 'subscribe_path', 'value' => 'existing-path']);
            DB::statement('CREATE TABLE v2_stat_user (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, server_rate DECIMAL(10,2), u BIGINT, d BIGINT, record_type CHAR(1), record_at INT, created_at INT, updated_at INT)');
            DB::table('v2_stat_user')->insert(['user_id' => $legacy->id, 'server_rate' => 1, 'u' => 100, 'd' => 200, 'record_type' => 'd', 'record_at' => 123, 'created_at' => 123, 'updated_at' => 123]);
            // Existing reset history table is reused without deleting its rows.
            Schema::create('v2_traffic_reset_logs', function (Illuminate\Database\Schema\Blueprint $table) {
                $table->id(); $table->bigInteger('user_id'); $table->string('reset_type', 50); $table->timestamp('reset_time');
                foreach (['old_upload', 'old_download', 'old_total', 'new_upload', 'new_download', 'new_total'] as $column) $table->bigInteger($column)->default(0);
                $table->string('trigger_source', 50); $table->json('metadata')->nullable(); $table->timestamps();
            });
            DB::table('v2_traffic_reset_logs')->insert(['user_id' => $legacy->id, 'reset_type' => 'manual', 'reset_time' => now(),
                'trigger_source' => 'admin', 'old_download' => -123, 'old_total' => -123, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($files as $file) {
            $runMigration($file); $migrations++;
        }
        $check(Schema::hasTable('panel_users') && Schema::hasTable('v2_server') && Schema::hasTable('user_bot_orders'), "$mode full migration chain creates required tables");
        if ($mode === 'legacy') {
            $after = DB::table('v2_user')->where('id', $legacy->id)->first();
            foreach (['email', 'password', 'uuid', 'token', 'u', 'd', 'transfer_enable', 'expired_at', 'created_at', 'updated_at'] as $key) {
                $check($after->$key === $legacy->$key, 'Existing account preserves ' . $key);
            }
            $check(DB::table('v2_settings')->where('name', 'subscribe_url')->value('value') === 'https://existing.test', 'Existing subscription domain survives migration');
            $check(DB::table('v2_settings')->where('name', 'subscribe_path')->value('value') === 'existing-path', 'Existing subscription path survives migration');
            $check((int) DB::table('v2_stat_user')->where('record_at', 123)->value('d') === 200, 'Existing usage history survives migration');
            $check((int) DB::table('v2_traffic_reset_logs')->value('old_download') === -123, 'Existing negative reset history survives migration');
            $check(DB::selectOne("SHOW TABLE STATUS WHERE Name = 'v2_user'")->Collation === $legacyCollation, 'Existing collation survives full migration chain');
        }

        $adminId = DB::table('panel_users')->insertGetId(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'test', 'code' => 'admin', 'role' => 1, 'credit' => 100000]);
        $partnerId = DB::table('panel_users')->insertGetId(['name' => 'Partner', 'email' => 'partner@test.com', 'password' => 'test', 'code' => 'testrep', 'role' => 2, 'credit' => 100000, 'partner_plan_type' => 'both']);
        $admin = DB::table('panel_users')->where('id', $adminId)->first();
        $partner = DB::table('panel_users')->where('id', $partnerId)->first();
        $timeGroup = (int) DB::table('panel_tariff_groups')->where('group_type', 'time')->where('is_default', 1)->value('id');
        $volumeGroup = (int) DB::table('panel_tariff_groups')->where('group_type', 'volume')->where('is_default', 1)->value('id');
        $planId = DB::table('v2_plan')->insertGetId(['name' => 'Test', 'group_id' => 1, 'transfer_enable' => 20, 'capacity_limit' => null, 'device_limit' => 2, 'reset_traffic_method' => 2, 'created_at' => time(), 'updated_at' => time()]);
        foreach ([[$timeGroup, '1m'], [$timeGroup, 'gig'], [$volumeGroup, 'volume_gig']] as [$group, $key]) {
            DB::table('panel_plan')->updateOrInsert(['tariff_group_id' => $group, 'plan_key' => $key],
                ['name' => 'Test', 'default_price' => '100', 'enable' => 1, 'v2_plan_id' => $planId, 'device_limit' => 2]);
        }
        $created = app(App\Services\V2UserCreator::class)->createUsers('testrep', $planId, 1, '1m', false, null,
            ['account_group_type' => 'time', 'account_tariff_group_id' => $timeGroup, 'account_plan_key' => '1m'], $partnerId);
        $check(count($created['created']) === 1 && !$created['errors'], "$mode account creation works");
        $account = DB::table('v2_user')->where('id', $created['created'][0]['id'])->first();
        $check((int) $account->transfer_enable === 20 * $gb, 'Plan GB is converted to account bytes once');
        $check((bool) DB::table('panel_users')->where('id', $partnerId)->value('partner_code_locked'), 'Creation locks representative ownership code');

        $service = app(App\Services\PanelAccountActionService::class);
        $result = $service->topupTrafficForPanelUser($partner, $account->email, 1, $timeGroup);
        $check($result['ok'], "$mode representative purchase works: " . ($result['error'] ?? ''));
        $check((int) DB::table('v2_user')->where('id', $account->id)->value('d') === -$gb, 'Purchase supports negative download');
        $check((int) DB::table('panel_users')->where('id', $partnerId)->value('credit') === 99900, 'Real pricing debits representative exactly once');
        $check(DB::table('panel_transactions')->where('id', $result['transaction_id'])->value('status') === 'success', 'Real transaction is finalized');

        $node = App\Models\ProxyServer::create(['type' => 'vless', 'name' => 'Test', 'host' => 'node.test', 'port' => '443', 'server_port' => 443,
            'rate' => 1, 'group_ids' => [1], 'enabled' => true, 'protocol_settings' => []]);
        $nodes = app(App\Services\NodeControlService::class);
        $check($nodes->usersFor($node)->contains('id', $account->id), 'Real node user query includes purchased account');
        $nodes->report($node, ['traffic' => [$account->id => [100, 200]]]);
        $check((int) DB::table('v2_user')->where('id', $account->id)->value('d') === -$gb + 200, 'Real node report increments negative download correctly');
        $check((int) DB::table('v2_stat_user')->where('user_id', $account->id)->where('record_at', '>', 123)->value('d') === 200, 'Node report writes actual positive usage history');

        $botSetting = DB::table('user_bot_settings')->insertGetId(['owner_type' => 'partner', 'owner_id' => $partnerId]);
        $order = App\Models\UserBotOrder::create(['setting_id' => $botSetting, 'order_type' => 'buy_traffic', 'account_email' => $account->email,
            'traffic_gb' => 1, 'plan_key' => $timeGroup . '_traffic_gig', 'amount' => 100]);
        $result = app(App\Services\UserBot\UserBotOrderProcessor::class)->approve($order);
        $check($result['ok'] && $order->fresh()->status === 'done', 'Actual user bot completes traffic order');
        $check((int) DB::table('panel_users')->where('id', $partnerId)->value('credit') === 99800, 'Bot purchase charges once');
        $check($result['traffic_remaining_bytes'] === 22 * $gb - 300, 'Bot computes correct remaining traffic');
        $telegram = new App\Http\Controllers\Api\TelegramWebhookController;
        foreach (['findV2UserByEmailSafe', 'findV2UserByUsernameInput'] as $method) {
            $lookup = new ReflectionMethod($telegram, $method);
            $check($lookup->invoke($telegram, strtoupper($account->email))?->id === $account->id, 'Partner bot lookup works across character sets: ' . $method);
        }

        $result = $service->renewAccountForPanelUser($partner, $account->email, '1m', $timeGroup);
        $check($result['ok'], 'Real representative/bot renewal works: ' . ($result['error'] ?? ''));
        $check((int) DB::table('v2_user')->where('id', $account->id)->value('d') === 0, 'Configured renewal resets consumption');
        $check(DB::table('v2_traffic_reset_logs')->where('user_id', $account->id)->where('reset_type', 'renewal')->exists(), 'Renewal records reset history');
        $check(DB::table('speedbox_subscription_cycles')->where('user_id', $account->id)->exists(), 'Renewal records paid cycle without touching XBoard orders');

        foreach (['admin', 'partner'] as $role) {
            DB::table('v2_user')->where('id', $account->id)->update(['u' => 100, 'd' => -$gb]);
            App\Services\RuntimeSetting::set('renew_order_event_id', 0);
            $request = Request::create('/extend', 'POST', ['email' => $account->email, 'period' => '1m', 'tariff_group_id' => $timeGroup]);
            $owner = $role === 'admin' ? $admin : $partner;
            $request->setUserResolver(fn () => $owner);
            $controller = $role === 'admin' ? new App\Http\Controllers\Api\Admin\UserAdminController : new App\Http\Controllers\Api\V2UserController;
            $response = $controller->extend($request);
            $check($response->getStatusCode() === 200, "Actual $role panel renewal succeeds");
            $check((int) DB::table('v2_user')->where('id', $account->id)->value('d') === -$gb, 'Keep-consumption renewal preserves purchased traffic');
        }

        $volumePlanId = DB::table('v2_plan')->insertGetId(['name' => 'Volume', 'group_id' => 1, 'transfer_enable' => 20,
            'capacity_limit' => null, 'device_limit' => 2, 'reset_traffic_method' => 2, 'created_at' => time(), 'updated_at' => time()]);
        DB::table('panel_plan')->where('tariff_group_id', $volumeGroup)->where('plan_key', 'volume_gig')->update(['v2_plan_id' => $volumePlanId]);
        $volume = app(App\Services\V2UserCreator::class)->createUsers('testrep', $volumePlanId, 1, 'volume_gig', false, 20,
            ['account_group_type' => 'volume', 'account_tariff_group_id' => $volumeGroup, 'account_plan_key' => 'volume_gig'], $partnerId);
        $volumeId = $volume['created'][0]['id'];
        foreach (['admin', 'partner'] as $role) {
            foreach ([false, true] as $expired) {
                DB::table('v2_user')->where('id', $volumeId)->update(['u' => 100, 'd' => 0, 'transfer_enable' => 20 * $gb,
                    'expired_at' => time() + ($expired ? -1 : 86400)]);
                $row = DB::table('v2_user')->where('id', $volumeId)->first();
                if ($role === 'admin') {
                    $request = Request::create('/topup', 'POST', ['email' => $row->email, 'gb' => 10, 'tariff_group_id' => $volumeGroup]);
                    $request->setUserResolver(fn () => $admin);
                    $result = (new App\Http\Controllers\Api\Admin\UserAdminController)->topup($request)->getData(true);
                    $check($result['status'] === 'ok', 'Actual admin volume purchase succeeds');
                } else {
                    $result = $service->topupTrafficForPanelUser($partner, $row->email, 10, $volumeGroup);
                    $check($result['ok'], 'Actual representative volume purchase succeeds: ' . ($result['error'] ?? ''));
                }
                $row = DB::table('v2_user')->where('id', $volumeId)->first();
                $check((int) $row->d === ($expired ? 0 : -10 * $gb), 'Active/expired volume download follows original policy');
                $check((int) $row->transfer_enable === ($expired ? 10 : 20) * $gb && (int) $row->u === ($expired ? 0 : 100), 'Expired volume discards old remainder; active volume retains it');
                $check(abs((int) $row->expired_at - time() - 30 * 86400) < 5, 'Volume purchase sets one month validity');
            }
        }

        DB::table('v2_plan')->where('id', $planId)->update(['reset_traffic_method' => 0]);
        DB::table('v2_user')->where('id', $account->id)->update(['u' => 100, 'd' => -$gb, 'next_reset_at' => time() - 1]);
        app(App\Services\SubscriptionTrafficReset::class)->tick();
        $check((int) DB::table('v2_user')->where('id', $account->id)->value('d') === 0, 'Periodic reset handles negative downloaded traffic');
        $check(DB::table('v2_traffic_reset_logs')->where('user_id', $account->id)->where('reset_type', 'periodic')->where('old_download', -$gb)->exists(), 'Periodic reset retains negative previous usage in history');

        $credit = DB::table('panel_users')->where('id', $partnerId)->value('credit');
        $before = DB::table('v2_user')->where('id', $account->id)->first();
        $count = DB::table('panel_transactions')->count();
        DB::table('panel_users')->where('id', $partnerId)->update(['credit' => 0]);
        $failed = $service->topupTrafficForPanelUser($partner, $account->email, 1, $timeGroup);
        $check(!$failed['ok'] && $failed['insufficient_credit'], 'Insufficient wallet prevents purchase');
        $check((int) DB::table('v2_user')->where('id', $account->id)->value('d') === (int) $before->d && DB::table('panel_transactions')->count() === $count, 'Rejected purchase changes neither traffic nor transaction history');
        DB::table('panel_users')->where('id', $partnerId)->update(['credit' => $credit]);
        echo "$mode: full migration chain and actual account, wallet, renewal, node and bot paths passed.\n";
    } finally {
        $db->statement('USE mysql'); $db->setDatabaseName('mysql');
        $db->statement("DROP DATABASE `{$name}`");
    }
}
echo "MySQL XBoard compatibility: {$migrations} migration executions, {$checks} checks passed.\n";
