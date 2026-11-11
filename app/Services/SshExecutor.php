<?php

namespace App\Services;

use App\Models\ManagedServer;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class SshExecutor
{
    private array $temporaryFiles = [];
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
        if ($remaining<=0) throw new RuntimeException('مهلت عملیات SSH تمام شد.',1002);
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

    public function run(ManagedServer $server, string $command, int $timeout = 120, ?string $input = null, ?callable $onOutput = null): string
    {
        $endpoint=['host'=>$server->host,'port'=>(int)$server->ssh_port];
        try {
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
            $process->run($onOutput);

            if (!$process->isSuccessful()) {
                $this->forgetChangedHostKey($server, $endpoint, $process->getErrorOutput());
                throw new RuntimeException(trim($process->getOutput()."\n".$process->getErrorOutput()) ?: 'اجرای دستور SSH ناموفق بود.',$process->getExitCode()===255?1001:1002);
            }

            return trim($process->getOutput());
        } finally {
            $this->cleanup();
        }
    }

    public function upload(ManagedServer $server, string $localPath, string $remotePath, int $timeout = 180): void
    {
        abort_unless(File::isFile($localPath), 500, "فایل موردنیاز نصب موجود نیست: {$localPath}");
        $endpoint=['host'=>$server->host,'port'=>(int)$server->ssh_port];
        $scpHost = filter_var($endpoint['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '['.$endpoint['host'].']' : $endpoint['host'];
        foreach ([false, true] as $legacy) {
            try {
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
                $process->run();
            } finally {
                $this->cleanup();
            }
            if ($process->isSuccessful()) return;
            $error = trim($process->getErrorOutput() ?: $process->getOutput());
            $this->forgetChangedHostKey($server, $endpoint, $error);
            // Some minimal/older hosts have SSH but no SFTP subsystem. The
            // legacy SCP protocol keeps the same authentication and host key.
            if (!$legacy && preg_match('/subsystem request failed|sftp/i', $error)) continue;
            throw new RuntimeException($error ?: 'بارگذاری فایل با SCP ناموفق بود.');
        }
    }

    /** Independent read-only commands. No automatic replay and no shared key files. */
    public function runParallel(array $requests, int $seconds = 15): array
    {
        $running = []; $results = [];
        try {
            foreach ($requests as $name => $request) {
                $worker = new self(); $start = microtime(true);
                $worker->budgetDeadline = $start + $seconds;
                $server = $request['server'];
                $endpoint = ['host'=>$server->host, 'port'=>(int)$server->ssh_port];
                try {
                    $worker->verifyHostKey($server, $endpoint);
                    $verified = microtime(true);
                    $command = 'timeout --signal=TERM --kill-after=1s '.max(1, (int)$worker->timeoutFor($seconds)-1).'s sh -c '.escapeshellarg($request['command']);
                    $process = new Process([
                        ...$worker->authPrefix($server), 'ssh', ...$worker->sshOptions($server, $endpoint),
                        $server->ssh_user.'@'.$endpoint['host'], $command,
                    ], null, $worker->environment($server));
                    $process->setTimeout($worker->timeoutFor($seconds));
                    $process->setInput($request['input'] ?? null);
                    $process->start();
                    $running[$name] = compact('worker','process','server','endpoint','start','verified');
                } catch (\Throwable $error) {
                    $results[$name] = ['error'=>$error, 'timings_ms'=>['host_key'=>(int)round((microtime(true)-$start)*1000)]];
                    $worker->cleanup();
                }
            }
            $pending = $running;
            while ($pending) {
                foreach ($pending as $name => $entry) {
                    $worker=$entry['worker'];$process=$entry['process'];$server=$entry['server'];$endpoint=$entry['endpoint'];
                    try {
                        if ($process->isRunning()) {
                            $process->checkTimeout();
                            continue;
                        }
                        if (!$process->isSuccessful()) {
                            $worker->forgetChangedHostKey($server, $endpoint, $process->getErrorOutput());
                            throw new RuntimeException(trim($process->getOutput()."\n".$process->getErrorOutput()) ?: 'اجرای دستور SSH ناموفق بود.');
                        }
                        $results[$name] = ['output'=>trim($process->getOutput())];
                    } catch (\Throwable $error) {
                        if ($process->isRunning()) $process->stop(1);
                        $results[$name] = ['error'=>$error];
                    }
                    $results[$name]['timings_ms'] = [
                        'host_key'=>(int)round(($entry['verified']-$entry['start'])*1000),
                        'ssh'=>(int)round((microtime(true)-$entry['verified'])*1000),
                    ];
                    $worker->cleanup();
                    unset($pending[$name]);
                }
                if ($pending) usleep(10000);
            }
        } finally {
            foreach ($running as $entry) {
                if ($entry['process']->isRunning()) $entry['process']->stop(1);
                $entry['worker']->cleanup();
            }
        }
        return $results;
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
            '-o', 'HostKeyAlias=speedbox-managed-'.$server->id,
            '-o', 'GlobalKnownHostsFile=/dev/null',
            ...$this->identityOptions($server),
        ];
    }

    private function scpOptions(ManagedServer $server,array $endpoint): array
    {
        return [
            '-C',
            '-P', (string) $endpoint['port'],
            '-o', 'BatchMode='.($server->auth_type === 'key' && !$server->private_key_passphrase ? 'yes' : 'no'),
            '-o', 'ConnectTimeout='.($this->budgetDeadline===null?15:5),
            '-o', 'ServerAliveInterval=20',
            '-o', 'ServerAliveCountMax=3',
            '-o', 'StrictHostKeyChecking='.($this->pinnedHostFile?'yes':'accept-new'),
            '-o', 'UserKnownHostsFile='.($this->pinnedHostFile?:storage_path('app/private/known_hosts')),
            '-o', 'HostKeyAlias=speedbox-managed-'.$server->id,
            '-o', 'GlobalKnownHostsFile=/dev/null',
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
        // A previously authenticated key is checked by OpenSSH on EVERY
        // connection. Reusing that exact public key avoids repeated key scans.
        if (($saved = $this->storedHostKey($server, $endpoint)) !== null) {
            $this->pinKey($saved);
            return;
        }
        $scan = new Process(['ssh-keyscan', '-p', (string) $endpoint['port'], '-T', $this->budgetDeadline===null?'8':'5', $endpoint['host']]);
        $scan->setTimeout($this->timeoutFor(15));
        $scan->run();
        $candidates = [];
        foreach (explode("\n", trim($scan->getOutput())) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 3 || str_starts_with($line, '#')) continue;
            if (!in_array($parts[1], ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa'], true)) continue;
            $key = 'speedbox-managed-'.$server->id.' '.$parts[1].' '.$parts[2]."\n";
            $this->pinKey($key);
            $fingerprint = new Process(['ssh-keygen', '-lf', $this->pinnedHostFile, '-E', 'sha256']);
            $fingerprint->setTimeout($this->timeoutFor(10));
            $fingerprint->run();
            if (!$fingerprint->isSuccessful() || !preg_match('/SHA256:[A-Za-z0-9+\/]+/', $fingerprint->getOutput(), $match)) continue;
            if (hash_equals(trim((string) $server->host_fingerprint), $match[0])) {
                $this->saveHostKey($server, $endpoint, $key);
                return;
            }
            $candidates[] = [$key, $match[0], $parts[1]];
        }
        if (!$candidates) {
            $this->cleanup();
            throw new RuntimeException('خواندن کلید میزبان SSH ناموفق بود.', 1001);
        }
        usort($candidates, fn ($a, $b) => ($b[2] === 'ssh-ed25519') <=> ($a[2] === 'ssh-ed25519'));
        [$key, $fingerprint] = $candidates[0];
        $this->pinKey($key);
        // Authenticate a harmless probe before accepting a replacement. Never
        // retry the caller's command: it may already have changed remote state.
        try {
            $probe = new Process([...$this->authPrefix($server), 'ssh', ...$this->sshOptions($server, $endpoint), $server->ssh_user.'@'.$endpoint['host'], 'true'], null, $this->environment($server));
            $probe->setTimeout($this->timeoutFor(30));
            $probe->run();
            if (!$probe->isSuccessful()) throw new RuntimeException('تأیید ورود SSH با کلید جدید ناموفق بود. '.trim($probe->getErrorOutput()), 1001);
            if ($server->exists) {
                // Do not attach an old connection's key to an edited endpoint.
                $updated = ManagedServer::query()->whereKey($server->id)->where('host', $endpoint['host'])->where('ssh_port', $endpoint['port'])
                    ->where('host_fingerprint', $server->host_fingerprint)->update(['host_fingerprint' => $fingerprint]);
                if (!$updated && !ManagedServer::query()->whereKey($server->id)->where('host', $endpoint['host'])->where('ssh_port', $endpoint['port'])->where('host_fingerprint', $fingerprint)->exists()) throw new RuntimeException('اطلاعات اتصال سرور تغییر کرده است؛ عملیات را دوباره اجرا کنید.', 1001);
            }
            $server->host_fingerprint = $fingerprint;
            if ($server->exists) $server->syncOriginalAttribute('host_fingerprint');
            // Each endpoint has its own known-host file; unrelated hosts and
            // ports, including the legacy shared known_hosts, are untouched.
            $this->saveHostKey($server, $endpoint, $key);
        } catch (\Throwable $error) {
            $this->cleanup();
            throw $error;
        }
    }

    private function saveHostKey(ManagedServer $server, array $endpoint, string $key): void
    {
        $destination = storage_path('app/private/known-host-'.hash('sha256', json_encode([$server->id, $endpoint])));
        $staged = tempnam(storage_path('app/private'), 'speedbox-host-save-');
        $this->temporaryFiles[] = $staged;
        file_put_contents($staged, $key, LOCK_EX); chmod($staged, 0600);
        if (!rename($staged, $destination)) throw new RuntimeException('ذخیره کلید میزبان ناموفق بود.', 1001);
    }

    private function storedHostKey(ManagedServer $server, array $endpoint): ?string
    {
        $expected = trim((string) $server->host_fingerprint);
        if ($expected === '') return null;
        $path = storage_path('app/private/known-host-'.hash('sha256', json_encode([$server->id, $endpoint])));
        if (is_link($path) || !is_file($path) || filesize($path) > 16384 || (fileperms($path) & 0022)) return null;
        $key = file_get_contents($path);
        if (!is_string($key)) return null;
        $parts = preg_split('/\s+/', trim($key));
        if (count($parts) !== 3 || $parts[0] !== 'speedbox-managed-'.$server->id) return null;
        if (!in_array($parts[1], ['ssh-ed25519','ecdsa-sha2-nistp256','ecdsa-sha2-nistp384','ecdsa-sha2-nistp521','ssh-rsa'], true)) return null;
        $blob = base64_decode($parts[2], true);
        if ($blob === false) return null;
        $actual = 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        return hash_equals($expected, $actual) ? rtrim($key)."\n" : null;
    }

    private function forgetChangedHostKey(ManagedServer $server, array $endpoint, string $error): void
    {
        // Never retry a possibly executed mutation. The next operation performs
        // a fresh scan and the existing authenticated replacement-key check.
        if (!preg_match('/REMOTE HOST IDENTIFICATION HAS CHANGED|Host key verification failed/i', $error)) return;
        @unlink(storage_path('app/private/known-host-'.hash('sha256', json_encode([$server->id, $endpoint]))));
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

