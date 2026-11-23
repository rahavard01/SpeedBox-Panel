<?php
// Isolated SQLite tests of the production models, migration and accounting service.
require __DIR__.'/../../vendor/autoload.php';
use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\{DB,Schema,Facade};
use Illuminate\Database\Schema\Blueprint;
use App\Models\{WireGuardInstance,WireGuardPeer};
use App\Services\{WireGuardService,SubscriptionServiceType,SubscriptionReservationService};
$app=new Application(realpath(__DIR__.'/../..'));
$app->instance('config',new Repository(['database'=>['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]],
    'app'=>['key'=>'base64:'.base64_encode(random_bytes(32)),'cipher'=>'AES-256-CBC'],'cache'=>['default'=>'array','stores'=>['array'=>['driver'=>'array']]]]));
Facade::setFacadeApplication($app);
$app->register(Illuminate\Events\EventServiceProvider::class);$app->register(Illuminate\Database\DatabaseServiceProvider::class);
$app->register(Illuminate\Encryption\EncryptionServiceProvider::class);$app->register(Illuminate\Cache\CacheServiceProvider::class);$app->boot();
foreach(['v2_user','v2_plan','panel_tariff_groups'] as $table) Schema::create($table,function(Blueprint $t)use($table){$t->id();if($table==='v2_user'){$t->integer('u')->default(0);$t->integer('d')->default(0);$t->integer('t')->default(0);$t->integer('transfer_enable')->default(1000);$t->integer('expired_at')->nullable();$t->boolean('banned')->default(false);$t->integer('device_limit')->default(2);$t->integer('speed_limit')->default(5);$t->integer('updated_at')->default(0);}});
$m=require __DIR__.'/../../database/migrations/2026_11_16_000001_add_independent_wireguard.php';$m->up();
$native=require __DIR__.'/../../database/migrations/2026_11_16_000002_add_native_wireguard_path.php';$native->up();
$checks=0;function yes($c,$msg){global $checks;if(!$c)throw new RuntimeException($msg);$checks++;}
function rejects(callable $f,string $class){try{$f();}catch(Throwable $e){yes($e instanceof $class,'wrong exception: '.get_class($e).' '.$e->getMessage());return;}throw new RuntimeException('expected '.$class);}
Schema::create('speedbox_subscription_reservations',function(Blueprint $t){$t->id();$t->integer('reserved_user_id')->nullable();});
$service=new WireGuardService;
$i=WireGuardInstance::create(['name'=>'test','managed_server_id'=>1,'interface'=>'sbw1','network'=>'10.87.1.0/24','listen_port'=>51820,'mode'=>'direct','endpoint_host'=>'vpn.example.org','endpoint_port'=>51820,'dns'=>['1.1.1.1'],'mtu'=>1380,'enabled'=>true,'public_key'=>base64_encode(random_bytes(32)),'status'=>'ready','last_synced_at'=>now()]);
$id=DB::table('v2_user')->insertGetId(['service_type'=>'wireguard','wireguard_instance_id'=>$i->id,'expired_at'=>time()+3600]);$u=DB::table('v2_user')->find($id);
$p=$service->provision($u);yes($p->address==='10.87.1.2','first peer address');yes($service->provision($u)->id===$p->id,'creation retry must not allocate another key');
$q=DB::table('speedbox_wireguard_peers')->find($p->id);yes($q->private_key!==$p->private_key,'private key encrypted at rest');yes(!isset($p->toArray()['private_key']),'private key hidden in JSON');
$p2=DB::transaction(fn()=>$service->newPeer($i,$u,'device2'));yes($p2->address==='10.87.1.3','unique address');
rejects(fn()=>DB::transaction(fn()=>$service->newPeer($i,$u,'overflow')),DomainException::class);
$report=['peers'=>[$p->public_key=>['rx'=>200,'tx'=>300,'handshake'=>time()],$p2->public_key=>['rx'=>10,'tx'=>20,'handshake'=>0]]];
$service->ingest($i,$report);$service->ingest($i,$report);$u=DB::table('v2_user')->find($id);yes($u->u===210&&$u->d===320,'duplicate report charged once and all peers share quota');
$policy=$service->policy($i);yes($policy['accounts'][$id]['ceiling']===1000,'remote ceiling anchored to acknowledged totals');
DB::table('v2_user')->where('id',$id)->update(['u'=>0,'d'=>0]);yes($service->policy($i)['accounts'][$id]['ceiling']===1530,'traffic reset grants fresh quota without zeroing native ledger');
DB::table('v2_user')->where('id',$id)->update(['banned'=>1]);yes(!$service->policy($i)['accounts'][$id]['enabled'],'ban reaches native policy');DB::table('v2_user')->where('id',$id)->update(['banned'=>0]);
$bad=$report;$bad['peers'][$p->public_key]['rx']=0;rejects(fn()=>$service->ingest($i,$bad),RuntimeException::class);yes(WireGuardPeer::find($p->id)->rx_total===200,'counter regression rolls back whole report');
$v2=DB::table('v2_plan')->insertGetId(['service_type'=>'v2ray']);$wg=DB::table('v2_plan')->insertGetId(['service_type'=>'wireguard','wireguard_instance_id'=>$i->id]);
rejects(fn()=>SubscriptionServiceType::same($u,$v2),DomainException::class);SubscriptionServiceType::same($u,$wg);yes(true,'same-service renewal accepted');
$other=DB::table('v2_plan')->insertGetId(['service_type'=>'wireguard','wireguard_instance_id'=>99]);rejects(fn()=>SubscriptionServiceType::same($u,$other),DomainException::class);
$conf=$service->config(DB::table('v2_user')->find($id),$p);yes(str_contains($conf,'[Interface]')&&str_contains($conf,'Endpoint = vpn.example.org:51820')&&str_contains($conf,'::/0'),'native full-tunnel config');
yes(str_contains($conf,$p->private_key)&&!str_contains($conf,$p2->private_key),'only requested profile secret delivered');
rejects(fn()=>$service->config((object)['id'=>999,'service_type'=>'wireguard'],$p),Symfony\Component\HttpKernel\Exception\HttpException::class);
Schema::create('test_wallets',function(Blueprint $t){$t->id();$t->integer('balance');});DB::table('test_wallets')->insert(['id'=>1,'balance'=>100]);try{DB::transaction(function()use($u,$v2){DB::table('test_wallets')->where('id',1)->decrement('balance',10);DB::table('v2_user')->where('id',$u->id)->update(['u'=>500]);SubscriptionServiceType::same($u,$v2);});}catch(DomainException $e){}yes(DB::table('test_wallets')->value('balance')===100 && DB::table('v2_user')->find($id)->u===0,'failed cross-service operation rolls back debit and account changes');

$old=$p->public_key;[$priv,$pub]=WireGuardService::keyPair();$p->update(['retired_keys'=>[$old],'public_key'=>$pub,'private_key'=>$priv]);
$rotated=$report;$rotated['peers'][$pub]=['rx'=>15,'tx'=>25,'handshake'=>time()];$service->ingest($i,$rotated);$u=DB::table('v2_user')->find($id);
yes($u->u===15&&$u->d===25,'rotated keys preserve old totals and charge only new traffic');
yes(($service->policy($i)['retired'][$old]??null)===$id,'old keys remain in quota accounting after revocation');
Schema::create('speedbox_managed_servers',function(Blueprint $t){$t->id();$t->string('host');});DB::table('speedbox_managed_servers')->insert([['id'=>10,'host'=>'iran.example.org'],['id'=>1,'host'=>'foreign.example.org']]);
$td=['mode'=>'tunnel','ingress_server_id'=>10,'managed_server_id'=>1,'link_network'=>'10.250.1.0/30','tunnel_port'=>51821,'endpoint_host'=>'iran.example.org','mtu'=>1280];$service->validateTunnel($td);yes(true,'native two-server path definition accepted');
foreach([['ingress_server_id'=>2],['ingress_server_id'=>1],['link_network'=>null],['mtu'=>1380]] as $bad)rejects(fn()=>$service->validateTunnel(array_replace($td,$bad)),DomainException::class);
rejects(fn()=>$m->down(),RuntimeException::class);
// Reproduce MySQL's partial DDL state on a separate empty database: the first
// table and its first two indexes survived, while no peer table was created.
$original=DB::getDefaultConnection();$builder=Schema::getFacadeRoot();
$ddl=DB::connection($original)->selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='speedbox_wireguard_instances'")->sql;
$surviving=(array)DB::connection($original)->table('speedbox_wireguard_instances')->first();
$app['config']->set('database.connections.recovery',['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
DB::setDefaultConnection('recovery');Schema::swap(DB::connection()->getSchemaBuilder());
DB::statement($ddl);
Schema::table('speedbox_wireguard_instances',function(Blueprint $t){$t->unique('owner_id','speedbox_wireguard_instances_owner_id_unique');$t->unique('interface','speedbox_wireguard_instances_interface_unique');});
DB::table('speedbox_wireguard_instances')->insert($surviving);
foreach(['v2_user','v2_plan','panel_tariff_groups'] as $table)Schema::create($table,function(Blueprint $t){$t->id();$t->string('existing_note')->nullable();});
DB::table('v2_user')->insert(['id'=>42,'existing_note'=>'preserve-existing-account']);
$m->up();$m->up();
yes(DB::table('speedbox_wireguard_instances')->count()===1,'retry preserves surviving instance');
yes(DB::table('speedbox_wireguard_instances')->value('owner_id')===$surviving['owner_id'],'retry preserves owner and keys');
yes(DB::table('v2_user')->where('id',42)->value('existing_note')==='preserve-existing-account','retry preserves existing account data');
yes(DB::table('v2_user')->where('id',42)->value('service_type')==='v2ray','existing account defaults to its original service');
yes(Schema::hasTable('speedbox_wireguard_peers')&&Schema::hasColumn('v2_plan','wireguard_instance_id'),'retry completes missing table and columns');
yes(count(Schema::getIndexes('speedbox_wireguard_instances'))===5,'retry reuses legacy unique indexes instead of duplicating them');
$mysql=new Illuminate\Database\MySqlConnection(fn()=>throw new RuntimeException('SQL compilation must not open a database'), 'test');
$mysql->setSchemaGrammar(new Illuminate\Database\Schema\Grammars\MySqlGrammar($mysql));
foreach(['speedbox_wireguard_instances','speedbox_wireguard_peers','v2_plan','v2_user','panel_tariff_groups'] as $table) {
    foreach(Schema::getIndexes($table) as $index) {
        if($index['primary'])continue;
        yes(strlen($index['name'])<=64,'index identifier fits MySQL limit');
        $bp=new Blueprint($mysql,$table);
        if($index['unique'])$bp->unique($index['columns'],$index['name']);else $bp->index($index['columns'],$index['name']);
        $sql=implode(';',$bp->toSql());preg_match_all('/`([^`]+)`/',$sql,$identifiers);
        yes(count($identifiers[1])>0&&max(array_map('strlen',$identifiers[1]))<=64,'actual MySQL grammar SQL fits identifier limit');
    }
}
Schema::swap($builder);DB::setDefaultConnection($original);

echo "WIREGUARD SERVICE: $checks checks passed\n";
