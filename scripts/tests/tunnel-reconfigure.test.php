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
foreach([Illuminate\Filesystem\FilesystemServiceProvider::class,Illuminate\Database\DatabaseServiceProvider::class,Illuminate\Encryption\EncryptionServiceProvider::class,Illuminate\Cache\CacheServiceProvider::class,Illuminate\Bus\BusServiceProvider::class] as $provider)$app->register($provider);
Model::setConnectionResolver($app['db']);
Bus::fake();
$origin=new class {
 public function allocate($t,$settings,$entries):int{return 25001;}
 public function refreshNodePolicy($t,$before,$after,$force=false):void{}
 public function activate($t,$settings):void{}
};
$app->instance(App\Services\TunnelOriginRemote::class,$origin);
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
 return ServerTunnel::create(['name'=>$name,'remote_name'=>'sb-'.bin2hex(random_bytes(16)),'iran_server_id'=>$iran,'foreign_server_id'=>$foreign,'kind'=>'reverse','transport'=>'pck','orientation'=>'normal','secret'=>str_repeat('x',64),'settings'=>['enabled'=>true,'telegram_alerts'=>false,'public_host'=>'ir.test','peer_host'=>'','tunnel_port'=>$port,'accept_udp'=>false,'mappings'=>[['public_port'=>$port+1,'target_port'=>$port+2]]],'status'=>'connected','subnet'=>$port]);
};

// Real controller validation/transactions with an isolated Laravel container.
$app->instance('translator',new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(),'en'));
$app->register(Illuminate\Validation\ValidationServiceProvider::class);
Illuminate\Http\Request::macro('validate',function(array $rules)use($app){return $app['validator']->make($this->all(),$rules)->validate();});
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class,new Illuminate\Routing\ResponseFactory(Mockery::mock(Illuminate\Contracts\View\Factory::class),Mockery::mock(Illuminate\Routing\Redirector::class)));
// A recovered source-only fixture may omit the application's empty base controller.
if(!class_exists(App\Http\Controllers\Controller::class))class_alias(Illuminate\Routing\Controller::class,App\Http\Controllers\Controller::class);
$controller=new App\Http\Controllers\Api\Admin\TunnelController();
$t=$make('same-card',1,2,8000);$unrelated=$make('shared-peer',1,3,9000);
$test=TunnelConnectionTest::create(['iran_server_id'=>1,'foreign_server_id'=>2,'status'=>'completed','results'=>$manager->rows([
 ['k'=>'reverse','t'=>'tcp','s'=>'ok','o'=>60,'n'=>60],['k'=>'direct','t'=>'xdi','s'=>'ok','o'=>60,'n'=>60],
 ['k'=>'reverse','t'=>'wss','s'=>'unstable','o'=>30,'n'=>60],['k'=>'reverse','t'=>'udp','s'=>'ok','o'=>60,'n'=>60],
 ['k'=>'reverse','t'=>'quic','s'=>'down','o'=>0,'n'=>60]
],'normal')]);
$body=['name'=>'edited-card',...$t->settings,'test_id'=>$test->id,'result_id'=>'normal:reverse:tcp'];
$update=function(array $input)use($controller,$t,$manager){return $controller->update(Illuminate\Http\Request::create('/','PUT',$input),$t->fresh(),$manager);};
$reject=function(array $input,string $label,int $status=422)use($update,$check){
 try{$update($input);$check(false,$label);}catch(Illuminate\Validation\ValidationException $e){$check($status===422,$label);}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===$status,$label);}
};
$reject([...$body,'result_id'=>'normal:reverse:madeup'],'forged method rejected');
$reject([...$body,'result_id'=>'normal:reverse:quic'],'failed method rejected');
$reject([...$body,'result_id'=>'normal:reverse:wss'],'unstable method needs acknowledgement');
$reject([...$body,'result_id'=>'normal:reverse:udp'],'UDP-only method cannot silently replace TCP');
$reject([...$body,'transport'=>'xdi'],'direct transport override cannot bypass test');
$reject(array_diff_key($body,['test_id'=>true]),'result id without test rejected');
$other=TunnelConnectionTest::create(['iran_server_id'=>1,'foreign_server_id'=>3,'status'=>'completed','results'=>$test->results]);
$reject([...$body,'test_id'=>$other->id],'another pair result rejected');
DB::table('speedbox_tunnel_tests')->where('id',$test->id)->update(['updated_at'=>now()->subDays(2)]);
$reject($body,'stale result rejected');
DB::table('speedbox_tunnel_tests')->where('id',$test->id)->update(['updated_at'=>now(),'status'=>'running']);
$reject($body,'unfinished test rejected');
DB::table('speedbox_tunnel_tests')->where('id',$test->id)->update(['status'=>'completed']);
$history=$controller->testHistory($t)->getData(true)['data'];
$check(count($history)===1&&$history[0]['id']===$test->id,'history is scoped to exact pair and recent completed tests');
$response=$update($body);$op=TunnelOperation::orderByDesc('id')->first();
$check($response->getStatusCode()===202&&$op->action==='reconfigure','changed method queues durable reconfiguration');
$check($t->fresh()->transport==='pck','database method stays old before remote confirmation');
$check(DB::table('speedbox_tunnel_leases')->count()===2,'both endpoints leased');
$check(Bus::dispatched(RunTunnelReconfigure::class)->count()===1,'dedicated worker dispatched');
$reject($body,'concurrent edit rejected',409);

// Simulate host/origin dependencies. Run the real durable job and SQLite state transitions.
$calls=[];$configs=[];$backups=[];$fault='';$generation=0;
$initialSettings=$t->settings;
$reset=function(bool $active=true)use(&$configs,&$backups,&$calls,$t,$initialSettings){
 $t->refresh()->update(['kind'=>'reverse','transport'=>'pck','orientation'=>'normal','status'=>$active?'connected':'stopped','name'=>'same-card','settings'=>$initialSettings]);
 $configs=[1=>['kind'=>'reverse','transport'=>'pck','orientation'=>'normal','active'=>$active],2=>['kind'=>'reverse','transport'=>'pck','orientation'=>'normal','active'=>$active]];$backups=[];$calls=[];
};
$reset();
$remote=Mockery::mock(BackpackRemote::class);
$remote->shouldReceive('ensure')->andReturnUsing(function($s)use(&$calls){$calls[]=[$s->id,'ensure'];});
$remote->shouldReceive('maintenance')->andReturnUsing(function($s,$action,$ports=[])use(&$calls){$calls[]=[$s->id,$action];return ['status'=>['optimized'=>true,'checked_at'=>now()->timestamp]];});
$remote->shouldReceive('call')->andReturnUsing(function($server,$r)use(&$calls,&$configs,&$backups,&$fault,&$generation){
 $id=$server->id;$action=$r['action'];$calls[]=[$id,$action];
 if($action==='snapshot'){$backups[$r['revision']][$id]=$configs[$id];if($fault==='snapshot'&&$id===2)throw new RuntimeException('secret ssh error');return ['saved'=>true];}
 if($action==='commit'){if($fault==='cleanup')throw new RuntimeException('lost commit reply');unset($backups[$r['revision']][$id]);return ['committed'=>true];}
 if($action==='stop'){$configs[$id]['active']=false;return ['active'=>false];}
 if($action==='rollback'){
  if($fault==='rollback'&&$id===2)throw new RuntimeException('remote unavailable');
  if(isset($backups[$r['revision']][$id])){$configs[$id]=[...$backups[$r['revision']][$id],'active'=>true];unset($backups[$r['revision']][$id]);}
  return ['restored'=>true];
 }
 if($action==='apply'){
  $configs[$id]=['kind'=>$r['kind'],'transport'=>$r['transport'],'orientation'=>$r['swapped']?'swapped':'normal','active'=>true,'taken'=>now()->timestamp+2+(++$generation)];
  if(in_array($fault,['apply','rollback'],true)&&$id===2)throw new RuntimeException('lost reply after mutation, private-token');
  return ['active'=>true,'checked_at'=>now()->timestamp];
 }
 if($action==='status'){
  $new=$configs[$id]['transport']!=='pck';$taken=$configs[$id]['taken']??now()->timestamp-60;
  if(!$new||$fault==='stale')$taken=now()->timestamp-60;
  return ['exists'=>true,'active'=>$configs[$id]['active'],'checked_at'=>now()->timestamp,'metrics'=>['connected'=>$configs[$id]['active']&&$fault!=='connection','taken'=>date(DATE_ATOM,$taken)],'targets'=>['8002'=>!($new&&$fault==='destination')]];
 }
 throw new RuntimeException('unexpected action');
});
$drive=function($op,?callable $hook=null)use($remote,$manager,$check){
 for($turn=0;$turn<100;$turn++){
  $op->refresh();if($hook)$hook($op);
  if(($op->payload['phase']??'')==='verify'&&in_array($GLOBALS['fault'],['stale','connection','destination'],true))$op->update(['payload'=>[...$op->payload,'verify_until'=>now()->timestamp-1]]);
  (new RunTunnelReconfigure($op->id))->handle($remote,$manager);$op->refresh();
  if(in_array($op->status,['succeeded','failed'],true))return $op;
 }
 throw new RuntimeException('durable reconfiguration did not terminate');
};
$id=$t->id;$name=$t->remote_name;$secret=$t->secret;$subnet=$t->subnet;$settings=$t->settings;
$result=$drive($op);
$check($result->status==='succeeded'&&$t->fresh()->transport==='tcp','fresh two-sided connection commits method: '.json_encode([$result->message,$result->payload['error']??null,$result->result]));
$check($t->fresh()->id===$id&&$t->fresh()->remote_name===$name&&$t->fresh()->secret===$secret&&$t->fresh()->subnet===$subnet,'identity, secret and private allocation preserved');
$check(array_intersect_key($t->fresh()->settings,$settings)==$settings&&$unrelated->fresh()->transport==='pck','mappings and unrelated shared tunnel preserved');
$check(!collect($calls)->contains(fn($c)=>$c[0]===3),'no SSH mutation on other shared peer');
$check(DB::table('speedbox_tunnel_leases')->count()===0,'success releases endpoint leases');
$n=count($calls);(new RunTunnelReconfigure($op->id))->handle($remote,$manager);$check(count($calls)===$n,'duplicate completed delivery is a no-op');

foreach(['apply','stale','connection','destination','snapshot','rollback','cleanup'] as $case){
 $reset();$fault=$case;$update($body);$op=TunnelOperation::orderByDesc('id')->first();$result=$drive($op);
 if($case==='cleanup'){$check($result->status==='succeeded'&&$t->fresh()->transport==='tcp','cleanup reply loss cannot roll back committed method');continue;}
 $check($result->status==='failed'&&$t->fresh()->transport==='pck'&&$t->fresh()->settings==$settings,"$case keeps database method/mappings old");
 if($case==='rollback'){$check($t->fresh()->status==='needs_review','partial rollback is explicit, never green');$check(count(array_filter($calls,fn($c)=>$c===[2,'rollback']))===3,'failed rollback retries are bounded');}
 else $check($configs[1]['transport']==='pck'&&$configs[2]['transport']==='pck',"$case restores actual old configuration on both endpoints");
 if($case==='snapshot')$check(!collect($calls)->contains(fn($c)=>in_array($c[1],['stop','apply'],true)),'snapshot failure cannot stop live services');
 $check(DB::table('speedbox_tunnel_leases')->count()===0,"$case releases endpoint leases after recovery");
 $check(!str_contains($result->toJson(),'private-token'),'SSH exceptions never expose private tokens');
}
$reset(false);$fault='apply';$update($body);$drive(TunnelOperation::orderByDesc('id')->first());
$check(!$configs[1]['active']&&!$configs[2]['active'],'failed change restores previously stopped services');
$reset();$fault='';$update([...$body,'result_id'=>'normal:direct:xdi']);$drive(TunnelOperation::orderByDesc('id')->first());
$check($t->fresh()->kind==='direct'&&$configs[1]['kind']==='direct'&&$configs[2]['kind']==='direct','reverse to direct method change uses physical Iran ingress');
$fault='';$update($body);$drive(TunnelOperation::orderByDesc('id')->first());
$check($t->fresh()->kind==='reverse'&&$configs[1]['kind']==='reverse','direct to reverse change keeps original card');
$reset();$fault='';$swapped=TunnelConnectionTest::create(['iran_server_id'=>1,'foreign_server_id'=>2,'status'=>'completed','results'=>$manager->rows([['k'=>'direct','t'=>'xdi','s'=>'ok','o'=>60,'n'=>60]],'swapped')]);
$update([...$body,'test_id'=>$swapped->id,'result_id'=>'swapped:direct:xdi']);$drive(TunnelOperation::orderByDesc('id')->first());
$check($t->fresh()->orientation==='swapped'&&$configs[1]['orientation']==='swapped'&&array_intersect_key($t->fresh()->settings,$settings)==$settings,'swapped direct mode preserves Iran ingress and the same port mappings');
$reset();$fault='';$update($body);$op=TunnelOperation::orderByDesc('id')->first();$interrupted=false;
$drive($op,function($op)use(&$interrupted){if(!$interrupted&&($op->payload['phase']??'')==='apply'&&($op->payload['index']??0)===1){$interrupted=true;(new RunTunnelReconfigure($op->id))->failed(new RuntimeException('worker died'));$op->refresh();}});
$check($interrupted&&$configs[1]['transport']==='pck'&&$configs[2]['transport']==='pck','worker death after first activation schedules rollback');
$reset();$fault='';$update($body);$op=TunnelOperation::orderByDesc('id')->first();
$lock=Illuminate\Support\Facades\Cache::lock('speedbox-tunnel-operation-'.$op->id,330);$lock->get();
$before=Bus::dispatched(RunTunnelReconfigure::class)->count();(new RunTunnelReconfigure($op->id))->handle($remote,$manager);$lock->release();
$check(Bus::dispatched(RunTunnelReconfigure::class)->count()===$before+1&&$op->fresh()->status==='queued','lock contention reschedules instead of dropping a change');
$op->update(['deadline'=>now()->subMinute()]);$drive($op);
$check(!collect($calls)->contains(fn($c)=>in_array($c[1],['stop','apply'],true))&&$t->fresh()->transport==='pck','expired operation before mutation leaves services untouched');
// A normal settings edit does not require a test or enter method migration.
$response=$update(array_diff_key($body,['test_id'=>true,'result_id'=>true]));$plain=TunnelOperation::orderByDesc('id')->first();
$check($response->getStatusCode()===202&&$plain->action==='apply','ordinary editing keeps its existing flow');$manager->finish($plain,'cancelled','fixture cleanup');
$update([...$body,'result_id'=>'normal:reverse:wss','accept_unstable'=>true]);$accepted=TunnelOperation::orderByDesc('id')->first();
$check($accepted->payload['method']['transport']==='wss','acknowledged unstable method can be queued');$manager->finish($accepted,'cancelled','fixture cleanup');
$update([...$body,'result_id'=>'normal:reverse:udp','accept_udp'=>true]);$accepted=TunnelOperation::orderByDesc('id')->first();
$check($accepted->payload['settings']['accept_udp']===true,'UDP-only choice requires explicit compatible settings');$manager->finish($accepted,'cancelled','fixture cleanup');
Mockery::close();
echo "PASS: $assertions Laravel/SQLite reconfiguration assertions\n";
