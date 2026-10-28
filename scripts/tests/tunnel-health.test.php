<?php
/** Production health projection and alert decision; no database/SSH writes. */
namespace {function now(){return (object)['timestamp'=>time()];}}
namespace Illuminate\Support\Facades {
 class DB {static function table($name){return new class {function insert($v){}function where(...$a){return $this;}function delete(){}};}}
}
namespace App\Models {
 #[\AllowDynamicProperties] class ServerTunnel {function __construct($v){foreach($v as $k=>$x)$this->$k=$x;}function update($v){foreach($v as $k=>$x)$this->$k=$x;}}
}
namespace {
 $root=dirname(__DIR__,2);foreach(['TunnelHealth','NetworkTunnelManager','TunnelOutageMonitor'] as $file)require $root.'/app/Services/'.$file.'.php';
 use App\Services\{TunnelHealth,NetworkTunnelManager,TunnelOutageMonitor};use App\Models\ServerTunnel;
 $manager=new NetworkTunnelManager;$monitor=new TunnelOutageMonitor;$checks=0;
 function check($v,$label){if(!$v)throw new \RuntimeException($label);$GLOBALS['checks']++;}
 $settings=['enabled'=>true,'source_identity'=>true,'source_identity_port'=>25001,'source_identity_runtime_version'=>3];
 $good=['active'=>true,'connected'=>true,'origin_endpoint'=>true,'path_verified'=>true,'source_identity_targets'=>[7798=>true]];
 foreach(['gost','gre','wattunnel'] as $engine){
  $t=new ServerTunnel(['id'=>1,'engine'=>$engine,'transport'=>'tcp','settings'=>$settings]);
  $assert=function($snap,$reason,$status,$health)use($manager,$monitor,$t,$engine){$manager->saveSnapshot($t,$snap);check(TunnelHealth::reason($t)===$reason,"$engine reason $reason");check($t->status===$status,"$engine status $status");check($monitor->health($t)===$health,"$engine alert health");};
  $snap=['iran'=>$good,'foreign'=>$good];$assert($snap,null,'connected',true);
  $snap['foreign']['origin_endpoint']=false;$assert($snap,'endpoint','offline',false);
  $snap=['iran'=>$good,'foreign'=>$good];unset($snap['foreign']['origin_endpoint']);$assert($snap,'unknown','degraded',null);
  $snap=['iran'=>$good,'foreign'=>$good];$snap['foreign']['source_identity_targets'][7798]=false;$assert($snap,'target','degraded',false);check(str_contains($t->message,'نود مقصد'),'backend failure has its own message');
  $snap=['iran'=>$good,'foreign'=>$good];$snap['iran']['target_available']=false;$snap['iran']['connected']=false;$assert($snap,'target','degraded',false);
  $snap=['iran'=>$good,'foreign'=>$good];$snap['iran']['connected']=false;$assert($snap,'carrier','offline',false);
  if($engine!=='gost'){$snap=['iran'=>$good,'foreign'=>$good];$snap['foreign']['connected']=false;$assert($snap,'carrier','offline',false);}
  else {$snap=['iran'=>$good,'foreign'=>$good];$snap['iran']['path_verified']=false;$assert($snap,'carrier','offline',false);}
  $t->settings=[...$settings,'enabled'=>false];$assert(['iran'=>$good,'foreign'=>$good],'stopped','stopped',null);
 }
 $t->engine='backpack';$t->settings=$settings;$t->remote_status=['iran'=>[...$good,'metrics'=>['connected'=>true]],'foreign'=>[...$good,'metrics'=>['connected'=>true]]];check($monitor->health($t)===true,'BackPack uses both native metric snapshots');$t->remote_status['foreign']['metrics']['connected']=false;check($monitor->health($t)===false,'BackPack false carrier metric is down');
 $t->settings=[...$settings,'quota_mode'=>'enforce'];$t->remote_status['iran']['quota_exhausted']=true;check(TunnelHealth::reason($t)==='quota'&&$monitor->health($t)===null,'quota stop does not open carrier outage');
 $t->settings=$settings;$t->remote_status=['iran'=>['error'=>'SSH unavailable'],'foreign'=>$good];check(TunnelHealth::reason($t)==='access'&&$monitor->health($t)===null,'SSH failure is not confirmed carrier loss');
 echo "TUNNEL HEALTH: $checks checks passed\n";
}
