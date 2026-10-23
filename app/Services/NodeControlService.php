<?php

namespace App\Services;

use App\Models\ProxyServer;
use App\Models\ServerRoute;
use App\Models\ServerMachine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NodeControlService
{
    public function machineNodes(int $machineId, ?bool $supportsWarp = null): Collection
    {
        return ProxyServer::query()
            ->where('machine_id', $machineId)
            ->where('enabled', true)
            ->when(!($supportsWarp ?? ServerMachine::find($machineId)?->supportsWarp()), fn ($q) => $q->where('warp_enabled', false))
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhere('parent_id', 0))
            ->orderBy('sort')
            ->get();
    }

    public function usersFor(ProxyServer $node): Collection
    {
        if ($node->transfer_enable && $node->u + $node->d >= $node->transfer_enable) return collect();
        $groupIds = array_map('intval', $node->group_ids ?: []);
        foreach (ProxyServer::where('parent_id', $node->id)->where('enabled', true)->get() as $child) {
            if (!$child->transfer_enable || $child->u + $child->d < $child->transfer_enable) $groupIds = array_merge($groupIds, array_map('intval', $child->group_ids ?: []));
        }
        $groupIds = array_unique($groupIds);
        if ($groupIds === []) {
            return collect();
        }

        return DB::table('v2_user')
            ->whereIn('group_id', $groupIds)
            ->where('banned', 0)
            ->whereRaw('(u + d) < transfer_enable')
            ->tap(fn ($query) => SubscriptionValidity::whereUnexpired($query))
            ->select(['id', 'uuid', 'speed_limit', 'device_limit'])
            ->get();
    }

    public function configFor(ProxyServer $node): array
    {
        $settings = $node->protocol_settings ?: [];
        $tlsSettings = data_get($settings, 'tls_settings');
        $tlsObject = data_get($settings, 'tls');
        if (is_array($tlsObject) && !empty($settings['alpn'])) $tlsObject['alpn'] = is_array($settings['alpn']) ? $settings['alpn'] : [$settings['alpn']];
        $cert = $node->cert_config ?: data_get($settings, 'cert_config');
        if (is_array($cert)) $cert['dns_env'] = (object) ($cert['dns_env'] ?? []);
        $base = [
            'protocol' => $node->type,
            'listen_ip' => data_get($settings, 'listen_address', '0.0.0.0'),
            'server_port' => (int) $node->server_port,
            'network' => data_get($settings, 'network'),
            'networkSettings' => (object) data_get($settings, 'network_settings', []),
            'trusted_tunnel_origin' => TrustedTunnelOrigin::required($node),
            'accept_proxy_protocol' => (bool) data_get($settings, 'network_settings.acceptProxyProtocol', false),
            'kernel_type' => $node->kernel ?: 'singbox',
            'warp_enabled' => (bool) $node->warp_enabled,
            'kernel_log_level' => data_get($settings, 'kernel_log_level', 'warn'),
            'base_config' => [
                'push_interval' => (int) RuntimeSetting::get('server_push_interval', 30),
                'pull_interval' => (int) RuntimeSetting::get('server_pull_interval', 60),
            ],
        ];

        $protocol = match ($node->type) {
            'shadowsocks' => [
                'cipher' => data_get($settings, 'cipher', 'aes-256-gcm'),
                'plugin' => data_get($settings, 'plugin'),
                'plugin_opts' => data_get($settings, 'plugin_opts'),
                'server_key' => data_get($settings, 'server_key') ?: $this->stableServerKey($node, data_get($settings, 'cipher')),
            ],
            'vmess' => [
                'tls' => (int) data_get($settings, 'tls', 0),
                'tls_settings' => $tlsSettings,
                'multiplex' => data_get($settings, 'multiplex'),
                'utls' => data_get($settings, 'utls'),
            ],
            'vless' => [
                'tls' => (int) data_get($settings, 'tls', 0),
                'flow' => data_get($settings, 'flow'),
                'decryption' => data_get($settings, 'encryption.enabled') ? data_get($settings, 'encryption.decryption') : null,
                'tls_settings' => (int) data_get($settings, 'tls', 0) === 2 ? data_get($settings, 'reality_settings') : $tlsSettings,
                'multiplex' => data_get($settings, 'multiplex'),
                'utls' => data_get($settings, 'utls'),
            ],
            'trojan' => [
                'host' => $node->host,
                'server_name' => data_get($tlsSettings, 'server_name'),
                'tls' => (int) data_get($settings, 'tls', 1),
                'tls_settings' => (int) data_get($settings, 'tls', 1) === 2 ? data_get($settings, 'reality_settings') : $tlsSettings,
                'multiplex' => data_get($settings, 'multiplex'),
                'utls' => data_get($settings, 'utls'),
            ],
            'hysteria' => [
                'version' => (int) data_get($settings, 'version', 2),
                'host' => $node->host,
                'server_name' => data_get($settings, 'tls.server_name'),
                'tls_settings' => $tlsObject,
                'up_mbps' => (int) data_get($settings, 'bandwidth.up', 0),
                'down_mbps' => (int) data_get($settings, 'bandwidth.down', 0),
                'obfs' => data_get($settings, 'obfs.open') ? ((int) data_get($settings, 'version', 2) === 1 ? data_get($settings, 'obfs.password') : data_get($settings, 'obfs.type', 'salamander')) : null,
                'obfs-password' => data_get($settings, 'obfs.password'),
                'hop_interval' => data_get($settings, 'hop_interval'),
                'ports' => data_get($settings, 'port_range') ?: (str_contains((string) $node->port, '-') ? $node->port : null),
            ],
            'tuic' => [
                'version' => (int) data_get($settings, 'version', 5),
                'server_name' => data_get($settings, 'tls.server_name'),
                'congestion_control' => data_get($settings, 'congestion_control', 'bbr'),
                'alpn' => data_get($settings, 'alpn'),
                'udp_relay_mode' => data_get($settings, 'udp_relay_mode'),
                'tls_settings' => $tlsObject,
            ],
            'anytls' => [
                'server_name' => data_get($settings, 'tls.server_name'),
                'tls_settings' => $tlsObject,
                'padding_scheme' => data_get($settings, 'padding_scheme'),
            ],
            'socks', 'http', 'naive' => [
                'tls' => (int) data_get($settings, 'tls', 0),
                'tls_settings' => $tlsSettings,
                'utls' => data_get($settings, 'utls'),
            ],
            'mieru' => [
                'transport' => data_get($settings, 'transport', 'TCP'),
                'traffic_pattern' => data_get($settings, 'traffic_pattern', ''),
            ],
            default => [],
        };

        $routes = ServerRoute::query()
            ->whereIn('id', array_map('intval', $node->route_ids ?: []))
            ->get(['id', 'match', 'action', 'action_value'])
            ->toArray();

        return array_filter([
            ...$base,
            ...$protocol,
            'routes' => $routes,
            'custom_outbounds' => $node->custom_outbounds,
            'custom_routes' => $node->custom_routes,
            'cert_config' => $cert,
        ], static fn ($value) => $value !== null);
    }

    public function report(ProxyServer $node, array $payload): void
    {
        $now = time();
        $ttl = max(300, (int) RuntimeSetting::get('server_push_interval', 30) * 3);
        Cache::put("speedbox:node:{$node->id}:last_seen", $now, $ttl);

        foreach (['status', 'metrics', 'online', 'alive'] as $field) {
            if (isset($payload[$field]) && is_array($payload[$field])) {
                Cache::put("speedbox:node:{$node->id}:{$field}", $payload[$field], $ttl);
            }
        }

        // Older agents omit the online field when empty. Their metrics still
        // explicitly report active_users=0; clear the old snapshot in that case.
        if (!array_key_exists('online', $payload)
            && is_numeric(data_get($payload, 'metrics.active_users'))
            && (float) data_get($payload, 'metrics.active_users') === 0.0) {
            Cache::put("speedbox:node:{$node->id}:online", [], $ttl);
        }

        $traffic = $payload['traffic'] ?? [];
        if (!is_array($traffic) || $traffic === []) {
            return;
        }

        $rate = $node->effectiveRate();
        DB::transaction(function () use ($traffic, $node, $rate, $now) {
            $serverUp = 0;
            $serverDown = 0;
            $recordAt = strtotime(date('Y-m-d', $now));

            foreach ($traffic as $userId => $values) {
                if (!is_array($values) || count($values) !== 2 || !is_numeric($values[0]) || !is_numeric($values[1])) {
                    continue;
                }
                $rawUp = max(0, (int) $values[0]);
                $rawDown = max(0, (int) $values[1]);
                $up = (int) round($rawUp * $rate);
                $down = (int) round($rawDown * $rate);
                $serverUp += $rawUp;
                $serverDown += $rawDown;

                DB::table('v2_user')->where('id', (int) $userId)->incrementEach(['u' => $up, 'd' => $down], ['t' => $now]);
                $this->upsertUserStat((int) $userId, $rate, $up, $down, $recordAt, $now);
            }

            if ($serverUp || $serverDown) {
                DB::table('v2_server')->where('id', $node->id)->incrementEach(['u' => $serverUp, 'd' => $serverDown]);
                $this->upsertServerStat($node, $serverUp, $serverDown, $recordAt, $now);
            }
        });
    }

    public function stateFor(ProxyServer $node, ?array $onlineCounts = null): array
    {
        $sourceId = $node->id;
        $nodeEnabled = (bool) $node->enabled;
        $node = $node->runtimeNode();
        $onlineCounts ??= app(AccountOnlineStatus::class)->nodeUserCounts([(int) $node->id]);
        $lastSeen = (int) Cache::get("speedbox:node:{$node->id}:last_seen", 0);
        $metrics = Cache::get("speedbox:node:{$node->id}:metrics", []);
        $connected = $lastSeen > time() - 300;
        $running = filter_var(data_get($metrics, 'kernel_status', false), FILTER_VALIDATE_BOOLEAN);
        $onlineUsers = $node->enabled && $connected && $running ? (int) ($onlineCounts[$node->id] ?? 0) : 0;
        $runtimeState = data_get($metrics, 'runtime_state');
        $connectionState = match (true) {
            !$node->machine_id => 'unassigned',
            !$node->enabled => 'disabled',
            !$lastSeen && (!$node->created_at || $node->created_at->timestamp > time() - 300) => 'awaiting_report',
            !$connected => 'offline',
            $running && $onlineUsers > 0 => 'active',
            $running => 'idle',
            $runtimeState === 'error' => 'kernel_error',
            $runtimeState === 'waiting_for_users' => 'waiting_for_users',
            $runtimeState === 'initializing' => 'initializing',
            array_key_exists('total_users', (array) $metrics) && (int) $metrics['total_users'] === 0 => 'waiting_for_users',
            default => 'kernel_stopped',
        };
        return [
            'online' => $node->enabled && $connected && $running,
            'connected' => $connected,
            'kernel_running' => $running,
            'last_seen_at' => $lastSeen ?: null,
            'status' => Cache::get("speedbox:node:{$node->id}:status"),
            'metrics' => Cache::get("speedbox:node:{$node->id}:metrics"),
            'online_users' => $onlineUsers,
            'connection_state' => $connectionState,
            'source_id' => (int) $node->id,
            'shared' => (int) $sourceId !== (int) $node->id,
            'node_enabled' => $nodeEnabled,
        ];
    }

    public function clearNodeCache(int $nodeId): void
    {
        foreach (['last_seen', 'status', 'metrics', 'online', 'alive'] as $field) {
            Cache::forget("speedbox:node:{$nodeId}:{$field}");
        }
    }

    private function stableServerKey(ProxyServer $node, ?string $cipher): string
    {
        $length = $cipher === '2022-blake3-aes-128-gcm' ? 16 : 32;
        $timestamp = $node->created_at?->timestamp ?? $node->id;
        return base64_encode(substr(md5((string) $timestamp), 0, $length));
    }

    private function upsertUserStat(int $userId, float $rate, int $up, int $down, int $recordAt, int $now): void
    {
        $where = ['user_id' => $userId, 'server_rate' => $rate, 'record_at' => $recordAt, 'record_type' => 'd'];
        // Let the unique key resolve the column's stored precision. A separate
        // WHERE using the original rate can miss a DECIMAL-rounded row.
        DB::table('v2_stat_user')->upsert(
            [[...$where, 'u' => $up, 'd' => $down, 'created_at' => $now, 'updated_at' => $now]],
            ['user_id', 'server_rate', 'record_at', 'record_type'],
            ['u' => DB::raw('u + '.$up), 'd' => DB::raw('d + '.$down), 'updated_at' => $now],
        );
    }

    private function upsertServerStat(ProxyServer $node, int $up, int $down, int $recordAt, int $now): void
    {
        $where = ['server_id' => $node->id, 'server_type' => $node->type, 'record_at' => $recordAt, 'record_type' => 'd'];
        DB::table('v2_stat_server')->insertOrIgnore([...$where, 'u' => 0, 'd' => 0, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('v2_stat_server')->where($where)->incrementEach(['u' => $up, 'd' => $down], ['updated_at' => $now]);
    }
}


