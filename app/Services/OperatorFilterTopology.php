<?php

namespace App\Services;

use App\Models\ManagedServer;
use App\Models\ProxyServer;
use App\Models\ServerTunnel;
use Illuminate\Support\Collection;

class OperatorFilterTopology
{
    private array $addresses = [];

    public function resolve(ProxyServer $node, Collection $servers, Collection $tunnels): ?array
    {
        $root = $node->runtimeNode();
        $port = filter_var($node->port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (!$port || !$root->machine_id) return null;

        $host = $this->host($node->host);
        $protocols = $this->protocols($root);
        $origins = $servers->where('machine_id', $root->machine_id)->values();
        if ($origins->count() !== 1 || !$origins[0]->ssh_user || $origins[0]->status === 'removing') return null;
        $origin = $origins[0];
        $matches = [];
        $knownTunnel = false;

        foreach ($tunnels as $tunnel) {
            $settings = $tunnel->settings ?? [];
            if ($host === '' || $host !== $this->host($settings['public_host'] ?? '')) continue;

            foreach ($settings['mappings'] ?? [] as $map) {
                $first = (int) $map['public_port'];
                if ($port < $first || $port > (int) ($map['public_end'] ?? $first)) continue;
                $knownTunnel = true;
                if ((int) $tunnel->foreign_server_id !== (int) $origin->id
                    || !($settings['enabled'] ?? true)
                    || !self::isRelevantTunnelStatus($tunnel->status)) continue;

                $targets = $map['destinations'] ?? [];
                if (count($targets) > 1) continue;
                $target = $targets[0] ?? null;
                if ($target && !$this->sameTarget($target['address'], $origin, $settings)) continue;
                if ((int) ($target['port'] ?? $map['target_port']) + $port - $first !== (int) $root->server_port) continue;

                $server = $servers->get((int) $tunnel->iran_server_id);
                $carried = $this->tunnelProtocols($tunnel, $settings);
                $allowed = array_values(array_intersect($protocols, $carried));
                if ($server && $allowed) $matches[] = [$server, $allowed, $tunnel->engine];
            }
        }

        if ($knownTunnel) {
            if (count($matches) !== 1) return null;
            [$server, $protocols, $mode] = $matches[0];
        } else {
            if ($port !== (int) $root->server_port) return null;
            $server = $origin;
            $mode = 'direct';
        }

        if (!$server->ssh_user || $server->status === 'removing'
            || $port === 22 || $port === (int) $server->ssh_port) return null;

        $addresses = $this->addresses($host);
        $managedAddresses = $this->addresses($this->host($server->host));
        if (!$addresses || array_diff($addresses, $managedAddresses)) return null;

        return ['server' => (int) $server->id, 'addresses' => $addresses, 'port' => $port,
            'protocols' => $protocols, 'mode' => $mode];
    }

    public static function isRelevantTunnelStatus(?string $status): bool
    {
        return in_array($status, ['connected', 'checking', 'degraded', 'active', 'running', 'ready', 'connected_unverified', 'offline', 'needs_review'], true);
    }

    public function sharesEntry(array $endpoint, ProxyServer $other): bool
    {
        return (int) $other->port === $endpoint['port']
            && array_intersect($endpoint['protocols'], $this->protocols($other->runtimeNode()))
            && array_intersect($endpoint['addresses'], $this->addresses($this->host($other->host)));
    }

    private function protocols(ProxyServer $root): array
    {
        return match ($root->type) {
            'hysteria', 'tuic' => ['udp'],
            'vless', 'vmess', 'trojan' => data_get($root->protocol_settings, 'network') === 'kcp' ? ['udp'] : ['tcp'],
            'shadowsocks', 'socks' => ['tcp', 'udp'],
            'mieru' => strtoupper((string) data_get($root->protocol_settings, 'transport', 'TCP')) === 'UDP' ? ['udp'] : ['tcp'],
            default => ['tcp'],
        };
    }

    private function tunnelProtocols(ServerTunnel $tunnel, array $settings): array
    {
        if ($tunnel->engine === 'backpack') {
            if ($tunnel->kind === 'reverse' && $tunnel->transport === 'udp' && $tunnel->orientation !== 'swapped') {
                return ['udp'];
            }
            return !empty($settings['accept_udp']) ? ['tcp', 'udp'] : ['tcp'];
        }

        return match ($settings['protocol'] ?? null) {
            'tcp' => ['tcp'], 'udp' => ['udp'], 'both' => ['tcp', 'udp'], default => [],
        };
    }

    private function sameTarget(string $address, ManagedServer $server, array $settings): bool
    {
        $host = $this->host($address);
        return in_array($host, ['127.0.0.1', '::1', 'localhost', $this->host($server->host),
            explode('/', $settings['inner_foreign'] ?? '')[0]], true);
    }

    private function host(?string $value): string
    {
        return rtrim(strtolower(trim((string) $value, " []\t\n\r")), '.');
    }

    protected function addresses(string $host): array
    {
        if (isset($this->addresses[$host])) return $this->addresses[$host];
        if (filter_var($host, FILTER_VALIDATE_IP)) return $this->addresses[$host] = [inet_ntop(inet_pton($host))];
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) return [];

        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if (filter_var($ip, FILTER_VALIDATE_IP)) $ips[] = inet_ntop(inet_pton($ip));
        }
        sort($ips, SORT_STRING);
        return $this->addresses[$host] = array_values(array_unique($ips));
    }
}
