<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\{ServerTunnel,TunnelOperation};
use App\Services\{NetworkTunnelManager,NetworkTunnelTest,RuntimeSetting,TunnelManager};
use App\Support\NetworkTunnelRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class NetworkTunnelController extends Controller
{
    public function capabilities(NetworkTunnelManager $network)
    {
        return response()->json(['data'=>['defaults'=>$network->defaults(),'engines'=>['gost','gre','wattunnel'],
            'wat_defaults'=>\App\Support\WatTunnelRules::defaults(),'wat_carriers'=>\App\Support\WatTunnelRules::CARRIERS,'versions'=>NetworkTunnelRules::VERSIONS,'gre_types'=>NetworkTunnelRules::GRE,'max_ports'=>256,
            'profiles'=>RuntimeSetting::get('network_tunnel_profiles',['pools'=>[],'sources'=>[]])]]);
    }

    public function test(Request $request,NetworkTunnelManager $network,TunnelManager $manager,NetworkTunnelTest $tester)
    {
        $data=$request->validate(['engine'=>['required',Rule::in(['gost','gre','wattunnel'])],
            'iran_server_id'=>'required|integer|different:foreign_server_id|exists:speedbox_managed_servers,id',
            'foreign_server_id'=>'required|integer|exists:speedbox_managed_servers,id',
            'direction'=>['sometimes',Rule::in(['normal','reverse','both'])],'carriers'=>'sometimes|array|min:1|max:9','carriers.*'=>['required','distinct',Rule::in(\App\Support\WatTunnelRules::CARRIERS)]]);
        [$test,$op]=DB::transaction(function()use($data,$network,$manager,$tester){
            $servers=\App\Models\ManagedServer::whereIn('id',[$data['iran_server_id'],$data['foreign_server_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $cases=$tester->candidates($data['engine'],$servers[$data['iran_server_id']],$servers[$data['foreign_server_id']],$network,$data);
            $test=\App\Models\TunnelConnectionTest::create(['iran_server_id'=>$data['iran_server_id'],'foreign_server_id'=>$data['foreign_server_id'],'results'=>array_map(fn($row)=>[...$row,'status'=>'queued'],$cases)]);
            return [$test,$manager->queue('network-test',null,$test,['engine'=>$data['engine'],'cases'=>$cases])];
        });
        return response()->json(['message'=>'تست اتصال در صف قرار گرفت.','data'=>$tester->publicTest($test),'operation'=>$op],202);
    }

    public function testShow(\App\Models\TunnelConnectionTest $tunnelTest,NetworkTunnelTest $tester)
    {
        return response()->json(['data'=>$tester->publicTest($tunnelTest),'operation'=>$tester->testOperation($tunnelTest)]);
    }

    public function store(Request $request,NetworkTunnelManager $network,TunnelManager $manager)
    {
        [$tunnel,$op]=DB::transaction(function()use($request,$network,$manager){
            $data=$network->prepare($request);
            app(NetworkTunnelTest::class)->assertSelection($request,$data);
            $blocked=$data['engine']==='wattunnel'?[...$manager->reservedPorts(\App\Models\ManagedServer::findOrFail($data['iran_server_id'])),...$manager->reservedPorts(\App\Models\ManagedServer::findOrFail($data['foreign_server_id'])),(int)$data['tunnel_port']]:[];
            if($data['engine']==='wattunnel')foreach($data['mappings'] as $m)$blocked=[...$blocked,...range($m['public_port'],$m['public_end']),...range($m['target_port'],$m['target_end'])];
            $subnet=random_int(32768,65500);
            for($i=0;(ServerTunnel::where('subnet',$subnet)->exists()||in_array($subnet,$blocked,true))&&$i<200;$i++)$subnet=random_int(32768,65500);
            abort_if(ServerTunnel::where('subnet',$subnet)->exists()||in_array($subnet,$blocked,true),422,'پورت داخلی آزاد پیدا نشد؛ دوباره تلاش کنید.');
            $data=$network->allocate($data,$subnet);
            if($data['engine']==='wattunnel')\App\Services\WatTunnelPorts::assertAvailable($data,(int)$data['iran_server_id'],(int)$data['foreign_server_id'],null);
            $tunnel=ServerTunnel::create(['engine'=>$data['engine'],'name'=>$data['name'],'remote_name'=>'sb-'.bin2hex(random_bytes(16)),
                'iran_server_id'=>$data['iran_server_id'],'foreign_server_id'=>$data['foreign_server_id'],
                'kind'=>!empty($data['reverse'])?'reverse':'direct','transport'=>$data['transport'],'orientation'=>'normal','subnet'=>$subnet,
                'settings'=>$data,'secret'=>bin2hex(random_bytes(32))]);
            return [$tunnel,$manager->queue('apply',$tunnel,null)];
        });
        return response()->json(['message'=>'ساخت اتصال در صف قرار گرفت.','data'=>$tunnel,'operation'=>$op],202);
    }

    public function update(Request $request,ServerTunnel $tunnel,NetworkTunnelManager $network,TunnelManager $manager)
    {
        $this->owned($tunnel);
        $op=DB::transaction(function()use($request,$tunnel,$network,$manager){
            $data=$network->prepare($request,$tunnel);
            $data=$network->allocate([...$data,'id'=>$tunnel->id],$tunnel->subnet);
            return $manager->queue('apply',$tunnel,null,['settings'=>$data,'name'=>$data['name']]);
        });
        return response()->json(['message'=>'تغییرات در صف قرار گرفت؛ پس از بررسی دو سمت ثبت می‌شوند.','data'=>$op],202);
    }

    public function action(Request $request,ServerTunnel $tunnel,TunnelManager $manager)
    {
        $this->owned($tunnel);
        abort_if($tunnel->engine==='wattunnel' && in_array($request->input('action'),['optimize','reset-quota','diagnose'],true),422,'این عملیات برای WatTunnel در دسترس نیست.');
        $data=$request->validate(['action'=>['required',Rule::in(['status','logs','start','stop','restart','reapply','delete','preview','diagnose','history','reset-quota','optimize','engine-update','maintenance-status'])],
            'kind'=>['sometimes',Rule::in(['ping','traceroute','mtu','connections','analyze'])]]);
        if(in_array($data['action'],['delete','reset-quota','optimize','engine-update'],true))$request->validate(['confirm'=>'accepted']);
        if($data['action']==='diagnose')$request->validate(['kind'=>'required']);
        if($data['action']==='optimize') {
            $selection=$request->validate(['sides'=>'required|array|min:1|max:2','sides.*'=>['required','distinct',Rule::in(['iran','foreign'])]]);
            $data['sides']=$selection['sides'];
        }
        abort_if($data['action']==='reset-quota' && $tunnel->engine!=='gre',422,'سهمیهٔ ترافیک فقط برای GRE در دسترس است.');
        $op=$manager->queue($data['action'],$tunnel,null,$data);
        return response()->json(['message'=>'عملیات در صف قرار گرفت.','data'=>$op],202);
    }

    public function export(ServerTunnel $tunnel,NetworkTunnelManager $network)
    {
        $this->owned($tunnel);
        return response()->json(['data'=>$network->export($tunnel)])->header('Cache-Control','no-store');
    }

    public function samples(ServerTunnel $tunnel)
    {
        $this->owned($tunnel);
        $rows=DB::table('speedbox_network_samples')->where('tunnel_id',$tunnel->id)->orderByDesc('id')->limit(288)->get();
        return response()->json(['data'=>$rows->map(fn($row)=>['taken_at'=>$row->taken_at,'snapshot'=>json_decode($row->snapshot,true)])]);
    }

    public function profiles(Request $request)
    {
        $data=$request->validate(['pools'=>'present|array|max:20','pools.*.name'=>'required|string|max:80','pools.*.cidr'=>'required|string|max:80',
            'sources'=>'present|array|max:40','sources.*.name'=>'required|string|max:80','sources.*.cidrs'=>'required|array|max:128','sources.*.cidrs.*'=>'required|string|max:80']);
        foreach($data['pools'] as $i=>$pool)$data['pools'][$i]['cidr']=NetworkTunnelRules::cidr($pool['cidr'],"pools.$i.cidr");
        foreach($data['sources'] as $i=>$list)foreach($list['cidrs'] as $j=>$cidr)$data['sources'][$i]['cidrs'][$j]=NetworkTunnelRules::cidr($cidr,"sources.$i.cidrs.$j");
        RuntimeSetting::set('network_tunnel_profiles',$data);
        return response()->json(['message'=>'فهرست‌های مشترک ذخیره شدند.','data'=>$data]);
    }

    private function owned(ServerTunnel $tunnel): void
    {
        abort_unless(in_array($tunnel->engine,['gost','gre','wattunnel'],true)&&$tunnel->status!=='deleted',422,'این عملیات برای یک اتصال شبکهٔ فعال در فهرست است.');
    }
}
