<?php
/** Standalone, isolated SQLite checks. Never loads the project's .env/database. */
$root=dirname(__DIR__,2);
require getenv('SPEEDBOX_TEST_AUTOLOAD') ?: $root.'/vendor/autoload.php';
spl_autoload_register(function($class)use($root){if(str_starts_with($class,'App\\')){ $path=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($path))require_once $path; }});

use App\Jobs\{RunTunnelMaintenance,RunTunnelOperation,RunTunnelReconfigure};
use App\Models\{ManagedServer,ServerTunnel,TunnelOperation,TunnelConnectionTest};
use App\Services\{BackpackRemote,TunnelManager};
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\{Bus,DB,Facade,Schema};

$app=new Application($root);
$app->instance('config',new Repository([
 'app'=>['key'=>'base64:'.base64_encode(str_repeat('t',32)),'cipher'=>'AES-256-CBC'],
 'database'=>['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]],
 'queue'=>['default'=>'database'],'cache'=>['default'=>'array','stores'=>['array'=>['driver'=>'array']]],
]));
$app->instance('events',new Illuminate\Events\Dispatcher($app));
Facade::setFacadeApplication($app);
foreach([Illuminate\Database\DatabaseServiceProvider::class,Illuminate\Encryption\EncryptionServiceProvider::class,Illuminate\Cache\CacheServiceProvider::class,Illuminate\Bus\BusServiceProvider::class] as $provider)$app->register($provider);
Model::setConnectionResolver($app['db']);
Bus::fake();
Schema::create('speedbox_managed_servers',function(Blueprint $t){$t->id();$t->string('name');$t->string('host');$t->integer('ssh_port')->default(22);$t->string('ssh_user')->default('root');$t->string('status')->default('ready');$t->integer('machine_id')->nullable();$t->timestamps();});
Schema::create('speedbox_deployments',function(Blueprint $t){$t->id();$t->integer('managed_server_id');$t->string('status');});
Schema::create('v2_server',function(Blueprint $t){$t->id();$t->integer('machine_id');$t->integer('server_port');$t->string('name')->default('');$t->string('host')->default('');$t->string('port')->default('');});
(require $root.'/database/migrations/2026_10_18_000002_create_speedbox_tunnels.php')->up();
(require $root.'/database/migrations/2026_10_18_000003_create_speedbox_tunnel_host_status.php')->up();
(require $root.'/database/migrations/2026_10_20_000001_add_network_tunnel_engines.php')->up();
// Real controller validation/transactions with an isolated Laravel container.
$app->instance('translator',new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(),'en'));
$app->register(Illuminate\Validation\ValidationServiceProvider::class);
Illuminate\Http\Request::macro('validate',function(array $rules)use($app){return $app['validator']->make($this->all(),$rules)->validate();});
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class,new Illuminate\Routing\ResponseFactory(Mockery::mock(Illuminate\Contracts\View\Factory::class),Mockery::mock(Illuminate\Routing\Redirector::class)));
// A recovered source-only fixture may omit the application's empty base controller.
if(!class_exists(App\Http\Controllers\Controller::class))class_alias(Illuminate\Routing\Controller::class,App\Http\Controllers\Controller::class);
$manager=new TunnelManager();$network=new App\Services\NetworkTunnelManager();
$app->instance(TunnelManager::class,$manager);
$controller=new App\Http\Controllers\Api\Admin\NetworkTunnelController();
$count=0;$check=function($yes,$name)use(&$count){if(!$yes)throw new RuntimeException($name);$count++;};
foreach(range(1,4) as $id)ManagedServer::create(['name'=>'host-'.$id,'host'=>'192.0.2.'.$id,'machine_id'=>$id]);
$base=[...$network->defaults(),'name'=>'GOST test','engine'=>'gost','iran_server_id'=>1,'foreign_server_id'=>2,'public_host'=>'192.0.2.1','peer_host'=>'192.0.2.2','mappings'=>[['public_port'=>10443,'target_port'=>443]]];
$reject=function(array $data,$message)use($network,$check){try{DB::transaction(fn()=>$network->prepare(Illuminate\Http\Request::create('/','POST',$data)));$check(false,$message);}catch(Illuminate\Validation\ValidationException $e){$check(true,$message);}};
$normalized=DB::transaction(fn()=>$network->prepare(Illuminate\Http\Request::create('/','POST',$base)));
$check($normalized['mappings'][0]['public_end']===10443,'single port normalized');
$reject([...$base,'transport'=>'grpc','version'=>'2.11.5'],'v2 rejects unsupported grpc');
$reject([...$base,'proxy_protocol'=>true],'unsupported proxy sending rejected');
$reject([...$base,'quota_gb'=>1],'GOST quota cannot use host-wide counters');
$reject([...$base,'mappings'=>[['public_port'=>10000,'public_end'=>10003,'target_port'=>443,'target_end'=>444]]],'unequal range rejected');
$reject([...$base,'mappings'=>[['public_port'=>10000,'public_end'=>10300,'target_port'=>10000,'target_end'=>10300]]],'range cap enforced');
$reject([...$base,'mappings'=>[['public_port'=>10000,'public_end'=>10001,'target_port'=>443,'target_end'=>444],['public_port'=>10001,'target_port'=>443]]],'overlapping inputs rejected');
$reject([...$base,'failover'=>true,'monitor'=>false],'failover needs monitoring');
$reject([...$base,'protocol'=>'udp','failover'=>true],'UDP does not imply TCP health');
$reject([...$base,'public_host'=>'x; touch /tmp/pwn'],'host injection rejected');
$gre=[...$base,'engine'=>'gre','transport'=>'gre','local_endpoint'=>'192.0.2.1'];
$reject([...$gre,'transport'=>'ip6gre'],'underlay family mismatch rejected');
$reject([...$gre,'inner_iran'=>'10.0.0.1/30'],'incomplete inner pair rejected');
$reject([...$gre,'bind_device'=>'eth0;reboot'],'device command injection rejected');
// Completed measurements are fixtures here; real queue/remote probing is tested separately.
$withTest=function(array $data)use($network){
 $data=[...$data,'address_source'=>'servers','ip_family'=>$data['ip_family']??(str_contains($data['peer_host'],':')?'ipv6':'ipv4')];
 $settings=DB::transaction(fn()=>$network->prepare(Illuminate\Http\Request::create('/','POST',$data)));
 $tester=new App\Services\NetworkTunnelTest();
 $row=['id'=>'fixture:'.bin2hex(random_bytes(8)),'engine'=>$settings['engine'],'transport'=>$settings['transport'],'version'=>$settings['version'],'ip_family'=>$settings['ip_family']??null,
 'peer_host'=>$settings['peer_host'],'local_endpoint'=>$settings['local_endpoint']??'',
 'server_fingerprints'=>[$tester->fingerprint(ManagedServer::findOrFail($settings['iran_server_id'])),$tester->fingerprint(ManagedServer::findOrFail($settings['foreign_server_id']))],
 'status'=>'ok','selectable'=>true,'protocols'=>['tcp'=>['status'=>'ok'],'udp'=>['status'=>'ok']]];
 $test=TunnelConnectionTest::create(['iran_server_id'=>$settings['iran_server_id'],'foreign_server_id'=>$settings['foreign_server_id'],'status'=>'completed','results'=>[$row]]);
 TunnelOperation::create(['action'=>'network-test','test_id'=>$test->id,'status'=>'succeeded','deadline'=>now()->addMinutes(5),'payload'=>['engine'=>$settings['engine']]]);
 return [...$data,'test_id'=>$test->id,'result_id'=>$row['id']];
};
$response=$controller->store(Illuminate\Http\Request::create('/','POST',$withTest($base)),$network,$manager);
$check($response->getStatusCode()===202,'create asynchronous');
$t=ServerTunnel::first();$op=TunnelOperation::where('action','apply')->first();
$check($t->engine==='gost','engine persisted');
$check(Bus::dispatched(App\Jobs\RunNetworkTunnelOperation::class)->count()===1,'new engine dispatches own worker');
$check(DB::table('speedbox_tunnel_leases')->count()===2,'host leases shared across engines');
$check(!array_key_exists('secret',$t->toArray()),'relay secret hidden');
$check(!str_contains(json_encode($network->export($t)),$t->secret),'export excludes relay secret');
$reject([...$base,'mappings'=>[['public_port'=>10443,'target_port'=>80]]],'new engine input reservation enforced');
$remote=Mockery::mock(App\Services\NetworkTunnelRemote::class);$calls=[];$failure=null;
$remote->shouldReceive('ensure')->andReturnUsing(function($server,$engine,$version)use(&$calls){$calls[]=['ensure',$server->id];});
$remote->shouldReceive('call')->andReturnUsing(function($server,$request)use(&$calls,&$failure){$action=$request['action'];$calls[]=[$action,$server->id];if($failure&&$failure===[$action,$server->id])throw new RuntimeException('simulated SSH interruption');return $action==='prepare'?['checks'=>[['name'=>'mock prerequisites','ok'=>true]]]:['active'=>true,'connected'=>true,'checked_at'=>time(),'totals'=>['rx'=>1,'tx'=>2]];});
$run=function($op)use($remote,$network,$manager,$check){for($i=0;$i<35;$i++){(new App\Jobs\RunNetworkTunnelOperation($op->id))->handle($remote,$network,$manager);$op->refresh();if(!in_array($op->status,['queued','running']))return $op;}$check(false,'durable job terminates');};
$op=$run($op);$check($op->status==='succeeded','two-sided apply succeeds');
$check($t->fresh()->status==='connected','confirmed snapshot updates central state');
$check(DB::table('speedbox_tunnel_leases')->count()===0,'leases released after apply');
$old=$t->fresh()->settings;$next=[...$old,'mtu'=>1380];$failure=['apply',1];$calls=[];
$op=$run($manager->queue('apply',$t->fresh(),null,['settings'=>$next,'name'=>'changed']));
$check($op->status==='failed','interrupted second host reports failure');
$check(in_array(['rollback',1],$calls)&&in_array(['rollback',2],$calls),'both prepared hosts rolled back');
$check($t->fresh()->settings===$old,'failed update preserves old settings');
$check($t->fresh()->name==='GOST test','failed update preserves name');
$check(DB::table('speedbox_tunnel_leases')->count()===0,'leases released after rollback');
$failure=['commit',1];$calls=[];$op=$run($manager->queue('restart',$t->fresh(),null));
$check($op->status==='succeeded','backup cleanup failure does not roll back committed configuration');
$check(!in_array(['rollback',1],$calls),'no rollback after commit boundary');
$check(isset($op->result['cleanup_errors']['iran']),'cleanup failure visible');
$failure=null;$calls=[];$op=$run($manager->queue('status',$t->fresh(),null));
$check(!array_filter($calls,fn($c)=>$c[0]==='ensure'),'status does not install packages or restart monitor');
$check($op->status==='succeeded','status succeeds');
$stopped=[...$t->fresh()->settings,'enabled'=>false];$t->update(['settings'=>$stopped]);
$prepared=DB::transaction(fn()=>$network->prepare(Illuminate\Http\Request::create('/','PUT',$base),$t->fresh()));
$check($prepared['enabled']===false,'editing stopped connection preserves stop state');
$allocated=$network->allocate([...$gre,'inner_iran'=>'','inner_foreign'=>''],33000);
$check($allocated['inner_iran']!==$allocated['inner_foreign'],'automatic GRE addresses distinct');
$check(str_ends_with($allocated['inner_iran'],'/30'),'automatic GRE point-to-point prefix');
$legacy=ServerTunnel::create(['name'=>'BP','remote_name'=>'sb-'.bin2hex(random_bytes(16)),'iran_server_id'=>3,'foreign_server_id'=>4,'kind'=>'reverse','transport'=>'tcp','orientation'=>'normal','secret'=>str_repeat('a',64),'settings'=>['tunnel_port'=>11000,'mappings'=>[['public_port'=>11001,'target_port'=>443]]],'status'=>'connected','subnet'=>5000]);
$check($legacy->fresh()->engine==='backpack','migration default preserves BackPack');
$check($manager->maintenanceScope($legacy)['tunnel_ids']===[$legacy->id],'BackPack maintenance excludes network engines');
try{(new App\Http\Controllers\Api\Admin\TunnelController())->action(Illuminate\Http\Request::create('/','POST',['action'=>'delete','confirm'=>true]),$t->fresh(),$manager);$check(false,'legacy endpoint blocks new engine');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'legacy endpoint blocks new engine');}
$check(DB::table('speedbox_network_samples')->count()>0,'central telemetry stored separately');

// v15: registered addresses, explicit families, and independent peers sharing Iran.
$automatic=[...$base,'address_source'=>'servers','ip_family'=>'ipv4','public_host'=>'ignored.example','peer_host'=>'ignored.example','mappings'=>[['public_port'=>17443,'target_port'=>443]]];
$auto=DB::transaction(fn()=>$network->prepare(Illuminate\Http\Request::create('/','POST',$automatic)));
$check($auto['public_host']==='192.0.2.1'&&$auto['peer_host']==='192.0.2.2','registered hosts override submitted addresses');
$check($auto['ip_family']==='ipv4','explicit IPv4 persisted');
$reject([...$automatic,'ip_family'=>'ipv6'],'IPv6 never silently falls back to IPv4');
$reject([...$automatic,'ip_family'=>'other'],'invalid family rejected');
$reject([...$automatic,'transport'=>'grpc'],'new GOST does not accept removed gRPC method');
$reject([...$base,'ip_family'=>'ipv6'],'custom host family mismatch rejected');
$before=$t->fresh()->toArray();
$second=$controller->store(Illuminate\Http\Request::create('/','POST',$withTest([...$automatic,'foreign_server_id'=>3,'name'=>'second independent peer'])),$network,$manager);
$secondId=$second->getData()->data->id;$sibling=ServerTunnel::findOrFail($secondId);
$check($sibling->iran_server_id===$t->iran_server_id&&$sibling->foreign_server_id===3,'same Iran can own a different foreign peer');
$check($sibling->remote_name!==$t->remote_name&&$sibling->secret!==$t->secret,'each connection owns an independent remote identity');
$op=$run(TunnelOperation::findOrFail($second->getData()->operation->id));
$check($op->status==='succeeded','second independent connection is applied by actual job');
$check($t->fresh()->toArray()===$before,'creating a peer leaves first connection unchanged');
$reject([...$automatic,'foreign_server_id'=>4],'same Iran input port reserved across different foreign hosts');
$reject([...$base,'foreign_server_id'=>3],'same Iran cannot reuse first connection public port');
$wrongPair=Illuminate\Http\Request::create('/','PUT',[...$automatic,'foreign_server_id'=>4]);
try{$network->prepare($wrongPair,$sibling);$check(false,'editing cannot move peer');}catch(Illuminate\Validation\ValidationException $e){$check(true,'editing cannot move peer');}
$op=$run($manager->queue('stop',$sibling,null));
$check($op->status==='succeeded'&&$sibling->fresh()->settings['enabled']===false,'stopping peer persists only its disabled state');
$check($t->fresh()->toArray()===$before,'stopping sibling does not touch first connection');
$reject([...$automatic,'foreign_server_id'=>4],'stopped peer still reserves its input port');
foreach([5=>'2001:db8::5',6=>'2001:db8::6',7=>'iran.example.test',8=>'foreign.example.test']as$id=>$host)ManagedServer::create(['id'=>$id,'name'=>'host-'.$id,'host'=>$host,'machine_id'=>$id]);
$ipv6=DB::transaction(fn()=>$network->prepare(Illuminate\Http\Request::create('/','POST',[...$automatic,'foreign_server_id'=>6,'ip_family'=>'ipv6','mappings'=>[['public_port'=>17444,'target_port'=>443]]])));
$check($ipv6['peer_host']==='2001:db8::6','GOST IPv6 uses registered foreign IPv6');
$gre6=DB::transaction(fn()=>$network->prepare(Illuminate\Http\Request::create('/','POST',[...$automatic,'engine'=>'gre','transport'=>'ip6gre','iran_server_id'=>5,'foreign_server_id'=>6,'ip_family'=>'ipv6'])));
$check($gre6['local_endpoint']==='2001:db8::5'&&$gre6['peer_host']==='2001:db8::6','GRE resolves both registered IPv6 endpoints');
$reject([...$automatic,'engine'=>'gre','transport'=>'gre','iran_server_id'=>5,'foreign_server_id'=>6,'ip_family'=>'ipv6'],'GRE transport and selected family must match');
// Stub only DNS; validation, DB locks and controller transactions remain real.
eval('namespace App\\Services; function dns_get_record($host,$type){return $GLOBALS["network_test_dns"][$host][$type]??[];}');
$GLOBALS['network_test_dns']=[
 'iran.example.test'=>[DNS_A=>[['ip'=>'192.0.2.7']],DNS_AAAA=>[['ipv6'=>'2001:db8::7']]],
 'foreign.example.test'=>[DNS_A=>[['ip'=>'192.0.2.8']],DNS_AAAA=>[['ipv6'=>'2001:db8::8']]],
];
$dnsNetwork=$network;
$dns=DB::transaction(fn()=>$dnsNetwork->prepare(Illuminate\Http\Request::create('/','POST',[...$automatic,'iran_server_id'=>7,'foreign_server_id'=>8])));
$check($dns['peer_host']==='192.0.2.8','registered domain selects A for IPv4');
$dns=DB::transaction(fn()=>$dnsNetwork->prepare(Illuminate\Http\Request::create('/','POST',[...$automatic,'engine'=>'gre','transport'=>'ip6gre','iran_server_id'=>7,'foreign_server_id'=>8,'ip_family'=>'ipv6'])));
$check($dns['local_endpoint']==='2001:db8::7'&&$dns['peer_host']==='2001:db8::8','registered domain selects AAAA for IPv6');
$legacySettings=[...$base,'peer_host'=>'foreign.example.test','public_host'=>'users.example.test','ip_family'=>null];
$legacyEdit=DB::transaction(fn()=>$network->prepare(Illuminate\Http\Request::create('/','PUT',$legacySettings),$t->fresh()));
$check($legacyEdit['peer_host']==='foreign.example.test'&&$legacyEdit['public_host']==='users.example.test','legacy custom domains remain compatible');
$oldGrpc=ServerTunnel::create(['engine'=>'gost','name'=>'legacy grpc','remote_name'=>'sb-'.bin2hex(random_bytes(16)),'iran_server_id'=>7,'foreign_server_id'=>8,'kind'=>'direct','transport'=>'grpc','orientation'=>'normal','secret'=>str_repeat('c',64),'settings'=>[...$base,'iran_server_id'=>7,'foreign_server_id'=>8,'transport'=>'grpc','version'=>'3.0.0','tunnel_port'=>19443,'mappings'=>[['public_port'=>18443,'target_port'=>443]]],'status'=>'stopped','subnet'=>55000]);
$oldGrpcPrepared=DB::transaction(fn()=>$network->prepare(Illuminate\Http\Request::create('/','PUT',$oldGrpc->settings),$oldGrpc));
$check($oldGrpcPrepared['transport']==='grpc','existing gRPC settings can still be edited without conversion');
Mockery::close();echo "PASS: $count isolated network tunnel assertions\n";
