<?php
namespace App\Jobs;

use App\Models\{ManagedServer, ServerTunnel, TunnelConnectionTest, TunnelOperation};
use App\Services\{BackpackRemote, TunnelManager};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunTunnelOperation implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public int $timeout = 600;
    public function __construct(public readonly int $operationId) { $this->onQueue('deployments'); }

    public function handle(BackpackRemote $remote, TunnelManager $manager): void
    {
        $lock = Cache::lock('speedbox-tunnel-operation-'.$this->operationId, 630);
        if (!$lock->get()) return;
        try {
            $op = TunnelOperation::findOrFail($this->operationId);
            if (!in_array($op->status,['queued','running'])) return;
            $op->update(['status'=>'running']);
            if ($op->action==='delete') { app(\App\Services\TunnelRemoval::class)->run($op,$remote,$manager);return; }
            if ($op->test_id) $this->test($op,$remote,$manager);
            else $this->tunnel($op,$remote,$manager);
        } catch (Throwable $e) {
            if (isset($op)) {
                $message = 'عملیات کامل نشد؛ اتصال SSH، آزاد بودن پورت‌ها و گزارش دو سمت را بررسی کنید.';
                if ($op->test_id) {
                    if(!$this->cleanupTest($op,$remote,$manager))return;
                    TunnelConnectionTest::whereKey($op->test_id)->update(['status'=>'failed','message'=>$message]);
                }
                if ($op->tunnel_id) ServerTunnel::whereKey($op->tunnel_id)->update(['status'=>'needs_review','message'=>$message]);
                $manager->finish($op,'failed',$message);
            }
        } finally { $lock->release(); }
    }

    private function test(TunnelOperation $op, BackpackRemote $remote, TunnelManager $manager): void
    {
        $test = TunnelConnectionTest::findOrFail($op->test_id);
        $iran = ManagedServer::findOrFail($test->iran_server_id); $foreign = ManagedServer::findOrFail($test->foreign_server_id);
        if ($op->cancel_requested || $op->deadline->isPast()) {
            if(!$this->cleanupTest($op,$remote,$manager))return;
            $test->update(['status'=>'cancelled','message'=>'بررسی اتصال متوقف شد.']);
            $manager->finish($op,'cancelled','بررسی اتصال متوقف شد.'); return;
        }
        $payload = $op->payload ?? [];
        $orientation = $payload['orientation'] ?? 'normal';
        $host = $orientation === 'normal' ? $iran : $foreign;
        $peer = $orientation === 'normal' ? $foreign : $iran;
        if (!isset($payload['test_id'])) {
            $prepareStarted=microtime(true);
            $op->update(['progress'=>max((int)$op->progress,$orientation==='normal'?5:50),'message'=>'در حال آماده‌سازی BackPack روی دو سرور…']);
            // Artifact transfer is preparation, not part of the handshake budget.
            $remote->call($iran,['action'=>'test-ready']);$remote->call($foreign,['action'=>'test-ready']);
            $id = bin2hex(random_bytes(16));
            // Persist the id before starting either side, so cancellation can always clean up.
            $payload = [...$payload,'test_id'=>$id,'orientation'=>$orientation,'peer_started'=>false,'phase_started_at'=>now()->timestamp];
            $payload['timings'][]=['orientation'=>$orientation,'phase'=>'prepare','seconds'=>round(microtime(true)-$prepareStarted,3)];
            $op->update(['payload'=>$payload]);
            $remote->call($host,['action'=>'spawn-host','id'=>$id,'host'=>$host->host]);
            $test->update(['status'=>'running','message'=>'در حال برقراری ارتباط اولیهٔ تست…']);
        }
        $answer = $remote->call($host,['action'=>'test-state','id'=>$payload['test_id']]);
        if (!empty($answer['link']) && !$payload['peer_started']) {
            $remote->call($peer,['action'=>'spawn-peer','id'=>$payload['test_id'],'link'=>$answer['link']]);
            $payload['peer_started']=true; $payload['peer_requested_at']=now()->timestamp; $op->update(['payload'=>$payload]);
        }
        if (!in_array($answer['state'] ?? '', ['completed','failed'], true)) {
            if (!empty($payload['peer_started'])) {
                $peerState=$remote->call($peer,['action'=>'test-state','id'=>$payload['test_id'],'side'=>'peer']);
                if (($peerState['state']??'')==='failed') {
                    $answer['state']='failed';$answer['error']=$peerState['error']??'test peer stopped';
                }
            }
            if (in_array($answer['state']??'',['starting','waiting'],true)
                && now()->timestamp-($payload['peer_requested_at']??$payload['phase_started_at']??$op->created_at->timestamp)>=30) {
                $answer['state']='failed';$answer['error']='initial test handshake timed out';
            }
        }
        if(($answer['state']??'')==='running'&&!isset($payload['measurement_started_at'])){
            $payload['measurement_started_at']=now()->timestamp;
            $payload['timings'][]=['orientation'=>$orientation,'phase'=>'handshake','seconds'=>max(0,now()->timestamp-($payload['peer_requested_at']??$payload['phase_started_at']??now()->timestamp))];
            $op->update(['payload'=>$payload]);
        }
        $existing = array_values(array_filter($test->results ?? [],fn($r)=>$r['orientation']!==$orientation));
        $rows = [...$existing,...$manager->rows($answer['rows'] ?? [],$orientation)];
        $test->update(['results'=>$rows]);
        $rawRows=$answer['rows']??[];
        $done=array_sum(array_map(fn($row)=>in_array($row['s']??'',['ok','unstable','down','skipped'],true)?1:
            min(.99,max(0,(int)($row['n']??0))/max(1,(int)($row['z']??60))),$rawRows));
        $total=max(count($rawRows),(int)($answer['total']??19),1);
        $base=$orientation==='normal'?10:55;
        $message=($answer['state']??'')==='running'?'در حال بررسی روش‌های اتصال…':'در حال برقراری ارتباط اولیهٔ تست…';
        $progress=max((int)$op->progress,$base+(int)(40*$done/$total));
        $test->update(['message'=>$message]);
        $op->update(['progress'=>min(95,$progress),'message'=>$message]);
        if (in_array($answer['state'] ?? '',['completed','failed'])) {
            if(empty($payload['phase_timing_recorded'])){
                $payload['timings'][]=['orientation'=>$orientation,'phase'=>isset($payload['measurement_started_at'])?'measure':'handshake','seconds'=>max(0,now()->timestamp-($payload['measurement_started_at']??$payload['peer_requested_at']??$payload['phase_started_at']??now()->timestamp))];
                $payload['phase_timing_recorded']=true;$op->update(['payload'=>$payload]);
            }
            if(!$this->cleanupTest($op,$remote,$manager))return;
            $payload=$op->payload??$payload;
            $usable = collect($rows)->contains(fn($r)=>$r['selectable'] && $r['status']==='ok');
            if ($orientation==='normal' && ($test->compare_both || !$usable)) {
                $payload['orientation']='swapped'; unset($payload['test_id'],$payload['peer_requested_at'],$payload['phase_started_at'],$payload['measurement_started_at'],$payload['phase_timing_recorded']); $payload['peer_started']=false;
                $op->update(['payload'=>$payload]);
            } else {
                $test->update(['status'=>'completed','results'=>$manager->recommend($rows),
                    'message'=>count($rows) ? 'بررسی تمام شد؛ روش موردنظر را انتخاب کنید.' : 'هماهنگی تست بین دو سرور برقرار نشد؛ روش‌های تونل اندازه‌گیری نشدند.']);
                if(!count($rows))$test->update(['status'=>'failed']);
                $manager->finish($op,count($rows)?'succeeded':'failed',$test->message,['timings'=>$payload['timings']??[]]); return;
            }
        }
        // A durable continuation leaves the queue worker free while engines test.
        self::dispatch($op->id)->delay(now()->addSeconds(2));
    }

    private function cleanupTest(TunnelOperation $op, BackpackRemote $remote, TunnelManager $manager): bool
    {
        $payload=$op->payload??[];$id=$payload['test_id']??null;if(!$id)return true;
        $test=TunnelConnectionTest::find($op->test_id);if(!$test)return true;
        $failed=[];
        foreach(['iran'=>$test->iran_server_id,'foreign'=>$test->foreign_server_id] as $side=>$serverId) {
            try {
                $answer=$remote->call(ManagedServer::findOrFail($serverId),['action'=>'test-stop','id'=>$id]);
                if(empty($answer['stopped']))$failed[]=$side;
            } catch(Throwable) {$failed[]=$side;}
        }
        if(!$failed) {
            unset($payload['cleanup_attempts']);$op->update(['payload'=>$payload]);return true;
        }
        $payload['cleanup_attempts']=($payload['cleanup_attempts']??0)+1;
        $message='توقف تست موقت روی یکی از سرورها تأیید نشد؛ در حال تلاش دوباره…';
        $op->update(['payload'=>$payload,'message'=>$message,'result'=>['cleanup_pending'=>$failed]]);
        if($payload['cleanup_attempts']<3)self::dispatch($op->id)->delay(now()->addSeconds(5));
        else {
            $message='پاک‌سازی تست موقت تأیید نشد؛ اتصال سرورها را بررسی کنید و تست را دوباره اجرا کنید.';
            $test->update(['status'=>'failed','message'=>$message]);
            $manager->finish($op,'failed',$message,['cleanup_pending'=>$failed]);
        }
        return false;
    }

    private function tunnel(TunnelOperation $op, BackpackRemote $remote, TunnelManager $manager): void
    {
        $t = ServerTunnel::with(['iran','foreign'])->findOrFail($op->tunnel_id);
        $settings = $op->payload['settings'] ?? $t->settings;
        $servers = ['iran'=>$t->iran,'foreign'=>$t->foreign]; $result=[];
        if ($op->deadline->isPast()) throw new \RuntimeException('deadline');
        if ($op->action === 'apply') {
            $beforeSettings=$t->settings;
            $settings=\App\Services\TrustedTunnelOrigin::upgrade($t,$settings);
            $op->update(['payload'=>[...($op->payload??[]),'settings'=>$settings,'origin_before_settings'=>$beforeSettings,'origin_snapshot_sides'=>[],'phase'=>'apply-origin']]);
            $revision = str_pad(dechex($op->id),32,'0',STR_PAD_LEFT);
            $snapshots=[];$policyChanged=false;$wasCdn=($t->settings['connection_mode']??'direct')==='cdn';
            try {
                foreach ($servers as $side=>$server) {
                    $remote->ensure($server);
                    app(\App\Services\TunnelCdnRemote::class)->revision($t,$settings,$side,'snapshot',$revision);
                    $remote->call($server,['action'=>'snapshot','name'=>$t->remote_name,'revision'=>$revision]);
                    $snapshots[$side]=$server;
                    $op->update(['payload'=>[...$op->payload,'origin_snapshot_sides'=>array_keys($snapshots)]]);
                }
                // The swapped carrier listens abroad, but its data listener is
                // in Iran. Keep that listener stable before replacing the dialer.
                $order=self::applyOrder($t);
                foreach ($order as $side) {
                    $op->update(['progress'=>$side===$order[0]?35:70,'message'=>'در حال اعمال تنظیمات سمت '.($side==='iran'?'ایران':'خارج').'…']);
                    $this->refreshReservations($servers[$side],$remote,$manager,$settings);
                    app(\App\Services\TunnelCdnRemote::class)->prepare($t,$settings,$side);
                    $result[$side]=$remote->call($servers[$side],$this->applyRequest($t,$settings,$side));
                    if ((bool)($result[$side]['active']??false)!==(bool)($settings['enabled']??true)) throw new \RuntimeException('service_state_mismatch');
                }
                if(($settings['enabled']??true)&&($settings['connection_mode']??'direct')==='cdn'){
                    $dialer=\App\Services\TunnelCdnRemote::listenerIran($t,$settings)?'foreign':'iran';
                    for($attempt=0;$attempt<15;$attempt++){
                        $route=app(\App\Services\TunnelCdnRemote::class)->status($t,$settings,$dialer);
                        if(!empty($route['cdn_verified']))break;
                        usleep(1000000);
                    }
                    if(empty($route['cdn_verified']))throw new \RuntimeException('CDN route not verified');
                    $result[$dialer]['cdn']=$route;
                }
                if(($settings['connection_mode']??'direct')==='cdn'||($t->settings['connection_mode']??'direct')==='cdn')app(\App\Services\TunnelCdnRemote::class)->commit($t,$settings);
                $t->update(['settings'=>$settings,'name'=>$op->payload['name'] ?? $t->name,'status'=>($settings['enabled']??true)?'checking':'stopped','remote_status'=>$result,'message'=>'تنظیمات دو سمت اعمال شد؛ وضعیت اتصال را بررسی کنید.']);
                $policyChanged=true;
                $op->update(['payload'=>[...$op->payload,'phase'=>'activate-origin']]);
                app(\App\Services\TunnelOriginRemote::class)->refreshNodePolicy($t,$beforeSettings,$settings);
                app(\App\Services\TunnelOriginRemote::class)->activate($t,$settings);
                $op->update(['payload'=>[...$op->payload,'phase'=>'origin-committed']]);
                foreach ($servers as $side=>$server) { $this->refreshReservations($server,$remote,$manager);try {app(\App\Services\TunnelCdnRemote::class)->revision($t,$settings,$side,'commit',$revision,$wasCdn);$remote->call($server,['action'=>'commit','name'=>$t->remote_name,'revision'=>$revision]);} catch(Throwable) {} }
            } catch (Throwable $e) {
                $restored=[];
                foreach ($snapshots as $side=>$server) { try { app(\App\Services\TunnelCdnRemote::class)->revision($t,$settings,$side,'rollback',$revision); $remote->call($server,['action'=>'rollback','name'=>$t->remote_name,'revision'=>$revision]);$restored[$side]=true; } catch(Throwable) {$restored[$side]=false;} }
                $message = in_array(false,$restored,true) ? 'اعمال تنظیمات کامل نشد؛ بازگردانی یکی از دو سمت نیازمند بررسی است.' : 'اعمال تنظیمات کامل نشد؛ تنظیمات قبلی دو سمت بازگردانده شد.';
                $t->update(['settings'=>$beforeSettings,'status'=>'needs_review','message'=>$message]);
                if($policyChanged)try {app(\App\Services\TunnelOriginRemote::class)->refreshNodePolicy($t,$settings,$beforeSettings,true);}catch(Throwable){
                    $restored['node_policy']=false;$message='تنظیمات تونل بازگردانده شد؛ بازیابی سیاست هویت نود نیازمند بررسی است.';$t->update(['message'=>$message]);
                }
                foreach($servers as $server)$this->refreshReservations($server,$remote,$manager);$manager->finish($op,'failed',$message,['rollback'=>$restored,'error'=>str_replace($t->secret,'[secret]',$e->getMessage())]);return;
            }
        } else {
            foreach ($servers as $side=>$server) {
                try {
                    $result[$side]=$remote->call($server,['action'=>$op->action,'name'=>$t->remote_name,
                        'enabled'=>$op->action==='start'?true:($op->action==='stop'?false:($settings['enabled']??true)),
                        ...($op->action==='start'?['origin_plan'=>($plan=\App\Services\TrustedTunnelOrigin::plan($t,[...$settings,'enabled'=>true],$side))?[...$plan,'staged'=>true]:null]:[]),
                        'target_ports'=>$side==='foreign'?array_column($settings['mappings'],'target_port'):[], 'udp'=>\App\Services\TrustedTunnelOrigin::udpOnlyBackpack($t)||(bool)($settings['accept_udp']??false)]);
                    if($op->action==='status'&&($settings['connection_mode']??'direct')==='cdn')$result[$side]['cdn']=app(\App\Services\TunnelCdnRemote::class)->status($t,$settings,$side);
                    if ($op->action === 'logs' && isset($result[$side]['text'])) {
                        $result[$side]['text'] = preg_replace('~backpack://[^\s]+~', '[setup-link]', str_replace($t->secret, '[secret]', $result[$side]['text']));
                    }
                } catch (Throwable) { $result[$side]=['error'=>'ارتباط با این سمت کامل نشد.']; }
            }
            $failed = isset($result['iran']['error']) || isset($result['foreign']['error']);
            if ($op->action === 'status') {
                $result['_checked_at']=now()->timestamp;
                $t->update(['remote_status'=>$result,'status'=>$failed?'needs_review':$this->connectionState($result,$t),
                    'message'=>$failed?'دریافت وضعیت یکی از دو سرور کامل نشد.':$this->connectionMessage($result,$t)]);
            } elseif ($op->action !== 'logs') {
                $t->update(['status'=>$failed?'needs_review':match($op->action){'delete'=>'deleted','stop'=>'stopped',default=>'checking'},
                    'message'=>$failed?'عملیات فقط روی یکی از دو سمت انجام شد؛ پس از رفع اتصال دوباره اجرا کنید.':'عملیات روی هر دو سرور انجام شد.']);
            }
            if (!$failed && in_array($op->action,['start','stop'],true)) {
                $next=[...$settings,'enabled'=>$op->action==='start'];$t->update(['settings'=>$next]);
                app(\App\Services\TunnelOriginRemote::class)->refreshNodePolicy($t,$settings,$next,$op->action==='start');
                app(\App\Services\TunnelOriginRemote::class)->activate($t,$next);
            }
            if ($failed) { $manager->finish($op,'failed',$t->message ?? 'دریافت گزارش کامل نشد.',$result);return; }
        }
        if($op->action==='delete')foreach($servers as $server)$this->refreshReservations($server,$remote,$manager);
        $manager->finish($op,'succeeded',$op->action==='logs'?'گزارش دو سمت دریافت شد.':$t->message,$result);
    }

    private function refreshReservations(ManagedServer $server,BackpackRemote $remote,TunnelManager $manager,array $extra=[]): void
    {
        try {
            $ports=$manager->reservedPorts($server);
            if($extra) {
                if(!empty($extra['source_identity_port']))$ports[]=(int)$extra['source_identity_port'];
                $ports[]=(int)$extra['tunnel_port'];
                foreach($extra['mappings'] as $mapping){$ports[]=(int)$mapping['public_port'];$ports[]=(int)$mapping['target_port'];}
            }
            $answer=$remote->maintenance($server,'reserve',$ports);
            if(isset($answer['status']))$manager->saveHostStatus($server->id,$answer['status']);
        } catch(Throwable) {
            // Tunnel changes stay usable; do not leave a stale green host badge.
            try{$manager->saveHostStatus($server->id,['checked_at'=>now()->timestamp,'error'=>'رزرو پورت‌ها بررسی نشد؛ وضعیت بهینه‌سازی را دوباره بررسی کنید.']);}catch(Throwable){}
        }
    }

    public static function applyOrder(ServerTunnel $t): array
    {
        return $t->kind==='reverse'||$t->orientation==='swapped'?['iran','foreign']:['foreign','iran'];
    }

    public function applyRequest(ServerTunnel $t, array $settings, string $side): array
    {
        $normal = $t->orientation === 'normal';
        $listeningIran = $t->kind === 'reverse' ? $normal : !$normal;
        $peerHost = ($settings['peer_host']??'') ?: ($listeningIran ? $t->iran->host : $t->foreign->host);
        $n = (int)$t->subnet * 4; $a='10.203.'.intdiv($n,256).'.'.($n%256+1); $b='10.203.'.intdiv($n,256).'.'.($n%256+2);
        // /30 allocation is confined to 10.203/16, bounded by the allocator.
        $maps=implode(',',array_map(fn($m)=>$m['public_port'].'='.$m['target_port'],$settings['mappings']));
        $request=['action'=>'apply','name'=>$t->remote_name,'kind'=>$t->kind,'transport'=>$t->transport,'token'=>$t->secret,
            'enabled'=>(bool)($settings['enabled']??true),'origin_plan'=>\App\Services\TrustedTunnelOrigin::plan($t,$settings,$side),
            'bridge_port'=>40000+(int)$t->subnet,
            'role'=>$t->kind==='reverse'?($side==='iran'?'server':'client'):($side==='iran'?'iran':'kharej'),
            'swapped'=>!$normal,'host'=>$peerHost,'port'=>$settings['tunnel_port'],'ports'=>$maps,'udp'=>(bool)($settings['accept_udp']??false),
            'local_ip'=>($side==='iran'?$a:$b).'/30','peer_ip'=>$side==='iran'?$b:$a];
        if(($settings['connection_mode']??'direct')==='cdn')$request['cdn']=['domain'=>$settings['cdn']['domain'],'port'=>(int)$settings['cdn']['port'],'edge_ip'=>$settings['cdn']['edge_ip']??'','cert_file'=>'/etc/speedbox-tunnel-cdn/'.$t->remote_name.'/fullchain.pem','key_file'=>'/etc/speedbox-tunnel-cdn/'.$t->remote_name.'/privkey.pem'];
        return $request;
    }

    private function connectionState(array $r,?ServerTunnel $t=null): string
    {
        $probe=$t?clone $t:new ServerTunnel;
        $probe->forceFill(['engine'=>'backpack','settings'=>$t?->settings??[],'remote_status'=>$r]);
        return match(\App\Services\TunnelHealth::reason($probe)){null=>'connected','stopped'=>'stopped','access','cdn'=>'needs_review','unknown','quota'=>'checking','target'=>'degraded',default=>'offline'};
    }
    private function connectionMessage(array $r,?ServerTunnel $t=null): string
    {
        if($this->connectionState($r,$t)==='degraded')return 'مسیر تونل بررسی شد؛ سرویس نود مقصد پاسخ نمی‌دهد.';
        if ($this->connectionState($r,$t)!=='connected') return 'ارتباط دو سمت هنوز تأیید نشده است.';
        $targets=$r['foreign']['targets']??[];
        return in_array(false,$targets,true)?'تونل متصل است؛ سرویس مقصد هنوز پاسخ نمی‌دهد.':'ارتباط تونل برقرار است؛ اتصال نود را از کلاینت هم بررسی کنید.';
    }
    public function failed(?Throwable $e): void
    {
        $op=TunnelOperation::find($this->operationId);if(!$op||!in_array($op->status,['queued','running']))return;
        if($op->test_id) {
            $op->update(['cancel_requested'=>true,'message'=>'اجرای تست قطع شد؛ در حال جمع‌آوری منابع موقت…']);
            self::dispatch($op->id)->delay(now()->addSeconds(5));return;
        }
        if($op->action==='apply' && array_key_exists('origin_before_settings',$op->payload??[])) {
            if(($op->payload['phase']??'')==='origin-committed') {
                app(TunnelManager::class)->finish($op,'succeeded','تنظیمات دو سمت و هویت نود آماده شد؛ پاک‌سازی نسخهٔ بازگشت کامل تأیید نشد.');return;
            }
            $t=ServerTunnel::with(['iran','foreign'])->find($op->tunnel_id);$restored=[];
            if($t) {
                $after=$t->settings;$before=$op->payload['origin_before_settings'];$revision=str_pad(dechex($op->id),32,'0',STR_PAD_LEFT);
                foreach($op->payload['origin_snapshot_sides']??[] as $side)try {
                    app(BackpackRemote::class)->call($t->$side,['action'=>'rollback','name'=>$t->remote_name,'revision'=>$revision]);$restored[$side]=true;
                }catch(Throwable){$restored[$side]=false;}
                $t->update(['settings'=>$before,'status'=>'needs_review']);
                try {app(\App\Services\TunnelOriginRemote::class)->refreshNodePolicy($t,$after,$before,true);}catch(Throwable){$restored['node_policy']=false;}
            }
            app(TunnelManager::class)->finish($op,'failed','اجرای عملیات قطع شد؛ بازگردانی دو سمت انجام شد و گزارش نیازمند بررسی است.',['rollback'=>$restored]);return;
        }
        app(TunnelManager::class)->finish($op,'failed','اجرای عملیات قطع شد؛ وضعیت دو سرور را بررسی کنید.');
        if($op->tunnel_id)ServerTunnel::whereKey($op->tunnel_id)->update(['status'=>'needs_review']);
    }
}

