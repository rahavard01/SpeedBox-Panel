<?php
namespace App\Jobs;

use App\Models\{ManagedServer, ServerTunnel, TunnelOperation};
use App\Services\{BackpackRemote, TunnelManager};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Cache;
use Throwable;

/** One host/phase per queue turn; leases span the entire connected component. */
class RunTunnelMaintenance implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries=1;
    public int $timeout=300;
    public function __construct(public readonly int $operationId) { $this->onQueue('deployments'); }

    public function handle(BackpackRemote $remote,TunnelManager $manager): void
    {
        $lock=Cache::lock('speedbox-tunnel-operation-'.$this->operationId,330);
        if(!$lock->get()){self::dispatch($this->operationId)->delay(now()->addSeconds(10));return;}
        try {
            $op=TunnelOperation::findOrFail($this->operationId);
            if(!in_array($op->status,['queued','running'],true))return;
            $op->update(['status'=>'running']);
            $this->step($op,$remote,$manager);
        } catch(Throwable $error) {
            if(isset($op))$this->beginRollback($op,$manager,$error->getMessage());
        } finally { $lock->release(); }
    }

    private function step(TunnelOperation $op,BackpackRemote $remote,TunnelManager $manager): void
    {
        $p=$op->payload;$phase=$p['phase']??'inspect';$index=(int)($p['index']??0);$result=$op->result??['hosts'=>[]];
        if($phase!=='rollback' && ($op->deadline->isPast() || !hash_equals($p['package_hash'],hash_file('sha256',base_path('backpack-dist/manifest.json'))))) {
            throw new \RuntimeException('مهلت عملیات تمام شده یا بستهٔ پنل تغییر کرده است.');
        }
        $revision=str_pad(dechex($op->id),32,'0',STR_PAD_LEFT);
        $ids=$p['server_ids'];
        if($phase==='inspect') {
            if(isset($ids[$index])) {
                $id=$ids[$index];$server=ManagedServer::findOrFail($id);
                try {
                    $answer=$remote->maintenance($server,'status',$manager->reservedPorts($server));
                    $manager->saveHostStatus($id,$answer['status']);
                    $result['hosts'][$id]=['name'=>$server->name,...$answer];
                } catch(Throwable $error) {
                    $result['hosts'][$id]=['name'=>$server->name,'error'=>$error->getMessage()];
                    $manager->saveHostStatus($id,['checked_at'=>now()->timestamp,'error'=>'دریافت وضعیت سرور کامل نشد.']);
                }
                $this->next($op,[...$p,'index'=>$index+1],$result,'در حال بررسی وضعیت سرورها…',10);return;
            }
            $errors=array_filter($result['hosts'],fn($host)=>isset($host['error']));
            if($op->action==='maintenance-status') {
                $manager->finish($op,$errors?'failed':'succeeded',$errors?'دریافت وضعیت بعضی سرورها کامل نشد.':'وضعیت سرورها بررسی شد.',$result);return;
            }
            if($op->action==='optimize') {
                $this->next($op,[...$p,'phase'=>'optimize','index'=>0],$result,'در حال بهینه‌سازی سرورهای انتخاب‌شده…',25);return;
            }
            if($errors)throw new \RuntimeException('پیش از به‌روزرسانی باید وضعیت همهٔ سرورهای وابسته دریافت شود.');
            foreach($result['hosts'] as $host) if(!empty($host['status']['installed'])&&empty($host['status']['compatible']))throw new \RuntimeException('موتور یکی از سرورها با رابط این پنل سازگار نیست؛ به‌روزرسانی شروع نشد.');
            $upgrade=array_values(array_filter($ids,fn($id)=>empty($result['hosts'][$id]['status']['up_to_date'])));
            if(!$upgrade){$manager->finish($op,'succeeded','موتور همهٔ سرورهای وابسته به‌روز است.',$result);return;}
            $this->next($op,[...$p,'phase'=>'baseline','index'=>0,'upgrade_ids'=>$upgrade],$result,'در حال ثبت وضعیت تونل‌های وابسته…',20);return;
        }
        if($phase==='optimize') {
            $selected=$p['selected_ids'];
            if(isset($selected[$index])) {
                $id=$selected[$index];$server=ManagedServer::findOrFail($id);
                try {
                    $answer=$remote->maintenance($server,'optimize',$manager->reservedPorts($server));
                    $result['hosts'][$id]=['name'=>$server->name,...$answer];$manager->saveHostStatus($id,$answer['status']);
                    if(empty($answer['status']['optimized']))$result['hosts'][$id]['error']='بعضی تنظیمات بهینه‌سازی تأیید نشد؛ گزارش سرور را بررسی کنید.';
                } catch(Throwable $error) {$result['hosts'][$id]['error']=$error->getMessage();$manager->saveHostStatus($id,['checked_at'=>now()->timestamp,'error'=>'بهینه‌سازی کامل نشد؛ وضعیت را دوباره بررسی کنید.']);}
                $this->next($op,[...$p,'index'=>$index+1],$result,'در حال بهینه‌سازی سرورها…',50);return;
            }
            $failed=collect($selected)->contains(fn($id)=>isset($result['hosts'][$id]['error']));
            $manager->finish($op,$failed?'failed':'succeeded',$failed?'بهینه‌سازی کامل نشد؛ نتیجهٔ هر سرور را بررسی کنید.':'سرورهای انتخاب‌شده بهینه‌سازی شدند.',$result);return;
        }
        if($phase==='baseline') {
            if(isset($p['tunnel_ids'][$index])) {
                $t=ServerTunnel::with(['iran','foreign'])->findOrFail($p['tunnel_ids'][$index]);
                $p['baseline'][$t->id]=$this->tunnelStatus($t,$remote);
                $this->next($op,[...$p,'index'=>$index+1],$result,'در حال ثبت وضعیت تونل‌ها پیش از به‌روزرسانی…',25);return;
            }
            $this->next($op,[...$p,'phase'=>'stage','index'=>0],$result,'در حال آماده‌سازی بسته و نسخهٔ بازگشت…',30);return;
        }
        if(in_array($phase,['stage','activate','commit','rollback'],true)) {
            $targets=$phase==='rollback'?array_reverse($p['staged_ids']??[]):$p['upgrade_ids'];
            if(isset($targets[$index])) {
                $id=$targets[$index];$server=ManagedServer::findOrFail($id);
                // Save intent first, including the host whose SSH reply might be lost.
                if($phase==='stage') {$p['staged_ids']=array_values(array_unique([...($p['staged_ids']??[]),$id]));$op->update(['payload'=>$p]);}
                try {
                    $answer=$remote->maintenance($server,$phase,$manager->reservedPorts($server),$revision);
                    $previous=$result['hosts'][$id]??['name'=>$server->name];
                    $previous['logs']=[...($previous['logs']??[]),...($answer['logs']??[])];
                    if(isset($answer['status'])){$previous['status']=$answer['status'];$manager->saveHostStatus($id,$answer['status']);}
                    if($phase==='activate')$p['activated_at'][$id]=$answer['status']['checked_at'];
                    $result['hosts'][$id]=$previous;
                } catch(Throwable $error) {
                    if($phase!=='rollback')throw $error;
                    $result['hosts'][$id]['rollback_error']=$error->getMessage();
                    $manager->saveHostStatus($id,['checked_at'=>now()->timestamp,'error'=>'بازگردانی موتور نیازمند بررسی است.']);
                }
                $message=match($phase){'stage'=>'در حال آماده‌سازی بستهٔ سرورها…','activate'=>'در حال به‌روزرسانی موتور سرورها…','commit'=>'در حال نهایی‌کردن به‌روزرسانی…',default=>'در حال بازگردانی نسخهٔ قبلی…'};
                $this->next($op,[...$p,'index'=>$index+1],$result,$message,$phase==='activate'?60:40);return;
            }
            if($phase==='rollback') {
                $failed=collect($result['hosts'])->contains(fn($h)=>isset($h['rollback_error']));
                if($failed)ServerTunnel::whereIn('id',$p['tunnel_ids'])->update(['status'=>'needs_review','message'=>'بازگردانی موتور یکی از سرورها نیازمند بررسی است.']);
                $result['error']=$p['failure']??'به‌روزرسانی کامل نشد.';
                $manager->finish($op,'failed',$failed?'به‌روزرسانی کامل نشد؛ بازگردانی بعضی سرورها نیازمند بررسی است.':'به‌روزرسانی کامل نشد؛ نسخهٔ قبلی سرورها بازگردانده شد.',$result);return;
            }
            if($phase==='stage'){$this->next($op,[...$p,'phase'=>'activate','index'=>0],$result,'در حال به‌روزرسانی موتور سرورها…',55);return;}
            if($phase==='activate'){$this->next($op,[...$p,'phase'=>'verify','index'=>0,'verify_until'=>now()->addSeconds(120)->timestamp],$result,'در حال بررسی اتصال تونل‌های وابسته…',75,5);return;}
            $manager->finish($op,'succeeded','موتور سرورها به‌روزرسانی شد و وضعیت تونل‌های وابسته بررسی شد.',$result);return;
        }
        if($phase==='verify') {
            if(isset($p['tunnel_ids'][$index])) {
                $id=$p['tunnel_ids'][$index];$t=ServerTunnel::with(['iran','foreign'])->findOrFail($id);
                $after=$this->tunnelStatus($t,$remote);$before=$p['baseline'][$id];
                $healthy=$this->recovered($before,$after,$t,$p['activated_at']??[]);
                if(!$healthy) {
                    if(now()->timestamp >= $p['verify_until'])throw new \RuntimeException('یکی از تونل‌های وابسته به وضعیت قبلی برنگشت.');
                    $this->next($op,$p,$result,'در انتظار بازگشت ارتباط تونل‌ها…',80,5);return;
                }
                $t->update(['remote_status'=>$after]);
                $this->next($op,[...$p,'index'=>$index+1],$result,'در حال تأیید اتصال تونل‌های وابسته…',85);return;
            }
            $this->next($op,[...$p,'phase'=>'commit','index'=>0],$result,'در حال نهایی‌کردن به‌روزرسانی…',95);return;
        }
        throw new \RuntimeException('مرحلهٔ عملیات معتبر نیست.');
    }

    private function tunnelStatus(ServerTunnel $t,BackpackRemote $remote): array
    {
        $result=[];
        foreach(['iran','foreign'] as $side)$result[$side]=$remote->call($t->$side,['action'=>'status','name'=>$t->remote_name,
            'target_ports'=>$side==='foreign'?array_column($t->settings['mappings'],'target_port'):[],'udp'=>\App\Services\TrustedTunnelOrigin::udpOnlyBackpack($t)||(bool)$t->settings['accept_udp']]);
        return $result;
    }

    public function recovered(array $before,array $after,ServerTunnel $t,array $activated): bool
    {
        foreach(['iran','foreign'] as $side) {
            $old=$before[$side];$new=$after[$side];
            if((bool)($old['active']??false)!==(bool)($new['active']??false))return false;
            if(($old['metrics']['connected']??null)===true) {
                if(($new['metrics']['connected']??null)!==true)return false;
                $id=$side==='iran'?$t->iran_server_id:$t->foreign_server_id;
                if(isset($activated[$id]) && strtotime($new['metrics']['taken']??'')<$activated[$id]-2)return false;
            }
            foreach($old['targets']??[] as $port=>$working)if($working===true&&($new['targets'][$port]??null)!==true)return false;
        }
        return true;
    }

    private function next(TunnelOperation $op,array $payload,array $result,string $message,int $progress,int $delay=1): void
    {
        $op->update(['payload'=>$payload,'result'=>$result,'message'=>$message,'progress'=>$progress]);
        self::dispatch($op->id)->delay(now()->addSeconds($delay));
    }

    private function beginRollback(TunnelOperation $op,TunnelManager $manager,string $error): void
    {
        $p=$op->payload;
        if($op->action==='engine-update'&&!empty($p['staged_ids'])) {
            $op->update(['payload'=>[...$p,'phase'=>'rollback','index'=>0,'failure'=>$error],'message'=>'در حال بازگردانی نسخهٔ قبلی…']);
            self::dispatch($op->id);return;
        }
        $manager->finish($op,'failed','عملیات کامل نشد؛ گزارش سرورها را بررسی کنید.',[...($op->result??[]),'error'=>$error]);
    }

    public function failed(?Throwable $error): void
    {
        $op=TunnelOperation::find($this->operationId);
        if($op&&in_array($op->status,['queued','running'],true))$this->beginRollback($op,app(TunnelManager::class),'اجرای عملیات قطع شد؛ وضعیت سرورها نیازمند بررسی است.');
    }
}
