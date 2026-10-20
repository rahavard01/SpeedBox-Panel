<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ConfigureMachineWarp;
use App\Models\ManagedServer;
use App\Models\ServerDeployment;
use App\Models\ServerMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MachineWarpController extends Controller
{
    public function show(ServerMachine $machine): JsonResponse
    {
        $server = $this->managedServer($machine);
        $operation = ServerDeployment::where('managed_server_id', $server->id)
            ->whereIn('action', ['warp_prepare', 'warp_test', 'upgrade'])
            ->orderByRaw("CASE WHEN status IN ('queued', 'running') THEN 0 ELSE 1 END")->latest('id')->first();
        $reportedAt = (int) data_get($machine->load_status, 'updated_at', 0);
        $agentState = $reportedAt < time() - 300 ? 'waiting' : ($machine->supportsWarp() ? 'supported' : 'unsupported');
        return response()->json(['data' => [
            'supported' => $machine->supportsWarp(),
            'agent_state' => $agentState,
            'managed_server_id' => $server->id,
            'status' => $machine->warp_status,
            'operation' => $operation?->only(['id', 'status', 'action', 'progress', 'log', 'finished_at']),
        ]]);
    }

    public function store(Request $request, ServerMachine $machine): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['prepare', 'test'])],
            'account' => ['required_if:action,prepare', Rule::in(['keep', 'free', 'plus'])],
            'license' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9-]+$/D'],
        ], ['license.regex' => 'کد لایسنس باید فقط شامل حروف انگلیسی، عدد و خط تیره باشد.']);
        $server = $this->managedServer($machine);
        if ($data['action'] === 'prepare' && $data['account'] === 'plus'
            && empty($data['license']) && data_get($machine->warp_status, 'account') !== 'plus') {
            return response()->json(['message' => 'کد لایسنس WARP+ را وارد کنید.'], 422);
        }
        $deployment = DB::transaction(function () use ($machine, $server, $request, $data) {
            ManagedServer::whereKey($server->id)->lockForUpdate()->firstOrFail();
            ServerMachine::whereKey($machine->id)->lockForUpdate()->firstOrFail();
            \App\Services\TunnelManager::protectServer($server->id);
            abort_if(ServerDeployment::where('managed_server_id', $server->id)
                ->whereIn('status', ['queued', 'running'])->exists(), 409, 'عملیات دیگری روی این سرور در حال اجراست؛ پس از پایان آن دوباره تلاش کنید.');
            return ServerDeployment::create([
                'managed_server_id' => $server->id, 'requested_by' => $request->user()?->id,
                'action' => 'warp_'.$data['action'], 'kernel' => 'singbox',
                'status' => 'queued', 'progress' => 0, 'log' => 'عملیات WARP در صف قرار گرفت.',
            ]);
        });
        // The license exists only in the encrypted queue payload and on the host.
        ConfigureMachineWarp::dispatch($deployment->id, $data['account'] ?? null, $data['license'] ?? null)->afterCommit();
        return response()->json(['message' => 'عملیات WARP در صف قرار گرفت؛ نتیجه در همین بخش نمایش داده می‌شود.', 'data' => ['id' => $deployment->id]], 202);
    }

    private function managedServer(ServerMachine $machine): ManagedServer
    {
        $servers = ManagedServer::where('machine_id', $machine->id)->get();
        abort_unless($servers->count() === 1, 422, 'برای این عملیات، ماشین باید دقیقاً به یک سرور مدیریت‌شده با دسترسی SSH متصل باشد.');
        return $servers->first();
    }
}
