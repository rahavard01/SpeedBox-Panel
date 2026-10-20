<?php
/** Isolated SQLite tests: never bootstrap the project or read its .env. */
$root = dirname(__DIR__, 2);
require getenv('SPEEDBOX_TEST_AUTOLOAD') ?: $root.'/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root) {
    if (str_starts_with($class, 'App\\')) {
        $path = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($path)) require_once $path;
    }
});

use App\Http\Controllers\Api\Admin\InfrastructureController;
use App\Models\ProxyServer;
use App\Services\NodeControlService;
use App\Support\InfrastructureNodeRules;
use App\Support\NodeKernelCapabilities;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Facade, Schema};

$app = new Application($root);
$app->instance('config', new Repository([
    'database' => ['default'=>'sqlite', 'connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]],
    'cache' => ['default'=>'array','stores'=>['array'=>['driver'=>'array']]],
]));
$app->instance('events', new Illuminate\Events\Dispatcher($app));
Facade::setFacadeApplication($app);
foreach ([Illuminate\Database\DatabaseServiceProvider::class, Illuminate\Cache\CacheServiceProvider::class] as $provider) $app->register($provider);
Model::setConnectionResolver($app['db']);
$app->instance('translator', new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'));
$app->register(Illuminate\Validation\ValidationServiceProvider::class);
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new Illuminate\Routing\ResponseFactory(Mockery::mock(Illuminate\Contracts\View\Factory::class), Mockery::mock(Illuminate\Routing\Redirector::class)));
if (!class_exists(App\Http\Controllers\Controller::class)) class_alias(Illuminate\Routing\Controller::class, App\Http\Controllers\Controller::class);

Schema::create('v2_server_machine', function (Blueprint $t) { $t->id();$t->string('name');$t->boolean('is_active')->default(true);$t->integer('last_seen_at')->nullable();$t->text('load_status')->nullable();$t->text('warp_status')->nullable();$t->timestamps(); });
Schema::create('v2_server_group', function (Blueprint $t) { $t->id();$t->string('name');$t->timestamps(); });
Schema::create('speedbox_managed_servers', function (Blueprint $t) { $t->id();$t->integer('machine_id');$t->string('status'); });
Schema::create('v2_server', function (Blueprint $t) {
    $t->id(); foreach (['name','type','kernel','host','port'] as $key) $t->string($key);
    $t->integer('parent_id')->nullable();$t->integer('machine_id')->nullable();$t->integer('server_port');$t->integer('sort')->default(0);
    foreach (['u','d','transfer_enable'] as $key) $t->bigInteger($key)->default(0);
    foreach (['show','enabled','warp_enabled','rate_time_enable'] as $key) $t->boolean($key)->default(false);
    $t->float('rate')->default(1);$t->string('code')->nullable();
    foreach (['tags','group_ids','route_ids','protocol_settings','custom_outbounds','custom_routes','rate_time_ranges','cert_config'] as $key) $t->text($key)->nullable();
    $t->timestamps();
});
Schema::create('v2_plan', function (Blueprint $t) { $t->id();$t->string('name');$t->integer('sort')->default(0);$t->text('tags')->nullable(); });
Schema::create('v2_user', function (Blueprint $t) {
    $t->id();$t->integer('plan_id')->nullable();$t->integer('group_id');$t->string('uuid');$t->boolean('banned')->default(false);$t->bigInteger('expired_at')->nullable();
    foreach (['u','d'] as $key) $t->bigInteger($key)->default(0);
    $t->bigInteger('transfer_enable')->default(100);$t->integer('speed_limit')->nullable();$t->integer('device_limit')->nullable();
});
Schema::create('speedbox_connection_leases', function (Blueprint $t) { $t->id();$t->integer('node_id');$t->integer('user_id');$t->string('source_ip');$t->bigInteger('expires_at'); });
$assertions=0;
$check=function ($condition, $message) use (&$assertions) { if (!$condition) throw new RuntimeException($message);$assertions++; };
$service=new NodeControlService();$controller=new InfrastructureController($service);
DB::table('v2_server_machine')->insert(['id'=>1,'name'=>'test-machine']);
DB::table('v2_server_group')->insert([['id'=>1,'name'=>'one'],['id'=>2,'name'=>'two']]);
$rootNode=ProxyServer::create(['name'=>'original','type'=>'vless','kernel'=>'xray','machine_id'=>1,'host'=>'example.test','port'=>'443','server_port'=>443,'enabled'=>true,'show'=>true,'u'=>123,'d'=>456,'code'=>'original-code','group_ids'=>[1],'protocol_settings'=>['tls'=>0,'network'=>'tcp'],'rate'=>2]);
$copy=$controller->cloneNode($rootNode)->getData(true)['data'];
$alias=ProxyServer::findOrFail($copy['id']);
$check((int)$alias->parent_id===$rootNode->id && $alias->enabled && !$alias->show, 'copy must be enabled, hidden and attached to original');
$check($alias->u===0 && $alias->d===0 && $alias->code===null, 'copy must not duplicate counters or unique code');
$check($alias->kernel===$rootNode->kernel && (float)$alias->rate===(float)$rootNode->rate && $alias->machine_id===$rootNode->machine_id, 'copy must inherit runtime assignment');
$alias->update(['group_ids'=>[2]]);
$second=$controller->cloneNode($alias)->getData(true)['data'];
$check((int)$second['parent_id']===$rootNode->id, 'copy of alias must remain one level under original');
$check($service->machineNodes(1)->count()===1, 'aliases must never create duplicate listeners');
$rootNode->update(['enabled'=>false]);
$disabled=$controller->cloneNode($rootNode)->getData(true)['data'];
$check(!$disabled['enabled'], 'copy must not enable a disabled runtime');
$rootNode->update(['enabled'=>true]);

DB::table('v2_plan')->insert([['id'=>1,'name'=>'used','tags'=>'["tag"]'],['id'=>2,'name'=>'empty','tags'=>null]]);
$users=[
    ['expired_at'=>time()+3600],['expired_at'=>null],['expired_at'=>0],
    ['expired_at'=>time()-10],['expired_at'=>time()+3600,'banned'=>true],
    ['expired_at'=>time()+3600,'u'=>60,'d'=>40],['expired_at'=>time()+3600,'transfer_enable'=>0],
];
foreach ($users as $i=>$user) DB::table('v2_user')->insert([...['id'=>$i+1,'plan_id'=>1,'group_id'=>2,'uuid'=>'user-'.$i],...$user]);
$plans=$controller->plans()->getData(true)['data'];
$check($plans[0]['users_count']===7 && $plans[0]['active_users_count']===3, 'plan validity must include NULL/zero expiry, exclude blocked, expired and exhausted accounts');
$check($plans[1]['users_count']===0 && $plans[1]['active_users_count']===0, 'empty plan must have integer zero counts');
$check($plans[0]['tags']===['tag'], 'plan response must preserve existing tags');
$check($service->usersFor($rootNode)->count()===3, 'enabled child groups must reach original runtime with matching validity');

DB::table('speedbox_connection_leases')->insert([
    ['node_id'=>$rootNode->id,'user_id'=>1,'source_ip'=>'192.0.2.1','expires_at'=>time()+60],
    ['node_id'=>$rootNode->id,'user_id'=>1,'source_ip'=>'192.0.2.2','expires_at'=>time()+60],
    ['node_id'=>$rootNode->id,'user_id'=>2,'source_ip'=>'192.0.2.3','expires_at'=>time()-1],
]);
$service->report($rootNode,['metrics'=>['kernel_status'=>true,'runtime_state'=>'ready','total_users'=>3]]);
$rows=$controller->nodes()->getData(true);
$byId=array_column($rows['data'], null, 'id');
$check($byId[$rootNode->id]['runtime']['connection_state']==='active', 'running kernel with live lease must be green');
$check($byId[$alias->id]['runtime']['online_users']===1 && $byId[$alias->id]['runtime']['shared'], 'alias must inherit distinct, unexpired online count');
$check($byId[$alias->id]['traffic_usage']['total']===579 && $byId[$alias->id]['traffic_usage']['shared'], 'alias traffic display must use shared original counters');
$check(isset($rows['meta']['kernel_capabilities']['protocols']['socks']), 'editor must receive shared capability definition');
$state=function(array $metrics,array $counts=[]) use ($service,$rootNode) { $service->report($rootNode,['metrics'=>$metrics]);return $service->stateFor($rootNode,$counts); };
$check($state(['kernel_status'=>true])['connection_state']==='idle', 'running with zero online users must be orange');
$check($state(['kernel_status'=>false,'runtime_state'=>'waiting_for_users','total_users'=>0])['connection_state']==='waiting_for_users', 'zero eligible users must not be reported as a failure');
$check($state(['kernel_status'=>false,'runtime_state'=>'error','total_users'=>0])['connection_state']==='kernel_error', 'explicit startup error must take precedence over empty users');
$check($state(['kernel_status'=>false,'total_users'=>1])['connection_state']==='kernel_stopped', 'stopped kernel with accounts must not be reported as connected and idle');
Cache::put('speedbox:node:'.$rootNode->id.':last_seen',time()-301);
$offline=$service->stateFor($rootNode,[$rootNode->id=>99]);
$check($offline['connection_state']==='offline' && $offline['online_users']===0, 'stale heartbeat and leases must not produce green state');
$unassigned=clone $rootNode;$unassigned->machine_id=null;
$check($service->stateFor($unassigned,[])['connection_state']==='unassigned','unassigned node needs a neutral state');

$base=['name'=>'validated','type'=>'vless','kernel'=>'singbox','machine_id'=>1,'host'=>'example.test','port'=>'8443','server_port'=>8443,'rate'=>1,'rate_time_enable'=>false,'show'=>false,'enabled'=>true,'group_ids'=>[1],'protocol_settings'=>['tls'=>0,'network'=>'tcp']];
$valid=function(array $body) { return InfrastructureNodeRules::validate(Request::create('/','POST',$body)); };
$reject=function(array $body,string $message) use ($valid,$check) { try { $valid($body);$check(false,$message); } catch (Illuminate\Validation\ValidationException $e) { $check(isset($e->errors()['kernel']),$message); } };
foreach (['tuic','anytls','naive','mieru','socks','http'] as $type) {
    $settings=match($type){'naive','http'=>['tls'=>0],'mieru'=>['transport'=>'TCP'],default=>[]};
    $reject([...$base,'type'=>$type,'kernel'=>'xray','protocol_settings'=>$settings],$type.' must reject Xray on backend');
    $valid([...$base,'type'=>$type,'protocol_settings'=>$settings]);$check(true,$type.' accepts Sing-box');
}
$reject([...$base,'type'=>'hysteria','kernel'=>'xray','protocol_settings'=>['version'=>1]],'Hysteria 1 must reject Xray');
$reject([...$base,'type'=>'tuic','protocol_settings'=>['version'=>4]],'TUIC 4 must not be silently built as TUIC 5');
$valid([...$base,'type'=>'hysteria','kernel'=>'xray','protocol_settings'=>['version'=>2]]);$check(true,'Hysteria 2 can use Xray');
foreach (['xhttp','kcp'] as $network) $reject([...$base,'protocol_settings'=>['tls'=>0,'network'=>$network]],$network.' must reject Sing-box');
$reject([...$base,'protocol_settings'=>['tls'=>0,'network'=>'tcp','network_settings'=>['header'=>['type'=>'http']]]],'TCP HTTP must reject Sing-box');
$reject([...$base,'protocol_settings'=>['tls'=>0,'network'=>'tcp','encryption'=>['enabled'=>true,'encryption'=>'a','decryption'=>'b']]],'encrypted VLESS must reject Sing-box');
$reject([...$base,'type'=>'shadowsocks','kernel'=>'xray','protocol_settings'=>['cipher'=>'2022-blake3-chacha20-poly1305']],'SS2022 chacha multiuser must reject Xray');
$rootNode->update(['kernel'=>'singbox']);
$reject([...$base,'parent_id'=>$rootNode->id,'kernel'=>'xray','protocol_settings'=>['tls'=>0,'network'=>'xhttp']],'child settings must not bypass inherited kernel restriction');
$validated=$valid([...$base,'parent_id'=>$rootNode->id,'kernel'=>'xray']);
$check($validated['kernel']==='singbox' && $validated['machine_id']===1,'valid alias must inherit runtime kernel rather than request override');
echo "PASS: $assertions isolated node, plan and compatibility checks.\n";
