<?php
namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
final class SyncWireGuard implements ShouldQueue,ShouldBeUnique {
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $timeout=1200; public int $tries=1; public int $uniqueFor=1320;
    public function __construct(public int $instanceId,public bool $install=false) {}
    public function uniqueId():string {return (string)$this->instanceId;}
    public function failed(\Throwable $error):void {
        $i=\App\Models\WireGuardInstance::find($this->instanceId);
        if($i && !in_array($i->status,['deleting','delete_failed'],true))$i->update(['status'=>'failed','last_error'=>mb_substr($error->getMessage(),0,1000)]+(!$i->public_key?['enabled'=>false]:[]));
    }
    public function handle(\App\Services\WireGuardService $service):void {$service->sync($this->instanceId,$this->install);}
}
