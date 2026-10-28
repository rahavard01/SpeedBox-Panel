<?php
namespace App\Jobs;

use App\Models\{ServerTunnel,TunnelOperation};
use App\Services\{BackpackRemote,TunnelManager};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted,ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\{Cache,DB};
use Throwable;

/** Keep the old card/configuration authoritative until both new endpoints connect. */
class RunTunnelReconfigure implements ShouldQueue,ShouldBeEncrypted
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=1;
    public int $timeout=600;
    public function __construct(public readonly int $operationId){$this->onQueue('deployments');}

    public function handle(BackpackRemote $remote,TunnelManager $manager): void
    {
        $lock=Cache::lock('speedbox-tunnel-operation-'.$this->operationId,630);
        if(!$lock->get()){self::dispatch($this->operationId)->delay(now()->addSeconds(10));return;}
        try {
            $op=TunnelOperation::findOrFail($this->operationId);
            if(!in_array($op->status,['queued','running'],true))return;
            $op->update(['status'=>'running']);
            $this->step($op,$remote,$manager);
        } catch(Throwable) {
            if(isset($op))$this->recover($op);
        } finally {$lock->release();}
    }

    private function step(TunnelOperation $op,BackpackRemote $remote,TunnelManager $manager): void
    {
        $p=$op->payload;$phase=$p['phase']??'ensure';$i=(int)($p['index']??0);
        $t=ServerTunnel::with(['iran','foreign'])->findOrFail($op->tunnel_id);
        $servers=['iran'=>$t->iran,'foreign'=>$t->foreign];$sides=['iran','foreign'];
        $revision=str_pad(dechex($op->id),32,'0',STR_PAD_LEFT);
        if(!isset($p['old_method'])) {
            $p['old_cdn']=($t->settings['connection_mode']??'direct')==='cdn';
            $p['old_method']=['kind'=>$t->kind,'transport'=>$t->transport,'orientation'=>$t->orientation];
            $p['old_settings']=$t->settings;$p['old_name']=$t->name;
            $op->update(['payload'=>$p]);
        }
        if($phase==='ensure' && empty($p['origin_upgraded'])) {
            $p['settings']=\App\Services\TrustedTunnelOrigin::upgrade($t,$p['settings']);
            $p['origin_upgraded']=true;$op->update(['payload'=>$p]);
        }
        if(!str_starts_with($phase,'rollback') && !in_array($phase,['cleanup-failed','commit'],true) && $op->deadline->isPast())throw new \RuntimeException('deadline');
        $candidate=clone $t;$candidate->forceFill($p['method']);
        if($phase==='ensure') {
            if(isset($sides[$i])){$remote->ensure($servers[$sides[$i]]);$this->next($op,[...$p,'index'=>$i+1],'در حال بررسی موتور دو سرور…',5);return;}
            $this->next($op,[...$p,'phase'=>'baseline','index'=>0],'در حال ذخیرهٔ تنظیمات قبلی…',10);return;
        }
        if($phase==='baseline') {
            if(isset($sides[$i])) {
                $side=$sides[$i];$r=$this->status($remote,$servers[$side],$t,$t->settings,$side);
                if(empty($r['exists']))throw new \RuntimeException('missing_previous_configuration');
                $p['baseline'][$side]=$r;$op->update(['payload'=>$p]);
                app(\App\Services\TunnelCdnRemote::class)->revision($t,$p['settings'],$side,'snapshot',$revision);
                $r=$remote->call($servers[$side],['action'=>'snapshot','name'=>$t->remote_name,'revision'=>$revision]);
                if(empty($r['saved']))throw new \RuntimeException('snapshot_not_saved');
                $p['snapshots'][]=$side;
                $this->next($op,[...$p,'index'=>$i+1],'در حال ذخیرهٔ تنظیمات قبلی دو سمت…',15);return;
            }
            $this->next($op,[...$p,'phase'=>'reserve','index'=>0],'در حال آماده‌سازی پورت‌های روش جدید…',20);return;
        }
        if($phase==='reserve') {
            if(isset($sides[$i])) {
                $side=$sides[$i];$ports=[...$manager->reservedPorts($servers[$side]),(int)$p['settings']['tunnel_port']];
                if(!empty($p['settings']['source_identity_port']))$ports[]=(int)$p['settings']['source_identity_port'];
                foreach($p['settings']['mappings'] as $map)$ports[]=(int)$map[$side==='iran'?'public_port':'target_port'];
                $answer=$remote->maintenance($servers[$side],'reserve',$ports);
                if(isset($answer['status']))$manager->saveHostStatus($servers[$side]->id,$answer['status']);
                $this->next($op,[...$p,'index'=>$i+1],'در حال آماده‌سازی پورت‌های دو سمت…',25);return;
            }
            // The intent is durable before either existing service can be stopped.
            $this->next($op,[...$p,'phase'=>'stop','index'=>0,'mutating'=>true],'در حال جابه‌جایی روش اتصال…',30);return;
        }
        if($phase==='stop') {
            if(isset($sides[$i])) {
                $r=$remote->call($servers[$sides[$i]],['action'=>'stop','name'=>$t->remote_name]);
                if(($r['active']??true)!==false)throw new \RuntimeException('stop_failed');
                $this->next($op,[...$p,'index'=>$i+1],'در حال جابه‌جایی روش اتصال…',35);return;
            }
            $this->next($op,[...$p,'phase'=>'apply','index'=>0],'در حال راه‌اندازی روش جدید…',40);return;
        }
        if($phase==='apply') {
            $order=RunTunnelOperation::applyOrder($candidate);
            if(isset($order[$i])) {
                $side=$order[$i];
                app(\App\Services\TunnelCdnRemote::class)->prepare($candidate,$p['settings'],$side);
                $r=$remote->call($servers[$side],(new RunTunnelOperation($op->id))->applyRequest($candidate,$p['settings'],$side));
                if((bool)($r['active']??false)!==(bool)($p['settings']['enabled']??true)||empty($r['checked_at']))throw new \RuntimeException('new_service_state_mismatch');
                $p['activated_at'][$side]=(int)$r['checked_at'];
                $this->next($op,[...$p,'index'=>$i+1],'در حال راه‌اندازی روش جدید روی '.($side==='iran'?'ایران':'خارج').'…',60);return;
            }
            $this->next($op,[...$p,'phase'=>'verify','verify_until'=>now()->addSeconds(120)->timestamp],'در انتظار تأیید ارتباط جدید دو سمت…',75,5);return;
        }
        if($phase==='verify') {
            $after=[];foreach($sides as $side)$after[$side]=$this->status($remote,$servers[$side],$candidate,$p['settings'],$side);
            $stopped=!($p['settings']['enabled']??true);
            if($stopped ? (!empty($after['iran']['active'])||!empty($after['foreign']['active'])) : !$this->connected($after,$p)) {
                if(now()->timestamp >= $p['verify_until'])throw new \RuntimeException('connection_not_verified');
                $this->next($op,$p,'در انتظار تأیید ارتباط جدید دو سمت…',80,5);return;
            }
            // DB state and the point after which rollback is forbidden commit together.
            DB::transaction(function()use($op,$p,$t,$candidate,$after){
                if(($p['settings']['connection_mode']??'direct')==='cdn'||($t->settings['connection_mode']??'direct')==='cdn')app(\App\Services\TunnelCdnRemote::class)->commit($candidate,$p['settings']);
                $t->update([...$p['method'],'settings'=>$p['settings'],'name'=>$p['name'],'remote_status'=>$after,'status'=>($p['settings']['enabled']??true)?'connected':'stopped','message'=>($p['settings']['enabled']??true)?'روش اتصال تغییر کرد و ارتباط دو سمت تأیید شد.':'روش اتصال تغییر کرد؛ تونل متوقف باقی ماند.']);
                $op->update(['payload'=>[...$p,'policy_changed'=>true,'phase'=>'activate-origin','index'=>0],'result'=>['before'=>$p['old_method'],'after'=>$p['method'],'servers'=>$after],'message'=>$t->message,'progress'=>95]);
            });
            self::dispatch($op->id);return;
        }
        if($phase==='activate-origin') {
            app(\App\Services\TunnelOriginRemote::class)->refreshNodePolicy($t,$p['old_settings'],$p['settings']);
            app(\App\Services\TunnelOriginRemote::class)->activate($t,$p['settings']);
            $this->next($op,[...$p,'committed'=>true,'phase'=>'commit','index'=>0],'در حال نهایی‌کردن تغییر روش…',95);return;
        }
        if($phase==='rollback-stop') {
            if(isset($sides[$i])) {
                try{$remote->call($servers[$sides[$i]],['action'=>'stop','name'=>$t->remote_name]);}catch(Throwable){}
                $this->next($op,[...$p,'index'=>$i+1],'در حال بازگردانی روش قبلی…',85);return;
            }
            $this->next($op,[...$p,'phase'=>'rollback','index'=>0],'در حال بازگردانی تنظیمات قبلی…',85);return;
        }
        if($phase==='rollback') {
            $restore=array_reverse($p['snapshots']??[]);
            if(isset($restore[$i])) {
                $side=$restore[$i];
                try {
                    app(\App\Services\TunnelCdnRemote::class)->revision($t,$p['settings'],$side,'rollback',$revision);
                    $r=$remote->call($servers[$side],['action'=>'rollback','name'=>$t->remote_name,'revision'=>$revision]);
                    if(empty($r['restored']))throw new \RuntimeException('rollback_failed');
                } catch(Throwable) {
                    $p['rollback_attempts'][$side]=($p['rollback_attempts'][$side]??0)+1;
                    if($p['rollback_attempts'][$side]<3){$this->next($op,$p,'در حال تلاش دوباره برای بازگردانی روش قبلی…',85,5);return;}
                    $p['rollback_errors'][$side]=true;
                }
                $this->next($op,[...$p,'index'=>$i+1],'در حال بازگردانی تنظیمات قبلی دو سمت…',90);return;
            }
            $this->next($op,[...$p,'phase'=>'rollback-state','index'=>0],'در حال بررسی وضعیت روش قبلی…',90);return;
        }
        if($phase==='rollback-state') {
            if(!empty($p['policy_changed']) && empty($p['policy_restored'])) {
                $t->update([...$p['old_method'],'settings'=>$p['old_settings'],'name'=>$p['old_name']]);
                try {app(\App\Services\TunnelOriginRemote::class)->refreshNodePolicy($t,$p['settings'],$p['old_settings'],true);}
                catch(Throwable){$p['rollback_errors']['node_policy']=true;}
                $p['policy_restored']=true;$op->update(['payload'=>$p]);
            }
            if(isset($sides[$i])) {
                $side=$sides[$i];
                try {
                    if(empty($p['baseline'][$side]['active']))$remote->call($servers[$side],['action'=>'stop','name'=>$t->remote_name]);
                    $r=$this->status($remote,$servers[$side],$t,$t->settings,$side);
                    if((bool)($r['active']??false)!==(bool)$p['baseline'][$side]['active'])throw new \RuntimeException('previous_service_state_not_restored');
                    $p['restored_status'][$side]=$r;
                } catch(Throwable){$p['rollback_errors'][$side]=true;}
                $this->next($op,[...$p,'index'=>$i+1],'در حال بررسی وضعیت روش قبلی دو سمت…',95);return;
            }
            $r=$p['restored_status']??[];$failed=!empty($p['rollback_errors']);
            $message=$failed?'تغییر روش کامل نشد؛ بازگردانی یکی از دو سرور نیازمند بررسی است.':'تغییر روش تأیید نشد؛ تنظیمات قبلی بازگردانده شد.';
            $state=$failed?'needs_review':((empty($r['iran']['active'])&&empty($r['foreign']['active']))?'stopped':($this->connected($r,[...$p,'settings'=>$t->settings])?'connected':'checking'));
            $t->update(['status'=>$state,'remote_status'=>$r,'message'=>$message]);
            $op->update(['result'=>['rollback'=>array_map(fn($side)=>!isset($p['rollback_errors'][$side]),array_combine($sides,$sides)),'node_policy'=>!isset($p['rollback_errors']['node_policy']),'servers'=>$r]]);
            $this->next($op,[...$p,'phase'=>'cleanup-failed','index'=>0],$message,95);return;
        }
        if(in_array($phase,['commit','cleanup-failed'],true)) {
            if(isset($sides[$i])) {
                $side=$sides[$i];
                try{$answer=$remote->maintenance($servers[$side],'reserve',$manager->reservedPorts($servers[$side]));if(isset($answer['status']))$manager->saveHostStatus($servers[$side]->id,$answer['status']);}catch(Throwable){}
                // Never delete a backup that is still needed by a failed rollback.
                if($phase==='commit'||empty($p['mutating']))try{app(\App\Services\TunnelCdnRemote::class)->revision($t,$p['settings'],$side,'commit',$revision,!empty($p['old_cdn']));$remote->call($servers[$side],['action'=>'commit','name'=>$t->remote_name,'revision'=>$revision]);}catch(Throwable){}
                $this->next($op,[...$p,'index'=>$i+1],$op->message,95);return;
            }
            $manager->finish($op,$phase==='commit'?'succeeded':'failed',$phase==='commit'?(($p['settings']['enabled']??true)?'روش اتصال تغییر کرد و ارتباط دو سمت تأیید شد.':'روش اتصال تغییر کرد؛ توقف دو سمت حفظ شد.'):($op->message?:'تغییر روش شروع نشد؛ تنظیمات قبلی حفظ شد.'),$op->result??[]);return;
        }
        throw new \RuntimeException('invalid_phase');
    }

    private function status(BackpackRemote $remote,$server,ServerTunnel $t,array $settings,string $side): array
    {
        $r=$remote->call($server,['action'=>'status','name'=>$t->remote_name,'target_ports'=>$side==='foreign'?array_column($settings['mappings'],'target_port'):[],'udp'=>\App\Services\TrustedTunnelOrigin::udpOnlyBackpack($t)||(bool)$settings['accept_udp']]);
        if(($settings['connection_mode']??'direct')==='cdn')$r['cdn']=app(\App\Services\TunnelCdnRemote::class)->status($t,$settings,$side);
        return $r;
    }

    public function connected(array $after,array $p): bool
    {
        foreach(['iran','foreign'] as $side) {
            $r=$after[$side]??[];$taken=strtotime($r['metrics']['taken']??'');
            if(isset($r['cdn']['cdn_verified'])&&!$r['cdn']['cdn_verified'])return false;
            if(empty($r['active'])||($r['metrics']['connected']??null)!==true||!$taken||$taken<($p['activated_at'][$side]??PHP_INT_MAX)||$taken<=strtotime($p['baseline'][$side]['metrics']['taken']??''))return false;
        }
        $udpOnly=($p['method']['kind']??'')==='reverse'&&($p['method']['transport']??'')==='udp'&&($p['method']['orientation']??'')==='normal';
        if(empty($p['settings']['accept_udp'])&&!$udpOnly)foreach($p['baseline']['foreign']['targets']??[] as $port=>$working) {
            if($working===true&&in_array((int)$port,array_column($p['settings']['mappings'],'target_port'),true)&&($after['foreign']['targets'][$port]??null)!==true)return false;
        }
        return true;
    }

    private function next(TunnelOperation $op,array $p,string $message,int $progress,int $delay=1): void
    {
        $op->update(['payload'=>$p,'message'=>$message,'progress'=>$progress]);self::dispatch($op->id)->delay(now()->addSeconds($delay));
    }
    private function recover(TunnelOperation $op): void
    {
        $p=$op->payload;
        if(!empty($p['committed'])){$this->next($op,[...$p,'phase'=>'commit'],'در حال نهایی‌کردن تغییر روش…',95);return;}
        if(str_starts_with($p['phase']??'','rollback')){$this->next($op,$p,'در حال ادامهٔ بازگردانی روش قبلی…',85,5);return;}
        $this->next($op,[...$p,'phase'=>empty($p['mutating'])?'cleanup-failed':'rollback-stop','index'=>0],'تغییر روش کامل نشد؛ تنظیمات قبلی در حال بررسی است.',85);
    }
    public function failed(?Throwable $error): void
    {
        $op=TunnelOperation::find($this->operationId);if($op&&in_array($op->status,['queued','running'],true))$this->recover($op);
    }
}

