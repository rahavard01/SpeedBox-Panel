<?php
use App\Services\RuntimeSetting;
use Illuminate\Support\Facades\DB;
// Included only by the isolated preventive fixture (SQLite/MySQL), never live.
config(['speedbox.node_rate_limit'=>1200, 'speedbox.node_ip_rate_limit'=>12000]);
$app['cache']->flush();
RuntimeSetting::set('connection_lease_batch_enable', true);
DB::table('speedbox_connection_leases')->delete();
DB::table('v2_user')->where('id',$userId)->update(['banned'=>0,'u'=>0,'d'=>0,'transfer_enable'=>1000000,'device_limit'=>0,'expired_at'=>0,'group_id'=>1]);
$node->group_ids=[1]; $node->enabled=true; $node->transfer_enable=0; $node->save();
$batchPath='connections/lease/renew-batch';
$batch=[];
for ($i=0;$i<64;$i++) {
    $item=['user_id'=>$userId,'source_ip'=>'198.18.1.'.($i+1),'lease_id'=>hash('sha256','batch-'.$i)];
    $check($allowed($send($item+['operation'=>'acquire']+$auth)), 'Legacy immediate acquisition remains available');
    $batch[]=$item;
}
$response=$send(['leases'=>$batch]+$auth, '198.51.100.30', $batchPath);
$check($response->getStatusCode()===200, '64 renewals in one authenticated request: '.$response->getContent());
$results=json_decode($response->getContent(),true)['results'];
$check(count($results)===64 && count(array_filter($results,fn($r)=>$r['allowed'] && $r['ttl']===60))===64, 'Every unlimited IP is renewed with the same 60-second lifetime');
$check(DB::table('speedbox_connection_leases')->where('user_id',$userId)->count()===64, 'All IP rows remain counted');
$snapshot=DB::table('speedbox_connection_leases')->orderBy('id')->get()->toJson();
foreach ([[], array_merge($batch,[$batch[0]]), [$batch[0],$batch[0]], [$batch[0],array_replace($batch[0],['source_ip'=>'bad'])], ['key'=>$batch[0]], [$batch[0],array_replace($batch[0],['source_ip'=>'::ffff:198.18.1.1'])], [array_replace($batch[0],['operation'=>'release'])]] as $badBatch) {
    $response=$send(['leases'=>$badBatch]+$auth, '198.51.100.31', $batchPath);
    $check($response->getStatusCode()===422, 'Malformed batch rejected before writes: '.$response->getStatusCode());
    $check(DB::table('speedbox_connection_leases')->orderBy('id')->get()->toJson()===$snapshot, 'Invalid envelope cannot partially renew or release');
}
$wrong=$auth; $wrong['token']='wrong';
$check($send(['leases'=>[$batch[0]]]+$wrong,'198.51.100.32',$batchPath)->getStatusCode()===401,'Batch requires valid node credentials');
$wrong=['machine_id'=>$wrongMachine->id,'token'=>$wrongMachine->token,'node_id'=>$node->id];
$check($send(['leases'=>[$batch[0]]]+$wrong,'198.51.100.33',$batchPath)->getStatusCode()===404,'Batch cannot borrow another machine node');
DB::table('v2_user')->where('id',$userId)->update(['device_limit'=>1]);
$response=$send(['leases'=>[$batch[1],$batch[0]]]+$auth,'198.51.100.34',$batchPath);
$results=json_decode($response->getContent(),true)['results'];
$check(!$results[0]['allowed'] && $results[0]['ttl']===0 && $results[1]['allowed'], 'Reduced IP limit denies only the newer address, independently of batch order');
$stale=array_replace($batch[0],['lease_id'=>str_repeat('f',64)]);
$results=json_decode($send(['leases'=>[$stale,$batch[1]]]+$auth,'198.51.100.35',$batchPath)->getContent(),true)['results'];
$check(!$results[0]['allowed'] && !$results[1]['allowed'],'Stale owner never renews and IP limit is still enforced');
foreach ([['banned'=>1],['banned'=>0,'expired_at'=>time()-1],['expired_at'=>0,'u'=>1000000]] as $denied) {
    DB::table('v2_user')->where('id',$userId)->update($denied);
    $results=json_decode($send(['leases'=>[$batch[0]]]+$auth,'198.51.100.36',$batchPath)->getContent(),true)['results'];
    $check(!$results[0]['allowed'],'Batch keeps ban, expiration and quota checks');
}
RuntimeSetting::set('connection_lease_batch_enable',false);
$check($send(['leases'=>[$batch[0]]]+$auth,'198.51.100.37',$batchPath)->getStatusCode()===404,'Explicit kill switch permits old-protocol fallback');
$handshake=json_decode($send($auth,'198.51.100.38','handshake')->getContent(),true);
$check($handshake['connection_limits']['renew_batch']['max_items']===0,'Disabled capability is not advertised');
RuntimeSetting::set('connection_lease_batch_enable',true);
$handshake=json_decode($send($auth,'198.51.100.39','handshake')->getContent(),true);
$check($handshake['connection_limits']['renew_batch']===['version'=>1,'max_items'=>64],'Handshake advertises bounded versioned capability');
echo "BATCH RENEWALS OK: authenticated envelope, all IPs, ownership, limits and denial isolation.\n";
