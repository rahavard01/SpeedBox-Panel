<?php

namespace App\Services;

use App\Models\ManagedServer;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class SshExecutor
{
    private array $temporaryFiles = [];
    private array $endpoints = [];
    private ?string $pinnedHostFile = null;

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
        $endpoint=$this->endpoint($server);
        $this->verifyHostKey($server,$endpoint);
        $process = new Process([
            ...$this->authPrefix($server),
            'ssh',
            ...$this->sshOptions($server,$endpoint),
            $server->ssh_user.'@'.$endpoint['host'],
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
        $endpoint=$this->endpoint($server);
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

    private function sshOptions(ManagedServer $server,array $endpoint): array
    {
        return [
            '-p', (string) $endpoint['port'],
            '-o', 'BatchMode='.($server->auth_type === 'key' && !$server->private_key_passphrase ? 'yes' : 'no'),
            '-o', 'ConnectTimeout=15',
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
            '-o', 'ConnectTimeout=15',
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
        $scan = new Process(['ssh-keyscan', '-p', (string) $endpoint['port'], '-T', '8', $endpoint['host']]);
        $scan->setTimeout(15);
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
                $this->pinnedHostFile=$keyFile;return;
            }
        }
        $this->cleanup();
        throw new RuntimeException('اثر انگشت میزبان SSH تغییر کرده است. عملیات متوقف شد.');
    }

    /** Select a route with a harmless command BEFORE executing or uploading anything.
     * A failed mutation is never retried through another route.
     */
    private function endpoint(ManagedServer $server): array
    {
        $direct=['host'=>$server->host,'port'=>(int)$server->ssh_port];
        if(!$server->reverse_ssh_port)return $direct;
        if(!preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D',trim((string)$server->host_fingerprint))) {
            throw new RuntimeException('برای مسیر SSH معکوس، اثر انگشت معتبر سرور مقصد را ثبت کنید.');
        }
        $key=hash('sha256',json_encode([$server->id,$direct,$server->reverse_ssh_port,$server->host_fingerprint]));
        if(isset($this->endpoints[$key]))return $this->endpoints[$key];
        foreach([$direct,['host'=>'127.0.0.1','port'=>(int)$server->reverse_ssh_port]] as $index=>$route) {
            $networkFailure=false;
            try {
                $this->verifyHostKey($server,$route);
                $process=new Process([...$this->authPrefix($server),'ssh',...$this->sshOptions($server,$route),
                    $server->ssh_user.'@'.$route['host'],'true'],null,$this->environment($server));
                $process->setTimeout(30);$process->run();
                if($process->isSuccessful())return $this->endpoints[$key]=$route;
                $error=trim($process->getErrorOutput()."\n".$process->getOutput());
            } catch (RuntimeException $e) { $error=$e->getMessage();$networkFailure=$e->getCode()===1001; }
            finally { $this->cleanup(); }
            // Key mismatch, denied credentials and remote command failures are
            // not evidence of a broken network route.
            if($index===1||!$networkFailure&&!preg_match('/Connection timed out|Operation timed out|timed out during/u',$error)) {
                throw new RuntimeException($error?:'برقراری ارتباط SSH ناموفق بود.');
            }
        }
        throw new RuntimeException('مسیر مستقیم و مسیر SSH معکوس پاسخ ندادند؛ اتصال معکوس سرور را بررسی کنید.');
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

