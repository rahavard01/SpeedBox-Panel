<?php
require __DIR__.'/network-tunnels.test.php';
use App\Models\{ManagedServer,TunnelConnectionTest,TunnelOperation};
use App\Services\{NetworkTunnelTest,NetworkTunnelRemote};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Bus,DB};
$tester=new NetworkTunnelTest();$checks=0;
$assert=function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
$cases=$tester->candidates('gost',ManagedServer::findOrFail(1),ManagedServer::findOrFail(2),$network);
$assert(count($cases)===2&&array_column($cases,'version')===['2.11.5','3.0.0'],'GOST tests both versions on available family');
$assert(count($tester->candidates('gre',ManagedServer::findOrFail(7),ManagedServer::findOrFail(8),$network))===4,'registered dual-stack domains produce four GRE modes');
$runTest=function($op,$remote,$cancelAfter=null)use($manager,$assert){for($i=0;$i<80;$i++){if($cancelAfter===$i)$op->update(['cancel_requested'=>true]);(new App\Jobs\RunNetworkTunnelTest($op->id))->handle($remote,$manager);$op->refresh();if(!in_array($op->status,['queued','running']))return $op;}$assert(false,'test state machine terminates');};
$mock=function(&$calls,$fail=null){$remote=Mockery::mock(NetworkTunnelRemote::class);$remote->shouldReceive('ensureProbe')->andReturnUsing(function($server,$engine)use(&$calls){$calls[]=['ensure',$server->id,$engine];});$remote->shouldReceive('probe')->andReturnUsing(function($server,$q)use(&$calls,$fail){$calls[]=[$q['action'],$server->id,$q['id']];if($fail&&$fail===[$q['action'],$server->id])throw new RuntimeException('simulated probe SSH failure');return match($q['action']){'start'=>['port'=>32443],'stop'=>['stopped'=>true],'measure-start'=>['state'=>'running'],'measure-state'=>['state'=>'completed','protocols'=>['tcp'=>['status'=>'ok','ok'=>20,'total'=>20,'rtt'=>45,'download'=>12,'upload'=>8],'udp'=>['status'=>'down','ok'=>0,'total'=>20,'rtt'=>0,'download'=>0,'upload'=>0]]]};});return $remote;};
$calls=[];$response=$controller->test(Request::create('/','POST',['engine'=>'gost','iran_server_id'=>1,'foreign_server_id'=>2]),$network,$manager,$tester);
$op=TunnelOperation::findOrFail($response->getData()->operation->id);$test=TunnelConnectionTest::findOrFail($op->test_id);
$assert(Bus::dispatched(App\Jobs\RunNetworkTunnelTest::class)->count()>0,'network tests use their own durable worker');
$assert(DB::table('speedbox_tunnel_leases')->count()===2,'tests lease both hosts across engine types');
try{$controller->test(Request::create('/','POST',['engine'=>'gre','iran_server_id'=>1,'foreign_server_id'=>2]),$network,$manager,$tester);$assert(false,'shared leases prevent a parallel GRE probe');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$assert($e->getStatusCode()===409,'shared leases prevent a parallel GRE probe');}
$remote=$mock($calls);$op=$runTest($op,$remote);$test->refresh();
$assert($op->status==='succeeded'&&$test->status==='completed'&&count($test->results)===2,'both GOST versions measured before completion');
$assert(count(array_filter($calls,fn($c)=>$c[0]==='stop'))===4,'each case cleans up both hosts');
$assert(DB::table('speedbox_tunnel_leases')->count()===0,'test leases released after confirmed cleanup');
$assert($test->results[0]['protocols']['udp']['status']==='down','TCP success does not imply UDP support');
$public=$tester->publicTest($test);$assert(!isset($public['results'][0]['server_fingerprints'])&&!isset($public['results'][0]['peer_host']),'public results exclude endpoint bindings');
$data=[...$automatic,'version'=>'2.11.5','protocol'=>'tcp','mappings'=>[['public_port'=>25443,'target_port'=>443]],'test_id'=>$test->id,'result_id'=>$test->results[0]['id']];
$settings=$network->prepare(Request::create('/','POST',$data));$tester->assertSelection(Request::create('/','POST',$data),$settings);$assert(true,'completed matching measurement authorizes creation');
$rejectTest=function($request,$settings,$label)use($tester,$assert){try{$tester->assertSelection(Request::create('/','POST',$request),$settings);$assert(false,$label);}catch(Illuminate\Validation\ValidationException|Symfony\Component\HttpKernel\Exception\HttpException $e){$assert(true,$label);}};
$rejectTest([], $settings,'creation cannot bypass testing');
$rejectTest($data,[...$settings,'mappings'=>[['public_port'=>25443,'target_port'=>443,'destinations'=>[['address'=>'192.0.2.99','port'=>443,'weight'=>1]]]]],'custom destination cannot bypass the tested peer');
$rejectTest($data,[...$settings,'protocol'=>'udp'],'untested or failed UDP rejected');
$rejectTest($data,[...$settings,'version'=>'3.0.0'],'version must match selected measured row');
$rejectTest($data,[...$settings,'foreign_server_id'=>3],'measurement cannot be reused for another peer');
$rejectTest($data,[...$settings,'peer_host'=>'192.0.2.99'],'changed resolved address requires retest');
$rows=$test->results;$rows[0]['protocols']['tcp']['status']='unstable';$test->update(['results'=>$rows]);
$rejectTest($data,$settings,'unstable result requires explicit acceptance');
$tester->assertSelection(Request::create('/','POST',[...$data,'accept_unstable'=>true]),$settings);$assert(true,'explicit unstable acceptance permitted');
$test->timestamps=false;$test->updated_at=now()->subHours(25);$test->save();$rejectTest([...$data,'accept_unstable'=>true],$settings,'expired result rejected');
try{(new App\Http\Controllers\Api\Admin\TunnelController())->store(Request::create('/','POST',['test_id'=>$test->id,'result_id'=>$test->results[0]['id']]),$manager);$assert(false,'network measurement cannot create BackPack');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$assert($e->getStatusCode()===422,'network measurement cannot create BackPack');}
$before=App\Models\ServerTunnel::findOrFail($t->id)->settings;
foreach([['cancel',null,4],['failure',['start',1],null],['cleanup-failure',['stop',2],null]] as [$name,$failure,$cancel]){
 $calls=[];$answer=$controller->test(Request::create('/','POST',['engine'=>'gre','iran_server_id'=>1,'foreign_server_id'=>2]),$network,$manager,$tester);
 $op=TunnelOperation::findOrFail($answer->getData()->operation->id);$remote=$mock($calls,$failure);$op=$runTest($op,$remote,$cancel);
 $assert($name==='cancel'?$op->status==='cancelled':($name==='cleanup-failure'?$op->status==='failed':$op->status==='succeeded'),$name.' has truthful terminal state');
 $assert(App\Models\ServerTunnel::findOrFail($t->id)->settings===$before,$name.' does not mutate existing tunnel');
 if($name!=='cleanup-failure')$assert(count(array_filter($calls,fn($c)=>$c[0]==='stop'&&$c[1]===1))>0&&count(array_filter($calls,fn($c)=>$c[0]==='stop'&&$c[1]===2))>0,$name.' cleans both hosts including interrupted start');
 else $assert(isset($op->result['cleanup_error']),'cleanup failure remains visible');
}
$calls=[];$answer=$controller->test(Request::create('/','POST',['engine'=>'gost','iran_server_id'=>1,'foreign_server_id'=>2]),$network,$manager,$tester);$op=TunnelOperation::findOrFail($answer->getData()->operation->id);$remote=$mock($calls);
for($i=0;$i<3;$i++)(new App\Jobs\RunNetworkTunnelTest($op->id))->handle($remote,$manager);
$assert(isset($op->fresh()->payload['probe']['id']),'temporary identity is durable before a remote reply can be lost');
(new App\Jobs\RunNetworkTunnelTest($op->id))->failed(new RuntimeException('worker interrupted'));
$op=$runTest($op,$remote);$assert($op->status==='failed'&&count(array_filter($calls,fn($c)=>$c[0]==='stop'))===2,'worker recovery cleans both hosts before releasing leases');
// Partial samples must be public while the operation remains running, and a
// completed protocol never authorizes construction before pair cleanup.
$answer=$controller->test(Request::create('/','POST',['engine'=>'gost','iran_server_id'=>1,'foreign_server_id'=>2]),$network,$manager,$tester);
$op=TunnelOperation::findOrFail($answer->getData()->operation->id);$live=TunnelConnectionTest::findOrFail($op->test_id);
$assert(count($tester->publicTest($live)['results'])===2,'candidate cards exist before workers start');
$remote=Mockery::mock(NetworkTunnelRemote::class);$remote->shouldReceive('ensureProbe')->andReturnNull();
$remote->shouldReceive('probe')->andReturnUsing(fn($s,$q)=>match($q['action']){
 'start'=>['port'=>32443],'stop'=>['stopped'=>true],'measure-start'=>['state'=>'running'],
 'measure-state'=>['state'=>'running','protocols'=>['tcp'=>['status'=>'testing','phase'=>'echo','ok'=>7,'tried'=>7,'total'=>20,'rtt'=>40,'download'=>null,'upload'=>null]]]
});
for($i=0;$i<6;$i++)(new App\Jobs\RunNetworkTunnelTest($op->id))->handle($remote,$manager);
$live->refresh();$op->refresh();
$assert($op->status==='running'&&(int)$live->results[0]['ok']===7,'intermediate echo count is persisted before completion');
$assert($live->results[0]['status']==='testing'&&!$live->results[0]['selectable'],'live samples cannot be selected early');
$assert($live->results[0]['download']===null&&$live->results[0]['upload']===null,'unmeasured speeds stay unknown');
$assert(!empty($op->payload['timings']),'phase timing is retained for diagnosis');
$op->update(['cancel_requested'=>true]);$calls=[];$op=$runTest($op,$mock($calls));
$assert($op->status==='cancelled','live measurement can still be cancelled and cleaned');
$index=(new App\Http\Controllers\Api\Admin\TunnelController())->index()->getData(true);
$networkHistory=array_values(array_filter($index['tests'],fn($row)=>in_array($row['engine']??'',['gost','gre'])));
$assert(count($networkHistory)>0,'network test history is available after closing or reloading');
$assert(!array_filter($networkHistory,fn($row)=>isset($row['results'][0]['server_fingerprints'])),'history excludes private result bindings');
Mockery::close();echo "PASS: $checks network preflight assertions\n";
