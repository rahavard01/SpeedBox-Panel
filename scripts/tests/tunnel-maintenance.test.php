<?php
/** Standalone, isolated SQLite checks. Never loads the project's .env/database. */
$root=dirname(__DIR__,2);
require getenv('SPEEDBOX_TEST_AUTOLOAD') ?: $root.'/vendor/autoload.php';
spl_autoload_register(function($class)use($root){if(str_starts_with($class,'App\\')){ $path=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($path))require_once $path; }});

use App\Jobs\{RunTunnelMaintenance,RunTunnelOperation};
use App\Models\{ManagedServer,ServerTunnel,TunnelOperation};
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
Schema::create('v2_server',function(Blueprint $t){$t->id();$t->integer('machine_id');$t->integer('server_port');});
(require $root.'/database/migrations/2026_10_18_000002_create_speedbox_tunnels.php')->up();
(require $root.'/database/migrations/2026_10_18_000003_create_speedbox_tunnel_host_status.php')->up();
$manager=new TunnelManager();$app->instance(TunnelManager::class,$manager);
$assertions=0;
$check=function($condition,$message)use(&$assertions){if(!$condition)throw new RuntimeException($message);$assertions++;};
$hosts=[];foreach(range(1,5) as $id)$hosts[$id]=ManagedServer::create(['name'=>'host-'.$id,'host'=>'192.0.2.'.$id,'machine_id'=>$id])->fresh();
$make=function($name,$iran,$foreign,$port) {
 return ServerTunnel::create(['name'=>$name,'remote_name'=>'sb-'.bin2hex(random_bytes(16)),'iran_server_id'=>$iran,'foreign_server_id'=>$foreign,'kind'=>'reverse','transport'=>'pck','orientation'=>'normal','secret'=>str_repeat('x',64),'settings'=>['public_host'=>'ir.test','peer_host'=>'','tunnel_port'=>$port,'accept_udp'=>false,'mappings'=>[['public_port'=>$port+1,'target_port'=>$port+2]]],'status'=>'connected','subnet'=>$port]);
};
$t1=$make('shared-1',1,2,8000);$t2=$make('shared-2',1,3,9000);$t3=$make('unrelated',4,5,10000);
$scope=$manager->maintenanceScope($t1);
$check($scope['server_ids']===[1,2,3],'connected-component scope includes shared peers only');
$check($scope['tunnel_ids']===[$t1->id,$t2->id],'unrelated tunnel excluded');
$ports=$manager->reservedPorts($hosts[1]);sort($ports);
$check($ports===[22,8000,8001,9000,9001],'Iran reservations cover every shared tunnel');
$check($manager->reservedPorts($hosts[2])===[22,8002],'foreign reservations include actual node destination');
$op=$manager->queue('optimize',$t1,null,['selected_ids'=>[1]]);
$check(DB::table('speedbox_tunnel_leases')->count()===3,'all dependent hosts leased');
$check(Bus::dispatched(RunTunnelMaintenance::class)->count()===1,'maintenance uses its durable worker');$check(Bus::dispatched(RunTunnelOperation::class)->isEmpty(),'maintenance does not run the single-tunnel worker');
try{$manager->queue('restart',$t2,null);$check(false,'shared concurrent operation should fail');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409,'shared host blocks conflicting operation');}
$manager->finish($op,'succeeded','done');
$check(DB::table('speedbox_tunnel_leases')->count()===0,'finish releases every dependent lease');
try{$manager->queue('engine-update',$t1,null,['confirmed_server_ids'=>[1,2]]);$check(false,'stale scope should fail');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409,'unconfirmed extra peer blocks upgrade');}
$check(DB::table('speedbox_tunnel_leases')->count()===0,'rejected scope has no side effects');
$manager->saveHostStatus(1,['optimized'=>true,'checked_at'=>1]);$manager->saveHostStatus(1,['optimized'=>false,'checked_at'=>2]);
$check(DB::table('speedbox_tunnel_host_status')->count()===1,'status is stored per server, not per card');
$check(json_decode(DB::table('speedbox_tunnel_host_status')->value('snapshot'),true)['optimized']===false,'new real state replaces stale green state');

// Run the actual durable job through all phases, faking only the SSH boundary.
$remote=Mockery::mock(BackpackRemote::class);
$updated=[];$remoteCalls=[];$failActivate=null;
$remote->shouldReceive('maintenance')->andReturnUsing(function($server,$action,$ports=[],$revision=null)use(&$updated,&$remoteCalls,&$failActivate){
 $remoteCalls[]=[$server->id,$action];
 if($action==='activate'){
  $updated[$server->id]=true;
  if($failActivate===$server->id)throw new RuntimeException('simulated interrupted SSH activation');
 }
 if($action==='rollback')unset($updated[$server->id]);
 $status=['installed'=>true,'compatible'=>true,'up_to_date'=>isset($updated[$server->id]),'optimized'=>true,'checked_at'=>now()->timestamp,'version'=>'v1.9.0'];
 return ['status'=>$status,'logs'=>[$action.' '.$server->id]];
});
$remote->shouldReceive('call')->andReturnUsing(fn($server,$request)=>['active'=>true,'metrics'=>['connected'=>true,'taken'=>now()->toIso8601String()],'targets'=>['8002'=>true]]);
$run=function($operation)use($remote,$manager,$check){
 for($turn=0;$turn<70;$turn++){
  (new RunTunnelMaintenance($operation->id))->handle($remote,$manager);
  $operation->refresh();
  if(in_array($operation->status,['succeeded','failed'],true))return $operation;
 }
 throw new RuntimeException('maintenance state machine failed to finish');
};
$upgrade=$run($manager->queue('engine-update',$t1,null,['confirmed_server_ids'=>[1,2,3]]));
$check($upgrade->status==='succeeded','actual upgrade job completes through stage/activate/verify/commit');
$check(array_keys($updated)===[1,2,3],'all connected hosts updated once');
$check(!collect($remoteCalls)->contains(fn($call)=>in_array($call[0],[4,5],true)),'no unrelated SSH mutation');
$check(DB::table('speedbox_tunnel_leases')->count()===0,'successful upgrade releases locks');
$updated=[];$remoteCalls=[];$failActivate=2;
$upgrade=$run($manager->queue('engine-update',$t1,null,['confirmed_server_ids'=>[1,2,3]]));
$check($upgrade->status==='failed','interrupted activation fails the operation');
$check($updated===[],'interrupted activation rolls back all staged hosts');
$check(collect($remoteCalls)->where(1,'rollback')->count()===3,'rollback includes even server with lost SSH response');
$check(DB::table('speedbox_tunnel_leases')->count()===0,'rollback releases all locks');

$job=new RunTunnelMaintenance(1);$before=['iran'=>['active'=>true,'metrics'=>['connected'=>true],'targets'=>[]],'foreign'=>['active'=>false,'targets'=>[]]];
$after=['iran'=>['active'=>true,'metrics'=>['connected'=>true,'taken'=>date(DATE_ATOM)],'targets'=>[]],'foreign'=>['active'=>false,'targets'=>[]]];
$check($job->recovered($before,$after,$t1,[1=>time()]),'healthy endpoint and intentionally stopped peer preserved');
$after['foreign']['active']=true;$check(!$job->recovered($before,$after,$t1,[]),'upgrade must not start a previously stopped tunnel');
$after['foreign']['active']=false;$after['iran']['metrics']['taken']='2020-01-01T00:00:00Z';
$check(!$job->recovered($before,$after,$t1,[1=>time()]),'stale pre-upgrade metrics cannot prove recovery');
$after['iran']['metrics']['connected']=false;$check(!$job->recovered($before,$after,$t1,[]),'lost control connection triggers rollback');
Mockery::close();
echo "PASS: $assertions Laravel/SQLite maintenance assertions\n";
