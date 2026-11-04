<?php
/** Isolated SQLite regression checks. Never reads .env or connects to the panel DB. */
$root = dirname(__DIR__, 2);
require getenv('SPEEDBOX_TEST_AUTOLOAD') ?: $root.'/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root) {
    if (str_starts_with($class, 'App\\')) {
        $path = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($path)) require_once $path;
    }
}, true, true);

use App\Http\Controllers\Api\Admin\InfrastructureController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Models\SubscriptionAccount;
use App\Services\RuntimeSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Facade, Schema};

$app = new Illuminate\Foundation\Application($root);
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['timezone' => 'Asia/Tehran'],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
]));
$app->instance('events', new Illuminate\Events\Dispatcher($app));
Facade::setFacadeApplication($app);
foreach ([Illuminate\Database\DatabaseServiceProvider::class, Illuminate\Cache\CacheServiceProvider::class] as $provider) $app->register($provider);
Illuminate\Database\Eloquent\Model::setConnectionResolver($app['db']);
$app->instance('translator', new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'));
$app->register(Illuminate\Validation\ValidationServiceProvider::class);
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, (new ReflectionClass(Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());
Request::macro('validate', function ($rules) { return app('validator')->make($this->all(), $rules)->validate(); });

Schema::create('v2_server_group', function (Blueprint $t) { $t->id(); });
Schema::create('v2_plan', function (Blueprint $t) {
    $t->id(); $t->string('name'); $t->integer('sort')->default(0);
    foreach (['group_id', 'transfer_enable', 'speed_limit', 'device_limit', 'capacity_limit', 'reset_traffic_method', 'created_at', 'updated_at'] as $field) $t->bigInteger($field)->nullable();
    $t->text('tags')->nullable();
});
Schema::create('v2_user', function (Blueprint $t) {
    $t->id(); $t->string('email'); $t->string('uuid')->default('fixture');
    foreach (['plan_id', 'group_id', 'transfer_enable', 'speed_limit', 'device_limit', 'u', 'd', 'expired_at', 'next_reset_at', 'account_tariff_group_id', 'account_reset_traffic_method'] as $field) $t->bigInteger($field)->nullable();
    $t->string('account_plan_key')->nullable(); $t->string('account_plan_name')->nullable(); $t->string('account_group_type')->nullable(); $t->boolean('banned')->default(false);
});
Schema::create('panel_tariff_groups', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('group_type'); });
Schema::create('panel_plan', function (Blueprint $t) { $t->id(); $t->integer('tariff_group_id'); $t->integer('v2_plan_id'); $t->string('plan_key'); $t->integer('device_limit')->nullable(); });
Schema::create('v2_settings', function (Blueprint $t) { $t->string('name')->primary(); $t->text('value')->nullable(); $t->timestamps(); });
Schema::create('speedbox_connection_leases', function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->string('source_ip'); $t->bigInteger('expires_at'); });
Schema::create('speedbox_subscription_reservations', function (Blueprint $t) {
    $t->id(); $t->integer('user_id'); $t->integer('reserved_user_id')->nullable(); $t->string('state'); $t->text('snapshot'); $t->boolean('trigger_on_quota')->default(true); $t->integer('bot_order_id')->nullable();
});
DB::table('v2_server_group')->insert([['id' => 1], ['id' => 2]]);
DB::table('panel_tariff_groups')->insert([['id' => 10, 'name' => 'Sale A', 'group_type' => 'time'], ['id' => 20, 'name' => 'Sale B', 'group_type' => 'time']]);
DB::table('panel_plan')->insert([['tariff_group_id' => 10, 'v2_plan_id' => 1, 'plan_key' => '1m', 'device_limit' => null], ['tariff_group_id' => 20, 'v2_plan_id' => 1, 'plan_key' => '1m', 'device_limit' => 5]]);
$defaults = ['name' => 'Main A', 'group_id' => 1, 'transfer_enable' => 1, 'speed_limit' => 10, 'device_limit' => 2, 'reset_traffic_method' => 2];
DB::table('v2_plan')->insert(['id' => 1, ...$defaults]);
DB::table('v2_plan')->insert(['id' => 2, ...$defaults, 'name' => 'Main B']);
foreach ([1 => 10, 2 => 20, 3 => 10] as $id => $sale) {
    DB::table('v2_user')->insert(['id' => $id, 'email' => 'user'.$id.'@fixture.test', 'plan_id' => $id === 3 ? 2 : 1,
        'group_id' => 9, 'transfer_enable' => ($id * 30 + 30) * 1073741824, 'speed_limit' => 77, 'device_limit' => $sale === 20 ? 5 : 7,
        'u' => 1234, 'd' => 5678, 'expired_at' => time() + 864000, 'next_reset_at' => time() + 86400,
        'account_tariff_group_id' => $sale, 'account_plan_key' => '1m', 'account_plan_name' => 'Frozen sale', 'account_group_type' => 'time']);
}
$controller = (new ReflectionClass(InfrastructureController::class))->newInstanceWithoutConstructor();
$checks = 0;
$check = function ($condition, $message) use (&$checks) { if (!$condition) throw new RuntimeException($message); $checks++; };
$snapshot = fn () => json_decode(json_encode(DB::table('v2_user')->orderBy('id')->get()), true);
$save = function ($changes = [], $force = true) use ($controller) {
    $plan = (array) DB::table('v2_plan')->find(1);
    return $controller->savePlan(Request::create('/', 'POST', [...array_intersect_key($plan, array_flip(['name', 'group_id', 'transfer_enable', 'speed_limit', 'device_limit', 'reset_traffic_method'])), ...$changes, 'force_update' => $force]), 1);
};
$before = $snapshot();
$save(['device_limit' => 3]);
$after = $snapshot();
$expected = $before; $expected[0]['device_limit'] = 3;
$check($after === $expected, 'Changing device limit must preserve custom quota/speed/group/usage/expiry and explicit sale overrides.');
$save(['name' => 'Renamed']);
$check($snapshot() === $after, 'Name-only edits with force must not rewrite accounts.');
$save();
$check($snapshot() === $after, 'Force with no changed default must be a no-op for accounts.');
$save(['transfer_enable' => 2]);
$expected = $after; $expected[0]['transfer_enable'] = $expected[1]['transfer_enable'] = 2 * 1073741824;
$check($snapshot() === $expected, 'Intentional quota changes must update only quota of this main plan.');
$save(['speed_limit' => null]);
$expected[0]['speed_limit'] = $expected[1]['speed_limit'] = null;
$check($snapshot() === $expected, 'Clearing speed limit must not rewrite any other field.');
$save(['group_id' => 2, 'device_limit' => 4]);
$expected[0]['group_id'] = $expected[1]['group_id'] = 2; $expected[0]['device_limit'] = 4;
$check($snapshot() === $expected, 'Multiple edits must apply just their fields and preserve tariff overrides.');
$save(['device_limit' => 9], false);
$check($snapshot() === $expected && (int) DB::table('v2_plan')->find(1)->device_limit === 9, 'Without force, only the plan default changes.');

$response = (new UserAdminController())->index(Request::create('/'))->getData(true);
$check(array_column($response['meta']['filters']['sales_plans'], 'id') === [10, 20], 'Sales filter metadata must use sales groups.');
$check(array_column($response['meta']['filters']['main_plans'], 'id') === [1, 2], 'Main-plan filter metadata must use actual infrastructure plans.');
$check((int) $response['data'][0]['account_tariff_group_id'] === 10 && (int) $response['data'][0]['plan_id'] === 2, 'Account payload must keep independent sale and main-plan IDs.');

RuntimeSetting::set('show_info_to_server_enable', true);
$user = (new SubscriptionAccount())->setRawAttributes(['id' => 1, 'email' => 'user1@fixture.test', 'u' => 1073741824, 'd' => 2147483648, 'transfer_enable' => 10 * 1073741824, 'expired_at' => time() + 864000, 'banned' => true]);
$decorate = new ReflectionMethod(SubscriptionController::class, 'decorateServers');
$names = fn () => array_column($decorate->invoke(new SubscriptionController(), [['name' => '🇩🇪 Node', 'type' => 'vless']], $user), 'name');
$check(str_contains(implode('|', $names()), 'Traffic  ：7 GB') && !str_contains(implode('|', $names()), 'Remaining Traffic'), 'Only the traffic label changes; arithmetic stays the same.');
$check(str_contains(implode('|', $names()), '⛔️️ Expire'), 'No reservation must keep the ordinary expiry icon.');
$frozen = json_encode(['update' => ['account_reset_traffic_method' => 2, 'account_plan_name' => 'Reserved', 'transfer_enable' => 50 * 1073741824], 'title' => 'Reserved', 'duration_days' => 30]);
DB::table('speedbox_subscription_reservations')->insert(['id' => 1, 'user_id' => 1, 'reserved_user_id' => 1, 'state' => 'reserved', 'snapshot' => $frozen]);
$check(str_contains(implode('|', $names()), '®️ Expire') && !str_contains(implode('|', $names()), '🟢'), 'Reserved expiry uses the registered emoji alone.');
foreach (['activated', 'cancelled'] as $state) {
    DB::table('speedbox_subscription_reservations')->where('id', 1)->update(['state' => $state, 'reserved_user_id' => null]);
    $check(str_contains(implode('|', $names()), '⛔️️ Expire') && !str_contains(implode('|', $names()), '®️ Expire'), $state.' must restore the ordinary icon.');
}
echo "PASS: {$checks} isolated plan, filter metadata and subscription regression checks.\n";
