<?php

namespace App\Jobs;

use App\Models\ServerDeployment;
use App\Services\SshExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Reload the old machine first, then the destination, using current panel data. */
class ApplyNodeAssignment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public array $backoff = [3,10,30];
    public int $timeout = 600;

    public function __construct(public readonly int $nodeId, public readonly array $deploymentIds, public readonly ?int $destinationServerId)
    {
        $this->onQueue('deployments');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(SshExecutor $ssh): void
    {
        // Compatibility for jobs queued before targeted synchronization existed.
        $operations=[];
        foreach($this->deploymentIds as $id){
            $deployment=ServerDeployment::find($id);
            $server=$deployment?\App\Models\ManagedServer::find($deployment->managed_server_id):null;
            if($deployment&&$server?->machine_id)$operations[]=['deployment_id'=>$id,'machine_id'=>(int)$server->machine_id,'nodes'=>[$this->nodeId=>'present']];
        }
        $sync=new SyncNodeRuntime($operations);
        if($this->job)$sync->setJob($this->job);
        $sync->handle($ssh);
    }

    public function failed(?\Throwable $e): void
    {
        foreach($this->deploymentIds as $id)ServerDeployment::whereKey($id)->whereIn('status',['queued','running'])->update(['status'=>'failed','finished_at'=>now(),'log'=>'همگام‌سازی فوری کامل نشد؛ وضعیت سرور را بررسی کنید.']);
    }
}
