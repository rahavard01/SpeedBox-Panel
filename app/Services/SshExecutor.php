<?php

namespace App\Services;

use App\Models\ManagedServer;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class SshExecutor
{
    private array $temporaryFiles = [];
    private array $verifiedHostKeys = [];
    private ?string $pinnedHostFile = null;
    private ?float $budgetDeadline = null;

    public function withinBudget(int $seconds, callable $callback): mixed
    {
        $previous=$this->budgetDeadline;
        $this->budgetDeadline=min($previous??INF,microtime(true)+$seconds);
        try { return $callback(); }
        finally { $this->budgetDeadline=$previous;$this->cleanup(); }
    }

    private function timeoutFor(int $timeout): float
    {
        if ($this->budgetDeadline===null) return $timeout;
        $remaining=$this->budgetDeadline-microtime(true);
        if ($remaining<=0) throw new RuntimeException('مهلت پاک‌سازی یا بررسی تمام شد.',1002);
        return min($timeout,$remaining);
    }

    public function __construct()
    {
        File::ensureDirectoryExists(storage_path('app/private'), 0700);
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    public function run(ManagedServer $server, string $command, int $timeout = 120, ?string $input = null): string
    {
        $endpoint=['host'=>$server->host,'port'=>(int)$server->ssh_port];
        $this->verifyHostKey($server,$endpoint);
        if ($this->budgetDeadline!==null) $command='timeout --signal=TERM --kill-after=1s '.max(1,(int)$this->timeoutFor($timeout)-1).'s sh -c '.escapeshellarg($command);
        $process = new Process([
            ...$this->authPrefix($server),
            'ssh',
            ...$this->sshOptions($server,$endpoint),
            $server->ssh_user.'@'.$endpoint['host'],
            $command,
        ], null, $this->environment($server));
        $process->setTimeout($this->timeoutFor($timeout));
        if ($input !== null) $process->setInput($input);
        try {
            $process->run();
        } finally {
            $this->cleanup();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim($process->getOutput()."\n".$process->getErrorOutput()) ?: 'اجرای دستور SSH ناموفق بود.',$process->getExitCode()===255?1001:1002);
        }

        return trim($process->getOutput());
    }

    public function upload(ManagedServer $server, string $localPath, string $remotePath, int $timeout = 180): void
    {
        abort_unless(File::isFile($localPath), 500, "فایل موردنیاز نصب موجود نیست: {$localPath}");
        $endpoint=['host'=>$server->host,'port'=>(int)$server->ssh_port];
        $scpHost = filter_var($endpoint['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '['.$endpoint['host'].']' : $endpoint['host'];
        foreach ([false, true] as $legacy) {
            $this->verifyHostKey($server,$endpoint);
            $process = new Process([
                ...$this->authPrefix($server),
                'scp',
                ...($legacy ? ['-O'] : []),
                ...$this->scpOptions($server,$endpoint),
                $localPath,
                "{$server->ssh_user}@{$scpHost}:{$remotePath}",
            ], null, $this->environment($server));
            $process->setTimeout($this->timeoutFor($timeout));
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

    private function sshOptions(ManagedServer $server,array $endpoint): array
    {
        return [
            '-p', (string) $endpoint['port'],
            '-o', 'BatchMode='.($server->auth_type === 'key' && !$server->private_key_passphrase ? 'yes' : 'no'),
            '-o', 'ConnectTimeout='.($this->budgetDeadline===null?15:5),
            '-o', 'ServerAliveInterval=20',
            '-o', 'StrictHostKeyChecking='.($this->pinnedHostFile?'yes':'accept-new'),
            '-o', 'UserKnownHostsFile='.($this->pinnedHostFile?:storage_path('app/private/known_hosts')),
            ...($server->host_fingerprint?['-o','HostKeyAlias=speedbox-managed-'.$server->id]:[]),
            ...$this->identityOptions($server),
        ];
    }

    private function scpOptions(ManagedServer $server,array $endpoint): array
    {
        return [
            '-P', (string) $endpoint['port'],
            '-o', 'BatchMode='.($server->auth_type === 'key' && !$server->private_key_passphrase ? 'yes' : 'no'),
            '-o', 'ConnectTimeout='.($this->budgetDeadline===null?15:5),
            '-o', 'StrictHostKeyChecking='.($this->pinnedHostFile?'yes':'accept-new'),
            '-o', 'UserKnownHostsFile='.($this->pinnedHostFile?:storage_path('app/private/known_hosts')),
            ...($server->host_fingerprint?['-o','HostKeyAlias=speedbox-managed-'.$server->id]:[]),
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
        return ['-i', $path,'-o','IdentitiesOnly=yes'];
    }

    private function environment(ManagedServer $server): array
    {
        return ['SSHPASS' => (string) ($server->auth_type === 'password' ? $server->password : $server->private_key_passphrase)];
    }

    private function verifyHostKey(ManagedServer $server,array $endpoint): void
    {
        if (!$server->host_fingerprint) {
            return;
        }
        // Cache only the verified public key, never an authenticated session.
        // Every real connection still has to present this exact pinned key.
        $cacheKey='speedbox-ssh-key-'.hash('sha256',json_encode([$server->id,$endpoint,$server->host_fingerprint]));
        $key=$this->verifiedHostKeys[$cacheKey]??null;
        if ($key===null && app()->bound('cache')) $key=\Illuminate\Support\Facades\Cache::get($cacheKey);
        if (is_string($key)) {
            $this->pinKey($key);return;
        }
        $scan = new Process(['ssh-keyscan', '-p', (string) $endpoint['port'], '-T', $this->budgetDeadline===null?'8':'5', $endpoint['host']]);
        $scan->setTimeout($this->timeoutFor($this->budgetDeadline===null?15:5));
        $scan->run();
        if (!$scan->isSuccessful() || trim($scan->getOutput()) === '') {
            throw new RuntimeException('خواندن کلید میزبان SSH ناموفق بود.',1001);
        }
        $lines=array_values(array_filter(explode("\n",trim($scan->getOutput())),fn($line)=>$line!==''&&!str_starts_with($line,'#')));
        foreach($lines as $line) {
            $parts=preg_split('/\s+/',trim($line));if(count($parts)<3)continue;
            $keyFile=tempnam(storage_path('app/private'),'speedbox-host-key-');
            $this->temporaryFiles[]=$keyFile;
            file_put_contents($keyFile,'speedbox-managed-'.$server->id.' '.$parts[1].' '.$parts[2]."\n");chmod($keyFile,0600);
            $fingerprint=new Process(['ssh-keygen','-lf',$keyFile,'-E','sha256']);$fingerprint->mustRun();
            if(in_array(trim($server->host_fingerprint),preg_split('/\s+/',$fingerprint->getOutput()),true)) {
                $this->pinnedHostFile=$keyFile;
                $key=file_get_contents($keyFile);$this->verifiedHostKeys[$cacheKey]=$key;
                if(app()->bound('cache'))\Illuminate\Support\Facades\Cache::put($cacheKey,$key,300);
                return;
            }
        }
        $this->cleanup();
        throw new RuntimeException('اثر انگشت میزبان SSH تغییر کرده است. عملیات متوقف شد.');
    }

    private function pinKey(string $key): void
    {
        $path=tempnam(storage_path('app/private'),'speedbox-host-key-');
        file_put_contents($path,$key);chmod($path,0600);
        $this->temporaryFiles[]=$path;$this->pinnedHostFile=$path;
    }

    private function cleanup(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->temporaryFiles = [];
        $this->pinnedHostFile = null;
    }
}

