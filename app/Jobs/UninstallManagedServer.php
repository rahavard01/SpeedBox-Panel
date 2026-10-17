<?php

namespace App\Jobs;

use App\Models\ManagedServer;
use App\Models\ServerDeployment;
use App\Services\ManagedServerRemoval;
use App\Services\SshExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class UninstallManagedServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public readonly int $deploymentId)
    {
        $this->onQueue('deployments');
    }

    public function handle(SshExecutor $ssh, ManagedServerRemoval $removal): void
    {
        $deployment = ServerDeployment::findOrFail($this->deploymentId);
        $server = ManagedServer::with('machine')->findOrFail($deployment->managed_server_id);
        $deployment->update(['status' => 'running', 'progress' => 5, 'started_at' => now(), 'log' => 'در حال پاک‌سازی سرویس مدیریت نود از سرور…']);
        $server->update(['status' => 'removing', 'last_error' => null]);
        $sudo = $server->ssh_user === 'root' ? '' : 'sudo -n ';
        $remote = '/tmp/speedbox-uninstall-'.$deployment->id.'.sh';
        try {
            // Stop must succeed before removing credentials or panel records.
            $ssh->run($server, 'if systemctl is-active --quiet xboard-node || systemctl cat xboard-node >/dev/null 2>&1; then '.$sudo.'systemctl stop xboard-node; fi', 90);
            $deployment->update(['progress' => 20]);
            $ssh->upload($server, base_path('agent/install.sh'), $remote);
            $command = $sudo.'bash '.escapeshellarg($remote).' uninstall --purge --yes';
            $output = $ssh->run($server, $command, 120);
            $token = (string) $server->machine?->token;
            $deployment->update(['progress' => 80, 'log' => $deployment->log."\n".($token ? str_replace($token, '[حذف‌شده]', $output) : $output)]);
            $cleanup = $sudo.'rm -f '.escapeshellarg($remote).' /tmp/speedbox-install.sh /tmp/speedbox-node /tmp/speedbox-xbctl';
            $ssh->run($server, $cleanup.' && ! systemctl is-active --quiet xboard-node && test ! -e /usr/local/bin/xboard-node && test ! -e /usr/local/bin/xbctl && test ! -e /usr/bin/xbctl && test ! -L /usr/bin/xbctl && test ! -e /etc/systemd/system/xboard-node.service && test ! -L /etc/systemd/system/multi-user.target.wants/xboard-node.service && test ! -e /etc/xboard-node', 45);
            DB::transaction(function () use ($server, $removal) {
                $locked = ManagedServer::whereKey($server->id)->lockForUpdate()->firstOrFail();
                $removal->removeLocked($locked);
            });
        } catch (Throwable $e) {
            $token = (string) $server->machine?->token;
            $message = $token ? str_replace($token, '[حذف‌شده]', $e->getMessage()) : $e->getMessage();
            $deployment->update(['status' => 'failed', 'finished_at' => now(), 'log' => $deployment->log."\nپاک‌سازی کامل نشد؛ رکورد پنل حفظ شد.\n".$message]);
            $server->update(['status' => 'failed', 'last_error' => $message]);
            throw $e;
        }
    }

    public function failed(?Throwable $error): void
    {
        $deployment = ServerDeployment::find($this->deploymentId);
        if (!$deployment || $deployment->status === 'failed') return;
        $deployment->update(['status' => 'failed', 'finished_at' => now(), 'log' => $deployment->log."\nپاک‌سازی متوقف شد؛ رکورد پنل برای بررسی و تلاش مجدد حفظ شد."]);
        ManagedServer::whereKey($deployment->managed_server_id)->update(['status' => 'failed', 'last_error' => 'پاک‌سازی متوقف شد؛ گزارش عملیات را بررسی کنید.']);
    }
}
