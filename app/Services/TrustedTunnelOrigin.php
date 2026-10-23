<?php

namespace App\Services;

use App\Models\{ManagedServer, ProxyServer, ServerTunnel};
use App\Support\WatTunnelRules;
use Illuminate\Support\Facades\Schema;

/** Keep identity policy tied to the committed, paired tunnel configuration. */
final class TrustedTunnelOrigin
{
    public static function upgrade(ServerTunnel $tunnel, array $settings): array
    {
        $settings['version'] = WatTunnelRules::VERSION;
        $settings['source_identity'] = (bool) ManagedServer::find($tunnel->foreign_server_id)?->machine_id;
        return $settings;
    }

    public static function ports(array $settings): array
    {
        if (empty($settings['source_identity'])) return [];
        $ports = [];
        foreach ($settings['mappings'] ?? [] as $map) {
            foreach (range((int) $map['target_port'], (int) ($map['target_end'] ?? $map['target_port'])) as $port) $ports[$port] = $port;
        }
        sort($ports, SORT_NUMERIC);
        return array_values($ports);
    }

    public static function required(ProxyServer $node): bool
    {
        if (!$node->machine_id || !Schema::hasTable('speedbox_tunnels')) return false;
        $serverIds = ManagedServer::where('machine_id', $node->machine_id)->pluck('id');
        foreach (ServerTunnel::where('engine', 'wattunnel')->where('status', '!=', 'deleted')->whereIn('foreign_server_id', $serverIds)->get() as $tunnel) {
            $settings = $tunnel->settings;
            if (($settings['enabled'] ?? true) && in_array((int) $node->server_port, self::ports($settings), true)) return true;
        }
        return false;
    }
}
