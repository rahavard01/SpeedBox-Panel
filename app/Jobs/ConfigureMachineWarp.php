<?php

namespace App\Jobs;

use App\Models\ManagedServer;
use App\Models\ServerDeployment;
use App\Services\SshExecutor;
use App\Services\EnsureMachineWarpSupport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ConfigureMachineWarp implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1200;
    private bool $checkSoftware = true;

    public function __construct(
        public readonly int $deploymentId,
        private readonly ?string $account = null,
        private readonly ?string $license = null,
        bool $checkSoftware = true,
    ) {
        $this->checkSoftware = $checkSoftware;
        $this->onQueue('deployments');
    }

    public function handle(SshExecutor $ssh, ?EnsureMachineWarpSupport $support = null): void
    {
        $deployment = ServerDeployment::findOrFail($this->deploymentId);
        $server = ManagedServer::with('machine')->findOrFail($deployment->managed_server_id);
        $deployment->update(['status' => 'running', 'progress' => $this->checkSoftware ? 5 : 35, 'started_at' => now(), 'log' => 'در حال بررسی و راه‌اندازی WARP روی سرور…']);
        $remoteDir = null;
        $localSecret = null;
        $checkingSoftware = false;
        try {
            if (!$server->machine) throw new \RuntimeException('Machine missing');
            if ($deployment->action === 'warp_prepare' && !$server->machine->supportsWarp()) {
                if (!$this->checkSoftware) throw new \RuntimeException('Server capability changed during setup');
                $checkingSoftware = true;
                ($support ?? app(EnsureMachineWarpSupport::class))->ensure($ssh, $server, $deployment);
                // Upgrade and WARP installation each retain their own worker timeout.
                // The encrypted continuation survives a closed browser or a worker restart.
                $deployment->update(['status' => 'queued', 'progress' => 30, 'log' => 'نرم‌افزار سرور آماده است؛ راه‌اندازی WARP در صف قرار گرفت.']);
                self::dispatch($deployment->id, $this->account, $this->license, false);
                return;
            }
            $sudo = $server->ssh_user === 'root' ? '' : 'sudo -n ';
            $remoteDir = $ssh->run($server, 'mktemp -d /tmp/speedbox-warp.XXXXXXXX', 30);
            if (!preg_match('~^/tmp/speedbox-warp\.[A-Za-z0-9]+$~D', $remoteDir)) {
                $remoteDir = null;
                throw new \RuntimeException('Invalid temporary path');
            }
            $ssh->upload($server, base_path('scripts/server-warp.sh'), $remoteDir.'/warp.sh');
            if ($this->license !== null && $this->license !== '') {
                $localSecret = tempnam(storage_path('app/private'), 'warp-');
                if ($localSecret === false) throw new \RuntimeException('Temporary file unavailable');
                chmod($localSecret, 0600);
                file_put_contents($localSecret, $this->license, LOCK_EX);
                $ssh->upload($server, $localSecret, $remoteDir.'/license');
            }
            $deployment->update(['progress' => 40, 'log' => $deployment->action === 'warp_prepare' ? 'در حال نصب و راه‌اندازی WARP و بررسی اتصال…' : 'در حال تست اتصال WARP…']);
            $action = $deployment->action === 'warp_prepare' ? 'prepare' : 'test';
            $command = $sudo.'bash '.escapeshellarg($remoteDir.'/warp.sh').' '.escapeshellarg($action)
                .' '.escapeshellarg($this->account ?? 'keep').' '.escapeshellarg($remoteDir.'/license');
            $result = json_decode($ssh->run($server, $command, 1000), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($result) || !is_bool($result['ready'] ?? null)) throw new \RuntimeException('Invalid WARP response');
            // Only known, non-secret fields can enter the database or API.
            $status = [
                'installed' => (bool) ($result['installed'] ?? false), 'ready' => $result['ready'],
                'account' => in_array($result['account'] ?? '', ['free', 'plus'], true) ? $result['account'] : 'unknown',
                'ip' => filter_var($result['ip'] ?? '', FILTER_VALIDATE_IP) ?: null,
                'checked_at' => time(),
            ];
            if (!$status['ready']) {
                $status['error_code'] = ($result['error'] ?? '') === 'not_installed' ? 'not_installed' : 'connection_failed';
                $status['error'] = match ($result['error'] ?? '') {
                    'existing_mode' => 'کلاینت فعلی در حالت Proxy نیست؛ برای جلوگیری از تغییر مسیر کل سرور، آماده‌سازی متوقف شد.',
                    'existing_team' => 'این کلاینت به حساب سازمانی متصل است؛ تغییر خودکار حساب سازمانی پشتیبانی نمی‌شود.',
                    'unsupported_os' => 'نسخه سیستم‌عامل برای نصب خودکار WARP پشتیبانی نمی‌شود؛ Ubuntu 22.04/24.04/26.04 یا Debian 12/13 استفاده کنید.',
                    'not_installed' => 'WARP روی این سرور نصب نیست. برای فعال‌سازی، از گزینهٔ «نصب و راه‌اندازی» استفاده کنید.',
                    'license_invalid' => 'لایسنس WARP+ پذیرفته یا تأیید نشد؛ کد و ظرفیت دستگاه‌های حساب را بررسی کنید.',
                    'port_in_use' => 'پورت 40000 توسط برنامه دیگری استفاده می‌شود؛ تنظیمات فعلی تغییر داده نشد.',
                    'public_listener' => 'پراکسی WARP باید فقط روی localhost گوش کند؛ اتصال عمومی پذیرفته نشد.',
                    'busy' => 'عملیات دیگری روی کلاینت WARP همین سرور در حال اجراست.',
                    default => 'اتصال WARP از پراکسی محلی تأیید نشد؛ وضعیت کلاینت و شبکه سرور را بررسی کنید.',
                };
            }
            $server->machine->update(['warp_status' => $status]);
            $deployment->update(['status' => $status['ready'] ? 'succeeded' : 'failed', 'progress' => 100,
                'finished_at' => now(), 'log' => $status['ready']
                    ? 'اتصال خروجی WARP از پراکسی محلی تأیید شد. نوع حساب: '.match ($status['account']) { 'plus' => 'WARP+', 'free' => 'رایگان', default => 'نامشخص' }.'.'
                    : $status['error']]);
        } catch (Throwable $error) {
            // SSH/CLI output may include a license. Never persist or rethrow it.
            $message = $checkingSoftware
                ? 'به‌روزرسانی یا تأیید ارتباط نرم‌افزار سرور کامل نشد؛ اتصال سرور به پنل و گزارش عملیات سرور را بررسی کنید.'
                : 'عملیات WARP ناموفق بود؛ دسترسی SSH، سیستم‌عامل، حالت Proxy و اتصال سرور به Cloudflare را بررسی کنید.';
            if ($server->machine) $server->machine->update(['warp_status' => [
                ...($server->machine->warp_status ?? []), 'ready' => false, 'checked_at' => time(), 'error' => $message,
                'error_code' => $checkingSoftware ? 'software_unconfirmed' : 'operation_failed',
            ]]);
            $deployment->update(['status' => 'failed', 'finished_at' => now(), 'log' => $message]);
            $this->fail(new \RuntimeException($message));
        } finally {
            if ($localSecret && is_file($localSecret)) @unlink($localSecret);
            if ($remoteDir !== null) {
                try { $ssh->run($server, 'rm -f -- '.escapeshellarg($remoteDir.'/license').' '.escapeshellarg($remoteDir.'/warp.sh').'; rmdir -- '.escapeshellarg($remoteDir), 30); }
                catch (Throwable) { /* The operation result must survive a disconnected SSH session. */ }
            }
        }
    }
}
