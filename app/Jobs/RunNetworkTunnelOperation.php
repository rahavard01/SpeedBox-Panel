<?php
namespace App\Jobs;

use App\Models\{ManagedServer,ServerTunnel,TunnelOperation};
use App\Services\{NetworkTunnelManager,NetworkTunnelRemote,TunnelManager};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted,ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunNetworkTunnelOperation implements ShouldQueue,ShouldBeEncrypted
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=1;
    public int $timeout=600;
    public function __construct(public readonly int $operationId){$this->onQueue('deployments');}

    /** Durable one-host-at-a-time continuation; a killed worker never loses the rollback phase. */
    public function handle(NetworkTunnelRemote $remote,NetworkTunnelManager $network,TunnelManager $manager): void
    {
        $lock=Cache::lock('speedbox-network-operation-'.$this->operationId,630);
        if(!$lock->get())return;
        try {
            $op=TunnelOperation::findOrFail($this->operationId);
            if(!in_array($op->status,['queued','running']))return;
            $tunnel=ServerTunnel::findOrFail($op->tunnel_id);
            if ($op->action==='delete') { app(\App\Services\TunnelRemoval::class)->run($op,$remote,$manager);return; }
            if ($op->action==='status') {
                $op->update(['status'=>'running']);$snapshots=[];
                foreach (['iran','foreign'] as $side) {
                    try {$snapshots[$side]=$remote->call($tunnel->$side,['action'=>'status','config'=>$network->config($tunnel,$side)]);if(($tunnel->settings['connection_mode']??'direct')==='cdn')$snapshots[$side]['cdn']=app(\App\Services\TunnelCdnRemote::class)->status($tunnel,$tunnel->settings,$side);}
                    catch (Throwable) {$snapshots[$side]=['error'=>'دسترسی به این سمت تأیید نشد.','checked_at'=>time()];}
                }
                $network->saveSnapshot($tunnel,$snapshots);
                $unavailable=isset($snapshots['iran']['error'])||isset($snapshots['foreign']['error']);
                $manager->finish($op,$unavailable?'failed':'succeeded',$tunnel->message,['hosts'=>$snapshots]);return;
            }
            $state=$op->payload??[];
            if(!isset($state['phase']))$state=[...$state,'phase'=>$op->action==='engine-update'?'inspect-update':'ensure','side'=>0,'revision'=>bin2hex(random_bytes(16)),
                'old_settings'=>$tunnel->settings,'old_name'=>$tunnel->name,'old_status'=>$tunnel->status,'old_message'=>$tunnel->message,'prepared'=>[],'hosts'=>[]];
            if($tunnel->engine==='wattunnel' && !isset($state['origin_upgraded']) && in_array($op->action,['apply','start','restart','reapply','engine-update'],true)) {
                $state['settings']=\App\Services\TrustedTunnelOrigin::upgrade($tunnel,$state['settings']??$tunnel->settings);
                $state['origin_upgraded']=true;
            }
            if($op->deadline->isPast()&&!in_array($state['phase'],['rollback','failed','commit','done'],true)){
                $state['error']='مهلت عملیات تمام شد.';$state['phase']='rollback';$state['side']=0;
            }
            $op->update(['status'=>'running','payload'=>$state]);
            $ids=[$tunnel->foreign_server_id,$tunnel->iran_server_id];$sides=['foreign','iran'];
            $side=$sides[$state['side']];$server=ManagedServer::findOrFail($ids[$state['side']]);
            $settings=$state['settings']??$tunnel->settings;
            if(in_array($op->action,['start','stop'],true))$settings['enabled']=$op->action==='start';
            $config=$network->config($tunnel,$side,$settings);
            $mutating=in_array($op->action,['apply','start','stop','restart','reapply','delete','engine-update'],true);
            try {
                switch($state['phase']) {
                    case 'inspect-update':
                        $state['maintenance'][$side]=['name'=>$server->name,'status'=>$remote->maintenanceStatus($server,$tunnel->engine,$settings['version'])];
                        $this->advance($state,'ensure');
                        if($state['phase']==='ensure' && $settings===$state['old_settings'] && !empty($state['maintenance']['iran']['status']['up_to_date']) && !empty($state['maintenance']['foreign']['status']['up_to_date'])) {
                            $state['phase']='done';$state['already_current']=true;
                        }
                        break;
                    case 'ensure':
                        $op->update(['progress'=>5+$state['side']*10,'message'=>'در حال آماده‌سازی ابزار شبکه روی '.($side==='iran'?'ایران':'خارج').'…']);
                        if($mutating)$remote->ensure($server,in_array($op->action,['stop','delete'])?'gre':$tunnel->engine,$settings['version']);
                        $this->advance($state,$mutating?'prepare':'execute');break;
                    case 'prepare':
                        // Save the host before requesting its prepare: an interrupted reply must be recoverable.
                        if(!in_array($side,$state['prepared'],true))$state['prepared'][]=$side;
                        $op->update(['payload'=>$state,'progress'=>25+$state['side']*10,'message'=>'در حال بررسی پیش‌نیازها و نگه‌داشتن تنظیمات بازگشت…']);
                        app(\App\Services\TunnelCdnRemote::class)->revision($tunnel,$settings,$side,'snapshot',$state['revision']);
                        $answer=$remote->call($server,['action'=>'prepare','operation'=>$op->action,'revision'=>$state['revision'],'config'=>$config]);
                        foreach($answer['checks']??[] as $check)if(($check['ok']??false)!==true)throw new \RuntimeException('پیش‌نیاز سرور آماده نیست: '.$check['name']);
                        $this->advance($state,'execute');break;
                    case 'execute':
                        $op->update(['progress'=>45+$state['side']*15,'message'=>'در حال اجرای عملیات روی '.($side==='iran'?'ایران':'خارج').'…']);
                        if($op->action==='maintenance-status') {
                            // Host tuning is shared; the engine's version is inspected separately.
                            $answer=app(\App\Services\BackpackRemote::class)->maintenance($server,'status',$manager->reservedPorts($server));
                            $state['hosts'][$side]=$answer;
                            $state['hosts'][$side]['status']=[...($answer['status']??[]),...$remote->maintenanceStatus($server,$tunnel->engine,$settings['version'])];
                            $state['hosts'][$side]['name']=$server->name;
                        } elseif($op->action==='optimize') {
                            if(in_array($side,$state['sides']??[],true)) {
                                $answer=app(\App\Services\BackpackRemote::class)->maintenance($server,'optimize',$manager->reservedPorts($server));
                                $state['hosts'][$side]=$answer;
                                $state['hosts'][$side]['name']=$server->name;
                                if(empty($answer['status']['optimized']))throw new \RuntimeException('بهینه‌سازی سرور '.$side.' کامل تأیید نشد؛ گزارش تنظیمات را بررسی کنید.');
                            } else $state['hosts'][$side]=['skipped'=>true];
                        } else {
                            if(in_array($op->action,['apply','reapply','engine-update'],true))app(\App\Services\TunnelCdnRemote::class)->prepare($tunnel,$settings,$side);
                            $state['hosts'][$side]=$remote->call($server,['action'=>$op->action==='engine-update'?'reapply':$op->action,'kind'=>$state['kind']??null,'revision'=>$state['revision'],'config'=>$config]);
                            if($op->action==='reset-quota')$state['hosts'][$side]=$remote->call($server,['action'=>'status','config'=>$config]);
                        }
                        $this->advance($state,$mutating?'verify':'done');break;
                    case 'verify':
                        $state['snapshots'][$side]=$op->action==='delete'?['deleted'=>true,'checked_at'=>time()]:$remote->call($server,['action'=>'status','config'=>$config]);
                        if(($settings['connection_mode']??'direct')==='cdn'){
                            $state['snapshots'][$side]['cdn']=app(\App\Services\TunnelCdnRemote::class)->status($tunnel,$settings,$side);
                            if(isset($state['snapshots'][$side]['cdn']['cdn_verified'])&&!$state['snapshots'][$side]['cdn']['cdn_verified'])$state['snapshots'][$side]['connected']=false;
                        }
                        if($tunnel->engine==='wattunnel'&&$op->action!=='delete'&&($settings['enabled']??true)&&empty($state['snapshots'][$side]['connected'])) {
                            $state['verify_attempts'][$side]=($state['verify_attempts'][$side]??0)+1;
                            if($state['verify_attempts'][$side]<10){$op->update(['message'=>'در انتظار تأیید ارتباط رمز‌شدهٔ WatTunnel…']);break;}
                            throw new \RuntimeException('ارتباط رمز‌شدهٔ WatTunnel سمت '.$side.' تأیید نشد؛ تنظیمات قبلی بازگردانده می‌شود.');
                        }
                        if($op->action==='engine-update')$state['maintenance'][$side]=['name'=>$server->name,'status'=>$remote->maintenanceStatus($server,$tunnel->engine,$settings['version'])];
                        if($op->action!=='delete'&&($settings['enabled']??true)&&!($state['snapshots'][$side]['active']??false))throw new \RuntimeException('سرویس سمت '.$side.' فعال نشد.');
                        $this->advance($state,'commit');break;
                    case 'commit':
                        // Persist only after both hosts were applied and structurally verified.
                        if($state['side']===0 && empty($state['database_committed'])) {
                            if(($settings['connection_mode']??'direct')==='cdn'||($tunnel->settings['connection_mode']??'direct')==='cdn')app(\App\Services\TunnelCdnRemote::class)->commit($tunnel,$settings);
                            if($op->action==='delete')$tunnel->update(['status'=>'deleted','message'=>'اتصال از دو سرور حذف شد.']);
                            else {$tunnel->update(['settings'=>$settings,'name'=>$state['name']??$tunnel->name,'kind'=>!empty($settings['reverse'])?'reverse':'direct','transport'=>$settings['transport']]);$network->saveSnapshot($tunnel->fresh(),$state['snapshots']);}
                            $state['database_committed']=true;$op->update(['payload'=>$state]);
                        }
                        try{app(\App\Services\TunnelCdnRemote::class)->revision($tunnel,$settings,$side,'commit',$state['revision'],($state['old_settings']['connection_mode']??'direct')==='cdn');$remote->call($server,['action'=>'commit','revision'=>$state['revision'],'config'=>$config]);}catch(Throwable $cleanup){$state['cleanup_errors'][$side]=$cleanup->getMessage();}
                        $this->advance($state,'done');break;
                    case 'rollback':
                        $op->update(['progress'=>85,'message'=>'عملیات کامل نشد؛ در حال بازگرداندن تنظیمات دو سمت…']);
                        if(in_array($side,$state['prepared'],true)) {
                            app(\App\Services\TunnelCdnRemote::class)->revision($tunnel,$settings,$side,'rollback',$state['revision']);
                            try{$remote->call($server,['action'=>'rollback','revision'=>$state['revision'],'config'=>$config]);}
                            catch(Throwable $error){$state['rollback_errors'][$side]=$error->getMessage();}
                        }
                        $this->advance($state,'failed');break;
                }
            } catch(Throwable $error) {
                $state['error']=$error->getMessage();
                if($mutating&&$state['prepared']){$state['phase']='rollback';$state['side']=0;}
                else $state['phase']='failed';
            }
            if($state['phase']==='done') {
                if(in_array($op->action,['status','reset-quota'],true))$network->saveSnapshot($tunnel,$state['hosts']);
                $resultHosts=$state['hosts']??[];
                if(in_array($op->action,['maintenance-status','optimize','engine-update'],true)) {
                    $source=$op->action==='engine-update'?($state['maintenance']??[]):$resultHosts;
                    foreach(['iran'=>$tunnel->iran_server_id,'foreign'=>$tunnel->foreign_server_id] as $key=>$id)if(isset($source[$key]))$resultHosts[$id]=$source[$key];
                }
                $manager->finish($op,'succeeded',!empty($state['already_current'])?'ابزار هر دو سرور به‌روز است؛ تغییری انجام نشد.':'عملیات کامل شد.',['hosts'=>$resultHosts,'snapshots'=>$state['snapshots']??[],'cleanup_errors'=>$state['cleanup_errors']??[]]);
            } elseif($state['phase']==='failed') {
                $tunnel->update(['settings'=>$state['old_settings'],'name'=>$state['old_name'],'status'=>empty($state['rollback_errors'])?($state['old_status']==='queued'?'failed':$state['old_status']):'failed',
                    'message'=>$op->action==='engine-update'?'به‌روزرسانی ابزار کامل نشد؛ وضعیت دو سرور و گزارش بازگردانی تنظیمات تونل را بررسی کنید.':($op->action==='optimize'?'بهینه‌سازی کامل نشد؛ ممکن است بخشی از تنظیمات سرور اعمال شده باشد. گزارش را بررسی کنید.':(!$mutating?'دریافت گزارش کامل نشد؛ تنظیمات اتصال تغییر نکرد.':(empty($state['rollback_errors'])?'عملیات کامل نشد؛ تنظیمات قبلی بازگردانده شد.':'بازگردانی یکی از سمت‌ها کامل نشد؛ گزارش را بررسی کنید.')))]);
                $manager->finish($op,'failed',$tunnel->message,['error'=>$state['error']??'operation failed','rollback_errors'=>$state['rollback_errors']??[],'hosts'=>$state['hosts']??[]]);
            } else {
                $op->update(['payload'=>$state]);self::dispatch($op->id)->delay(now()->addSeconds(1));
            }
        } finally {$lock->release();}
    }

    private function advance(array &$state,string $next): void
    {
        if($state['side']===0)$state['side']=1;
        else{$state['side']=0;$state['phase']=$next;}
    }

    public function failed(?Throwable $error): void
    {
        $op=TunnelOperation::find($this->operationId);
        if(!$op||!in_array($op->status,['queued','running']))return;
        $state=$op->payload??[];
        if(($state['recovery_attempts']??0)>=2){app(TunnelManager::class)->finish($op,'failed','بازیابی کامل نشد؛ سرورها را بررسی کنید.',['phase'=>$state['phase']??null]);return;}
        $state['recovery_attempts']=($state['recovery_attempts']??0)+1;
        if(in_array($state['phase']??'', ['commit','done'],true)){$op->update(['payload'=>$state]);self::dispatch($op->id)->delay(now()->addSeconds(5));return;}
        $state['error']='اجرای پردازش قطع شد؛ بازیابی تنظیمات درخواست شد.';$state['phase']='rollback';$state['side']=0;
        $op->update(['payload'=>$state,'deadline'=>now()->addMinutes(10)]);
        self::dispatch($op->id)->delay(now()->addSeconds(5));
    }
}

