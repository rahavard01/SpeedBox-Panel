<?php
namespace Illuminate\Contracts\Queue {interface ShouldQueue {}interface ShouldBeEncrypted {}}
namespace Illuminate\Bus {trait Queueable {public function onQueue($q){}}}
namespace Illuminate\Foundation\Bus {trait Dispatchable {}}
namespace Illuminate\Queue {trait SerializesModels {}trait InteractsWithQueue {public array $delays=[];public function release($seconds){$this->delays[]=$seconds;}}}
namespace Symfony\Component\HttpKernel\Exception {interface HttpExceptionInterface extends \Throwable {public function getStatusCode();}}
namespace App\Services {
 class Busy extends \RuntimeException implements \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface {public function getStatusCode(){return 409;}}
 class TunnelManager {public array $calls=[];public bool $busy=false;public function queue($action,$t,$test,$payload){if($this->busy)throw new Busy;$this->calls[]=[$action,$t->id,$payload];}}
}
namespace {
 require __DIR__.'/tunnel-origin-plan.test.php';require dirname(__DIR__,2).'/app/Jobs/SyncTunnelOrigins.php';
 use App\Models\{ServerTunnel,ProxyServer};use App\Services\{TrustedTunnelOrigin,TunnelManager};use App\Jobs\SyncTunnelOrigins;
 $s=[...$base,'mappings'=>[['public_port'=>7798,'public_end'=>7801,'target_port'=>7798,'target_end'=>7801]]];
 $t=new ServerTunnel(['id'=>3,'engine'=>'backpack','foreign_server_id'=>15,'iran_server_id'=>1,'remote_name'=>'sb-'.str_repeat('a',32),'secret'=>str_repeat('b',64),'settings'=>$s,'status'=>'connected']);$t->settings=TrustedTunnelOrigin::upgrade($t,$s);ServerTunnel::$rows=[$t];
 $manager=new TunnelManager;(new SyncTunnelOrigins([15]))->handle($manager);check($manager->calls===[],'unchanged enrolled topology does not restart');
 $new=new ProxyServer(['id'=>12,'machine_id'=>15,'enabled'=>true,'parent_id'=>null,'server_port'=>7799]);ProxyServer::$rows[]=$new;
 (new SyncTunnelOrigins([1]))->handle($manager);check($manager->calls===[],'unrelated machine does not restart pair');
 (new SyncTunnelOrigins([15]))->handle($manager);check(count($manager->calls)===1&&$manager->calls[0][0]==='apply'&&$manager->calls[0][1]===3,'new mapped node queues paired apply');check($manager->calls[0][2]['settings']===$t->settings,'job leaves settings to leased paired operation');
 $manager->busy=true;$job=new SyncTunnelOrigins([15]);$job->handle($manager);check($job->delays===[15],'shared server lease schedules retry');
 $manager->busy=false;$t->settings=TrustedTunnelOrigin::upgrade($t,$t->settings);$manager->calls=[];(new SyncTunnelOrigins([15]))->handle($manager);check($manager->calls===[],'successful enrollment terminates retries');
 $t->engine='gost';$new->enabled=false;(new SyncTunnelOrigins([15]))->handle($manager);check($manager->calls[0][0]==='reapply','enablement change queues network reapply');
 $manager->calls=[];$t->engine='wattunnel';(new SyncTunnelOrigins([15]))->handle($manager);check($manager->calls===[],'Wat native mapped-port identity needs no adapter enrollment');
 echo "TUNNEL ORIGIN SYNC: production job checks passed\n";
}
