<?php

namespace Illuminate\Support {
    final class Collection extends \ArrayObject
    {
        public function where(string $key, mixed $value): self
        {
            return new self(array_filter((array) $this, fn ($row) => $row->$key == $value));
        }

        public function values(): self
        {
            return new self(array_values((array) $this));
        }

        public function get(int $key): mixed
        {
            return $this[$key] ?? null;
        }
    }
}

namespace App\Models {
    class ManagedServer
    {
        public string $ssh_user = 'root';
        public string $status = 'installed';
        public int $ssh_port = 22;

        public function __construct(public int $id, public int $machine_id, public string $host) {}
    }

    class ServerTunnel
    {
        public string $status = 'connected';
        public string $kind = 'reverse';
        public string $transport = 'tcp';
        public string $orientation = 'normal';

        public function __construct(
            public int $iran_server_id, public int $foreign_server_id, public string $engine, public array $settings,
        ) {}
    }

    class ProxyServer
    {
        public function __construct(
            public int $machine_id, public string $host, public string $port, public int $server_port,
            public string $type = 'vless', public array $protocol_settings = [], public ?self $parent = null,
        ) {}

        public function runtimeNode(): self
        {
            return $this->parent ?? $this;
        }
    }
}

namespace {
    use App\Models\{ManagedServer, ProxyServer, ServerTunnel};
    use App\Services\OperatorFilterTopology;
    use Illuminate\Support\Collection;

    function data_get(array $data, string $key, mixed $default = null): mixed
    {
        return $data[$key] ?? $default;
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }

    require dirname(__DIR__, 2) . '/app/Services/OperatorFilterTopology.php';

    $topology = new class extends OperatorFilterTopology {
        protected function addresses(string $host): array
        {
            return [
                'iran.example' => ['192.0.2.1'], 'alias.example' => ['192.0.2.1'],
                'exit.example' => ['198.51.100.2'], 'cdn.example' => ['203.0.113.3'],
            ][$host] ?? [];
        }
    };
    $servers = new Collection([
        1 => new ManagedServer(1, 20, 'iran.example'),
        2 => new ManagedServer(2, 40, 'exit.example'),
    ]);
    $tunnel = new ServerTunnel(1, 2, 'gre', [
        'enabled' => true, 'public_host' => 'iran.example', 'protocol' => 'both',
        'mappings' => [['public_port' => 10040, 'public_end' => 10042, 'target_port' => 2044]],
    ]);
    $tunnels = new Collection([$tunnel]);

    $direct = new ProxyServer(40, 'exit.example', '2044', 2044);
    $entry = $topology->resolve($direct, $servers, $tunnels);
    check($entry['server'] === 2 && $entry['port'] === 2044 && $entry['protocols'] === ['tcp'], 'direct node');

    $node = new ProxyServer(40, 'iran.example', '10041', 2045, 'vless', ['network' => 'kcp']);
    $entry = $topology->resolve($node, $servers, $tunnels);
    check($entry['server'] === 1 && $entry['port'] === 10041 && $entry['protocols'] === ['udp'], 'forwarded public port and KCP');
    $child = new ProxyServer(40, 'alias.example', '10041', 1, parent: $node);
    check($topology->sharesEntry($entry, $child), 'DNS aliases of shared entry');
    $child->port = '10042';
    check(!$topology->sharesEntry($entry, $child), 'isolated public ports');

    $alias = new ProxyServer(40, 'iran.example', '10041', 1, parent: $node);
    check($topology->resolve($alias, $servers, $tunnels) === $entry, 'child reuses the root listener');
    $tunnel->settings = array_replace($tunnel->settings, ['protocol' => 'tcp']);
    check($topology->resolve($node, $servers, $tunnels) === null, 'no supported tunnel transport');
    $outside = new ProxyServer(40, 'cdn.example', '2044', 2044);
    check($topology->resolve($outside, $servers, $tunnels) === null, 'unmanaged/CDN entry is not targeted');
    $servers[2]->ssh_port = 2044;
    check($topology->resolve($direct, $servers, $tunnels) === null, 'SSH port is protected');

    echo "Operator filter topology checks passed.\n";
}
