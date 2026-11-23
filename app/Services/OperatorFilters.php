<?php

namespace App\Services;

use App\Jobs\SyncOperatorFilters;
use App\Models\ManagedServer;
use App\Models\ProxyServer;
use App\Models\ServerTunnel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class OperatorFilters
{
    private const ASNS = ['mci' => 'AS197207', 'irancell' => 'AS44244'];

    public function __construct(
        private readonly OperatorFilterTopology $topology,
        private readonly SshExecutor $ssh,
    ) {}

    public static function scheduleAfterCommit(): void
    {
        if (DB::transactionLevel()) {
            DB::afterCommit(fn () => self::schedule());
        } else {
            self::schedule();
        }
    }

    public static function schedule(): void
    {
        if (Schema::hasColumn('v2_server', 'operator_filters')
            && (ProxyServer::whereNotNull('operator_filters')->exists()
                || ManagedServer::whereNotNull('operator_firewall')->exists()
                || ProxyServer::where('operator_filter_status', 'pending')->exists())) {
            SyncOperatorFilters::dispatch();
        }
    }

    public function sync(): void
    {
        $nodes = ProxyServer::with('parent')->orderBy('id')->get();
        $servers = ManagedServer::orderBy('id')->get()->keyBy('id');
        $tunnels = ServerTunnel::where('status', '!=', 'deleted')->get();
        $active = $nodes->filter(fn ($node) => $node->enabled && $node->runtimeNode()->enabled);
        $byPort = $active->groupBy(fn ($node) => (int) $node->port);

        $rules = [];
        $status = [];
        foreach ($nodes as $node) {
            if (!$node->operator_filters) {
                if ($node->operator_filter_status !== null && $node->operator_filter_status !== 'manual') $status[$node->id] = null;
                continue;
            }
            if (!$active->contains($node)) {
                $status[$node->id] = 'inactive';
                continue;
            }
            $endpoint = $this->topology->resolve($node, $servers, $tunnels);
            if (!$endpoint) {
                $status[$node->id] = 'unsupported';
                continue;
            }
            foreach ($byPort[$endpoint['port']] ?? [] as $other) {
                if ($other->id !== $node->id && $this->topology->sharesEntry($endpoint, $other)) {
                    $status[$node->id] = 'shared';
                    continue 2;
                }
            }
            $rules[$endpoint['server']][$node->id] = $endpoint + ['operators' => $node->operator_filters];
            $status[$node->id] = 'applied';
        }

        $script = file_get_contents(base_path('scripts/operator-firewall/host.py'));
        // If a run hits its job budget, visit least recently attempted hosts first on the next run.
        $ordered = $servers->sortBy(fn ($server) => $server->operator_firewall['attempted_at'] ?? 0);
        $deadline = microtime(true) + 23;
        foreach ($ordered as $server) {
            $desired = $rules[$server->id] ?? [];
            $previous = $server->operator_firewall ?? [];
            if (!$previous && !$desired) continue;

            $affected = array_unique([...array_keys($desired), ...($previous['nodes'] ?? [])]);
            if (microtime(true) >= $deadline) {
                foreach ($affected as $id) $status[$id] = 'pending';
                continue;
            }
            if ($server->status === 'removing') {
                foreach ($affected as $id) $status[$id] = 'failed';
                continue;
            }

            $now = time();
            $attempt = ['nodes' => $affected, 'attempted_at' => $now];
            if (($previous['status'] ?? null) === 'failed' && $now - ($previous['attempted_at'] ?? 0) < 60
                && ($previous['desired'] ?? null) == $desired) {
                foreach ($affected as $id) $status[$id] = $previous['error'] ?? 'failed';
                continue;
            }

            try {
                $inspect = $desired + array_intersect_key($previous['entries'] ?? [], $nodes->keyBy('id')->all());
                $prefixes = $this->prefixes($desired);
                $hash = hash('sha256', json_encode([$script, $desired, $prefixes], JSON_THROW_ON_ERROR));
                if (($previous['hash'] ?? null) === $hash && $now - ($previous['attempted_at'] ?? 0) < 900) {
                    foreach ($previous['manual'] ?? [] as $id) if (($status[$id] ?? null) === 'applied') $status[$id] = 'manual';
                    continue;
                }
                // Record the attempt before SSH so a lost response can be reconciled later.
                $attempt += ['desired' => $desired, 'entries' => $previous['entries'] ?? [], 'status' => 'failed'];
                $this->recordAttempt($server, $attempt);
                $manual = $this->apply($server, $script, $desired, $prefixes, $inspect);
                foreach ($manual as $id) {
                    if ((isset($desired[$id]) && ($status[$id] ?? null) === 'applied')
                        || (!isset($desired[$id]) && array_key_exists($id, $status) && $status[$id] === null)) {
                        $status[$id] = 'manual';
                    }
                }
                OperatorFilterObservations::forget($affected);
                ManagedServer::whereKey($server->id)->update(['operator_firewall' => $desired
                    ? json_encode(['nodes' => array_keys($desired), 'attempted_at' => $now, 'hash' => $hash, 'manual' => $manual, 'entries' => $desired], JSON_THROW_ON_ERROR) : null]);
            } catch (Throwable $error) {
                report($error);
                $reason = $error->getMessage() === 'operator-firewall-tools-missing' ? 'missing_tools' : 'failed';
                $this->recordFailure($server, $attempt + ['desired' => $desired,
                    'entries' => $previous['entries'] ?? [], 'status' => 'failed', 'error' => $reason]);
                foreach ($affected as $id) $status[$id] = $reason;
            }
        }

        foreach ($nodes as $node) {
            if (!array_key_exists($node->id, $status) || $node->operator_filter_status === $status[$node->id]) continue;
            // Ignore stale results if an admin changed the target or policy during SSH.
            DB::transaction(function () use ($node, $status) {
                $current = ProxyServer::whereKey($node->id)->lockForUpdate()->first();
                if (!$current) return;
                foreach (['operator_filters', 'host', 'port', 'server_port', 'machine_id', 'parent_id', 'protocol_settings', 'enabled'] as $field) {
                    if ($current->getRawOriginal($field) !== $node->getRawOriginal($field)) return;
                }
                ProxyServer::whereKey($node->id)->update(['operator_filter_status' => $status[$node->id]]);
            });
        }
    }

    private function recordAttempt(ManagedServer $previous, array $attempt): void
    {
        DB::transaction(function () use ($previous, $attempt) {
            $server = ManagedServer::whereKey($previous->id)->lockForUpdate()->first();
            if (!$server || $server->status === 'removing' || $server->host !== $previous->host
                || (int) $server->ssh_port !== (int) $previous->ssh_port) {
                throw new RuntimeException('Operator firewall target changed; retrying during reconciliation.');
            }
            $server->operator_firewall = $attempt;
            $server->save();
        });
    }

    private function recordFailure(ManagedServer $server, array $attempt): void
    {
        try {
            $this->recordAttempt($server, $attempt);
        } catch (RuntimeException $error) {
            report($error);
        }
    }

    private function prefixes(array $rules): array
    {
        $ops = array_unique(array_merge([], ...array_column($rules, 'operators')));
        sort($ops, SORT_STRING);
        $result = [];
        foreach ($ops as $operator) {
            $key = 'speedbox:operator-prefixes:' . $operator;
            $cached = Cache::get($key);
            if (is_array($cached) && time() - $cached['at'] < 86400) {
                $result[$operator] = $cached['prefixes'];
                continue;
            }
            try {
                $items = Http::connectTimeout(4)->timeout(8)->get('https://stat.ripe.net/data/announced-prefixes/data.json', [
                    'resource' => self::ASNS[$operator], 'min_peers_seeing' => 1,
                ])->throw()->json('data.prefixes');
                $valid = [];
                $ipv4 = false;
                if (!is_array($items) || count($items) > 10000) throw new RuntimeException('Invalid operator prefix response.');
                foreach ($items as $item) {
                    $prefix = $item['prefix'] ?? '';
                    [$ip, $bits] = array_pad(explode('/', $prefix, 2), 2, '');
                    $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                        || !ctype_digit($bits) || (int) $bits < ($isV4 ? 8 : 16) || (int) $bits > ($isV4 ? 32 : 128)) {
                        throw new RuntimeException('Invalid published operator prefix.');
                    }
                    $ipv4 = $ipv4 || $isV4;
                    $valid[$prefix] = true;
                }
                if (!$ipv4) throw new RuntimeException('No IPv4 prefixes for operator.');
                $result[$operator] = array_keys($valid);
                sort($result[$operator], SORT_STRING);
                Cache::put($key, ['at' => time(), 'prefixes' => $result[$operator]], now()->addDays(7));
            } catch (Throwable $error) {
                if (empty($cached['prefixes'])) throw $error;
                $result[$operator] = $cached['prefixes'];
                // Keep the last good list while avoiding repeated requests during an outage.
                Cache::put($key, ['at' => time() - 82800, 'prefixes' => $cached['prefixes']], now()->addDays(7));
            }
        }
        return $result;
    }

    private function apply(ManagedServer $server, string $script, array $rules, array $prefixes, array $inspect): array
    {
        $sudo = $server->ssh_user === 'root' ? '' : 'sudo -n ';
        $command = $sudo . 'python3 -c ' . escapeshellarg($script);
        $response = $this->ssh->withinBudget(38, fn () => $this->ssh->run($server, $command, 35, json_encode([
            'rules' => (object) $rules, 'prefixes' => (object) $prefixes, 'inspect' => (object) $inspect, 'ssh_port' => (int) $server->ssh_port,
        ], JSON_THROW_ON_ERROR)));
        $data = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        if (($data['error'] ?? null) === 'missing_tools') {
            throw new RuntimeException('operator-firewall-tools-missing');
        }
        if (($data['ok'] ?? false) !== true || !is_array($data['manual'] ?? null)) {
            throw new RuntimeException('Unexpected operator firewall response.');
        }
        return $data['manual'];
    }
}
