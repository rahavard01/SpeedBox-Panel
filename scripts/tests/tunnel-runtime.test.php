<?php
/** Durable worker regressions in an isolated database; no server is contacted. */
require __DIR__.'/network-tunnels.test.php';

use App\Jobs\RunTunnelOperation;
use App\Models\{ManagedServer,TunnelConnectionTest,TunnelOperation};
use App\Services\{BackpackRemote,TunnelManager};
use Illuminate\Support\Facades\DB;

$checks=0;
$assert=function($yes,$label)use(&$checks){if(!$yes)throw new RuntimeException($label);$checks++;};
foreach([80,81] as $id)ManagedServer::forceCreate(['id'=>$id,'name'=>'runtime-'.$id,'host'=>'192.0.2.'.$id,'machine_id'=>$id]);
$make=function(array $payload=[])use($manager){
    $test=TunnelConnectionTest::create(['iran_server_id'=>80,'foreign_server_id'=>81]);
    return [$test,$manager->queue('test',null,$test,[...['test_id'=>str_repeat('c',32),'orientation'=>'normal','peer_started'=>true,'phase_started_at'=>now()->timestamp,'peer_requested_at'=>now()->timestamp],...$payload])];
};
$remote=function(callable $reply){$mock=Mockery::mock(BackpackRemote::class);$mock->shouldReceive('call')->andReturnUsing($reply);return $mock;};
$close=function($op){DB::table('speedbox_tunnel_leases')->where('operation_id',$op->id)->delete();$op->update(['status'=>'cancelled']);};

// Peer exits while the coordinator remains waiting: fail immediately, not at
// the coordinator's old fifteen-minute timeout, and stop both sides first.
[$test,$op]=$make();$calls=[];
$mock=$remote(function($server,$request)use(&$calls){
    $calls[]=[$server->id,$request['action']];
    if($request['action']==='test-stop')return ['stopped'=>true];
    return ($request['side']??'')==='peer'?['state'=>'failed','error'=>'coordinator did not answer']:['state'=>'waiting'];
});
$op->update(['progress'=>35]);(new RunTunnelOperation($op->id))->handle($mock,$manager);$op->refresh();
$assert($op->payload['orientation']==='swapped'&&!isset($op->payload['test_id']),'failed peer immediately schedules second direction');
$assert(in_array([80,'test-stop'],$calls,true)&&in_array([81,'test-stop'],$calls,true),'both sides stop before direction switch');
$assert($op->progress>=35,'progress never retreats on direction switch');$close($op);

[$test,$op]=$make(['peer_requested_at'=>now()->subSeconds(66)->timestamp]);
$mock=$remote(fn($s,$r)=>$r['action']==='test-stop'?['stopped'=>true]:['state'=>'waiting']);
(new RunTunnelOperation($op->id))->handle($mock,$manager);$op->refresh();
$assert($op->payload['orientation']==='swapped','missing handshake is bounded independently of remote wait');$close($op);

[$test,$op]=$make(['orientation'=>'swapped']);
$mock=$remote(fn($s,$r)=>$r['action']==='test-stop'?['stopped'=>true]:['state'=>'failed','rows'=>[]]);
(new RunTunnelOperation($op->id))->handle($mock,$manager);
$assert($op->fresh()->status==='failed'&&$test->fresh()->status==='failed','two failed handshakes never become a successful test');
$assert(DB::table('speedbox_tunnel_leases')->where('operation_id',$op->id)->count()===0,'finished failure releases this pair');

[$test,$op]=$make();
$mock=$remote(fn($s,$r)=>$r['action']==='test-stop'?['stopped'=>true]:['state'=>'completed','rows'=>[['k'=>'reverse','t'=>'kcp','s'=>'ok','o'=>60,'n'=>60,'z'=>60,'m'=>21.6,'r'=>95]]]);
(new RunTunnelOperation($op->id))->handle($mock,$manager);$rows=$test->fresh()->results;
$assert($op->fresh()->status==='succeeded'&&$rows[0]['speed']===21.6,'official round-trip value survives without rescaling');
$assert(!isset($rows[0]['upload'])&&$rows[0]['measurement']==='round_trip_1mib','combined speed is never invented as directional upload');

[$test,$op]=$make();$op->update(['cancel_requested'=>true]);$calls=[];
$mock=$remote(function($s,$r)use(&$calls){$calls[]=$s->id;if($s->id===80)throw new RuntimeException('lost connection');return ['stopped'=>true];});
for($attempt=0;$attempt<3;$attempt++){
    (new RunTunnelOperation($op->id))->handle($mock,$manager);
    if($attempt<2)$assert(DB::table('speedbox_tunnel_leases')->where('operation_id',$op->id)->count()===2,'cleanup retry retains pair lease');
}
$assert($op->fresh()->status==='failed'&&$op->fresh()->result['cleanup_pending']===['iran'],'unconfirmed cleanup is recorded as failure');
$assert(count(array_filter($calls,fn($id)=>$id===81))===3,'a failed first cleanup never skips the other side');

foreach(TunnelManager::REVERSE as $transport){
    $rows=$manager->rows([['k'=>'reverse','t'=>$transport,'s'=>'ok','o'=>60,'n'=>60]],'swapped');
    $assert($rows[0]['selectable'],'supported swapped '.$transport.' is available with Iran ingress adapter');
}
$assert(!$manager->rows([['k'=>'reverse','t'=>'tcp','s'=>'skipped']],'swapped')[0]['selectable'],'skipped rows stay unavailable');

$t=App\Models\ServerTunnel::where('engine','backpack')->first()->load(['iran','foreign']);
foreach(['normal','swapped'] as $orientation){
    $t->orientation=$orientation;$r=(new RunTunnelOperation(1))->applyRequest($t,$t->settings,'iran');
    $assert($r['role']==='server'&&$r['host']===($orientation==='normal'?$t->iran->host:$t->foreign->host),'physical role and carrier endpoint are independent');
    $assert($r['bridge_port']===40000+(int)$t->subnet,'private bridge has a deterministic reserved port');
}
$migration=require $root.'/database/migrations/2026_10_23_000001_add_reverse_ssh_route.php';$migration->up();
DB::table('speedbox_managed_servers')->where('id',80)->update(['reverse_ssh_port'=>45221]);
try{DB::table('speedbox_managed_servers')->where('id',81)->update(['reverse_ssh_port'=>45221]);$assert(false,'reverse ports must be unique');}
catch(Illuminate\Database\QueryException){$assert(true,'reverse ports must be unique');}
$migration->down();$assert(!Illuminate\Support\Facades\Schema::hasColumn('speedbox_managed_servers','reverse_ssh_port'),'reverse route migration rolls back cleanly');
DB::table('speedbox_tunnel_operations')->where('id',$op->id)->update(['created_at'=>now()->subMinutes(20)]);$op->refresh();
$recent=(new ReflectionMethod(App\Services\QueueMonitor::class,'recentOperations'))->invoke(new App\Services\QueueMonitor(),collect([(object)['name'=>RunTunnelOperation::class,'id'=>'queue-continuation','started_at'=>now()->toDateTimeString()]]));
$logical=collect($recent)->firstWhere('id','tunnel-operation-'.$op->id);
$assert($logical['started_at']===$op->created_at->toDateTimeString(),'dashboard shows original operation start, not latest continuation');
$assert(!collect($recent)->contains(fn($row)=>$row['id']==='queue-continuation'),'dashboard does not duplicate tunnel continuations as new operations');
Mockery::close();echo "PASS: $checks durable tunnel runtime assertions\n";
