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
    public int $timeout = 300;
    public function __construct(public readonly int $operationId) { $this->onQueue('deployments'); }

    public function handle(BackpackRemote $remote, TunnelManager $manager): void
    {
        $lock = Cache::lock('speedbox-tunnel-operation-'.$this->operationId, 330);
        if (!$lock->get()) return;
        try {
            $op = TunnelOperation::findOrFail($this->operationId);
            if (!in_array($op->status,['queued','running'])) return;
            $op->update(['status'=>'running']);
            if ($op->test_id) $this->test($op,$remote,$manager);
            else $this->tunnel($op,$remote,$manager);
        } catch (Throwable $e) {
            if (isset($op)) {
                $message = 'عملیات کامل نشد؛ اتصال SSH، آزاد بودن پورت‌ها و گزارش دو سمت را بررسی کنید.';
                if ($op->test_id) {
                    $this->cleanupTest($op,$remote);
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
            $this->cleanupTest($op,$remote);
            $test->update(['status'=>'cancelled','message'=>'بررسی اتصال متوقف شد.']);
            $manager->finish($op,'cancelled','بررسی اتصال متوقف شد.'); return;
        }
        $payload = $op->payload ?? [];
        $orientation = $payload['orientation'] ?? 'normal';
        $host = $orientation === 'normal' ? $iran : $foreign;
        $peer = $orientation === 'normal' ? $foreign : $iran;
        if (!isset($payload['test_id'])) {
            $op->update(['progress'=>5,'message'=>'در حال آماده‌سازی BackPack روی دو سرور…']);
            $remote->ensure($iran); $remote->ensure($foreign);
            $id = bin2hex(random_bytes(16));
            // Persist the id before starting either side, so cancellation can always clean up.
            $payload = [...$payload,'test_id'=>$id,'orientation'=>$orientation,'peer_started'=>false];
            $op->update(['payload'=>$payload]);
            $remote->call($host,['action'=>'spawn-host','id'=>$id,'host'=>$host->host]);
            $test->update(['status'=>'running','message'=>$orientation === 'normal' ? 'در حال بررسی روش‌های اتصال…' : 'در حال بررسی حالت برعکس…']);
        }
        $answer = $remote->call($host,['action'=>'test-state','id'=>$payload['test_id']]);
        if (!empty($answer['link']) && !$payload['peer_started']) {
            $remote->call($peer,['action'=>'spawn-peer','id'=>$payload['test_id'],'link'=>$answer['link']]);
            $payload['peer_started']=true; $op->update(['payload'=>$payload]);
        }
        $existing = array_values(array_filter($test->results ?? [],fn($r)=>$r['orientation']!==$orientation));
        $rows = [...$existing,...$manager->rows($answer['rows'] ?? [],$orientation)];
        $test->update(['results'=>$rows]);
        $op->update(['progress'=>$orientation==='normal'?35:75,'message'=>$test->message]);
        if (in_array($answer['state'] ?? '',['completed','failed'])) {
            $this->cleanupTest($op,$remote);
            $usable = collect($rows)->contains(fn($r)=>$r['selectable'] && $r['status']==='ok');
            if ($orientation==='normal' && ($test->compare_both || !$usable)) {
                $payload['orientation']='swapped'; unset($payload['test_id']); $payload['peer_started']=false;
                $op->update(['payload'=>$payload]);
            } else {
                $test->update(['status'=>'completed','results'=>$manager->recommend($rows),
                    'message'=>count($rows) ? 'بررسی تمام شد؛ روش موردنظر را انتخاب کنید.' : 'هماهنگی تست بین دو سرور برقرار نشد؛ روش‌های تونل اندازه‌گیری نشدند.']);
                $manager->finish($op,'succeeded',$test->message); return;
            }
        }
        // A durable continuation leaves the queue worker free while engines test.
        self::dispatch($op->id)->delay(now()->addSeconds(5));
    }

    private function cleanupTest(TunnelOperation $op, BackpackRemote $remote): void
    {
        $id = $op->payload['test_id'] ?? null; if (!$id) return;
        $test = TunnelConnectionTest::find($op->test_id); if (!$test) return;
        foreach ([$test->iran_server_id,$test->foreign_server_id] as $serverId) {
            try { $remote->call(ManagedServer::findOrFail($serverId),['action'=>'test-stop','id'=>$id]); } catch (Throwable) { /* systemd RuntimeMaxSec bounds disconnected cleanup */ }
        }
    }

    private function tunnel(TunnelOperation $op, BackpackRemote $remote, TunnelManager $manager): void
    {
        $t = ServerTunnel::with(['iran','foreign'])->findOrFail($op->tunnel_id);
        $settings = $op->payload['settings'] ?? $t->settings;
        $servers = ['iran'=>$t->iran,'foreign'=>$t->foreign]; $result=[];
        if ($op->deadline->isPast()) throw new \RuntimeException('deadline');
        if ($op->action === 'apply') {
            $revision = str_pad(dechex($op->id),32,'0',STR_PAD_LEFT);
            $snapshots=[];
            try {
                foreach ($servers as $side=>$server) {
                    $remote->ensure($server);
                    $remote->call($server,['action'=>'snapshot','name'=>$t->remote_name,'revision'=>$revision]);
                    $snapshots[$side]=$server;
                }
                // Start the listening end first; both configs remain independently recoverable.
                $order = $t->kind === 'reverse' || $t->orientation === 'swapped' ? ['iran','foreign'] : ['foreign','iran'];
                foreach ($order as $side) {
                    $op->update(['progress'=>$side===$order[0]?35:70,'message'=>'در حال اعمال تنظیمات سمت '.($side==='iran'?'ایران':'خارج').'…']);
                    $this->refreshReservations($servers[$side],$remote,$manager,$settings);
                    $result[$side]=$remote->call($servers[$side],$this->applyRequest($t,$settings,$side));
                    if (empty($result[$side]['active'])) throw new \RuntimeException('service_inactive');
                }
                $t->update(['settings'=>$settings,'name'=>$op->payload['name'] ?? $t->name,'status'=>'checking','remote_status'=>$result,'message'=>'تنظیمات دو سمت اعمال شد؛ وضعیت اتصال را بررسی کنید.']);
                foreach ($servers as $server) { $this->refreshReservations($server,$remote,$manager);try {$remote->call($server,['action'=>'commit','name'=>$t->remote_name,'revision'=>$revision]);} catch(Throwable) {} }
            } catch (Throwable $e) {
                $restored=[];
                foreach ($snapshots as $side=>$server) { try { $remote->call($server,['action'=>'rollback','name'=>$t->remote_name,'revision'=>$revision]);$restored[$side]=true; } catch(Throwable) {$restored[$side]=false;} }
                $message = in_array(false,$restored,true) ? 'اعمال تنظیمات کامل نشد؛ بازگردانی یکی از دو سمت نیازمند بررسی است.' : 'اعمال تنظیمات کامل نشد؛ تنظیمات قبلی دو سمت بازگردانده شد.';
                $t->update(['status'=>'needs_review','message'=>$message]);foreach($servers as $server)$this->refreshReservations($server,$remote,$manager);$manager->finish($op,'failed',$message,['rollback'=>$restored]);return;
            }
        } else {
            foreach ($servers as $side=>$server) {
                try {
                    $result[$side]=$remote->call($server,['action'=>$op->action,'name'=>$t->remote_name,
                        'target_ports'=>$side==='foreign'?array_column($settings['mappings'],'target_port'):[], 'udp'=>(bool)$settings['accept_udp']]);
                    if ($op->action === 'logs' && isset($result[$side]['text'])) {
                        $result[$side]['text'] = preg_replace('~backpack://[^\s]+~', '[setup-link]', str_replace($t->secret, '[secret]', $result[$side]['text']));
                    }
                } catch (Throwable) { $result[$side]=['error'=>'ارتباط با این سمت کامل نشد.']; }
            }
            $failed = isset($result['iran']['error']) || isset($result['foreign']['error']);
            if ($op->action === 'status') {
                $t->update(['remote_status'=>$result,'status'=>$failed?'needs_review':$this->connectionState($result),
                    'message'=>$failed?'دریافت وضعیت یکی از دو سرور کامل نشد.':$this->connectionMessage($result)]);
            } elseif ($op->action !== 'logs') {
                $t->update(['status'=>$failed?'needs_review':match($op->action){'delete'=>'deleted','stop'=>'stopped',default=>'checking'},
                    'message'=>$failed?'عملیات فقط روی یکی از دو سمت انجام شد؛ پس از رفع اتصال دوباره اجرا کنید.':'عملیات روی هر دو سرور انجام شد.']);
            }
            if (!$failed && in_array($op->action,['start','stop'],true)) $t->update(['settings'=>[...$settings,'enabled'=>$op->action==='start']]);
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

    public function applyRequest(ServerTunnel $t, array $settings, string $side): array
    {
        $normal = $t->orientation === 'normal';
        $listeningIran = $t->kind === 'reverse' || !$normal;
        $peerHost = $settings['peer_host'] ?: ($listeningIran ? $t->iran->host : $t->foreign->host);
        $n = (int)$t->subnet * 4; $a='10.203.'.intdiv($n,256).'.'.($n%256+1); $b='10.203.'.intdiv($n,256).'.'.($n%256+2);
        // /30 allocation is confined to 10.203/16, bounded by the allocator.
        $maps=implode(',',array_map(fn($m)=>$m['public_port'].'='.$m['target_port'],$settings['mappings']));
        return ['action'=>'apply','name'=>$t->remote_name,'kind'=>$t->kind,'transport'=>$t->transport,'token'=>$t->secret,
            'role'=>$t->kind==='reverse'?($side==='iran'?'server':'client'):($side==='iran'?'iran':'kharej'),
            'swapped'=>!$normal,'host'=>$peerHost,'port'=>$settings['tunnel_port'],'ports'=>$maps,'udp'=>(bool)$settings['accept_udp'],
            'local_ip'=>($side==='iran'?$a:$b).'/30','peer_ip'=>$side==='iran'?$b:$a];
    }

    private function connectionState(array $r): string
    {
        if (empty($r['iran']['active']) || empty($r['foreign']['active'])) return 'offline';
        $states = [data_get($r,'iran.metrics.connected'), data_get($r,'foreign.metrics.connected')];
        if (in_array(false, $states, true)) return 'offline';
        return $states === [true, true] ? 'connected' : 'checking';
    }
    private function connectionMessage(array $r): string
    {
        if ($this->connectionState($r)!=='connected') return 'ارتباط دو سمت هنوز تأیید نشده است.';
        $targets=$r['foreign']['targets']??[];
        return in_array(false,$targets,true)?'تونل متصل است؛ سرویس مقصد هنوز پاسخ نمی‌دهد.':'ارتباط تونل برقرار است؛ اتصال نود را از کلاینت هم بررسی کنید.';
    }
    public function failed(?Throwable $e): void
    {
        $op=TunnelOperation::find($this->operationId);if(!$op||!in_array($op->status,['queued','running']))return;
        app(TunnelManager::class)->finish($op,'failed','اجرای عملیات قطع شد؛ وضعیت دو سرور را بررسی کنید.');
        if($op->tunnel_id)ServerTunnel::whereKey($op->tunnel_id)->update(['status'=>'needs_review']);
        if($op->test_id)TunnelConnectionTest::whereKey($op->test_id)->update(['status'=>'failed']);
    }
}
