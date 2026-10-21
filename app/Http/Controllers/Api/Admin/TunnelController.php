<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\{ManagedServer, ProxyServer, ServerTunnel, TunnelConnectionTest, TunnelOperation};
use App\Services\TunnelManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TunnelController extends Controller
{
    public function index()
    {
        $tunnels = ServerTunnel::with(['iran:id,name,host,machine_id','foreign:id,name,host,machine_id'])->latest()->get();
        foreach ($tunnels as $t) {
            $blocking=DB::table('speedbox_tunnel_leases')->whereIn('managed_server_id',[$t->iran_server_id,$t->foreign_server_id])->value('operation_id');
            $t->operation = $blocking ? TunnelOperation::find($blocking) : $t->operations()->latest()->first();
            $ports = [];
            foreach ($t->settings['mappings'] as $mapping) $ports = [...$ports,...range((int)$mapping['target_port'],(int)($mapping['target_end'] ?? $mapping['target_port']))];
            $t->nodes = ProxyServer::where('machine_id', $t->foreign?->machine_id)->whereIn('server_port', $ports)->get(['id','name','host','port','server_port','machine_id']);
        }
        return response()->json(['data' => $tunnels, 'tests' => TunnelConnectionTest::latest()->limit(10)->get()->map(fn($test)=>$test->operations()->where('action','network-test')->exists()?app(\App\Services\NetworkTunnelTest::class)->publicTest($test):[...$test->toArray(),'engine'=>'backpack']),
            'alerting' => app(\App\Services\TunnelAlertSettings::class)->capability(),
            'host_status' => DB::table('speedbox_tunnel_host_status')->get()->mapWithKeys(fn($row)=>[$row->managed_server_id=>json_decode($row->snapshot,true)])->all(),
            'servers' => ManagedServer::where('status', '!=', 'removing')->get(['id','name','host','machine_id'])]);
    }

    public function test(Request $request, TunnelManager $manager)
    {
        $data = $request->validate([
            'iran_server_id' => ['required','integer','different:foreign_server_id','exists:speedbox_managed_servers,id'],
            'foreign_server_id' => ['required','integer','exists:speedbox_managed_servers,id'], 'compare_both' => 'sometimes|boolean',
        ], [], ['iran_server_id' => 'سرور ایران', 'foreign_server_id' => 'سرور خارج']);
        [$test,$op] = DB::transaction(function () use ($data,$manager) {
            $this->lockServers($data['iran_server_id'], $data['foreign_server_id']);
            $test = TunnelConnectionTest::create($data);
            return [$test, $manager->queue('test', null, $test)];
        });
        return response()->json(['message' => 'بررسی روش‌های اتصال در صف قرار گرفت.', 'data' => $test, 'operation' => $op], 202);
    }

    public function testShow(TunnelConnectionTest $tunnelTest)
    {
        return response()->json(['data' => $tunnelTest, 'operation' => $tunnelTest->operations()->latest()->first()]);
    }

    public function testHistory(ServerTunnel $tunnel)
    {
        abort_unless($tunnel->engine==='backpack',422,'این مسیر فقط برای بک‌پک است.');
        abort_if($tunnel->status==='deleted',422,'این تونل حذف شده است.');
        return response()->json(['data'=>TunnelConnectionTest::whereDoesntHave('operations',fn($q)=>$q->where('action','network-test'))->where('iran_server_id',$tunnel->iran_server_id)
            ->where('foreign_server_id',$tunnel->foreign_server_id)->where('status','completed')
            ->where('updated_at','>',now()->subHours(24))->orderByDesc('id')->limit(20)->get()]);
    }

    public function store(Request $request, TunnelManager $manager)
    {
        $selection = $request->validate(['test_id' => 'required|integer|exists:speedbox_tunnel_tests,id', 'result_id' => 'required|string|max:100', 'accept_unstable' => 'sometimes|boolean']);
        $test = TunnelConnectionTest::findOrFail($selection['test_id']);
        abort_if($test->operations()->where('action','network-test')->exists(),422,'نتیجهٔ GOST یا GRE برای ساخت BackPack قابل استفاده نیست.');
        abort_unless($test->status === 'completed' && $test->updated_at->greaterThan(now()->subHours(24)), 422, 'ابتدا بررسی اتصال را کامل کنید؛ نتیجه باید مربوط به ۲۴ ساعت اخیر باشد.');
        $row = collect($test->results)->firstWhere('id', $selection['result_id']);
        abort_unless($row && $row['selectable'], 422, 'یک روش قابل استفاده از نتیجهٔ تست انتخاب کنید.');
        abort_if($row['status'] === 'unstable' && !$request->boolean('accept_unstable'), 422, 'این روش ناپایدار بود؛ انتخاب آن را تأیید کنید.');
        $settings = $this->settings($request, $row['kind'], $row['transport']);
        [$tunnel,$op] = DB::transaction(function () use ($request,$test,$row,$settings,$manager) {
            $this->lockServers($test->iran_server_id, $test->foreign_server_id);
            $this->portsAvailable($test->iran_server_id, $settings);
            $subnet = random_int(1024, 16000);
            for ($i=0; ServerTunnel::where('subnet',$subnet)->exists() && $i<100; $i++) $subnet = random_int(1024,16000);
            $tunnel = ServerTunnel::create(['name' => $request->input('name'), 'remote_name' => 'sb-'.bin2hex(random_bytes(16)),
                'iran_server_id' => $test->iran_server_id, 'foreign_server_id' => $test->foreign_server_id,
                'kind' => $row['kind'], 'transport' => $row['transport'], 'orientation' => $row['orientation'],
                'settings' => $settings, 'secret' => bin2hex(random_bytes(32)), 'subnet' => $subnet]);
            return [$tunnel,$manager->queue('apply',$tunnel,null)];
        });
        return response()->json(['message' => 'ساخت تونل در صف قرار گرفت.', 'data' => $tunnel, 'operation' => $op], 202);
    }

    public function update(Request $request, ServerTunnel $tunnel, TunnelManager $manager)
    {
        abort_unless($tunnel->engine==='backpack',422,'برای این اتصال از مسیر ابزار خودش استفاده کنید.');
        abort_if($tunnel->status === 'deleted', 422, 'این تونل حذف شده است.');
        $request->validate(['test_id'=>'sometimes|required|integer|exists:speedbox_tunnel_tests,id','result_id'=>'required_with:test_id|string|max:100','accept_unstable'=>'sometimes|boolean',
            'kind'=>'prohibited','transport'=>'prohibited','orientation'=>'prohibited']);
        abort_if($request->has('result_id') && !$request->has('test_id'),422,'نتیجهٔ تست را همراه شناسهٔ بررسی اتصال انتخاب کنید.');
        $op = DB::transaction(function () use ($tunnel,$request,$manager) {
            $this->lockServers($tunnel->iran_server_id, $tunnel->foreign_server_id);
            $tunnel=ServerTunnel::whereKey($tunnel->id)->lockForUpdate()->firstOrFail();
            abort_if($tunnel->status==='deleted',422,'این تونل حذف شده است.');
            $method=['kind'=>$tunnel->kind,'transport'=>$tunnel->transport,'orientation'=>$tunnel->orientation];
            if($request->has('test_id')) {
                $test=TunnelConnectionTest::findOrFail($request->integer('test_id'));
                abort_if($test->operations()->where('action','network-test')->exists(),422,'این نتیجه مربوط به BackPack نیست.');
                abort_unless($test->iran_server_id===$tunnel->iran_server_id && $test->foreign_server_id===$tunnel->foreign_server_id,422,'نتیجهٔ تست باید متعلق به همین سرور ایران و خارج باشد.');
                abort_unless($test->status==='completed' && $test->updated_at->greaterThan(now()->subHours(24)),422,'نتیجهٔ تکمیل‌شدهٔ ۲۴ ساعت اخیر را انتخاب کنید یا دوباره تست بگیرید.');
                $row=collect($test->results??[])->firstWhere('id',$request->input('result_id'));
                abort_unless($row && ($row['selectable']??false) && in_array($row['status']??'',['ok','unstable'],true),422,'یک روش قابل استفاده از نتیجهٔ تست انتخاب کنید.');
                abort_if($row['status']==='unstable' && !$request->boolean('accept_unstable'),422,'انتخاب روش ناپایدار را تأیید کنید.');
                $supported=($row['kind']??'')==='reverse'?TunnelManager::REVERSE:(($row['kind']??'')==='direct'?TunnelManager::DIRECT:[]);
                abort_unless(in_array($row['transport']??'',$supported,true) && in_array($row['orientation']??'',['normal','swapped'],true) && !($row['kind']==='reverse'&&$row['orientation']==='swapped'),422,'این روش برای ورود کاربران از ایران قابل استفاده نیست.');
                $method=['kind'=>$row['kind'],'transport'=>$row['transport'],'orientation'=>$row['orientation']];
            }
            $settings=$this->settings($request,$method['kind'],$method['transport'],$tunnel);
            $this->portsAvailable($tunnel->iran_server_id, $settings, $tunnel->id);
            $changed=$method!==['kind'=>$tunnel->kind,'transport'=>$tunnel->transport,'orientation'=>$tunnel->orientation];
            return $manager->queue($changed?'reconfigure':'apply',$tunnel,null,['settings'=>$settings,'name'=>$request->input('name'),'method'=>$method]);
        });
        return response()->json(['message' => 'ویرایش تونل در صف قرار گرفت.', 'data' => $op], 202);
    }

    public function action(Request $request, ServerTunnel $tunnel, TunnelManager $manager)
    {
        abort_unless($tunnel->engine==='backpack',422,'برای این اتصال از مسیر ابزار خودش استفاده کنید.');
        $data = $request->validate(['action' => ['required',Rule::in(['start','stop','restart','status','logs','delete','maintenance-status','optimize','engine-update'])]]);
        abort_if($tunnel->status === 'deleted', 422, 'این تونل حذف شده است.');
        if (in_array($data['action'],['delete','engine-update'],true)) $request->validate(['confirm' => 'accepted']);
        $payload=[];
        if($data['action']==='engine-update') {
            $expected=$request->validate(['server_ids'=>'required|array|min:2','server_ids.*'=>'required|integer|distinct']);
            $ids=array_map('intval',$expected['server_ids']);sort($ids);
            abort_unless($manager->maintenanceScope($tunnel)['server_ids']===$ids,409,'سرورهای وابسته تغییر کرده‌اند؛ وضعیت را دوباره بررسی کنید.');
            $payload['confirmed_server_ids']=$ids;
        }
        if($data['action']==='optimize') {
            $selection=$request->validate(['sides'=>'required|array|min:1|max:2','sides.*'=>['required',Rule::in(['iran','foreign'])]]);
            $payload['selected_ids']=array_values(array_unique(array_map(fn($side)=>(int)($side==='iran'?$tunnel->iran_server_id:$tunnel->foreign_server_id),$selection['sides'])));
        }
        $op = $manager->queue($data['action'],$tunnel,null,$payload);
        return response()->json(['message' => 'عملیات تونل در صف قرار گرفت.', 'data' => $op], 202);
    }

    public function operation(TunnelOperation $tunnelOperation) { return response()->json(['data' => $tunnelOperation]); }

    public function cancel(TunnelOperation $tunnelOperation)
    {
        abort_unless($tunnelOperation->test_id && in_array($tunnelOperation->status,['queued','running']), 422, 'این عملیات قابل توقف نیست.');
        $tunnelOperation->update(['cancel_requested' => true, 'message' => 'در حال توقف تست و جمع‌آوری فایل‌های موقت…']);
        return response()->json(['message' => 'درخواست توقف ثبت شد.']);
    }

    private function settings(Request $request, string $kind, string $transport, ?ServerTunnel $existing=null): array
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'public_host' => ['required','string','max:253',function ($a,$v,$fail) {
            if (!filter_var($v,FILTER_VALIDATE_IP) && !filter_var($v,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)) $fail('IP یا دامنهٔ معتبر وارد کنید؛ بدون http و شماره پورت.');
        }], 'tunnel_port' => 'required|integer|between:1,65535', 'accept_udp' => 'required|boolean',
            'mappings' => 'required|array|min:1|max:20', 'mappings.*.public_port' => 'required|integer|between:1,65535|distinct',
            'mappings.*.target_port' => 'required|integer|between:1,65535',
            'peer_host' => ['nullable','string','max:253',function ($a,$v,$fail) { if ($v && !filter_var($v,FILTER_VALIDATE_IP) && !filter_var($v,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)) $fail('آدرس ارتباط دو سرور معتبر نیست.'); }],
        ], [], ['name'=>'نام تونل','public_host'=>'آدرس اتصال کاربران','tunnel_port'=>'پورت ارتباط بین دو سرور','mappings'=>'پورت‌ها']);
        abort_if(in_array((int)$data['tunnel_port'], array_map('intval',array_column($data['mappings'],'public_port')),true), 422, 'پورت ارتباط بین دو سرور باید با پورت کاربران متفاوت باشد.');
        // Reverse UDP is datagram-only in the pinned engine; do not sell it as a TCP tunnel.
        abort_if($kind === 'reverse' && $transport === 'udp' && !$data['accept_udp'], 422, 'این روش فقط UDP را منتقل می‌کند؛ برای نود TCP روش دیگری انتخاب کنید.');
        $data['telegram_alerts']=app(\App\Services\TunnelAlertSettings::class)->resolve($request,$existing);
        $data['enabled']=true;
        unset($data['name']); $data['peer_host'] = $data['peer_host'] ?? '';
        return $data;
    }

    private function lockServers(int $iran, int $foreign): void
    {
        ManagedServer::whereIn('id', [$iran, $foreign])->orderBy('id')->lockForUpdate()->get();
    }

    private function portsAvailable(int $iranId, array $settings, ?int $except = null): void
    {
        $ports = array_map('intval',array_column($settings['mappings'],'public_port'));
        foreach (ServerTunnel::where(fn($q)=>$q->where('iran_server_id',$iranId)->orWhere('foreign_server_id',$iranId))->where('status','!=','deleted')->when($except,fn($q)=>$q->whereKeyNot($except))->lockForUpdate()->get() as $other) {
            $occupied=[];if((int)$other->iran_server_id===$iranId)foreach($other->settings['mappings'] as $m)$occupied=[...$occupied,...range((int)$m['public_port'],(int)($m['public_end']??$m['public_port']))];
            if($other->engine==='gost'&&$other->transport==='grpc'&&(int)$other->foreign_server_id===$iranId)$occupied[]=(int)$other->settings['tunnel_port'];
            abort_if(array_intersect($ports,$occupied), 422, 'یکی از پورت‌های ورودی در تونل دیگری روی این سرور استفاده می‌شود.');
        }
    }
}
