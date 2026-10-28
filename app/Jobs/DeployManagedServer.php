<?php

namespace App\Jobs;

use App\Models\ManagedServer;
use App\Models\ServerDeployment;
use App\Services\SshExecutor;
use App\Services\DeploymentLogStream;
use App\Services\MachineResourceSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Throwable;

class DeployManagedServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1200;

    public function __construct(public readonly int $deploymentId)
    {
        $this->onQueue('deployments');
    }

    public function handle(SshExecutor $ssh): void
    {
        $deployment = ServerDeployment::findOrFail($this->deploymentId);
        $server = ManagedServer::with('machine')->findOrFail($deployment->managed_server_id);
        $deployment->update(['status' => 'running', 'progress' => 5, 'started_at' => now(), 'log' => 'در حال اتصال به سرور…']);
        $server->update(['status' => 'deploying', 'last_error' => null]);
        $remoteDir = null;

        try {
            $probe = $ssh->run($server, "printf 'OS='; . /etc/os-release 2>/dev/null; printf '%s' \"\${ID:-linux}\"; printf '\nARCH='; uname -m", 45);
            [$os, $arch] = $this->parseProbe($probe);
            $assetArch = match ($arch) {
                'x86_64', 'amd64' => 'amd64',
                'aarch64', 'arm64' => 'arm64',
                default => throw new \RuntimeException("معماری پشتیبانی نمی‌شود: {$arch}"),
            };
            $deployment->update(['progress' => 20, 'log' => $deployment->log."\nسیستم و معماری شناسایی شد: {$os}/{$assetArch}."]);

            if (in_array($deployment->action, ['check', 'restart'], true)) {
                $this->runOperationalAction($ssh, $server, $deployment, $os, $assetArch);
                return;
            }

            $assetDir = storage_path('app/agent');
            $sudo = $server->ssh_user === 'root' ? '' : 'sudo -n ';
            $ssh->run($server, $sudo.'sh -c '.escapeshellarg('command -v systemctl >/dev/null && test -d /run/systemd/system && command -v sha256sum >/dev/null && command -v bash >/dev/null'), 45);
            $remoteDir = $ssh->run($server, 'mktemp -d /tmp/panel-speedbox-deploy.XXXXXX', 30);
            if (!preg_match('~^/tmp/panel-speedbox-deploy\.[A-Za-z0-9]+$~', $remoteDir)) {
                throw new \RuntimeException('ساخت پوشه موقت امن برای نصب ناموفق بود.');
            }
            $assets = [
                base_path('agent/install.sh') => "{$remoteDir}/install.sh",
                "{$assetDir}/xboard-node-linux-{$assetArch}" => "{$remoteDir}/xboard-node",
                "{$assetDir}/xbctl-linux-{$assetArch}" => "{$remoteDir}/xbctl",
            ];
            $checksums = [];
            foreach ($assets as $local => $remote) {
                if (!File::isFile($local)) {
                    throw new \RuntimeException('بسته نصب نود آماده نیست؛ ساخت خودکار بسته هنگام نصب یا به‌روزرسانی پنل باید کامل شود.');
                }
                $checksum = hash_file('sha256', $local);
                if ($checksum === false) throw new \RuntimeException('خواندن بسته نصب برای بررسی صحت ناموفق بود.');
                try {
                    $ssh->upload($server, $local, $remote);
                } catch (Throwable $uploadError) {
                    $deployment->update(['log' => $deployment->log."\nانتقال فایل کامل نشد؛ تلاش دوباره…"]);
                    $ssh->upload($server, $local, $remote);
                }
                $checksums[] = $checksum.'  '.$remote;
            }
            $ssh->run($server, 'printf %s '.escapeshellarg(implode("\n", $checksums)."\n").' | sha256sum -c -', 60);
            $deployment->update(['progress' => 55, 'log' => $deployment->log."\nبسته نود شامل Xray و Sing-box منتقل شد و صحت فایل‌ها تأیید شد."]);

            $machine = $server->machine;
            if (!$machine) {
                throw new \RuntimeException('هیچ ماشینی به این سرور متصل نیست.');
            }
            $panelUrl = rtrim((string) config('app.url'), '/');
            $command = $sudo.'bash '.escapeshellarg("{$remoteDir}/install.sh").' install'.
                ' --panel '.escapeshellarg($panelUrl).
                ' --token '.escapeshellarg($machine->token).
                ' --machine-id '.escapeshellarg((string) $machine->id).
                ' --kernel '.escapeshellarg($deployment->kernel).
                ' --binary '.escapeshellarg("{$remoteDir}/xboard-node").
                ' --xbctl-binary '.escapeshellarg("{$remoteDir}/xbctl").
                ' --expected-version panel-speedbox-http-obfs'.
                ' --force-reconfigure --yes';
            $stream = new DeploymentLogStream($deployment, $this->secrets($server));
            try {
                $ssh->run($server, $command, (int) config('speedbox.deploy_timeout', 900), null, $stream->append(...));
            } finally {
                $stream->finish();
            }
            $deployment->update(['progress' => 90]);

            $status = $ssh->run($server, $sudo.'systemctl is-active xboard-node && /usr/local/bin/xboard-node -v', 45);
            if (preg_match('/^active$/m', $status) !== 1) {
                throw new \RuntimeException('نصب انجام شد اما سرویس مدیریت نود فعال نیست؛ گزارش نصب را بررسی کنید.');
            }
            $server->update([
                'status' => preg_match('/^active$/m', $status) === 1 ? 'online' : 'degraded',
                'agent_version' => trim(str_replace('active', '', $status)),
                'operating_system' => $os,
                'architecture' => $assetArch,
                'last_checked_at' => time(),
            ]);
            $this->collectResources($ssh, $server, $deployment);
            $deployment->update(['status' => 'succeeded', 'progress' => 100, 'finished_at' => now(), 'log' => $deployment->log."\nنصب با موفقیت پایان یافت."]);
        } catch (Throwable $e) {
            $message = mb_strcut(str_replace($this->secrets($server), '[REDACTED]', $e->getMessage()), -8192, null, 'UTF-8');
            $server->update(['status' => 'failed', 'last_error' => $message]);
            $deployment->update(['status' => 'failed', 'finished_at' => now(), 'log' => mb_strcut($deployment->log."\nخطا: {$message}", -262144, null, 'UTF-8')]);
            throw new \RuntimeException($message);
        } finally {
            if ($remoteDir && preg_match('~^/tmp/panel-speedbox-deploy\.[A-Za-z0-9]+$~', $remoteDir)) {
                try {
                    $ssh->run($server, 'rm -rf -- '.escapeshellarg($remoteDir), 30);
                } catch (Throwable $cleanupError) {
                    // A disconnected server must not hide the original result.
                    report($cleanupError);
                }
            }
        }
    }

    private function runOperationalAction(SshExecutor $ssh, ManagedServer $server, ServerDeployment $deployment, string $os, string $arch): void
    {
        $sudo = $server->ssh_user === 'root' ? '' : 'sudo -n ';
        if ($deployment->action === 'restart') {
            $deployment->update(['log' => $deployment->log."\nدر حال راه‌اندازی مجدد هسته‌های نود…"]);
            $ssh->run($server, $sudo.'systemctl restart xboard-node', 90);
        }
        $status = $ssh->run($server, $sudo.'systemctl is-active xboard-node; '.$sudo.'systemctl status xboard-node --no-pager -n 15', 60);
        $online = preg_match('/^active$/m', $status) === 1;
        $server->update([
            'status' => $online ? 'online' : 'degraded',
            'operating_system' => $os,
            'architecture' => $arch,
            'last_checked_at' => time(),
        ]);
        if ($online) $this->collectResources($ssh, $server, $deployment);
        $deployment->update([
            'status' => $online ? 'succeeded' : 'failed',
            'progress' => 100,
            'finished_at' => now(),
            'log' => $deployment->log."\n".($online ? 'سرویس مدیریت نود فعال است.' : 'سرویس مدیریت نود فعال نیست؛ گزارش فنی را بررسی کنید.')."\n".str_replace($this->secrets($server), '[REDACTED]', $status),
        ]);
    }

    private function parseProbe(string $probe): array
    {
        preg_match('/OS=([^\r\n]+)/', $probe, $os);
        preg_match('/ARCH=([^\r\n]+)/', $probe, $arch);
        return [trim($os[1] ?? 'linux'), trim($arch[1] ?? '')];
    }

    private function secrets(ManagedServer $server): array
    {
        return array_values(array_filter([(string) $server->machine?->token, (string) $server->password, (string) $server->private_key, (string) $server->private_key_passphrase], fn ($secret) => $secret !== ''));
    }

    private function collectResources(SshExecutor $ssh, ManagedServer $server, ServerDeployment $deployment): void
    {
        try {
            app(MachineResourceSnapshot::class)->collect($ssh, $server);
            $deployment->update(['log' => $deployment->log."\nنمونه اولیه منابع دریافت شد."]);
        } catch (Throwable) {
            // A metrics failure must not mark a successful installation broken.
            $deployment->update(['log' => $deployment->log."\nدریافت نمونه اولیه منابع ناموفق بود؛ گزارش دوره‌ای عامل جایگزین می‌شود."]);
        }
    }
}
