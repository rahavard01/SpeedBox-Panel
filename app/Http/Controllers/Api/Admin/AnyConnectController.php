<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncAnyConnect;
use App\Models\{AnyConnectInstance, ManagedServer, VpnOperation};
use App\Services\{AnyConnectCertificate, AnyConnectNetwork, AnyConnectService, WireGuardService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AnyConnectController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => VpnOperation::attachLatest('anyconnect', AnyConnectInstance::withCount('accounts')->withSum('accounts as online_sessions', 'online')->orderBy('name')->get())])->header('Cache-Control', 'no-store');
    }

    public function operations(Request $request, int $instance): JsonResponse
    {
        return response()->json(['data' => VpnOperation::history('anyconnect', $instance, AnyConnectInstance::find($instance), $request->boolean('latest'))])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, AnyConnectNetwork $network): JsonResponse
    {
        $data = $this->validateSettings($request);
        $data['certificate_config'] = AnyConnectCertificate::settings($request, $data['endpoint_host'], endpointPort: (int) $data['endpoint_port']);
        $instance = DB::transaction(function () use ($data, $network) {
            ManagedServer::whereIn('id', array_filter([$data['managed_server_id'], $data['ingress_server_id'] ?? null]))
                ->orderBy('id')->lockForUpdate()->get();
            $data = $network->allocate($data);
            $keys = null;
            if ($data['ingress_server_id'] ?? null) {
                [$a, $b] = WireGuardService::keyPair();
                [$c, $d] = WireGuardService::keyPair();
                $keys = ['iran' => ['private' => $a, 'public' => $b], 'foreign' => ['private' => $c, 'public' => $d]];
            }
            $instance = AnyConnectInstance::create($data + [
                'enabled' => true, 'status' => 'queued',
                'owner_id' => (string) Str::uuid(), 'radius_secret' => bin2hex(random_bytes(32)), 'link_keys' => $keys,
            ]);
            VpnOperation::record('anyconnect', $instance->id, 'install');
            return $instance;
        }, 3);
        SyncAnyConnect::dispatch($instance->id);
        return response()->json(['data' => $instance], 201);
    }

    public function update(Request $request, AnyConnectInstance $instance): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'dns' => 'required|array|min:1|max:3',
            'dns.*' => 'ipv4', 'mtu' => 'required|integer|min:1280|max:1500']);
        $lock = Cache::lock('anyconnect:sync:'.$instance->id, 20);
        abort_unless($lock->get(), 422, 'عملیات سرویس هنوز در حال اجراست.');
        try {
            $sync = DB::transaction(function () use ($request, $instance, $data) {
                $row = AnyConnectInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
                abort_if(in_array($row->status, ['queued', 'deleting', 'stopping'], true) ||
                    VpnOperation::pending('anyconnect', $row->id), 422, 'عملیات سرویس هنوز در حال اجراست.');
                $certificate = AnyConnectCertificate::settings($request, $row->endpoint_host, $row->certificate_config, (int) $row->endpoint_port);
                if ($certificate !== $row->certificate_config) {
                    $data['certificate_config'] = $certificate;
                }
                $row->update($data);
                if ($row->enabled) VpnOperation::record('anyconnect', $row->id, 'sync');

                return $row->enabled;
            }, 3);
        } finally {
            $lock->release();
        }
        if ($sync) SyncAnyConnect::dispatch($instance->id);
        return response()->json(['data' => $instance->refresh()]);
    }

    public function action(Request $request, AnyConnectInstance $instance): JsonResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['start', 'stop', 'update'])]])['action'];
        $lock = Cache::lock('anyconnect:sync:'.$instance->id, 20);
        abort_unless($lock->get(), 422, 'عملیات سرویس هنوز در حال اجراست.');
        try {
            DB::transaction(function () use ($action, $instance) {
                $row = AnyConnectInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
                abort_if($row->status === 'deleting' || $row->status === 'queued' ||
                    ($row->status === 'stopping' && !$row->last_error) ||
                    VpnOperation::pending('anyconnect', $row->id), 422, 'عملیات سرویس هنوز در حال اجراست.');
                if ($action !== 'stop') Cache::forget('anyconnect:certificate:'.$row->id);
                $operation = match ($action) {
                    'stop' => 'disable',
                    'update' => 'sync',
                    default => $row->last_synced_at ? 'enable' : 'install',
                };
                $row->update([
                    'enabled' => $action !== 'stop', 'status' => $action === 'stop' ? 'stopping' : 'queued',
                    'last_error' => null,
                ]);
                VpnOperation::record('anyconnect', $row->id, $operation);
            }, 3);
        } finally {
            $lock->release();
        }
        SyncAnyConnect::dispatch($instance->id);
        return response()->json(['data' => $instance->fresh()], 202);
    }

    public function destroy(AnyConnectInstance $instance, AnyConnectService $service): JsonResponse
    {
        $lock = Cache::lock('anyconnect:sync:'.$instance->id, 20);
        abort_unless($lock->get(), 422, 'عملیات سرویس هنوز در حال اجراست.');
        try {
            DB::transaction(function () use ($instance, $service) {
                $row = AnyConnectInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
                abort_if($row->status === 'queued' || (in_array($row->status, ['stopping', 'deleting'], true) && !$row->last_error) ||
                    VpnOperation::pending('anyconnect', $row->id), 422, 'عملیات سرویس هنوز در حال اجراست.');
                $service->assertUnused($row->id);
                $row->update(['enabled' => false, 'status' => 'deleting', 'last_error' => null]);
                VpnOperation::record('anyconnect', $row->id, 'delete');
            }, 3);
        } finally {
            $lock->release();
        }
        SyncAnyConnect::dispatch($instance->id);
        return response()->json(['data' => $instance->fresh()], 202);
    }

    private function validateSettings(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'managed_server_id' => 'required|integer|exists:speedbox_managed_servers,id',
            'ingress_server_id' => 'nullable|integer|different:managed_server_id|exists:speedbox_managed_servers,id',
            'endpoint_host' => ['required', 'string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD'],
            'endpoint_port' => 'required|integer|between:1,65535',
            'listen_port' => 'required|integer|between:1,65535',
            'network' => 'nullable|string|max:18',
            'dns' => 'required|array|min:1|max:3', 'dns.*' => 'ipv4',
            'mtu' => 'required|integer|min:1280|max:1500',
            'link_port' => 'nullable|integer|between:1024,65535',
        ]);
        $data['endpoint_host'] = strtolower($data['endpoint_host']);
        if (empty($data['ingress_server_id'])) {
            abort_if((int) $data['listen_port'] !== (int) $data['endpoint_port'], 422, 'در حالت مستقیم پورت اتصال و پورت سرویس باید یکی باشند.');
        }
        return $data;
    }
}
