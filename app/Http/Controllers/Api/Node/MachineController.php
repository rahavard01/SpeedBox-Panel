<?php

namespace App\Http\Controllers\Api\Node;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeControlService;
use App\Services\RuntimeSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    public function __construct(private readonly NodeControlService $nodes)
    {
    }

    public function nodes(Request $request): JsonResponse
    {
        $machine = $this->authenticate($request);
        $supportsWarp = in_array('warp-egress-v1', array_map('trim', explode(',', $request->header('X-Speedbox-Capabilities', ''))), true);
        return response()->json([
            'nodes' => $this->nodes->machineNodes($machine->id, $supportsWarp)->map(fn ($node) => [
                'id' => $node->id,
                'type' => $node->type,
                'name' => $node->name,
                'kernel' => $node->kernel ?: 'singbox',
            ])->values(),
            'base_config' => [
                'push_interval' => (int) RuntimeSetting::get('server_push_interval', 30),
                'pull_interval' => (int) RuntimeSetting::get('server_pull_interval', 60),
            ],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cpu' => ['required', 'numeric', 'between:0,100'],
            'capabilities' => ['sometimes', 'array', 'max:32'],
            'capabilities.*' => ['string', 'max:64'],
            'mem.total' => ['required', 'integer', 'min:0'],
            'mem.used' => ['required', 'integer', 'min:0'],
            'swap.total' => ['nullable', 'integer', 'min:0'],
            'swap.used' => ['nullable', 'integer', 'min:0'],
            'disk.total' => ['nullable', 'integer', 'min:0'],
            'disk.used' => ['nullable', 'integer', 'min:0'],
            'net.in_speed' => ['nullable', 'numeric', 'min:0'],
            'net.out_speed' => ['nullable', 'numeric', 'min:0'],
        ]);
        $machine = $this->authenticate($request);
        // Existing WARP-capable releases advertise the capability in the header
        // but omit it from this report's body. Trust that header only after
        // machine authentication; an explicit body list remains authoritative.
        if (!array_key_exists('capabilities', $validated)) {
            $advertised = array_map('trim', explode(',', $request->header('X-Speedbox-Capabilities', '')));
            $validated['capabilities'] = in_array('warp-egress-v1', $advertised, true) ? ['warp-egress-v1'] : [];
        }
        $now = time();
        $load = [...$validated, 'updated_at' => $now];
        $machine->forceFill(['load_status' => $load, 'last_seen_at' => $now])->save();

        ServerMachineLoadHistory::create([
            'machine_id' => $machine->id,
            'cpu' => data_get($validated, 'cpu', 0),
            'mem_total' => data_get($validated, 'mem.total', 0),
            'mem_used' => data_get($validated, 'mem.used', 0),
            'disk_total' => data_get($validated, 'disk.total', 0),
            'disk_used' => data_get($validated, 'disk.used', 0),
            'net_in_speed' => data_get($validated, 'net.in_speed'),
            'net_out_speed' => data_get($validated, 'net.out_speed'),
            'recorded_at' => $now,
        ]);
        ServerMachineLoadHistory::where('recorded_at', '<', now()->subDay()->timestamp)->delete();

        return response()->json(['data' => true]);
    }

    private function authenticate(Request $request): ServerMachine
    {
        $request->validate(['machine_id' => ['required', 'integer'], 'token' => ['required', 'string']]);
        $machine = ServerMachine::query()->whereKey($request->integer('machine_id'))->where('is_active', true)->first();
        abort_unless($machine && hash_equals((string) $machine->token, (string) $request->input('token')), 401, 'Machine authentication failed.');
        $machine->forceFill(['last_seen_at' => time()])->saveQuietly();
        return $machine;
    }
}
