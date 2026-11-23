<?php
namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
final class DeleteWireGuard implements ShouldQueue,ShouldBeUnique {
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $timeout=1200;public int $tries=1;public int $uniqueFor=1320;
    public function __construct(public int $instanceId){}
    public function uniqueId():string{return (string)$this->instanceId;}
    public function handle(\App\Services\WireGuardService $service):void{$service->remove($this->instanceId);}
    public function failed(\Throwable $error):void{
        \App\Models\WireGuardInstance::whereKey($this->instanceId)->where('status','deleting')->update(['status'=>'delete_failed','last_error'=>mb_substr($error->getMessage(),0,1000)]);
    }
}
