<?php

// Upgrade all managed pairs through the panel's locked, paired operations.
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$only=null;
foreach(array_slice($argv,1) as $arg) {
    if(in_array($arg,['--apply-all','--refresh-runtime'],true))continue;
    if(preg_match('/^--tunnel=([1-9][0-9]*)$/D',$arg,$m)){$only=(int)$m[1];continue;}
    throw new RuntimeException('Unknown option.');
}
$apply = in_array('--apply-all', $argv, true);
$refresh = in_array('--refresh-runtime', $argv, true);
if($refresh&&!$apply)throw new RuntimeException('--refresh-runtime requires --apply-all.');
foreach(['verify-origin.py','verify-backpack.py'] as $verifier){
    passthru('python3 '.escapeshellarg($root.'/scripts/'.$verifier),$verified);
    if($verified!==0)throw new RuntimeException('Artifact verification failed; no tunnel was changed.');
}
$manager=app(App\Services\TunnelManager::class);
$wait=function($op,int $seconds): void {
    $deadline=time()+$seconds;$last=null;
    do {
        $op->refresh();$phase=$op->payload['phase']??'queued';
        if($phase!==$last){echo 'operation_id='.$op->id.' phase='.$phase.PHP_EOL;$last=$phase;}
        if($op->status==='succeeded'){echo 'operation_id='.$op->id.' status=succeeded'.PHP_EOL;return;}
        if(!in_array($op->status,['queued','running'],true)){
            $details=['status'=>$op->status,'message'=>$op->message,
                ...array_intersect_key($op->result??[],array_flip(['error','rollback','rollback_errors','cleanup_pending']))];
            $text=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            $secret=App\Models\ServerTunnel::find($op->tunnel_id)?->secret;
            if($secret)$text=str_replace($secret,'[SECRET]',$text);
            echo 'operation_id='.$op->id.' result='.$text.PHP_EOL;
            throw new RuntimeException('Upgrade failed; inspect operation '.$op->id.' and its rollback report in the panel.');
        }
        if(time()>=$deadline)throw new RuntimeException('Operation '.$op->id.' is still pending. Inspect the deployments queue before retrying.');
        sleep(2);
    }while(true);
};
$tunnels = App\Models\ServerTunnel::where('status', '!=', 'deleted')->when($only,fn($q)=>$q->where('id',$only))->orderBy('id')->get();
// Upgrade each shared BackPack host component once, before applying configs
// that rely on the new native enabled/rollback contract. Leases include peers.
if($refresh){
    $done=[];
    foreach($tunnels as $tunnel){
        if($tunnel->engine!=='backpack'||isset($done[$tunnel->id]))continue;
        $scope=$manager->maintenanceScope($tunnel);
        echo 'BACKPACK RUNTIME servers='.implode(',',$scope['server_ids']).PHP_EOL;
        $wait($manager->queue('engine-update',$tunnel,null,['confirmed_server_ids'=>$scope['server_ids']]),2760);
        foreach($scope['tunnel_ids'] as $id)$done[$id]=true;
    }
}
foreach ($tunnels as $tunnel) {
    $tunnel->refresh();
    $settings = App\Services\TrustedTunnelOrigin::upgrade($tunnel, $tunnel->settings);
    echo 'tunnel_id='.$tunnel->id.' source_identity='.(!empty($settings['source_identity']) ? 'enabled' : 'not-required')
        .(!empty($settings['source_identity_port'])?' relay_port='.$settings['source_identity_port']:'').PHP_EOL;
    // MySQL JSON can reorder object keys; compare settings by value.
    if (!$apply || (!$refresh && $settings == $tunnel->settings)) continue;
    $op = $manager->queue($tunnel->engine==='backpack'?'apply':'reapply', $tunnel, null, ['settings' => $settings]);
    $wait($op,660);
    $fresh = $tunnel->fresh();
    if ($fresh->settings != $settings) throw new RuntimeException('Paired tunnel configuration was not committed.');
}
echo $apply ? 'TUNNEL ORIGIN UPGRADE COMPLETE'.PHP_EOL : 'PREVIEW ONLY: use --apply-all to commit paired upgrades.'.PHP_EOL;

