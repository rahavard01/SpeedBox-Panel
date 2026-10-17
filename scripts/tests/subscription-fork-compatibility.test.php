<?php

// No bootstrap/app.php, .env or real database: all writes use SQLite :memory:.
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Enable pdo_sqlite in the CLI php.ini to run this isolated test.');
date_default_timezone_set('Asia/Tehran');

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Services\RuntimeSetting;
use App\Services\SubscriptionFilter;
use App\Services\Plugin\HookManager;
use App\Http\Controllers\Api\SubscriptionController;

$app = new Illuminate\Foundation\Application($root);
$app->instance('env', 'testing');
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['url' => 'https://configured.example', 'timezone' => 'Asia/Tehran', 'locale' => 'en', 'fallback_locale' => 'en',
        'key' => 'base64:'.base64_encode(random_bytes(32)), 'cipher' => 'AES-256-CBC'],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    'auth' => ['defaults' => ['guard' => 'test'], 'guards' => ['test' => ['driver' => 'subscription-test']]],
    'services' => ['speedbox' => ['sub_key' => 'fixture-speedbox-key']],
]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
foreach ([Illuminate\Events\EventServiceProvider::class, Illuminate\Database\DatabaseServiceProvider::class,
    Illuminate\Cache\CacheServiceProvider::class, Illuminate\Auth\AuthServiceProvider::class,
    Illuminate\Filesystem\FilesystemServiceProvider::class, Illuminate\Translation\TranslationServiceProvider::class,
    Illuminate\Validation\ValidationServiceProvider::class,
    Illuminate\Routing\RoutingServiceProvider::class,
    Illuminate\Encryption\EncryptionServiceProvider::class] as $provider) $app->register($provider);
$app->instance('request', Request::create('http://current.example/'));
$app['auth']->viaRequest('subscription-test', fn () => null);
$app->scoped(App\Services\Plugin\HookRegistry::class);
$app->scoped('protocols.manager', fn ($app) => (new App\Support\ProtocolManager($app))->registerAllProtocols());
$app->boot();
Request::macro('validate', function (array $rules) use ($app) {
    return $app['validator']->make($this->all(), $rules)->validate();
});
$factory = new class extends Illuminate\Routing\ResponseFactory {
    public array $lastView = [];
    public function __construct() {}
    public function view($view, $data = [], $status = 200, array $headers = []) {
        $this->lastView = $data;
        return $this->make('portal', $status, $headers);
    }
};
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, $factory);
$checks = 0;
$check = function ($ok, string $message) use (&$checks) {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$private = function (string $method, ...$arguments) {
    return (new ReflectionMethod(SubscriptionController::class, $method))->invoke(new SubscriptionController, ...$arguments);
};
$request = function (array $input = [], array $headers = []) use ($app) {
    $r = Request::create('http://current.example/s/'.str_repeat('a', 32), 'GET', $input);
    foreach ($headers as $key => $value) $r->headers->set($key, $value);
    $app->instance('request', $r);
    return $r;
};

Schema::create('v2_user', function ($t) {
    $t->id(); $t->string('token'); $t->string('uuid'); $t->string('email');
    foreach (['group_id', 'plan_id', 'expired_at', 'next_reset_at', 'device_limit'] as $c) $t->bigInteger($c)->nullable();
    foreach (['u', 'd', 'transfer_enable', 'banned'] as $c) $t->bigInteger($c)->default(0);
    $t->bigInteger('created_at'); $t->bigInteger('updated_at');
});
Schema::create('v2_settings', function ($t) {
    $t->id(); $t->string('name')->unique(); $t->text('value')->nullable(); $t->string('group')->nullable(); $t->timestamps();
});
Schema::create('v2_plan', function ($t) { $t->id(); $t->integer('reset_traffic_method')->nullable(); });
Schema::create('v2_server', function ($t) {
    $t->id(); $t->string('name'); $t->string('type'); $t->string('host'); $t->string('port');
    $t->bigInteger('parent_id')->nullable(); $t->bigInteger('sort')->default(0);
    $t->boolean('show')->default(true); $t->boolean('enabled')->default(true);
    foreach (['group_ids', 'tags', 'protocol_settings', 'rate_time_ranges'] as $c) $t->text($c)->nullable();
    $t->float('rate')->default(1); $t->boolean('rate_time_enable')->default(false);
    $t->bigInteger('u')->default(0); $t->bigInteger('d')->default(0); $t->bigInteger('transfer_enable')->nullable();
    $t->timestamps();
});
Schema::create('speedbox_connection_leases', function ($t) { $t->id(); $t->bigInteger('user_id'); $t->string('source_ip'); $t->bigInteger('expires_at'); });
Schema::create('speedbox_emergency_configs', function ($t) {
    $t->id(); $t->string('name'); $t->string('protocol'); $t->text('config_uri');
    $t->boolean('show')->default(true); $t->integer('sort')->default(0); $t->timestamps();
});
$gb = 1073741824;
DB::table('v2_plan')->insert(['id' => 1, 'reset_traffic_method' => 2]);
DB::table('v2_user')->insert(['id' => 1, 'token' => str_repeat('a', 32), 'uuid' => '11111111-1111-4111-8111-111111111111',
    'email' => 'user@example.com', 'group_id' => 1, 'plan_id' => 1, 'device_limit' => 2,
    'transfer_enable' => 20*$gb, 'expired_at' => time()+864000, 'created_at' => time(), 'updated_at' => time()]);
$account = fn () => App\Models\SubscriptionAccount::findOrFail(1);
$node = function (int $id, string $name, string $type, array $extra = []) {
    DB::table('v2_server')->insert(array_replace([
        'id' => $id, 'name' => $name, 'type' => $type, 'host' => '1.1.1.1', 'port' => '443',
        'group_ids' => json_encode(['1']), 'tags' => '[]', 'protocol_settings' => json_encode(['network' => 'tcp', 'tls' => 0]),
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ], $extra));
};
$node(1, '🇮🇷 Iran', 'vless');
$node(2, '🇩🇪 Germany', 'vmess', ['tags' => json_encode(['DE'])]);
$node(3, '🇹🇷 Turkey', 'trojan');

foreach (['all' => 3, 'vless|vmess' => 2, 'vless｜vmess' => 2, 'vless, vmess' => 2, 'invalid' => 3] as $input => $expected) {
    $servers = $private('serversFor', $account());
    $selected = array_filter($servers, fn ($s) => SubscriptionFilter::matches($s, SubscriptionFilter::types($input), null));
    $check(count($selected) === $expected, 'Fork types: '.$input);
}
foreach (['Germany|Turkey' => 2, 'Germany,Turkey' => 2, 'DE' => 1, 'germany' => 1, str_repeat('x',21) => 3] as $input => $expected) {
    $selected = array_filter($private('serversFor', $account()), fn ($s) => SubscriptionFilter::matches($s, [], SubscriptionFilter::keywords($input)));
    $check(count($selected) === $expected, 'Fork keywords and tags: '.$input);
}
foreach (['Clash.Meta/1.18.0' => ['meta','1.18.0'], 'HiddifyNext/2.5.7' => ['hiddify','2.5.7'],
    'sing-box/1.4.0' => ['sing-box','1.4.0'], 'Shadowrocket/2.2.0' => ['shadowrocket','2.2.0']] as $ua => $expected) {
    $info = $private('getClientInfo', $request([], ['User-Agent' => $ua]));
    $check([$info['name'],$info['version']] === $expected, 'Fork UA metadata: '.$ua);
}
$info = $private('getClientInfo', $request(['flag' => null], ['User-Agent' => 'V2RayN/6.30.0']));
$check($info['version'] === '6.30.0', 'Null flag falls back to UA');
$html = ['Accept' => 'TEXT/HTML', 'Sec-Fetch-Dest' => 'DOCUMENT'];
$check($private('shouldRenderSubscriptionPage', $request([], $html)), 'Browser headers normalized');
$check(!$private('shouldRenderSubscriptionPage', $request([], $html+['User-Agent'=>'Clash.Meta/1.18.0'])), 'Known client never gets browser portal');
$check(!$private('shouldRenderSubscriptionPage', $request(['raw'=>1], $html)), 'Raw bypasses browser portal');
$check(!$private('shouldRenderSubscriptionPage', $request(['flag'=>'general'], $html)), 'Explicit flag bypasses portal');

$controller = new SubscriptionController;
$check($controller->show($request())->getStatusCode() === 403, 'Empty token returns 403');
$invalid = $controller->show($request(['token'=>'wrong']));
$check($invalid->getStatusCode() === 403 && $invalid->getData(true)['message'] === 'token is error', 'Invalid token response');
foreach (['types','filter','flag'] as $field) {
    try { $controller->show($request([$field=>['bad']]), str_repeat('a',32)); throw new RuntimeException('Array input accepted: '.$field); }
    catch (Illuminate\Validation\ValidationException $e) { $check(isset($e->errors()[$field]), 'String validation: '.$field); }
}
foreach ([['expired_at'=>time()], ['expired_at'=>time()-1], ['banned'=>1], ['transfer_enable'=>0]] as $change) {
    $user = $account(); foreach ($change as $key=>$value) $user->$key = $value;
    $check(!$private('isAvailable', $user), 'Unavailable account boundary: '.json_encode($change));
}
$user=$account(); $user->expired_at=null;
$check($private('isAvailable',$user), 'NULL expiry is unlimited');
$user->expired_at=0;
$check($private('isAvailable',$user), 'Zero expiry is unlimited for every account');
$check($private('isAvailable',$account()), 'Future expiry is active');
DB::table('v2_user')->where('id',1)->update(['expired_at'=>time()-1]);
$disabled=$controller->show($request(['flag'=>'general']),str_repeat('a',32));
$check(str_starts_with($disabled->getContent(),'vless://') && count(explode("\n",$disabled->getContent()))===2, 'Expired response is two plaintext URIs');
$disabledNames = array_map(fn($uri)=>rawurldecode(explode('#',$uri,2)[1]),explode("\n",$disabled->getContent()));
$check($disabledNames === ['⛔️ اکانت شما منقضی شد ⛔️','👤 USER ：user-example'] && str_contains($disabled->headers->get('Content-Type'),'text/plain'), 'Exact fork expired message and USER label');
DB::table('v2_user')->where('id',1)->update(['transfer_enable'=>0]);
$disabled=$controller->show($request(['flag'=>'general']),str_repeat('a',32));
$disabledNames = array_map(fn($uri)=>rawurldecode(explode('#',$uri,2)[1]),explode("\n",$disabled->getContent()));
$check($disabledNames === ['⛔ ترافیک شما به اتمام رسید ⛔','👤 USER ：user-example'], 'Exact fork traffic exhausted message and USER label');
DB::table('v2_user')->where('id',1)->update(['expired_at'=>time()+864000]);

DB::table('v2_plan')->where('id',1)->update(['reset_traffic_method'=>1]);
RuntimeSetting::set('show_info_to_server_enable',1);
DB::table('v2_user')->where('id',1)->update(['next_reset_at'=>time()+3*86400, 'transfer_enable'=>$gb,
    'expired_at'=>strtotime(date('Y-m-d').' +2 days')+3600]);
$decorated=$private('decorateServers',[$private('serversFor',$account())[0]],$account());
$names=array_column($decorated,'name');
$check(array_slice($names,0,2) === ['🇮🇷❌ ترافیک شما رو به اتمام است ❌','🇮🇷❌ اکانت شما رو به اتمام است ❌'], 'Exact fork warnings and order');
$jalaliExpiry=App\Support\JalaliDateFormatter::format((int)$account()->expired_at,'Y-m-d');
$check(array_slice($names,2,4) === [
    '🇮🇷☠️ Remaining Traffic  ：1 GB',
    '🇮🇷👤 USER  ：user-example ',
    '🇮🇷♻️ Reset Traffic ：3 Days',
    "🇮🇷⛔️️ Expire ：{$jalaliExpiry} / 2 Days",
], 'Exact fork info text, punctuation, spacing, emojis and order');
$check(str_starts_with($decorated[2]['name'],'🇮🇷') && !preg_match('/\p{Cf}/u',implode('',$names)), 'Fork flags preserved without added bidi control characters');
$check(str_contains($names[5],'/ 2') && str_contains($names[4],'3'), 'Civil remaining days and stored reset');

$user=$account(); $user->expired_at=null; $user->next_reset_at=null; $user->transfer_enable=20*$gb;
$unlimitedNames=array_column($private('decorateServers',[$private('serversFor',$account())[0]],$user),'name');
$check(array_slice($unlimitedNames,0,3) === [
    '🇮🇷☠️ Remaining Traffic  ：20 GB','🇮🇷👤 USER  ：user-example ',
    '🇮🇷⛔️️ Expire ：Updating Soon ... /  Days',
], 'Fork unlimited expiry placeholder and omitted reset row');
$user->expired_at=strtotime(date('Y-m-d').' -1 day');
$pastNames=array_column($private('decorateServers',[$private('serversFor',$account())[0]],$user),'name');
$pastExpiry=App\Support\JalaliDateFormatter::format((int)$user->expired_at,'Y-m-d');
$check($pastNames[2] === "🇮🇷⛔️️ Expire ：{$pastExpiry} /  Days", 'Fork past expiry display retains empty day value');
$user->next_reset_at=time()-60;
$zeroResetNames=array_column($private('decorateServers',[$private('serversFor',$account())[0]],$user),'name');
$check(!str_contains(implode('',$zeroResetNames),'Reset Traffic'), 'Fork zero reset day row omitted');
$flagless=$private('serversFor',$account())[0]; $flagless['name']='No flag';
$flaglessNames=array_column($private('decorateServers',[$flagless],$user),'name');
$check($flaglessNames[0] === '☠️ Remaining Traffic  ：20 GB', 'Fork output without a leading country flag');
$user->transfer_enable=0;
$exhaustedNames=array_column($private('decorateServers',[$flagless],$user),'name');
$check($exhaustedNames[0] === '⛔ ترافیک شما به اتمام رسید ⛔', 'Fork exhausted warning retained without bidi controls');
RuntimeSetting::set('show_protocol_to_server_enable',1);
$prefixed=$private('decorateServers',[$private('serversFor',$account())[0]],$account());
$check(str_starts_with($prefixed[0]['name'],'[vless]'), 'Fork protocol prefix');
RuntimeSetting::set('show_protocol_to_server_enable',0);
RuntimeSetting::set('show_info_to_server_enable',0);
DB::table('v2_user')->where('id',1)->update(['transfer_enable'=>20*$gb,'expired_at'=>time()+864000]);

$privateNodes=[['name'=>'🇮🇷  [SBONLY]  -   Private','type'=>'vless','tags'=>[]], ['name'=>'Tagged','type'=>'vless','tags'=>['sbonly']], ['name'=>'Public','type'=>'vless','tags'=>[]]];
$check(count($private('speedBoxServers',$privateNodes,$request()))===1, 'SBONLY hidden without key');
$check(count($private('speedBoxServers',$privateNodes,$request([],['X-SpeedBox-Key'=>'wrong'])))===1, 'SBONLY hidden with wrong key');
$visible=$private('speedBoxServers',$privateNodes,$request([],['X-SpeedBox-Key'=>'fixture-speedbox-key']));
$check(count($visible)===3 && $visible[0]['name']==='🇮🇷 - Private', 'Key and SBONLY name cleanup');

$node(10,'Parent','vless',['enabled'=>false,'group_ids'=>json_encode(['99']),'rate'=>7,'u'=>100,'transfer_enable'=>1,'protocol_settings'=>json_encode(['tls_settings'=>['server_name'=>'parent.example']])]);
$node(11,'Child','vless',['parent_id'=>10,'enabled'=>false,'rate'=>2,'protocol_settings'=>json_encode(['tls_settings'=>['server_name'=>'child.example']])]);
$node(12,'Quota exhausted','vless',['u'=>5,'transfer_enable'=>5]);
$node(13,'Hidden','vless',['show'=>false]);
$servers=$private('serversFor',$account()); $byId=array_column($servers,null,'id');
$check(isset($byId[11]) && $byId[11]['rate']===2.0 && $byId[11]['protocol_settings']['tls_settings']['server_name']==='child.example', 'Child settings/rate independent; enabled/parent quota not subscription gates');
$check(!isset($byId[10]) && !isset($byId[12]) && !isset($byId[13]), 'Group, own quota and show gates');
$node(14,'SS child','shadowsocks',['parent_id'=>10,'protocol_settings'=>json_encode(['cipher'=>'2022-blake3-aes-128-gcm'])]);
$byId=array_column($private('serversFor',$account()),null,'id');
$parent=App\Models\ProxyServer::find(10);
$expected=App\Utils\Helper::getServerKey($parent->created_at->timestamp,16).':'.App\Utils\Helper::uuidToBase64($account()->uuid,16);
$check($byId[14]['password']===$expected, 'SS2022 parent timestamp with child cipher');

class SubscriptionProbe extends App\Support\AbstractProtocol {
    public function handle() {
        return response(base64_encode(json_encode(['servers'=>$this->servers,'client'=>$this->clientName,'version'=>$this->clientVersion,'ua'=>$this->userAgent])))
            ->header('Profile-Title','exporter-title')->header('Profile-Update-Interval','1')->header('Cache-Control','no-store, no-cache, must-revalidate');
    }
}
$app->bind(App\Protocols\General::class, fn($app,$p)=>new SubscriptionProbe($p['user'],$p['servers'],$p['clientName'],$p['clientVersion'],$p['userAgent']));
RuntimeSetting::set('subscribe_update_interval',6);
$called=0;
HookManager::register('client.subscribe.before', function() use (&$called,$check) { $called++; $check(Auth::id()===1,'Hook sees subscription account'); });
HookManager::registerFilter('client.subscribe.servers',fn($servers,$user,$r)=>array_slice($servers,0,1));
$previous=new App\Models\User; $previous->id=9000; Auth::guard()->setUser($previous);
$response=$controller->show($request(['flag'=>'general','raw'=>1]),str_repeat('a',32));
$payload=json_decode($response->getContent(),true);
$check($called===1 && count($payload['servers'])===1,'Action/filter hooks actually execute');
$check(Auth::user()===$previous,'Subscription auth context restored');
$check($response->headers->get('Profile-Title')==='exporter-title' && $response->headers->get('Profile-Update-Interval')==='1' && str_contains($response->headers->get('Cache-Control'),'must-revalidate'),'Exporter headers preserved');
HookManager::reset();
$response=$controller->doSubscribe($request(['flag'=>'general','raw'=>1]),$account(),[$byId[1]]);
$check(count(json_decode($response->getContent(),true)['servers'])===1,'Supplied server list supported');
HookManager::register('client.subscribe.before',fn()=>HookManager::intercept(response('intercepted',202)));
$response=$controller->show($request(),str_repeat('a',32));
$check($response->getStatusCode()===202 && $response->getContent()==='intercepted','Hook interception response');
HookManager::reset();
HookManager::register('scope-test',fn()=>null);
$app->forgetScopedInstances();
$check(!HookManager::hasHook('scope-test'),'No hook registry leak between scopes');

App\Models\EmergencyConfig::create(['name'=>'Backup Turkey','protocol'=>'vless','config_uri'=>'vless://11111111-1111-4111-8111-111111111111@8.8.8.8:443?security=tls&type=ws&sni=example.test#Backup','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
$response=$controller->show($request(['flag'=>'general','raw'=>1,'types'=>'all','filter'=>'Iran|Backup']),str_repeat('a',32));
$payload=json_decode($response->getContent(),true);
$check(count($payload['servers'])===2 && !empty($payload['servers'][1]['_emergency']),'Emergency uses same all/OR filters');
RuntimeSetting::set('emergency_configs_position','top');
$response=$controller->show($request(['flag'=>'general','raw'=>1,'filter'=>'Backup']),str_repeat('a',32));
$check(!empty(json_decode($response->getContent(),true)['servers'][0]['_emergency']),'Emergency top position');

DB::table('speedbox_connection_leases')->insert(['user_id'=>1,'source_ip'=>'1.2.3.4','expires_at'=>time()+60]);
$response=$controller->show($request([],$html),str_repeat('a',32));
$check($factory->lastView['subscription_url']===App\Utils\Helper::getSubscribeUrl(str_repeat('a',32)),'Portal uses canonical complete Helper URL with the real token');
$check($factory->lastView['reset_day']===app(App\Services\SubscriptionResetDay::class)->forUser($account()) && $factory->lastView['online_count']===1,'Portal reset and online count');
$check($response->headers->get('Pragma')==='no-cache' && str_contains($response->headers->get('Cache-Control'),'must-revalidate'),'Portal cache headers');

// Real SMART exporter, independent of the probe binding for General.
DB::table('v2_plan')->where('id',1)->update(['reset_traffic_method'=>1]);
RuntimeSetting::set('show_info_to_server_enable',1);
$xrayFlags=(new ReflectionClass(App\Protocols\XrayJson::class))->newInstanceWithoutConstructor()->flags;
$flag=$xrayFlags[0];
$response=$controller->show($request(['flag'=>$flag,'filter'=>'Iran']),str_repeat('a',32));
$check($response->headers->get('Profile-Update-Interval')==='1' && base64_decode(substr($response->headers->get('Profile-Title'),7))==='🚀 SMART SERVER','Real SMART title and interval');
$configs=json_decode($response->getContent(),true);
$check(is_array($configs) && count($configs)>1,'Real SMART exporter builds JSON with fork info labels');

// The fork's protocol hook runs after the allowed-protocol and client-version filters.
class ProtocolHookProbe extends App\Support\AbstractProtocol {
    protected $allowedProtocols = ['vless'];
    protected $protocolRequirements = [
        'probe.vless.protocol_settings.network' => ['tcp'=>'0.0.0','xhttp'=>'2.0.0'],
    ];
    public function handle() { return $this->servers; }
}
$probeServers = [
    ['id'=>1,'name'=>'Allowed','type'=>'vless','protocol_settings'=>['network'=>'tcp']],
    ['id'=>2,'name'=>'Needs newer client','type'=>'vless','protocol_settings'=>['network'=>'xhttp']],
    ['id'=>3,'name'=>'Unsupported protocol','type'=>'vmess','protocol_settings'=>['network'=>'tcp']],
];
HookManager::reset();
$probe = fn($version='1.0.0') => new ProtocolHookProbe($account(),$probeServers,'probe',$version);
$baseline = $probe()->handle();
$check(array_column($baseline,'id') === [1], 'Protocol and version filters remain effective without a hook');
$hookInput = null;
HookManager::registerFilter('protocol.servers.filtered', function($servers) use (&$hookInput) {
    $hookInput = $servers;
    $servers[0]['name'] .= ' first';
    return $servers;
}, 10);
HookManager::registerFilter('protocol.servers.filtered', function($servers) {
    $servers[0]['name'] .= ' second';
    return $servers;
}, 30);
$hooked = $probe()->handle();
$check($hookInput === $baseline, 'Protocol hook receives the list after compatibility filtering');
$check($hooked[0]['name'] === 'Allowed first second' && array_column($hooked,'id') === [1], 'Protocol hook output and callback priorities reach the exporter');
HookManager::reset();
$check($probe()->handle() === $baseline, 'Removing protocol hooks restores the unchanged filtered list');
$check(array_column($probe('2.0.0')->handle(),'id') === [1,2], 'Newer client retains compatible protocols');
$check(array_column($probe(null)->handle(),'id') === [1,2], 'Unknown version keeps the fork fallback and protocol whitelist');

// URL generation keeps the fork hook/current-request fallback and merged-panel options.
HookManager::reset();
$clearUrlSetting = function (string $name) {
    DB::table('v2_settings')->where('name', $name)->delete();
    Illuminate\Support\Facades\Cache::forget("speedbox:setting:{$name}");
    Illuminate\Support\Facades\Cache::forget("speedbox:setting:v2:{$name}");
};
foreach (['subscribe_url', 'subscribe_path', 'force_https'] as $name) $clearUrlSetting($name);
$app->instance('request', Request::create('http://request.example/s/fixture'));
$urlToken = str_repeat('b', 32);
$subscribe = fn($token = null, $base = null) => App\Utils\Helper::getSubscribeUrl($token ?? $urlToken, $base);
$check($subscribe() === 'http://request.example/s/'.$urlToken, 'Missing domain uses the current request instead of app.url');
$check($subscribe('') === 'http://request.example/s/', 'Missing domain preserves an empty-token base URL');
$app->instance('request', Request::create('http://second-request.example/s/fixture'));
$check($subscribe() === 'http://second-request.example/s/'.$urlToken, 'URL fallback follows a rebound request');
$app->instance('request', Request::create('http://request.example/s/fixture'));
RuntimeSetting::set('subscribe_url', '');
$check($subscribe() === 'http://request.example/s/'.$urlToken, 'Empty configured domain uses the request URL');
RuntimeSetting::set('subscribe_url', ' ,  , ');
$check($subscribe() === 'http://request.example/s/'.$urlToken, 'Whitespace-only domain list uses the request URL');
RuntimeSetting::set('subscribe_path', '///custom/sub///');
$check($subscribe() === 'http://request.example/custom/sub/'.$urlToken, 'Request fallback preserves a custom multi-segment path');
$check($subscribe('') === 'http://request.example/custom/sub/', 'Custom path supports an empty token');
foreach (['', '///'] as $emptyPath) {
    RuntimeSetting::set('subscribe_path', $emptyPath);
    $check($subscribe() === 'http://request.example/s/'.$urlToken, 'Empty or slash-only path retains s fallback');
}
RuntimeSetting::set('subscribe_path', 's');
RuntimeSetting::set('subscribe_url', ' https://configured-sub.example/base/// , , ');
$check($subscribe() === 'https://configured-sub.example/base/s/'.$urlToken, 'Configured domain is trimmed and preserves its base path');
$check($subscribe('') === 'https://configured-sub.example/base/s/', 'Configured domain supports an empty token');
$check($subscribe('0') === 'https://configured-sub.example/base/s/0', 'Zero-string token is not treated as missing');
RuntimeSetting::set('subscribe_url', ' https://one.example/ , , https://two.example/base// , ');
for ($i = 0; $i < 12; $i++) {
    $check(in_array($subscribe(), ['https://one.example/s/'.$urlToken, 'https://two.example/base/s/'.$urlToken], true), 'Multi-domain selection ignores blank entries and trims either selected domain');
}
RuntimeSetting::set('subscribe_url', 'https://node[2-2].example');
$check($subscribe() === 'https://node2.example/s/'.$urlToken, 'Configured domain range pattern remains supported');
RuntimeSetting::set('subscribe_url', 'https://[uuid].example');
$check((bool) preg_match('~^https://[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\\.example/s/'.$urlToken.'$~', $subscribe()), 'Configured UUID domain pattern remains supported');
$check($subscribe(null, ' http://explicit.example/base/// ') === 'http://explicit.example/base/s/'.$urlToken, 'Explicit domain overrides configured patterns and is trimmed');
$check($subscribe('', 'http://explicit.example') === 'http://explicit.example/s/', 'Explicit domain supports an empty-token base URL');
RuntimeSetting::set('subscribe_url', 'http://configured-sub.example');
RuntimeSetting::set('force_https', true);
$check($subscribe() === 'https://configured-sub.example/s/'.$urlToken, 'HTTPS applies to configured domains');
$check($subscribe(null, 'HTTP://explicit.example') === 'https://explicit.example/s/'.$urlToken, 'HTTPS applies to explicit domains case-insensitively');
$clearUrlSetting('subscribe_url');
$check($subscribe() === 'https://request.example/s/'.$urlToken, 'HTTPS applies to the request-domain fallback');
RuntimeSetting::set('force_https', false);
$check($subscribe() === 'http://request.example/s/'.$urlToken, 'Disabling HTTPS preserves the HTTP request scheme');
$clearUrlSetting('force_https');
foreach (['production', 'staging'] as $environment) {
    $app->instance('env', $environment);
    $check($subscribe() === 'https://request.example/s/'.$urlToken, 'Default HTTPS is retained in '.$environment);
}
$app->instance('env', 'testing');
$check($subscribe() === 'http://request.example/s/'.$urlToken, 'Default HTTPS stays disabled in the testing environment');

RuntimeSetting::set('subscribe_url', 'http://configured-sub.example');
RuntimeSetting::set('force_https', true);
RuntimeSetting::set('subscribe_path', 'custom/sub');
$hookInputs = [];
HookManager::registerFilter('subscribe.url', function ($value) use (&$hookInputs) {
    $hookInputs[] = $value;
    return $value.'?first=1';
}, 10);
HookManager::registerFilter('subscribe.url', fn($value) => $value.'&second=1', 30);
foreach ([null, 'http://explicit.example', 'fallback'] as $branch) {
    if ($branch === 'fallback') $clearUrlSetting('subscribe_url');
    $base = $branch === 'fallback' ? null : $branch;
    $domain = $branch === null ? 'configured-sub.example' : ($branch === 'fallback' ? 'request.example' : 'explicit.example');
    foreach ([$urlToken, ''] as $token) {
        $before = count($hookInputs);
        $expected = 'https://'.$domain.'/custom/sub/'.$token;
        $check($subscribe($token, $base) === $expected.'?first=1&second=1', 'URL hook output and priorities apply to '.$domain.' with '.($token === '' ? 'empty' : 'full').' token');
        $check(count($hookInputs) === $before + 1 && end($hookInputs) === $expected, 'URL hook runs exactly once after HTTPS and token construction');
    }
}
HookManager::reset();
$check($subscribe() === 'https://request.example/custom/sub/'.$urlToken, 'Resetting URL hooks restores the generated URL');
foreach (['subscribe_url', 'subscribe_path', 'force_https'] as $name) $clearUrlSetting($name);

echo "SUBSCRIPTION COMPATIBILITY OK: {$checks} checks passed; SQLite :memory: only.\n";
