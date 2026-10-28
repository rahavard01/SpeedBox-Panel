<?php
namespace App\Jobs;
use App\Models\{ManagedServer,ServerDeployment};
use App\Services\SshExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class SyncNodeRuntime implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=0;
    public array $backoff=[3,10,30];
    public int $deadline;
    public int $timeout=600;
    public function __construct(public array $operations){$this->onQueue('deployments');$this->deadline=time()+1800;}
    public function retryUntil(): \DateTimeInterface{return (new \DateTimeImmutable())->setTimestamp($this->deadline);}
    public function handle(SshExecutor $ssh):void
    {
        $failed=false;
        foreach($this->operations as $op){
            $d=ServerDeployment::findOrFail($op['deployment_id']);
            if($d->status==='succeeded')continue;
            try{
                $server=ManagedServer::findOrFail($d->managed_server_id);
                $d->update(['action'=>'sync_nodes','status'=>'running','progress'=>20,'started_at'=>now()]);
                $lock=Cache::lock('speedbox-node-sync-server-'.$server->id,120);
                if(!$lock->get()){$d->update(['status'=>'queued']);$this->release(2);return;}
                try{
                    $sudo=$server->ssh_user==='root'?'':'sudo -n ';
                    $command=$sudo.'/usr/local/bin/xbctl node-tools sync --panel '.escapeshellarg(rtrim((string)config('app.url'),'/')).' --machine-id '.(int)$op['machine_id'].' --nodes '.escapeshellarg(implode(',',array_keys($op['nodes'])));
                    $result=json_decode($ssh->run($server,$command,90),true,32,JSON_THROW_ON_ERROR);
                }finally{$lock->release();}
                $states=[];foreach($result['states']??[] as $state)$states[(int)($state['node_id']??0)]=$state['state']??'';
                foreach($op['nodes'] as $id=>$wanted){
                    $state=$states[$id]??'';
                    // A newer committed edit may have removed/reassigned this ID.
                    $node=\App\Models\ProxyServer::find($id);
                    $present=$node&&$node->enabled&&!$node->parent_id&&(int)$node->machine_id===(int)$op['machine_id'];
                    if($present?!in_array($state,['running','waiting_for_users'],true):$state!=='removed')throw new RuntimeException('تأیید اعمال نود از عامل دریافت نشد.');
                }
                $d->update(['status'=>'succeeded','progress'=>100,'finished_at'=>now(),'log'=>$d->log."\nعامل دریافت و اعمال وضعیت فعلی نودها را تأیید کرد."]);
            }catch(Throwable){
                $failed=true;$d->update(['status'=>'failed','finished_at'=>now(),'log'=>$d->log."\nاعمال فوری کامل نشد؛ نسخهٔ جدید عامل و اتصال SSH را بررسی کنید. دریافت دوره‌ای همچنان فعال است."]);
            }
        }
        if($failed)throw new RuntimeException('اعمال فوری بعضی نودها تأیید نشد؛ گزارش عملیات سرورها را بررسی کنید.');
    }
    public function failed(?Throwable $e):void
    {
        foreach($this->operations as $op)ServerDeployment::whereKey($op['deployment_id'])->whereIn('status',['queued','running'])->update(['status'=>'failed','finished_at'=>now(),'log'=>'همگام‌سازی فوری کامل نشد؛ دوباره ذخیره یا وضعیت سرور را بررسی کنید.']);
    }
}
