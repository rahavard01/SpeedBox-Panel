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
            'connection_limits' => ['enabled' => true],
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

    private function etag(Request $request, array $payload, string $prefix): JsonResponse
    {
        $etag = '"'.hash('sha256', $prefix.':'.json_encode($payload)).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->header('ETag', $etag);
        }
        return response()->json($payload)->header('ETag', $etag);
    }
}
