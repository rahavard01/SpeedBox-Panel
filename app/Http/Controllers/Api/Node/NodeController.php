<?php

namespace App\Http\Controllers\Api\Node;

use App\Http\Controllers\Controller;
use App\Models\ProxyServer;
use App\Services\NodeControlService;
use App\Services\RuntimeSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NodeController extends Controller
{
    public function __construct(private readonly NodeControlService $nodes)
    {
    }

    public function handshake(Request $request): JsonResponse
    {
        $websocket = ['enabled' => false];
        if ((bool) RuntimeSetting::get('server_ws_enable', false)) {
            $url = trim((string) RuntimeSetting::get('server_ws_url', ''));
            if ($url !== '') {
                $websocket = ['enabled' => true, 'ws_url' => rtrim($url, '/')];
            }
        }

        return response()->json([
            'websocket' => $websocket,
            'connection_limits' => ['enabled' => true, 'renew_batch' => [
                'version' => 1, 'max_items' => (bool) RuntimeSetting::get('connection_lease_batch_enable', true) ? 64 : 0,
            ]],
            'settings' => [
                'push_interval' => (int) RuntimeSetting::get('server_push_interval', 30),
                'pull_interval' => (int) RuntimeSetting::get('server_pull_interval', 60),
            ],
        ]);
    }

    public function config(Request $request): JsonResponse
    {
        /** @var ProxyServer|null $node */
        $node = $request->attributes->get('speedbox_node');
        abort_if(!$node, 422, 'node_id is required.');
        abort_if($node->warp_enabled && !in_array('warp-egress-v1', array_map('trim', explode(',', $request->header('X-Speedbox-Capabilities', ''))), true), 426, 'This node requires an agent supporting WARP egress.');
        $payload = $this->nodes->configFor($node);
        return $this->etag($request, $payload, "node-config-{$node->id}");
    }

    public function users(Request $request): JsonResponse
    {
        /** @var ProxyServer|null $node */
        $node = $request->attributes->get('speedbox_node');
        abort_if(!$node, 422, 'node_id is required.');
        \Illuminate\Support\Facades\Cache::put('speedbox:node:'.$node->id.':capabilities',
            array_map('trim', explode(',', $request->header('X-Speedbox-Capabilities', ''))), 600);
        $payload = ['users' => $this->nodes->usersFor($node)->values()];
        return $this->etag($request, $payload, "node-users-{$node->id}");
    }

    public function report(Request $request): JsonResponse
    {
        /** @var ProxyServer|null $node */
        $node = $request->attributes->get('speedbox_node');
        abort_if(!$node, 422, 'node_id is required.');
        $request->validate(['traffic_v2' => ['sometimes', 'nullable', 'array', 'max:50000'],
            'traffic_v2.*.id' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/D'],
            'traffic_v2.*.user_id' => ['required', 'integer', 'min:1'],
            'traffic_v2.*.generation' => ['required', 'integer', 'min:0'],
            'traffic_v2.*.u' => ['required', 'integer', 'min:0'], 'traffic_v2.*.d' => ['required', 'integer', 'min:0']]);
        $this->nodes->report($node, $request->only(['traffic', 'traffic_v2', 'alive', 'online', 'status', 'metrics']));
        return response()->json(['data' => true]);
    }

    public function connectionLease(Request $request, \App\Services\ConnectionAdmission $admission): JsonResponse
    {
        $node = $request->attributes->get('speedbox_node');
        abort_if(!$node, 422, 'node_id is required.');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'source_ip' => ['required', 'ip'],
            'lease_id' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'operation' => ['required', 'in:acquire,renew,release'],
        ]);
        return response()->json($admission->handle($node, $data));
    }

    public function renewConnectionLeases(Request $request, \App\Services\ConnectionAdmission $admission): JsonResponse
    {
        $node = $request->attributes->get('speedbox_node');
        abort_if(!$node, 422, 'node_id is required.');
        abort_unless((bool) RuntimeSetting::get('connection_lease_batch_enable', true), 404);
        $data = $request->validate([
            'leases' => ['required', 'array', 'min:1', 'max:64'],
            'leases.*' => ['required', 'array:user_id,source_ip,lease_id'],
            'leases.*.user_id' => ['required', 'integer', 'min:1'],
            'leases.*.source_ip' => ['required', 'ip'],
            'leases.*.lease_id' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D'],
        ]);
        $leases = $data['leases'];
        abort_unless(array_is_list($leases), 422, 'leases must be a list.');
        // Validate every identity before any write. Each item still takes the
        // existing account lock in its own transaction on the authenticated node.
        $seen = [];
        foreach ($leases as $lease) {
            $ip = inet_ntop(inet_pton($lease['source_ip']));
            if (str_starts_with($ip, '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = substr($ip, 7);
            $key = (int) $lease['user_id'].'/'.$ip;
            abort_if(isset($seen[$key]), 422, 'Duplicate renewal identity.');
            $seen[$key] = true;
        }
        $results = [];
        foreach ($leases as $lease) {
            $grant = $admission->handle($node, $lease + ['operation' => 'renew']);
            $results[] = [
                'user_id' => (int) $lease['user_id'], 'source_ip' => $lease['source_ip'], 'lease_id' => $lease['lease_id'],
                'allowed' => (bool) $grant['allowed'], 'ttl' => (int) ($grant['ttl'] ?? 0),
            ];
        }
        return response()->json(['results' => $results]);
    }

    private function etag(Request $request, array $payload, string $prefix): JsonResponse
    {
        $etag = '"'.hash('sha256', $prefix.':'.json_encode($payload)).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->header('ETag', $etag);
        }
        return response()->json($payload)->header('ETag', $etag);
    }
}

