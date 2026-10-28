<?php
namespace App\Jobs;

use App\Models\{ManagedServer,ServerTunnel};
use App\Services\{TrustedTunnelOrigin,TunnelManager};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeEncrypted};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Node topology changes enroll through the same leased paired operations.
 * Admission already fails closed for a new local alias while this is pending. */
class SyncTunnelOrigins implements ShouldQueue,ShouldBeEncrypted
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=0;
    public int $timeout=60;
    public array $backoff=[15,30,60,300];
    public function __construct(public array $machineIds){$this->onQueue('deployments');}
    public function retryUntil(): \DateTimeInterface{return now()->addHours(6);}
    public function handle(TunnelManager $manager): void
    {
        $ids=ManagedServer::whereIn('machine_id',$this->machineIds)->pluck('id');
        foreach(ServerTunnel::where('status','!=','deleted')->whereIn('foreign_server_id',$ids)->get() as $t){
            if($t->engine==='wattunnel')continue;
            try{$entries=TrustedTunnelOrigin::entries($t,$t->settings);}
            catch(\RuntimeException){$t->update(['status'=>'needs_review','message'=>'مقصد نود در مسیر چندمقصدی پشتیبانی نمی‌شود؛ برای نود مسیر جدا تنظیم کنید.']);continue;}
            $wanted=array_values(array_unique(array_column($entries,'target_port')));sort($wanted,SORT_NUMERIC);
            $current=TrustedTunnelOrigin::ports($t->settings);sort($current,SORT_NUMERIC);
            if($wanted===$current&&($t->settings['source_identity_policy_version']??0)>=3
                &&($t->settings['source_identity_topology']??'')===TrustedTunnelOrigin::topology($t,$t->settings))continue;
            try{$manager->queue($t->engine==='backpack'?'apply':'reapply',$t,null,['settings'=>$t->settings]);}
            catch(HttpExceptionInterface $error){if($error->getStatusCode()===409){$this->release(15);return;}throw $error;}
        }
    }
}
