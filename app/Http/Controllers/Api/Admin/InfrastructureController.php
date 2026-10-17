<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeployManagedServer;
use App\Jobs\UninstallManagedServer;
use App\Support\InfrastructureNodeRules;
use App\Models\ManagedServer;
use App\Models\ProxyServer;
use App\Models\ServerDeployment;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Models\ServerRoute;
use App\Services\NodeControlService;
use App\Services\ManagedServerRemoval;
use App\Services\RuntimeSetting;
use App\Services\RemoteNodeTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InfrastructureController extends Controller
{
    public function __construct(private readonly NodeControlService $nodeControl)
    {
    }

    public function overview(): JsonResponse
    {
        $machines = ServerMachine::withCount('nodes')->get();
        $nodes = ProxyServer::with('parent')->get();
        return response()->json(['data' => [
            'managed_servers' => ManagedServer::count(),
            'machines' => $machines->count(),
            'online_machines' => $machines->filter(fn ($machine) => $machine->is_active && $machine->last_seen_at >= time() - 300)->count(),
            'nodes' => $nodes->count(),
            'online_nodes' => $nodes->filter(fn ($node) => $this->nodeControl->stateFor($node, [])['online'])->count(),
            'active_users' => DB::table('v2_user')->where('banned', 0)->whereRaw('(u + d) < transfer_enable')->tap(fn ($query) => \App\Services\SubscriptionValidity::whereUnexpired($query))->count(),
            'traffic' => ['upload' => (int) $nodes->sum('u'), 'download' => (int) $nodes->sum('d')],
        ]]);
    }

    public function servers(): JsonResponse
    {
        $servers = ManagedServer::with(['machine' => fn ($q) => $q->withCount('nodes'), 'deployments' => fn ($query) => $query->whereIn('id', ServerDeployment::selectRaw('MAX(id)')->groupBy('managed_server_id'))])
            ->latest()->get()->map(function ($server) {
                $server->connection_status = !$server->machine?->is_active ? 'inactive' : (!$server->machine?->last_seen_at ? 'not_seen' : ($server->machine->last_seen_at >= time() - 300 ? 'online' : 'offline'));
                return $server;
            });
        return response()->json(['data' => $servers]);
    }

    public function storeServer(Request $request): JsonResponse
    {
        $data = $this->validateServer($request);
        $result = DB::transaction(function () use ($data) {
            $machine = ServerMachine::create([
                'name' => $data['name'],
                'token' => ServerMachine::generateToken(),
                'notes' => $data['notes'] ?? null,
                'is_active' => true,
            ]);
            $server = ManagedServer::create([...$data, 'machine_id' => $machine->id, 'status' => 'draft']);
            return $server->load('machine');
        });

        if ($request->boolean('install_after_save', true)) {
            $deployment = ServerDeployment::create(['managed_server_id' => $result->id, 'requested_by' => $request->user()?->id, 'action' => 'install', 'kernel' => 'singbox', 'status' => 'queued']);
            DeployManagedServer::dispatch($deployment->id);
        }
        return response()->json(['message' => $request->boolean('install_after_save', true)
            ? 'سرور ذخیره شد. نصب خودکار در صف عملیات قرار گرفت.'
            : 'سرور ذخیره شد.', 'data' => $result], 201);
    }

    public function updateServer(Request $request, ManagedServer $managedServer): JsonResponse
    {
        abort_if($managedServer->status === 'removing', 409, 'این سرور در حال حذف است.');
        $data = $this->validateServer($request, $managedServer);
        foreach (['password', 'private_key', 'private_key_passphrase'] as $secret) {
            if (!array_key_exists($secret, $data) || $data[$secret] === null || $data[$secret] === '') {
                unset($data[$secret]);
            }
        }
        $managedServer->update($data);
        $managedServer->machine?->update(['name' => $managedServer->name, 'notes' => $data['notes'] ?? $managedServer->machine->notes]);
        return response()->json(['message' => 'اطلاعات سرور به‌روز شد.', 'data' => $managedServer->fresh('machine')]);
    }

    public function deleteServer(Request $request, ManagedServer $managedServer, ManagedServerRemoval $removal): JsonResponse
    {
        $request->validate(['panel_only' => ['sometimes', 'boolean']]);
        $panelOnly = $request->boolean('panel_only');
        $deployment = DB::transaction(function () use ($managedServer, $request, $panelOnly, $removal) {
            $server = ManagedServer::whereKey($managedServer->id)->lockForUpdate()->firstOrFail();
            abort_if($server->machine?->managedServers()->where('id', '!=', $server->id)->exists(), 422, 'این ماشین با سرور دیگری مشترک است؛ ابتدا ارتباط مشترک را اصلاح کنید.');
            abort_if($server->deployments()->whereIn('status', ['queued', 'running'])->exists(), 409, 'عملیات قبلی این سرور هنوز تمام نشده است.');
            if ($panelOnly) {
                $removal->removeLocked($server);
                return null;
            }
            $server->update(['status' => 'removing']);
            return ServerDeployment::create(['managed_server_id' => $server->id, 'requested_by' => $request->user()?->id, 'action' => 'uninstall', 'kernel' => 'singbox', 'status' => 'queued']);
        });
        if ($panelOnly) {
            return response()->json(['message' => 'سرور فقط از پنل حذف شد. نودهای متصل حذف نشدند و برای اتصال به سرور دیگری غیرفعال شدند.']);
        }
        UninstallManagedServer::dispatch($deployment->id);
        return response()->json(['message' => 'پاک‌سازی کامل سرور در صف قرار گرفت؛ رکورد پنل پس از موفقیت حذف می‌شود.', 'data' => $deployment], 202);
    }

    public function deploy(Request $request, ManagedServer $managedServer): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['install', 'upgrade', 'repair', 'restart', 'check'])],
            'kernel' => ['nullable', Rule::in(ProxyServer::KERNELS)],
        ], [
            'action.required' => 'نوع عملیات را انتخاب کنید.',
            'action.in' => 'عملیات انتخاب‌شده معتبر نیست.',
            'kernel.in' => 'هسته انتخاب‌شده معتبر نیست.',
        ]);
        $deployment = DB::transaction(function () use ($managedServer, $request, $data) {
            $server = ManagedServer::whereKey($managedServer->id)->lockForUpdate()->firstOrFail();
            abort_if($server->status === 'removing' || $server->deployments()->whereIn('status', ['queued', 'running'])->exists(), 409, 'عملیات قبلی این سرور هنوز در صف یا در حال اجراست. تا پایان آن صبر کنید.');
            return ServerDeployment::create([
                'managed_server_id' => $managedServer->id,
                'requested_by' => $request->user()?->id,
                'action' => $data['action'],
                'kernel' => $data['kernel'] ?? 'singbox',
                'status' => 'queued',
            ]);
        });
        DeployManagedServer::dispatch($deployment->id);
        return response()->json(['message' => 'درخواست در صف عملیات سرور قرار گرفت.', 'data' => $deployment], 202);
    }

    public function deployments(ManagedServer $managedServer): JsonResponse
    {
        return response()->json(['data' => $managedServer->deployments()->latest()->limit(30)->get()]);
    }

    public function machines(): JsonResponse
    {
        $machines = ServerMachine::withCount('nodes')->latest()->get()->map(function ($machine) {
            $machine->online = $machine->is_active && $machine->last_seen_at >= time() - 300;
            return $machine;
        });
        return response()->json(['data' => $machines]);
    }

    public function rotateMachineToken(ServerMachine $machine): JsonResponse
    {
        abort_if($machine->managedServers()->exists(), 422, 'Rotate by running Repair on the linked managed server so the agent receives the new token safely.');
        $token = ServerMachine::generateToken();
        $machine->forceFill(['token' => $token])->save();
        return response()->json(['message' => 'Machine token rotated.', 'data' => ['token' => $token]]);
    }

    public function machineHistory(ServerMachine $machine, Request $request): JsonResponse
    {
        $hours = min(168, max(1, $request->integer('hours', 24)));
        return response()->json(['data' => ServerMachineLoadHistory::where('machine_id', $machine->id)->where('recorded_at', '>=', now()->subHours($hours)->timestamp)->orderBy('recorded_at')->get()]);
    }

    public function nodes(): JsonResponse
    {
        $nodes = ProxyServer::with(['machine:id,name,last_seen_at', 'parent'])->orderBy('sort')->orderBy('id')->get();
        $onlineCounts = app(\App\Services\AccountOnlineStatus::class)->nodeUserCounts($nodes->map(fn ($node) => (int) $node->runtimeNode()->id)->all());
        $nodes = $nodes->map(function ($node) use ($onlineCounts) {
            $node->runtime = $this->nodeControl->stateFor($node, $onlineCounts);
            $trafficNode = $node->runtimeNode();
            $node->traffic_usage = [
                'upload' => (int) $trafficNode->u, 'download' => (int) $trafficNode->d,
                'total' => (int) $trafficNode->u + (int) $trafficNode->d,
                'limit' => (int) $trafficNode->transfer_enable,
                'shared' => (int) $trafficNode->id !== (int) $node->id,
                'source_name' => $trafficNode->name,
            ];
            return $node;
        });
        return response()->json(['data' => $nodes]);
    }

    public function storeNode(Request $request): JsonResponse
    {
        $data = $this->validateNode($request);
        $data['sort'] ??= ((int) ProxyServer::max('sort')) + 1;
        $node = ProxyServer::create($data);
        return response()->json(['message' => 'نود ساخته شد؛ عامل سرور آن را خودکار دریافت می‌کند.', 'data' => $node->load('machine')], 201);
    }

    public function updateNode(Request $request, ProxyServer $node): JsonResponse
    {
        $beforeMachine = $node->machine_id;
        $beforeKernel = $node->kernel;
        $data = $this->validateNode($request, $node);
        DB::transaction(function () use ($node, $data) {
            $node->update($data);
            if (!$node->parent_id) ProxyServer::where('parent_id', $node->id)->update(['machine_id' => $node->machine_id, 'kernel' => $node->kernel, 'rate' => $node->rate]);
        });
        $this->nodeControl->clearNodeCache($node->id);
        return response()->json([
            'message' => ($beforeMachine !== $node->machine_id || $beforeKernel !== $node->kernel)
                ? 'نود به‌روز شد؛ عامل آن را با هسته انتخاب‌شده دوباره اجرا می‌کند.'
                : 'نود به‌روز شد و تنظیمات خودکار اعمال می‌شود.',
            'data' => $node->fresh('machine'),
        ]);
    }

    public function cloneNode(ProxyServer $node): JsonResponse
    {
        $copy = $node->replicate();
        $copy->name = $node->name.' (کپی)';
        $copy->code = null;
        $copy->sort = ((int) ProxyServer::max('sort')) + 1;
        $copy->enabled = false;
        $copy->show = false;
        $copy->save();
        return response()->json(['message' => 'کپی نود به صورت غیرفعال ساخته شد.', 'data' => $copy], 201);
    }

    public function deleteNode(ProxyServer $node): JsonResponse
    {
        abort_if(ProxyServer::where('parent_id', $node->id)->exists(), 422, 'ابتدا نودهای زیرمجموعه را منتقل یا حذف کنید.');
        $node->delete();
        $this->nodeControl->clearNodeCache($node->id);
        return response()->json(['message' => 'نود حذف شد.']);
    }

    public function plans(): JsonResponse
    {
        $plans = DB::table('v2_plan')->orderBy('sort')->get()->map(function ($plan) {
            $plan->tags = is_string($plan->tags) ? (json_decode($plan->tags, true) ?: []) : ($plan->tags ?? []);
            return $plan;
        });
        return response()->json(['data' => $plans]);
    }

    public function savePlan(Request $request, ?int $plan = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'group_id' => ['required', 'integer', 'exists:v2_server_group,id'],
            'transfer_enable' => ['required', 'integer', 'min:0'],
            'speed_limit' => ['nullable', 'integer', 'min:0'],
            'device_limit' => ['nullable', 'integer', 'min:0'],
            'capacity_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'reset_traffic_method' => ['sometimes', 'nullable', 'integer', Rule::in([0, 1, 2, 3, 4])],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'force_update' => ['sometimes', 'boolean'],
        ], [
            'required' => 'وارد کردن :attribute الزامی است.',
            'integer' => ':attribute باید عدد صحیح باشد.',
            'min' => ':attribute نباید کمتر از :min باشد.',
            'group_id.exists' => 'گروه دسترسی انتخاب‌شده وجود ندارد.',
            'reset_traffic_method.in' => 'روش بازنشانی ترافیک معتبر نیست.',
        ], [
            'name' => 'نام پلن', 'group_id' => 'گروه دسترسی', 'transfer_enable' => 'ترافیک پلن',
            'speed_limit' => 'محدودیت سرعت', 'device_limit' => 'محدودیت اتصال',
            'capacity_limit' => 'محدودیت ظرفیت', 'tags' => 'تگ‌ها',
        ]);
        $forceUpdate = $request->boolean('force_update');
        unset($data['force_update']);
        foreach (['group_id', 'transfer_enable', 'speed_limit', 'device_limit', 'capacity_limit', 'reset_traffic_method'] as $key) {
            if (isset($data[$key])) $data[$key] = (int) $data[$key];
        }
        if (array_key_exists('tags', $data)) $data['tags'] = json_encode($data['tags'] ?? [], JSON_UNESCAPED_UNICODE);
        $data['updated_at'] = time();
        $plan = DB::transaction(function () use ($plan, $data, $forceUpdate) {
            if (!$plan) return DB::table('v2_plan')->insertGetId([...$data, 'created_at' => time()]);
            $existing = DB::table('v2_plan')->where('id', $plan)->lockForUpdate()->first();
            abort_unless($existing, 404, 'پلن یافت نشد.');
            DB::table('v2_plan')->where('id', $plan)->update($data);
            if ($forceUpdate) {
                // Same four fields as XBoard; keep usage, expiry and Panel account snapshots.
                DB::table('v2_user')->where('plan_id', $plan)->update([
                    'group_id' => $data['group_id'],
                    'transfer_enable' => (int) $data['transfer_enable'] * 1073741824,
                    'speed_limit' => $data['speed_limit'] ?? null,
                ]);
                app(\App\Services\TariffConnectionPolicy::class)->applyPlanDefault((int) $plan, $data['device_limit'] ?? null);
            }
            $previousReset = $existing->reset_traffic_method === null ? null : (int) $existing->reset_traffic_method;
            if (array_key_exists('reset_traffic_method', $data) && $previousReset !== $data['reset_traffic_method']) {
                // Recalculate the next schedule without resetting any user's consumed traffic.
                DB::table('v2_user')->where('plan_id', $plan)->update(['next_reset_at' => null]);
            }
            return $plan;
        });
        return response()->json(['message' => 'پلن ذخیره شد و در بخش تعرفه‌های پنل قابل انتخاب است.', 'data' => DB::table('v2_plan')->where('id', $plan)->first()]);
    }

    public function deletePlan(int $plan): JsonResponse
    {
        DB::transaction(function () use ($plan) {
            abort_unless(DB::table('v2_plan')->where('id', $plan)->lockForUpdate()->first(), 404, 'پلن یافت نشد.');
            abort_if(DB::table('v2_user')->where('plan_id', $plan)->exists() || DB::table('panel_plan')->where('v2_plan_id', $plan)->exists(), 422, 'این پلن توسط کاربران یا تعرفه‌های فروش استفاده می‌شود و قابل حذف نیست.');
            DB::table('v2_plan')->where('id', $plan)->delete();
        });
        return response()->json(['message' => 'پلن حذف شد.']);
    }

    public function realityKeys(): JsonResponse
    {
        $private = new \Symfony\Component\Process\Process(['openssl', 'genpkey', '-algorithm', 'X25519', '-outform', 'DER']);
        $private->mustRun();
        $public = new \Symfony\Component\Process\Process(['openssl', 'pkey', '-inform', 'DER', '-pubout', '-outform', 'DER']);
        $public->setInput($private->getOutput());
        $public->mustRun();
        $encode = fn ($value) => rtrim(strtr(base64_encode(substr($value, -32)), '+/', '-_'), '=');
        return response()->json(['data' => ['private_key' => $encode($private->getOutput()), 'public_key' => $encode($public->getOutput()), 'short_id' => bin2hex(random_bytes(8))]])->header('Cache-Control', 'no-store');
    }

    public function groups(): JsonResponse
    {
        return response()->json(['data' => ServerGroup::orderBy('id')->get()]);
    }

    public function saveGroup(Request $request, ?ServerGroup $group = null): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $group ??= new ServerGroup();
        $group->fill($data)->save();
        return response()->json(['message' => 'گروه دسترسی ذخیره شد.', 'data' => $group], $group->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteGroup(ServerGroup $group): JsonResponse
    {
        abort_if(DB::table('v2_user')->where('group_id', $group->id)->exists() || DB::table('v2_plan')->where('group_id', $group->id)->exists(), 422, 'این گروه دسترسی توسط کاربران یا پلن‌ها استفاده می‌شود.');
        $group->delete();
        return response()->json(['message' => 'گروه دسترسی حذف شد.']);
    }

    public function routes(): JsonResponse
    {
        return response()->json(['data' => ServerRoute::orderBy('id')->get()]);
    }

    public function saveRoute(Request $request, ?ServerRoute $serverRoute = null): JsonResponse
    {
        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:190'],
            'match' => ['required', 'array'],
            'action' => ['required', 'string', 'in:block,direct,dns,proxy'],
            'action_value' => ['nullable', 'string', 'max:255'],
        ]);
        $serverRoute ??= new ServerRoute();
        $serverRoute->fill($data)->save();
        return response()->json(['message' => 'قانون مسیریابی ذخیره شد.', 'data' => $serverRoute], $serverRoute->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteRoute(ServerRoute $serverRoute): JsonResponse
    {
        $serverRoute->delete();
        return response()->json(['message' => 'قانون مسیریابی حذف شد.']);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => [
            'server_push_interval' => (int) RuntimeSetting::get('server_push_interval', 30),
            'server_pull_interval' => (int) RuntimeSetting::get('server_pull_interval', 60),
            'server_ws_enable' => (bool) RuntimeSetting::get('server_ws_enable', false),
            'server_ws_url' => (string) RuntimeSetting::get('server_ws_url', ''),
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_push_interval' => ['required', 'integer', 'between:5,300'],
            'server_pull_interval' => ['required', 'integer', 'between:10,600'],
            'server_ws_enable' => ['required', 'boolean'],
            'server_ws_url' => ['nullable', 'url', 'max:255'],
        ]);
        foreach ($data as $key => $value) {
            RuntimeSetting::set($key, $value);
        }
        return response()->json(['message' => 'Infrastructure settings updated.']);
    }

    private function validateServer(Request $request, ?ManagedServer $server = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.:_-]+$/'],
            'ssh_port' => ['required', 'integer', 'between:1,65535', Rule::unique('speedbox_managed_servers')->where(fn ($q) => $q->where('host', $request->input('host')))->ignore($server?->id)],
            'ssh_user' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_-]*$/'],
            'auth_type' => ['required', Rule::in(['password', 'key'])],
            'password' => [Rule::requiredIf($request->input('auth_type') === 'password' && (!$server || !$server->password)), 'nullable', 'string'],
            'private_key' => [Rule::requiredIf($request->input('auth_type') === 'key' && (!$server || !$server->private_key)), 'nullable', 'string'],
            'private_key_passphrase' => ['nullable', 'string'],
            'host_fingerprint' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'required' => 'وارد کردن :attribute الزامی است.',
            'string' => ':attribute باید متن باشد.',
            'max' => ':attribute نباید بیشتر از :max نویسه باشد.',
            'integer' => ':attribute باید عدد صحیح باشد.',
            'between' => ':attribute باید بین :min و :max باشد.',
            'in' => 'مقدار :attribute معتبر نیست.',
            'host.regex' => 'آدرس سرور باید یک آی‌پی یا نام میزبان معتبر باشد.',
            'ssh_user.regex' => 'نام کاربری SSH معتبر نیست.',
            'ssh_port.unique' => 'این سرور با همین آدرس و پورت SSH قبلاً ثبت شده است.',
        ], [
            'name' => 'نام سرور', 'host' => 'آدرس سرور', 'ssh_port' => 'پورت SSH',
            'ssh_user' => 'نام کاربری SSH', 'auth_type' => 'روش ورود',
            'password' => 'رمز عبور', 'private_key' => 'کلید خصوصی',
            'private_key_passphrase' => 'عبارت عبور کلید',
            'host_fingerprint' => 'اثر انگشت میزبان', 'notes' => 'توضیحات',
        ]);
        unset($data['notes']);
        return $data;
    }

    private function validateNode(Request $request, ?ProxyServer $node = null): array
    {
        return InfrastructureNodeRules::validate($request, $node);
    }

    public function generateVlessEncryption(Request $request, RemoteNodeTools $tools): JsonResponse
    {
        $data = $request->validate(['machine_id' => 'required|integer|exists:v2_server_machine,id'], [
            'machine_id.required' => 'ابتدا سرور مقصد را انتخاب کنید.',
            'machine_id.exists' => 'سرور مقصد معتبر نیست.',
        ]);
        $server = $this->serverForTools((int) $data['machine_id']);
        $result = $tools->run($server, 'vless-encryption');
        foreach (['encryption', 'decryption'] as $key) {
            abort_unless(is_string($result[$key] ?? null) && str_starts_with($result[$key], 'mlkem768x25519plus.native.'), 422, 'زوج رمزنگاری دریافتی از سرور معتبر نیست.');
        }
        return response()->json(['data' => [
            'encryption' => $result['encryption'], 'decryption' => $result['decryption'],
        ]])->header('Cache-Control', 'no-store');
    }

    public function certificateFingerprint(Request $request, RemoteNodeTools $tools): JsonResponse
    {
        $data = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'node_id' => 'required|integer|exists:v2_server,id',
        ], [
            'machine_id.required' => 'ابتدا سرور مقصد را انتخاب کنید.',
            'node_id.required' => 'ابتدا نود و تنظیمات گواهی را ذخیره کنید.',
            'node_id.exists' => 'نود انتخاب‌شده وجود ندارد.',
        ]);
        $node = ProxyServer::with('parent')->findOrFail($data['node_id'])->runtimeNode();
        abort_unless($node->type === 'hysteria', 422, 'دریافت اثر انگشت برای نود هیستریا است.');
        abort_unless((int) $node->machine_id === (int) $data['machine_id'], 422, 'ابتدا تغییر سرور مقصد نود را ذخیره کنید.');
        $server = $this->serverForTools((int) $node->machine_id);
        $result = $tools->run($server, 'certificate-fingerprint', [
            'panel-url' => rtrim((string) config('app.url'), '/'),
            'machine-id' => $node->machine_id,
            'node-id' => $node->id,
        ]);
        abort_unless(is_string($result['fingerprint'] ?? null) && preg_match('/^[a-fA-F0-9]{64}$/D', $result['fingerprint']), 422, 'اثر انگشت دریافتی معتبر نیست.');
        return response()->json(['data' => ['fingerprint' => strtolower($result['fingerprint'])]])->header('Cache-Control', 'no-store');
    }

    private function serverForTools(int $machineId): ManagedServer
    {
        $servers = ManagedServer::where('machine_id', $machineId)->get();
        abort_unless($servers->count() === 1, 422, 'سرور مقصد باید به یک سرور مدیریت‌شده با اطلاعات SSH متصل باشد.');
        $server = $servers->first();
        abort_if($server->status === 'removing' || $server->deployments()->whereIn('status', ['queued', 'running'])->exists(), 409, 'تا پایان عملیات جاری سرور صبر کنید.');
        return $server;
    }

    public function generateEchKey(Request $request)
    {
        $request->validate(['public_name' => ['nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.-]+$/']], ['public_name.regex' => 'نام عمومی ECH باید یک دامنه معتبر باشد.']);
        $publicName = $request->input('public_name') ?: 'ech.example.com';
        abort_unless(function_exists('sodium_crypto_scalarmult_base'), 422, 'برای تولید کلید ECH، افزونه sodium را در PHP فعال کنید.');

        // Generate X25519 key pair
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // Build ECHConfigContents (draft-ietf-tls-esni-18)
        $contents = '';
        $contents .= pack('C', $configId);                // config_id
        $contents .= pack('n', 0x0020);                   // kem_id: DHKEM(X25519)
        $contents .= pack('n', 32) . $publicKey;          // public_key (length-prefixed)
        // cipher_suites: 2 suites × 4 bytes = 8 bytes
        $contents .= pack('n', 8);                        // cipher_suites byte length
        $contents .= pack('nn', 0x0001, 0x0001);          // HKDF-SHA256 + AES-128-GCM
        $contents .= pack('nn', 0x0001, 0x0003);          // HKDF-SHA256 + ChaCha20Poly1305
        $contents .= pack('C', 0);                        // max_name_length
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);                        // extensions: empty

        // ECHConfig = version(2) + length(2) + contents
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;

        // ECHConfigList = total_length(2) + configs
        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;

        // ECH Keys = private_key_len(2) + key(32) + config_len(2) + config
        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        $keyPem = "-----BEGIN ECH KEYS-----\n"
            . chunk_split(base64_encode($echKeysPayload), 64, "\n")
            . "-----END ECH KEYS-----";

        $configPem = "-----BEGIN ECH CONFIGS-----\n"
            . chunk_split(base64_encode($echConfigList), 64, "\n")
            . "-----END ECH CONFIGS-----";

        return response()->json(['data' => [
            'key' => $keyPem,
            'config' => $configPem,
        ]])->header('Cache-Control', 'no-store');
    }

    public function nodeFlags(Request $request, ProxyServer $node): JsonResponse
    {
        $data = $request->validate(['show' => 'sometimes|required|boolean', 'enabled' => 'sometimes|required|boolean']);
        if (array_key_exists('enabled', $data) && $data['enabled'] && !$node->parent_id && $node->machine_id) {
            abort_if(ProxyServer::where('machine_id', $node->machine_id)->where('server_port', $node->server_port)->where('enabled', true)->where('id', '!=', $node->id)->where(fn ($q) => $q->whereNull('parent_id')->orWhere('parent_id', 0))->exists(), 422, 'پورت سرور توسط نود دیگری استفاده شده است.');
        }
        $node->update($data);
        if (array_key_exists('enabled', $data)) $this->nodeControl->clearNodeCache($node->id);
        return response()->json(['message' => 'وضعیت نود ذخیره شد.', 'data' => $node]);
    }

    public function sortNodes(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'required|integer|distinct|exists:v2_server,id']);
        DB::transaction(function () use ($data) {
            $ids = ProxyServer::orderBy('sort')->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $submitted = array_map('intval', $data['ids']);
            abort_if(count($ids) !== count($submitted) || array_diff($ids, $submitted), 409, 'فهرست نودها تغییر کرده؛ صفحه را تازه کنید.');
            foreach ($submitted as $order => $id) ProxyServer::whereKey($id)->update(['sort' => $order + 1]);
        });
        return response()->json(['message' => 'ترتیب نودها ذخیره شد.']);
    }
}
