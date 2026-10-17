<?php

namespace App\Services;

use App\Models\ManagedServer;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class SshExecutor
{
    private array $temporaryFiles = [];

    public function __construct()
    {
        File::ensureDirectoryExists(storage_path('app/private'), 0700);
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    public function run(ManagedServer $server, string $command, int $timeout = 120): string
    {
        $this->verifyHostKey($server);
        $process = new Process([
            ...$this->authPrefix($server),
            'ssh',
            ...$this->sshOptions($server),
            "{$server->ssh_user}@{$server->host}",
            $command,
        ], null, $this->environment($server));
        $process->setTimeout($timeout);
        try {
            $process->run();
        } finally {
            $this->cleanup();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim($process->getOutput()."\n".$process->getErrorOutput()) ?: 'اجرای دستور SSH ناموفق بود.');
        }

        return trim($process->getOutput());
    }

    public function upload(ManagedServer $server, string $localPath, string $remotePath, int $timeout = 180): void
    {
        abort_unless(File::isFile($localPath), 500, "فایل موردنیاز نصب موجود نیست: {$localPath}");
        $scpHost = filter_var($server->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '['.$server->host.']' : $server->host;
        foreach ([false, true] as $legacy) {
            $this->verifyHostKey($server);
            $process = new Process([
                ...$this->authPrefix($server),
                'scp',
                ...($legacy ? ['-O'] : []),
                ...$this->scpOptions($server),
                $localPath,
                "{$server->ssh_user}@{$scpHost}:{$remotePath}",
            ], null, $this->environment($server));
            $process->setTimeout($timeout);
            try {
                $process->run();
            } finally {
                $this->cleanup();
            }
            if ($process->isSuccessful()) return;
            $error = trim($process->getErrorOutput() ?: $process->getOutput());
            // Some minimal/older hosts have SSH but no SFTP subsystem. The
            // legacy SCP protocol keeps the same authentication and host key.
            if (!$legacy && preg_match('/subsystem request failed|sftp/i', $error)) continue;
            throw new RuntimeException($error ?: 'بارگذاری فایل با SCP ناموفق بود.');
        }
    }

    private function authPrefix(ManagedServer $server): array
    {
        if ($server->auth_type === 'password') {
            if (!trim((string) $server->password)) {
                throw new RuntimeException('رمز عبور SSH وارد نشده است.');
            }
            return ['sshpass', '-e'];
        }

        return $server->private_key_passphrase ? ['sshpass', '-P', 'Enter passphrase for key', '-e'] : [];
    }

    private function sshOptions(ManagedServer $server): array
    {
        return [
            '-p', (string) $server->ssh_port,
            '-o', 'BatchMode='.($server->auth_type === 'key' && !$server->private_key_passphrase ? 'yes' : 'no'),
            '-o', 'ConnectTimeout=15',
            '-o', 'ServerAliveInterval=20',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile='.storage_path('app/private/known_hosts'),
            ...$this->identityOptions($server),
        ];
    }

    private function scpOptions(ManagedServer $server): array
    {
        return [
            '-P', (string) $server->ssh_port,
            '-o', 'BatchMode='.($server->auth_type === 'key' && !$server->private_key_passphrase ? 'yes' : 'no'),
            '-o', 'ConnectTimeout=15',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile='.storage_path('app/private/known_hosts'),
            ...$this->identityOptions($server),
        ];
    }

    private function identityOptions(ManagedServer $server): array
    {
        if ($server->auth_type !== 'key') {
            return [];
        }
        if (!trim((string) $server->private_key)) {
            throw new RuntimeException('کلید خصوصی SSH وارد نشده است.');
        }

        $path = tempnam(storage_path('app/private'), 'speedbox-key-');
        file_put_contents($path, rtrim($server->private_key)."\n");
        chmod($path, 0600);
        $this->temporaryFiles[] = $path;
        return ['-i', $path];
    }

    private function environment(ManagedServer $server): array
    {
        return ['SSHPASS' => (string) ($server->auth_type === 'password' ? $server->password : $server->private_key_passphrase)];
    }

    private function verifyHostKey(ManagedServer $server): void
    {
        if (!$server->host_fingerprint) {
            return;
        }
        $scan = new Process(['ssh-keyscan', '-p', (string) $server->ssh_port, '-T', '10', $server->host]);
        $scan->setTimeout(15);
        $scan->run();
        if (!$scan->isSuccessful() || trim($scan->getOutput()) === '') {
            throw new RuntimeException('خواندن کلید میزبان SSH ناموفق بود.');
        }
        $keyFile = tempnam(storage_path('app/private'), 'speedbox-host-key-');
        file_put_contents($keyFile, $scan->getOutput());
        $this->temporaryFiles[] = $keyFile;
        $fingerprint = new Process(['ssh-keygen', '-lf', $keyFile, '-E', 'sha256']);
        $fingerprint->run();
        if (!$fingerprint->isSuccessful() || !str_contains($fingerprint->getOutput(), $server->host_fingerprint)) {
            $this->cleanup();
            throw new RuntimeException('اثر انگشت میزبان SSH تغییر کرده است. عملیات متوقف شد.');
        }
    }

    private function cleanup(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->temporaryFiles = [];
    }
}

