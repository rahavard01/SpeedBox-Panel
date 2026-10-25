<?php
namespace App\Jobs;

use App\Models\{ManagedServer,ServerDeployment};
use App\Services\{ManagedServerRemoval,SshExecutor};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\{Cache,DB,Log};
use Throwable;

class UninstallManagedServer implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=1;
    public int $timeout=40;
    public function __construct(public readonly int $deploymentId) {$this->onQueue('deployments');}

    public function handle(SshExecutor $ssh,ManagedServerRemoval $removal): void
    {
        $deployment=ServerDeployment::find($this->deploymentId);
        if (!$deployment) return;
        $server=ManagedServer::with('machine')->find($deployment->managed_server_id);
        if (!$server) return;
        $deployment->update(['status'=>'running','progress'=>10,'started_at'=>now(),'log'=>'در حال حذف سرور…']);
        $cleaned=false;
        try {
            $sudo=$server->ssh_user==='root'?'':'sudo -n ';
            $command='set -e; if systemctl cat xboard-node >/dev/null 2>&1; then '.$sudo.'systemctl stop xboard-node; fi; '
                .$sudo.'bash -s -- uninstall --purge --yes; '
                .$sudo.'rm -f /tmp/speedbox-install.sh /tmp/speedbox-node /tmp/speedbox-xbctl; '
                .'! systemctl is-active --quiet xboard-node; test ! -e /usr/local/bin/xboard-node; test ! -e /etc/xboard-node';
            $ssh->withinBudget(15,fn()=>$ssh->run($server,$command,15,file_get_contents(base_path('agent/install.sh'))));
            $cleaned=true;
        } catch (Throwable) { /* A retired host cannot keep its panel record alive. */ }
        $this->complete($server,$removal,$cleaned);
    }

    private function complete(ManagedServer $server,ManagedServerRemoval $removal,bool $cleaned): void
    {
        $message='سرور حذف شد.'.($cleaned?'':' پاک‌سازی سرور تأیید نشد.');
        DB::transaction(function () use ($server,$removal) {
            $locked=ManagedServer::whereKey($server->id)->lockForUpdate()->first();
            if ($locked) $removal->removeLocked($locked);
        });
        // Deployments are cascaded on deletion; retain a credential-free receipt.
        $receipt=['status'=>'succeeded','message'=>$message,'cleanup_confirmed'=>$cleaned];
        Cache::put('speedbox-server-removal-'.$this->deploymentId,$receipt,now()->addDay());
        Log::notice('SpeedBox server removal',['deployment_id'=>$this->deploymentId,'server_id'=>$server->id,'name'=>$server->name,...$receipt]);
    }

    public function failed(?Throwable $error): void
    {
        $deployment=ServerDeployment::find($this->deploymentId);
        $server=$deployment?ManagedServer::find($deployment->managed_server_id):null;
        if (!$server) return;
        try {$this->complete($server,app(ManagedServerRemoval::class),false);}
        catch (Throwable) {
            $deployment->update(['status'=>'failed','finished_at'=>now(),'log'=>'حذف از پنل کامل نشد؛ دوباره تلاش کنید.']);
            $server->update(['status'=>'failed']);
            Cache::put('speedbox-server-removal-'.$this->deploymentId,['status'=>'failed','message'=>'حذف از پنل کامل نشد؛ دوباره تلاش کنید.'],now()->addDay());
        }
    }
}
