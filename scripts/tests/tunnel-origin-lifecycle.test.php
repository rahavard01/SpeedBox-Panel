<?php
/** Production job transitions with in-memory model/SSH doubles; no .env or host changes. */
namespace {
    $events=[];$services=[];$checks=0;
    function event($name){$GLOBALS['events'][]=$name;}
    function app($class){return $GLOBALS['services'][$class]??=new $class;}
    function now(){return new Clock;}
    final class Clock {
        public int $timestamp=1800000000;
        public function isPast(){return false;}
        public function addSeconds($n){return $this;}
        public function addMinutes($n){return $this;}
    }
    final class Pending {public function delay($when){return $this;}}
    function check($value,$label){if(!$value)throw new \RuntimeException($label);$GLOBALS['checks']++;}
    function step($job,$op,$remote,$manager){(new \ReflectionMethod($job,'step'))->invoke($job,$op,$remote,$manager);}
}
namespace Illuminate\Contracts\Queue {interface ShouldQueue {} interface ShouldBeEncrypted {}}
namespace Illuminate\Bus {trait Queueable {public function onQueue($q){}}}
namespace Illuminate\Foundation\Bus {trait Dispatchable {public static function dispatch(...$args){return new \Pending;}}}
namespace Illuminate\Queue {trait InteractsWithQueue {} trait SerializesModels {}}
namespace Illuminate\Support\Facades {
    final class DB {public static function transaction($fn){return $fn();}}
    final class Cache {public static function lock(...$args){return new class {public function get(){return true;}public function release(){}};}}
}
namespace App\Models {
    #[\AllowDynamicProperties] class Model {
        public function __construct(array $values=[]){$this->forceFill($values);}
        public function forceFill($values){foreach($values as $k=>$v)$this->$k=$v;return $this;}
        public function update($values){\event(static::class.':'.implode(',',array_keys($values)));return $this->forceFill($values);}
        public function fresh(){return $this;}
    }
    class ManagedServer extends Model {
        public static function findOrFail($id){return new self(['id'=>$id,'name'=>'host-'.$id]);}
    }
    class ServerTunnel extends Model {
        public static $record;
        public static function with($relations){return new class {public function findOrFail($id){return ServerTunnel::$record;}};}
        public static function findOrFail($id){return self::$record;}
    }
    class TunnelOperation extends Model {public static $record;public static function findOrFail($id){return self::$record;}}
    class TunnelConnectionTest extends Model {}
}
namespace App\Services {
    class TrustedTunnelOrigin {
        public static function udpOnlyBackpack($t){return $t->engine==='backpack'&&($t->kind??'')==='reverse'&&($t->transport??'')==='udp'&&($t->orientation??'')==='normal';}
        public static bool $fail=false;
        public static function upgrade($t,$s){\event('upgrade');if(self::$fail)throw new \RuntimeException('SSH allocation unavailable');return [...$s,'source_identity'=>true,'source_identity_targets'=>[7798],'source_identity_port'=>25001];}
        public static function plan($t,$s,$side){return ['side'=>$side,'staged'=>false];}
    }
    class TunnelOriginRemote {
        public bool $fail=false;
        public $activationCheck=null;
        public array $refresh=[];
        public function refreshNodePolicy($t,$before,$after,$force=false){\event('policy');$this->refresh[]=[$before,$after,$force];if($this->fail)throw new \RuntimeException('node policy reload failed');}
        public function activate($t,$settings){\event('activate');if($this->activationCheck)($this->activationCheck)($t,$settings);}
    }
    class TunnelCdnRemote {public function revision(...$args){}public function commit(...$args){}public function prepare(...$args){}}
    class BackpackRemote {
        public array $calls=[];
        public function ensure($s){\event('ensure');}
        public function call($s,$r){$this->calls[]=$r;\event('remote:'.$r['action']);return ['active'=>true];}
        public function maintenance(...$args){return [];}
    }
    /** A swapped data dialer can attach to the old Iran listener. It stays
     * stale until protocol recovery unless the dialer is applied last. */
    class SessionBackpackRemote extends BackpackRemote {
        public int $listenerRevision=1;
        public ?int $dialRevision=1;
        public array $active=[1=>true,15=>true];
        public array $exists=[1=>true,15=>true];
        public bool $failForeignApply=false;
        public function call($s,$r){
            $this->calls[]=['server_id'=>$s->id,...$r];\event('remote:'.$r['action']);
            if($r['action']==='apply'){
                if($s->id===15&&$this->failForeignApply)throw new \RuntimeException('foreign apply failed');
                $this->exists[$s->id]=true;$this->active[$s->id]=(bool)$r['enabled'];
                if($this->active[$s->id]){
                    if($s->id===1){$this->listenerRevision++;if($this->active[15]&&$this->dialRevision===null)$this->dialRevision=$this->listenerRevision;}
                    else $this->dialRevision=$this->active[1]?$this->listenerRevision:null;
                }
            }
            return ['exists'=>$this->exists[$s->id],'active'=>$this->active[$s->id],'saved'=>true,'restored'=>true];
        }
    }
    class TunnelManager {
        public function finish($op,$status,$message,$result=[]){$op->update(['status'=>$status,'message'=>$message,'result'=>$result]);}
        public function reservedPorts($s){return [];}
    }
    class NetworkTunnelManager {
        public function config($t,$side,$settings){return ['id'=>'fixture','settings'=>$settings];}
        public function saveSnapshot($t,$s){\event('snapshot');}
    }
    class NetworkTunnelRemote {
        public array $calls=[];
        public function call($server,$r){$this->calls[]=$r;\event('remote:'.$r['action']);return [];}
    }
}
namespace {
    $root=dirname(__DIR__,2);
    foreach(['RunTunnelReconfigure','RunTunnelOperation','RunNetworkTunnelOperation'] as $name)require $root.'/app/Jobs/'.$name.'.php';
    use App\Jobs\{RunTunnelReconfigure,RunTunnelOperation,RunNetworkTunnelOperation};
    use App\Models\{ServerTunnel,TunnelOperation,ManagedServer};
    use App\Services\{TrustedTunnelOrigin,TunnelOriginRemote,BackpackRemote,TunnelManager,NetworkTunnelManager,NetworkTunnelRemote};
    $old=['enabled'=>true,'accept_udp'=>false,'tunnel_port'=>8005,'mappings'=>[['public_port'=>7798,'target_port'=>7798]]];
    $method=['kind'=>'reverse','transport'=>'tcp','orientation'=>'swapped'];
    $t=new ServerTunnel(['id'=>3,'engine'=>'backpack','remote_name'=>'sb-'.str_repeat('a',32),'settings'=>$old,'name'=>'original','iran_server_id'=>1,'foreign_server_id'=>15,'iran'=>new ManagedServer(['id'=>1]),'foreign'=>new ManagedServer(['id'=>15]),...$method]);ServerTunnel::$record=$t;
    $op=new TunnelOperation(['id'=>1,'tunnel_id'=>3,'action'=>'reconfigure','status'=>'running','deadline'=>now(),'payload'=>['method'=>$method,'settings'=>$old,'name'=>'new']]);TunnelOperation::$record=$op;
    $remote=new BackpackRemote;$manager=new TunnelManager;$job=new RunTunnelReconfigure(1);
    step($job,$op,$remote,$manager);
    check($op->payload['old_settings']===$old&&$op->payload['old_name']==='original','old policy/name durable before native mutation');
    check($op->payload['settings']['source_identity']===true,'reconfigure retains automatic identity upgrade');
    step($job,$op,$remote,$manager);
    check(count(array_filter($events,fn($v)=>$v==='upgrade'))===1,'source plan is allocated once across queue turns');

    // A preparation error must terminate through cleanup, without retrying SSH
    // allocation forever while a pair lease remains held.
    TrustedTunnelOrigin::$fail=true;
    $bad=new TunnelOperation(['id'=>2,'tunnel_id'=>3,'action'=>'reconfigure','message'=>'preparation failed','deadline'=>now(),'payload'=>['method'=>$method,'settings'=>$old,'name'=>'new']]);
    try{step(new RunTunnelReconfigure(2),$bad,$remote,$manager);throw new \RuntimeException('expected allocation failure');}catch(\RuntimeException $e){check($e->getMessage()==='SSH allocation unavailable','allocation failed before native mutation');}
    $bad->payload=[...$bad->payload,'phase'=>'cleanup-failed','index'=>2];
    step(new RunTunnelReconfigure(2),$bad,$remote,$manager);
    check($bad->status==='failed','reconfigure preparation failure releases through terminal cleanup');
    TrustedTunnelOrigin::$fail=false;

    $origin=app(TunnelOriginRemote::class);$events=[];
    $op->payload=[...$op->payload,'phase'=>'activate-origin','policy_changed'=>true,'index'=>0];
    step($job,$op,$remote,$manager);
    check($events[0]==='policy'&&$events[1]==='activate','policy reload precedes reconfigure ingress activation');
    check($op->payload['committed']===true&&$op->payload['phase']==='commit','rollback forbidden only after identity activation');

    // Restore both the database method and policy after a failed post-DB
    // activation, before evaluating old endpoint status.
    $candidate=$op->payload['settings'];$t->settings=$candidate;$t->name='new';$t->transport='quic';
    $op->payload=[...$op->payload,'phase'=>'rollback-state','policy_changed'=>true,'index'=>2,'restored_status'=>[]];
    step($job,$op,$remote,$manager);
    check($t->settings===$old&&$t->name==='original'&&$t->transport==='tcp','rollback restores original committed model');
    check(end($origin->refresh)===[ $candidate,$old,true ],'rollback forces node policy back to original');

    // Repeated Start after a failed policy refresh must still keep ingress
    // staged and force a fresh policy load, even if enabled was already saved.
    $t->settings=[...$candidate,'enabled'=>true];$start=new TunnelOperation(['id'=>3,'tunnel_id'=>3,'action'=>'start','payload'=>[],'deadline'=>now()]);
    $events=[];$remote->calls=[];
    (new \ReflectionMethod(RunTunnelOperation::class,'tunnel'))->invoke(new RunTunnelOperation(3),$start,$remote,$manager);
    check(count($remote->calls)===2&&$remote->calls[0]['origin_plan']['staged']&&$remote->calls[1]['origin_plan']['staged'],'explicit start stages both endpoint requests');
    check(end($origin->refresh)[2]===true,'every explicit start forces policy refresh');
    check(array_search('policy',$events,true)<array_search('activate',$events,true),'start exposes ingress after policy refresh');

    // Network allocation failure is a terminal preparation failure and has a
    // complete durable rollback record, without touching either native host.
    $t->engine='gost';$t->settings=$old;$t->status='connected';$t->message='ok';
    $n=new TunnelOperation(['id'=>4,'tunnel_id'=>3,'action'=>'reapply','status'=>'queued','payload'=>[],'deadline'=>now()]);TunnelOperation::$record=$n;
    TrustedTunnelOrigin::$fail=true;$nr=new NetworkTunnelRemote;
    (new RunNetworkTunnelOperation(4))->handle($nr,new NetworkTunnelManager,$manager);
    check($n->status==='failed'&&$t->settings===$old&&$nr->calls===[],'network preparation error preserves old config and touches no native host');
    TrustedTunnelOrigin::$fail=false;

    $n->status='running';$n->payload=['phase'=>'commit','side'=>0,'revision'=>str_repeat('a',32),'settings'=>[...$candidate,'transport'=>'tcp'],'old_settings'=>$old,'old_name'=>'original','old_status'=>'connected','prepared'=>['foreign','iran'],'snapshots'=>[]];
    $events=[];(new RunNetworkTunnelOperation(4))->handle($nr,new NetworkTunnelManager,$manager);
    check($n->payload['database_committed']===true,'network commit waits for policy and activation');
    check(array_search('App\\Models\\ServerTunnel:settings,name,kind,transport',$events,true)<array_search('policy',$events,true),'new committed policy is visible before reload');
    check(array_search('policy',$events,true)<array_search('activate',$events,true),'network activates after policy reload');

    // A node-policy failure after the database change must keep the rollback
    // journal and never publish ingress. A failed policy rollback is reported.
    $origin->fail=true;$n->status='running';$t->settings=$old;
    $n->payload=['phase'=>'commit','side'=>0,'revision'=>str_repeat('a',32),'settings'=>[...$candidate,'transport'=>'tcp'],'old_settings'=>$old,'old_name'=>'original','old_status'=>'connected','prepared'=>['foreign','iran'],'snapshots'=>[]];
    $events=[];(new RunNetworkTunnelOperation(4))->handle($nr,new NetworkTunnelManager,$manager);
    check($n->payload['phase']==='rollback'&&$n->payload['policy_changed']&&!isset($n->payload['database_committed']),'post-database failure remains rollback eligible');
    check(!in_array('activate',$events,true),'failed node policy never exposes ingress');
    (new RunNetworkTunnelOperation(4))->handle($nr,new NetworkTunnelManager,$manager);
    (new RunNetworkTunnelOperation(4))->handle($nr,new NetworkTunnelManager,$manager);
    check($n->status==='failed'&&$t->settings===$old,'failed activation restores original network model');
    check($t->status==='needs_review'&&isset($n->result['rollback_errors']['node_policy']),'policy rollback failure is visible in result and status');
    require $root.'/app/Services/TunnelHealth.php';require $root.'/app/Jobs/RunTunnelMaintenance.php';
    $t->engine='backpack';$t->kind='reverse';$t->orientation='normal';$t->transport='udp';$t->settings=$old;$remote->calls=[];
    $statusOp=new TunnelOperation(['id'=>8,'tunnel_id'=>3,'action'=>'status','payload'=>[],'deadline'=>now()]);
    (new \ReflectionMethod(RunTunnelOperation::class,'tunnel'))->invoke(new RunTunnelOperation(8),$statusOp,$remote,$manager);
    check(count($remote->calls)===2&&$remote->calls[0]['udp']&&$remote->calls[1]['udp'],'native UDP-only status never requires a TCP backend');
    $remote->calls=[];(new \ReflectionMethod(RunTunnelReconfigure::class,'status'))->invoke($job,$remote,$t->foreign,$t,$old,'foreign');
    check($remote->calls[0]['udp'],'UDP-only reconfigure verification uses UDP status');
    $remote->calls=[];(new \ReflectionMethod(\App\Jobs\RunTunnelMaintenance::class,'tunnelStatus'))->invoke(new \App\Jobs\RunTunnelMaintenance(8),$t,$remote);
    check(count($remote->calls)===2&&$remote->calls[0]['udp']&&$remote->calls[1]['udp'],'UDP-only engine maintenance does not require TCP backend');
    $before=['metrics'=>['taken'=>'2026-10-26T00:00:00Z'],'targets'=>[7798=>true]];$after=['active'=>true,'metrics'=>['connected'=>true,'taken'=>'2026-10-27T00:00:00Z'],'targets'=>[7798=>null]];
    $p=['settings'=>$old,'method'=>['kind'=>'reverse','transport'=>'udp','orientation'=>'normal'],'baseline'=>['iran'=>$before,'foreign'=>$before],'activated_at'=>['iran'=>1,'foreign'=>1]];
    check($job->connected(['iran'=>$after,'foreign'=>$after],$p),'switch from TCP to native UDP accepts a verified carrier without a TCP backend');
    $p['method']['transport']='tcp';check(!$job->connected(['iran'=>$after,'foreign'=>$after],$p),'TCP reconfiguration still requires prior working backend to recover');
    $origin->fail=false;
    foreach(['existing','new','stopped','failure'] as $scenario){
        $t->engine='backpack';$t->kind='reverse';$t->transport='tcp';$t->orientation='swapped';$t->subnet=6992;$t->secret=str_repeat('c',64);
        $t->iran->host='192.0.2.1';$t->foreign->host='192.0.2.2';$t->settings=[...$old,'enabled'=>$scenario!=='stopped'];
        $session=new \App\Services\SessionBackpackRemote;
        if($scenario==='new'){$session->active=[1=>false,15=>false];$session->exists=[1=>false,15=>false];$session->dialRevision=null;}
        if($scenario==='failure')$session->failForeignApply=true;
        $origin->activationCheck=function($t,$settings)use($session){if(($settings['enabled']??true)&&$session->dialRevision!==$session->listenerRevision)throw new \RuntimeException('dialer attached to old listener');};
        $apply=new TunnelOperation(['id'=>9,'tunnel_id'=>3,'action'=>'apply','payload'=>['settings'=>$t->settings],'deadline'=>now()]);
        (new \ReflectionMethod(RunTunnelOperation::class,'tunnel'))->invoke(new RunTunnelOperation(9),$apply,$session,$manager);
        $requests=array_values(array_filter($session->calls,fn($r)=>$r['action']==='apply'));
        check($apply->status===($scenario==='failure'?'failed':'succeeded'),"$scenario terminal result: ".($apply->result['error']??''));
        check(array_column($requests,'server_id')===[1,15],"$scenario applies stable data listener before dialer");
        if($scenario==='existing'||$scenario==='new')check($session->dialRevision===$session->listenerRevision,"$scenario authenticated activation uses current listener");
        if($scenario==='stopped')check(!$session->active[1]&&!$session->active[15]&&!$t->settings['enabled'],'stopped pair stays stopped');
        if($scenario==='failure')check($apply->result['rollback']===['iran'=>true,'foreign'=>true],'failed pair restores both snapshots');
    }
    $origin->activationCheck=null;
    foreach([['reverse','normal',[1,15]],['reverse','swapped',[1,15]],['direct','normal',[15,1]],['direct','swapped',[1,15]]] as [$kind,$orientation,$expected]){
        $t->kind=$kind;$t->orientation=$orientation;$sides=RunTunnelOperation::applyOrder($t);check(array_map(fn($side)=>$t->$side->id,$sides)===$expected,"$kind/$orientation data-listener order");
    }
    echo 'TUNNEL ORIGIN LIFECYCLE: '.$checks." checks passed\n";
}
