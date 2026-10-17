<?php

namespace App\Services;

use App\Models\ManagedServer;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RemoteNodeTools
{
    public function __construct(private readonly SshExecutor $ssh)
    {
    }

    public function run(ManagedServer $server, string $tool, array $arguments = []): array
    {
        $sudo = $server->ssh_user === 'root' ? '' : 'sudo -n ';
        $temporaryDir = null;
        try {
            // Prefer the installed CLI; an older installation can use the new
            // bundled CLI temporarily without changing its running services.
            try {
                $supported = $this->ssh->run($server, $sudo.'/usr/local/bin/xbctl node-tools version', 25) === '1';
            } catch (RuntimeException) {
                $supported = false;
            }
            $binary = '/usr/local/bin/xbctl';
            if (!$supported) {
                $arch = $this->ssh->run($server, 'uname -m', 25);
                $assetArch = match ($arch) {
                    'x86_64', 'amd64' => 'amd64',
                    'aarch64', 'arm64' => 'arm64',
                    default => throw ValidationException::withMessages(['server' => 'معماری سرور برای این ابزار پشتیبانی نمی‌شود.']),
                };
                $local = storage_path("app/agent/xbctl-linux-{$assetArch}");
                if (!is_file($local)) {
                    throw ValidationException::withMessages(['server' => 'ابزار جدید سرور آماده نیست؛ scripts/build-agent.sh را روی میزبان پنل اجرا کنید یا ابزار سرور را با نصب مجدد به‌روزرسانی کنید.']);
                }
                $temporaryDir = $this->ssh->run($server, 'mktemp -d /tmp/speedbox-node-tools.XXXXXXXX', 25);
                if (!preg_match('~^/tmp/speedbox-node-tools\.[A-Za-z0-9]+$~D', $temporaryDir)) {
                    $temporaryDir = null;
                    throw new RuntimeException('ساخت مسیر موقت ابزار ناموفق بود.');
                }
                $binary = $temporaryDir.'/xbctl';
                $this->ssh->upload($server, $local, $binary, 120);
                $this->ssh->run($server, 'chmod 700 '.escapeshellarg($binary), 25);
            }
            $command = $sudo.escapeshellarg($binary).' node-tools '.escapeshellarg($tool);
            foreach ($arguments as $name => $value) {
                $command .= ' --'. $name.' '.escapeshellarg((string) $value);
            }
            $result = json_decode($this->ssh->run($server, $command, 45), true);
            if (!is_array($result)) {
                throw ValidationException::withMessages(['server' => 'پاسخ ابزار سرور معتبر نیست؛ بسته جدید ابزار سرور را بسازید.']);
            }
            return $result;
        } catch (RuntimeException $error) {
            // Do not expose command output that could contain private key material.
            $knownErrors = [
                'گواهی این نود هنوز روی سرور آماده نیست؛ ابتدا نود و تنظیمات گواهی را ذخیره کنید.',
                'گواهی ذخیره‌شده روی سرور معتبر نیست.',
                'گواهی TLS در فایل این نود پیدا نشد.',
                'اتصال این ماشین به پنل روی سرور پیدا نشد.',
                'تنظیمات سرویس مدیریت نود روی سرور قابل خواندن نیست.',
                'تنظیمات ماشین‌های سرور معتبر نیست.',
                'بیش از یک تنظیم اتصال برای این ماشین پیدا شد؛ تنظیمات سرور را بررسی کنید.',
                'تولید کلید رمزنگاری ناموفق بود.',
            ];
            $message = $tool === 'certificate-fingerprint'
                ? 'دریافت گواهی ناموفق بود؛ اتصال SSH، دسترسی سرور و آماده بودن گواهی نود را بررسی کنید.'
                : 'تولید رمزنگاری روی سرور ناموفق بود؛ اتصال SSH را بررسی کنید و بسته جدید ابزار سرور را بسازید.';
            if (in_array(trim($error->getMessage()), $knownErrors, true)) {
                $message = trim($error->getMessage());
            }
            throw ValidationException::withMessages(['server' => $message]);
        } finally {
            if ($temporaryDir !== null) {
                try {
                    $this->ssh->run($server, 'rm -f '.escapeshellarg($temporaryDir.'/xbctl').'; rmdir '.escapeshellarg($temporaryDir), 25);
                } catch (\Throwable) {
                    // Cleanup failure must not replace the useful operation result.
                }
            }
        }
    }
}
