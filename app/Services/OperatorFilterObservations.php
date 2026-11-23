<?php

namespace App\Services;

use App\Models\ManagedServer;
use App\Models\ProxyServer;
use App\Models\ServerTunnel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class OperatorFilterObservations
{
    private const REFRESH_SECONDS = 180;
    private const VALID_SECONDS = 480;

    public function __construct(
        private readonly OperatorFilterTopology $topology,
        private readonly SshExecutor $ssh,
    ) {}

    private function topologyVersion(Collection $servers, Collection $tunnels): string
    {
        return hash('sha256', json_encode([
            $servers->map(fn ($server) => [$server->id, $server->machine_id, $server->host,
                $server->ssh_user, $server->ssh_port, $server->status === 'removing'])->values(),
            $tunnels->map(fn ($tunnel) => [$tunnel->id, $tunnel->iran_server_id, $tunnel->foreign_server_id,
                $tunnel->engine, $tunnel->kind, $tunnel->transport, $tunnel->orientation,
                OperatorFilterTopology::isRelevantTunnelStatus($tunnel->status), $tunnel->settings])->values(),
        ], JSON_THROW_ON_ERROR));
    }

    private function servers(): Collection
    {
        return ManagedServer::query()->orderBy('id')->get(['id', 'machine_id', 'host', 'ssh_user', 'ssh_port', 'status'])->keyBy('id');
    }

    private function tunnels(): Collection
    {
        return ServerTunnel::where('status', '!=', 'deleted')->orderBy('id')->get();
    }

    private function signature(ProxyServer $node, string $version): string
    {
        $root = $node->runtimeNode();
        return hash('sha256', json_encode([
            $version, $node->host, $node->port, $root->id, $root->machine_id, $root->server_port, $root->type,
            data_get($root->protocol_settings, 'network'), data_get($root->protocol_settings, 'transport'),
        ], JSON_THROW_ON_ERROR));
    }

    private static function key(int $id): string
    {
        return 'speedbox:operator-observation:v1:'.$id;
    }

    public static function forget(iterable $ids): void
    {
        foreach ($ids as $id) Cache::forget(self::key((int) $id));
    }

    public function forNodes(Collection $nodes): array
    {
        if ($nodes->isEmpty()) return [];
        $version = $this->topologyVersion($this->servers(), $this->tunnels());
        $snapshots = Cache::many($nodes->map(fn ($node) => self::key($node->id))->all());
        $result = [];
        foreach ($nodes as $node) {
            $snapshot = $snapshots[self::key($node->id)] ?? null;
            $current = ($snapshot['signature'] ?? null) === $this->signature($node, $version);
            $result[$node->id] = $current ? $snapshot['status'] : ['manual' => 'unknown', 'blocked' => []];
        }
        return $result;
    }

    public function refresh(): void
    {
        $nodes = ProxyServer::with('parent')->orderBy('id')->get();
        if ($nodes->isEmpty()) return;
        $servers = $this->servers();
        $tunnels = $this->tunnels();
        $version = $this->topologyVersion($servers, $tunnels);
        $snapshots = Cache::many($nodes->map(fn ($node) => self::key($node->id))->all());
        $due = [];
        $oldest = [];
        $deadline = microtime(true) + 30;
        foreach ($nodes as $node) {
            $signature = $this->signature($node, $version);
            $previous = $snapshots[self::key($node->id)] ?? [];
            $checkedAt = ($previous['signature'] ?? null) === $signature ? $previous['checked_at'] : 0;
            if ($checkedAt >= time() - self::REFRESH_SECONDS) continue;
            $entry = $this->topology->resolve($node, $servers, $tunnels);
            if (!$entry) {
                $this->record($node->id, $signature, ['manual' => 'unsupported', 'blocked' => []]);
                continue;
            }
            $serverId = $entry['server'];
            $due[$serverId][$node->id] = ['entry' => $entry, 'signature' => $signature];
            $oldest[$serverId] = min($oldest[$serverId] ?? $checkedAt, $checkedAt);
        }

        asort($oldest);
        $script = file_get_contents(base_path('scripts/operator-firewall/host.py'));
        foreach (array_keys($oldest) as $serverId) {
            if (microtime(true) >= $deadline) break;
            $batch = $due[$serverId];
            try {
                $server = ManagedServer::findOrFail($serverId);
                $sudo = $server->ssh_user === 'root' ? '' : 'sudo -n ';
                // Preserve node IDs in the host response.
                $request = json_encode(['action' => 'observe', 'entries' => (object) array_map(
                    fn ($item) => $item['entry'], $batch
                )], JSON_THROW_ON_ERROR);
                $command = $sudo.'python3 -c '.escapeshellarg($script);
                $reply = $this->ssh->withinBudget(12, fn () => $this->ssh->run($server, $command, 10, $request));
                $answer = json_decode($reply, true, 16, JSON_THROW_ON_ERROR);
                if (($answer['ok'] ?? false) !== true || !is_array($answer['nodes'] ?? null)) {
                    throw new RuntimeException('Could not observe firewall rules on the ingress host.');
                }
                $observed = $answer['nodes'];
            } catch (Throwable $error) {
                report($error);
                $observed = [];
            }

            foreach ($batch as $nodeId => $item) {
                $status = $observed[$nodeId] ?? ['manual' => 'unknown', 'blocked' => []];
                $this->record($nodeId, $item['signature'], $status);
            }
        }
    }

    private function record(int $id, string $signature, array $status): void
    {
        Cache::put(self::key($id), ['signature' => $signature, 'checked_at' => time(), 'status' => $status], self::VALID_SECONDS);
    }
}
