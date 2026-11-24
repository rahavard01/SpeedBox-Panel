<?php

namespace App\Services;

use App\Models\ManagedServer;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class AnyConnectRemote
{
    private const HELPER = '/opt/speedbox-anyconnect/host.py';

    public function __construct(private readonly SshExecutor $ssh)
    {
    }

    public function request(ManagedServer $server, string $action, array $policy, array $accounts = []): array
    {
        $sudo = ($server->ssh_user ?: 'root') === 'root' ? '' : 'sudo -n ';
        $this->deploy($server, $sudo);
        $payload = json_encode(compact('action', 'policy', 'accounts'), JSON_THROW_ON_ERROR);
        $timeout = match ($action) {
            'prepare_certificate', 'issue_http_certificate' => 450,
            'install', 'install_link' => 360,
            'report_online' => 12,
            default => 40,
        };
        try {
            $answer = json_decode($this->ssh->run($server, $sudo.'python3 '.self::HELPER, $timeout, $payload), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            // The ingress response may contain private material. Never surface a partial response.
            if ($action === 'issue_http_certificate') throw new RuntimeException('دریافت گواهی TLS از سرور ورودی ناموفق بود.');
            throw $error;
        }
        if (($answer['ok'] ?? false) !== true || !is_array($answer['data'] ?? null)) {
            throw new RuntimeException(mb_substr((string) ($answer['error'] ?? 'پاسخ AnyConnect معتبر نیست.'), 0, 600));
        }
        return $answer['data'];
    }

    private function deploy(ManagedServer $server, string $sudo): void
    {
        $local = base_path('scripts/anyconnect/host.py');
        $hash = hash_file('sha256', $local);
        $key = 'anyconnect:helper:'.$server->id.':'.$hash.':'.hash('sha256', (string) $server->updated_at);
        if (Cache::get($key)) return;

        $tmp = trim($this->ssh->run($server, 'mktemp -d /tmp/speedbox-ac.XXXXXXXX', 15));
        if (!preg_match('~^/tmp/speedbox-ac\.[A-Za-z0-9]+$~D', $tmp)) {
            throw new RuntimeException('مسیر موقت معتبر نیست.');
        }
        try {
            $upload = $tmp.'/host.py';
            $this->ssh->upload($server, $local, $upload);
            $verify = 'test "$(sha256sum '.escapeshellarg($upload).' | cut -d " " -f 1)" = '.escapeshellarg($hash);
            $install = 'install -d -m 0755 '.dirname(self::HELPER).'; install -m 0755 '.escapeshellarg($upload).' '.self::HELPER.'.new; mv '.self::HELPER.'.new '.self::HELPER;
            $this->ssh->run($server, $sudo.'sh -ec '.escapeshellarg($verify.'; '.$install), 40);
            Cache::put($key, true, 3600);
        } finally {
            $this->ssh->run($server, 'rm -rf -- '.escapeshellarg($tmp), 15);
        }
    }
}
