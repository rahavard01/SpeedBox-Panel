<?php

// Run with: php scripts/tests/partner-accounts.test.php
// Uses a separate in-memory SQLite database; it never boots the project .env.
$root = dirname(__DIR__, 2);
require getenv('PARTNER_TEST_AUTOLOAD') ?: $root . '/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root) {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require_once $path;
    }
});

use App\Http\Controllers\Api\Admin\PartnersAdminController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\Api\PartnerAccountReadController;
use App\Services\AccountOnlineStatus;
use App\Services\PanelAccountOwnership;
use App\Services\V2UserCreator;
use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$app = new Application($root);
$app->instance('env', 'testing');
$app->instance('config', new Repository([
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
    'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
    'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
]));
Facade::setFacadeApplication($app);
foreach ([Illuminate\Routing\RoutingServiceProvider::class, Illuminate\View\ViewServiceProvider::class, Illuminate\Filesystem\FilesystemServiceProvider::class, Illuminate\Events\EventServiceProvider::class, Illuminate\Database\DatabaseServiceProvider::class,
    Illuminate\Translation\TranslationServiceProvider::class, Illuminate\Validation\ValidationServiceProvider::class,
    Illuminate\Hashing\HashServiceProvider::class, Illuminate\Cache\CacheServiceProvider::class] as $provider) $app->register($provider);
$app->instance('request', Request::create('http://partner.test/'));
Request::macro('validate', function (array $rules) use ($app) { return $app['validator']->make($this->all(), $rules)->validate(); });
$checks = 0;
$check = function (bool $value, string $message) use (&$checks) { if (!$value) throw new RuntimeException($message); $checks++; };
$reject = function (callable $call, string $field = 'panel_code') use ($check) {
    try { $call(); } catch (ValidationException $error) { $check(isset($error->errors()[$field]), 'Expected field error: ' . $field); return; }
    throw new RuntimeException('Expected validation rejection');
};
$httpReject = function (callable $call, int $status) use ($check) {
    try { $call(); } catch (HttpException $error) { $check($error->getStatusCode() === $status, 'Expected HTTP ' . $status); return; }
    throw new RuntimeException('Expected access rejection');
};
$request = function (object $owner, array $data = [], string $method = 'GET'): Request {
    $request = Request::create('/test', $method, $data); $request->setUserResolver(fn () => $owner); return $request;
};
$json = fn (JsonResponse $response) => $response->getData(true);
Schema::create('panel_users', function (Blueprint $table) {
    $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('code')->nullable()->unique();
    $table->integer('role')->default(2); $table->string('password')->default('test');
    $table->unsignedInteger('referred_by_id')->nullable(); $table->float('ref_commission_rate')->nullable();
    $table->string('partner_plan_type')->default('time'); $table->timestamps();
});
(require $root . '/database/migrations/2025_01_01_000000_create_speedbox_core_tables.php')->up();
Schema::table('v2_user', function (Blueprint $table) {
    $table->string('account_plan_name')->nullable(); $table->string('account_group_type')->nullable();
    $table->integer('account_tariff_group_id')->nullable(); $table->string('account_plan_key')->nullable();
    $table->integer('reset_count')->default(0); $table->integer('last_reset_at')->nullable(); $table->integer('next_reset_at')->nullable();
});
Schema::create('panel_transactions', function (Blueprint $table) {
    $table->id(); $table->integer('panel_user_id'); $table->string('type'); $table->string('status'); $table->json('meta')->nullable();
});
(require $root . '/database/migrations/2026_10_10_000003_create_connection_admission_leases.php')->up();
(require $root . '/database/migrations/2026_10_11_000001_create_account_operation_history.php')->up();
$owner = function (string $code, int $role = 2): object {
    $id = DB::table('panel_users')->insertGetId(['name' => $code, 'email' => ($code ?: 'blank') . '@panel.test', 'code' => $code, 'role' => $role]);
    return DB::table('panel_users')->where('id', $id)->first();
};
$insertAccount = function (string $email): int {
    return DB::table('v2_user')->insertGetId(['email' => $email, 'password' => 'secret', 'uuid' => Illuminate\Support\Str::uuid(),
        'token' => bin2hex(random_bytes(16)), 'created_at' => time(), 'updated_at' => time(), 'device_limit' => 2]);
};
$legacy = $owner('legacy'); $deleted = $owner('deleted'); $fresh = $owner('fresh'); $other = $owner('other'); $blank = $owner('');
$admin = $owner('admin', 1); $metaOwner = $owner('metaowner');
$legacyId = $insertAccount('renamed@legacy.com'); $otherId = $insertAccount('private@other.com');
DB::table('panel_transactions')->insert([
    ['panel_user_id' => $deleted->id, 'type' => 'account_purchase', 'status' => 'success', 'meta' => null],
    ['panel_user_id' => $fresh->id, 'type' => 'account_purchase', 'status' => 'failed', 'meta' => null],
    ['panel_user_id' => $admin->id, 'type' => 'account_purchase', 'status' => 'success', 'meta' => json_encode(['panel_code' => 'metaowner'])],
]);
(require $root . '/database/migrations/2026_10_11_000002_lock_partner_codes_after_account_creation.php')->up();
foreach ([$legacy, $deleted, $metaOwner] as $locked) $check((bool) DB::table('panel_users')->where('id', $locked->id)->value('partner_code_locked'), 'Migration preserves legacy ownership');
$check(!DB::table('panel_users')->where('id', $fresh->id)->value('partner_code_locked'), 'Failed purchases do not freeze code');
$profiles = new PartnersAdminController;
$profiles->updateProfile($request($admin, ['panel_code' => 'newfresh'], 'PATCH'), $fresh->id);
$check(DB::table('panel_users')->where('id', $fresh->id)->value('code') === 'newfresh', 'Code editable before first creation');
$reject(fn () => $profiles->updateProfile($request($admin, ['panel_code' => '   '], 'PATCH'), $fresh->id));
$reject(fn () => $profiles->updateProfile($request($admin, ['panel_code' => 'OTHER'], 'PATCH'), $fresh->id));
$reject(fn () => $profiles->updateProfile($request($admin, ['panel_code' => 'changed'], 'PATCH'), $deleted->id));
$planId = DB::table('v2_plan')->insertGetId(['name' => 'Test', 'group_id' => 1, 'transfer_enable' => 10, 'device_limit' => 2,
    'capacity_limit' => 100, 'reset_traffic_method' => 2, 'created_at' => time(), 'updated_at' => time()]);
$creator = new V2UserCreator;
$creator->createUsers('newfresh', 99999, 1, '1m', true, panelOwnerId: $fresh->id);
$check(!DB::table('panel_users')->where('id', $fresh->id)->value('partner_code_locked'), 'Failed creation leaves code editable');
$reject(fn () => $creator->createUsers('fresh', $planId, 1, '1m', true, panelOwnerId: $fresh->id));
$result = $creator->createUsers('newfresh', $planId, 1, '1m', true, panelOwnerId: $fresh->id);
$check(count($result['created']) === 1, 'Actual creator inserts account');
$check((bool) DB::table('panel_users')->where('id', $fresh->id)->value('partner_code_locked'), 'Creation freezes code atomically');
DB::table('v2_user')->where('id', $result['created'][0]['id'])->delete();
$reject(fn () => $profiles->updateProfile($request($admin, ['panel_code' => 'changed'], 'PATCH'), $fresh->id));
$profiles->updateProfile($request($admin, ['name' => 'Updated name', 'panel_code' => 'newfresh'], 'PATCH'), $fresh->id);
$check(DB::table('panel_users')->where('id', $fresh->id)->value('name') === 'Updated name', 'Other profile fields remain editable');
// Literal code matching, rather than substring or SQL wildcard ownership.
$wild = $insertAccount('a@a_b.com'); $near = $insertAccount('a@axb.com');
$check(PanelAccountOwnership::scope(DB::table('v2_user'), 'a_b')->pluck('id')->all() === [$wild], 'SQL wildcards in code are literal');
$check(!PanelAccountOwnership::scope(DB::table('v2_user'), '')->exists(), 'Empty scope denies all');
$check(PanelAccountOwnership::matches('name@LEGACY.net', 'legacy') && !PanelAccountOwnership::matches('name@mylegacy.com', 'legacy'), 'Exact normalized domain code');
// Online count uses distinct active IPs and never returns another owner's data.
foreach ([[1, '1.1.1.1', time()+60], [2, '1.1.1.1', time()+60], [1, '2.2.2.2', time()+60], [1, '3.3.3.3', time()-1]] as [$node, $ip, $expires]) {
    DB::table('speedbox_connection_leases')->insert(['user_id' => $legacyId, 'node_id' => $node, 'source_ip' => $ip, 'expires_at' => $expires, 'lease_id' => bin2hex(random_bytes(8))]);
}
$read = new PartnerAccountReadController;
$response = $read->details($request($legacy), $legacyId);
$details = $json($response)['data'];
$check($details['id'] === $legacyId && $details['email'] === 'renamed@legacy.com', 'Owner can refresh their account');
$check(!array_key_exists('password', $details) && !array_key_exists('uuid', $details), 'Details omit private authentication fields');
$check($response->headers->get('Cache-Control') === 'no-store, private', 'Fresh account reads are not cached');
$httpReject(fn () => $read->details($request($legacy), $otherId), 404);
$httpReject(fn () => $read->details($request($blank), $legacyId), 403);
$check($json($read->details($request($admin), $otherId))['data']['id'] === $otherId, 'Admin can refresh every account');
DB::table('v2_user')->where('id', $legacyId)->update(['u' => 0, 'd' => 0, 'transfer_enable' => 20 * 1073741824,
    'expired_at' => 1900000000, 'device_limit' => 4, 'reset_count' => 5, 'account_plan_name' => 'New tariff',
    'account_group_type' => 'volume', 'account_tariff_group_id' => 7, 'account_plan_key' => 'volume_gig']);
$details = $json($read->details($request($legacy), $legacyId))['data'];
$check($details['u'] === 0 && $details['d'] === 0 && $details['transfer_enable'] === 20 * 1073741824 && $details['expired_at'] === 1900000000, 'Refresh includes quota, consumption and expiry');
$check($details['account_plan_name'] === 'New tariff' && $details['account_is_volume'] === 1 && $details['device_limit'] === 4 && $details['reset_count'] === 5, 'Refresh includes tariff, device limit and reset count');
Schema::create('panel_tariff_groups', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('group_type'); });
Schema::create('panel_plan', function (Blueprint $table) { $table->id(); $table->integer('tariff_group_id'); $table->integer('v2_plan_id'); $table->string('plan_key'); });
DB::table('panel_tariff_groups')->insert(['id' => 7, 'name' => 'Imported tariff', 'group_type' => 'volume']);
DB::table('panel_plan')->insert(['tariff_group_id' => 7, 'v2_plan_id' => $planId, 'plan_key' => 'volume_gig']);
DB::table('v2_user')->where('id', $otherId)->update(['plan_id' => $planId]);
$details = $json($read->details($request($admin), $otherId))['data'];
$check($details['account_plan_name'] === 'Imported tariff' && $details['account_is_volume'] === 1 && $details['account_tariff_group_id'] === 7, 'Imported accounts retain tariff fallback');
DB::table('v2_user')->where('id', $legacyId)->update(['device_limit' => 2]);
$data = $json($read->online($request($legacy, ['ids' => [$legacyId]], 'POST'), new AccountOnlineStatus));
$check($data['data'][0]['online_count'] === 2 && $data['data'][0]['device_limit'] === 2, 'Distinct active IP count and quota');
$httpReject(fn () => $read->online($request($legacy, ['ids' => [$legacyId, $otherId]], 'POST'), new AccountOnlineStatus), 404);
$httpReject(fn () => $read->online($request($blank, ['ids' => [$legacyId]], 'POST'), new AccountOnlineStatus), 403);
$reject(fn () => $read->online($request($legacy, ['ids' => [$legacyId, $legacyId]], 'POST'), new AccountOnlineStatus), 'ids.0');
for ($i = 0; $i < 12; $i++) DB::table('v2_traffic_reset_logs')->insert(['user_id' => $legacyId, 'reset_type' => ['manual','periodic','renewal'][$i%3],
    'reset_time' => '2026-10-11 00:00:00', 'old_total' => 1234, 'new_total' => 0, 'trigger_source' => 'admin', 'metadata' => json_encode(['operator_email' => 'private@admin.test'])]);
DB::table('v2_traffic_reset_logs')->insert(['user_id' => $otherId, 'reset_type' => 'manual', 'reset_time' => '2026-10-11 00:00:00', 'trigger_source' => 'admin']);
Paginator::currentPageResolver(fn () => 1);
$history = $json($read->resetHistory($request($legacy), $legacyId));
$check(count($history['data']) === 10 && $history['pagination']['total'] === 12 && $history['data'][0]['id'] === 12, 'Newest first and ten per page');
$check($history['account']['reset_count'] === 5 && array_key_exists('last_reset_at', $history['account']) && array_key_exists('next_reset_at', $history['account']), 'History includes full reset summary');
$check($history['data'][0]['trigger_source'] === 'admin', 'History exposes source category without operator identity');
$check(!isset($history['data'][0]['metadata']) && !isset($history['account']['token']), 'History omits internal data and credentials');
Paginator::currentPageResolver(fn () => 2);
$history = $json($read->resetHistory($request($legacy, ['page' => 2]), $legacyId));
$check(count($history['data']) === 2 && $history['data'][0]['id'] === 2, 'Second page has remaining owner records');
$httpReject(fn () => $read->resetHistory($request($legacy), $otherId), 404);
$httpReject(fn () => $read->resetHistory($request($blank), $legacyId), 403);
$panel = new PanelController;
$check($json($panel->findByEmail($request($blank, ['email' => 'renamed@legacy.com'])))['data'] === [], 'Blank code cannot retrieve token by email');
$token = DB::table('v2_user')->where('id', $legacyId)->value('token');
$check($json($panel->findByToken($request($blank, ['token' => $token])))['data'] === [], 'Blank code cannot retrieve by token');
$check($panel->updateBan($request($blank, ['email' => 'renamed@legacy.com', 'banned' => 1], 'POST'))->getStatusCode() === 403, 'Blank code cannot ban');
$check($panel->trafficRecords($request($blank, ['email' => 'renamed@legacy.com']))->getStatusCode() === 403, 'Blank code cannot read traffic');
$check($panel->transactionRecords($request($blank, ['email' => 'renamed@legacy.com']))->getStatusCode() === 403, 'Blank code cannot read transactions');
$check((int) DB::table('v2_user')->where('id', $legacyId)->value('banned') === 0, 'Rejected mutation leaves account intact');
echo "Partner code lifecycle, migration, owner isolation, online counts and history: {$checks} checks passed.\n";
