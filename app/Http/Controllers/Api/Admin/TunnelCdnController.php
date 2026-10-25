<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\{ManagedServer,ServerTunnel,TunnelConnectionTest};
use App\Services\{NetworkTunnelManager,TunnelCdn,TunnelManager};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TunnelCdnController extends Controller
{
    public function store(Request $request,TunnelManager $manager,NetworkTunnelManager $network)
    {
        $request->validate(['engine'=>'required|in:backpack,wattunnel','tunnel_id'=>'nullable|integer|exists:speedbox_tunnels,id','test_id'=>'nullable|integer|exists:speedbox_tunnel_tests,id','result_id'=>'nullable|string|max:100']);
        $engine=$request->input('engine');$existing=$request->filled('tunnel_id')?ServerTunnel::findOrFail($request->integer('tunnel_id')):null;
        if($existing)abort_unless($existing->engine===$engine&&$existing->status!=='deleted',422,'تونل معتبر نیست.');
        if($request->filled('test_id')) {
            $baseline=TunnelConnectionTest::findOrFail($request->integer('test_id'));
            abort_unless($baseline->status==='completed'&&$baseline->updated_at->greaterThan(now()->subHours(24)),422,'ابتدا بررسی روش‌های اتصال را کامل کنید.');
            abort_if($baseline->operations()->where('action','cdn-test')->exists(),422,'نتیجهٔ بررسی روش‌ها را انتخاب کنید.');
            $row=collect($baseline->results??[])->firstWhere('id',$request->input('result_id'));
            abort_unless($row,422,'روش انتخاب‌شده در نتیجهٔ تست نیست.');
            $isNetwork=$baseline->operations()->where('action','network-test')->exists();
            abort_unless(($engine==='wattunnel')===$isNetwork,422,'نتیجهٔ بررسی متعلق به این موتور نیست.');
            if($engine==='wattunnel')abort_unless(($row['engine']??'')===$engine,422,'موتور نتیجه سازگار نیست.');
            $iran=(int)$baseline->iran_server_id;$foreign=(int)$baseline->foreign_server_id;
            $method=['engine'=>$engine,'kind'=>$engine==='backpack'?$row['kind']:(!empty($row['reverse'])?'reverse':'direct'),'transport'=>$row['transport'],'orientation'=>$row['orientation']??'normal'];
            if($engine==='wattunnel')$request->merge(['transport'=>$row['transport'],'reverse'=>(bool)$row['reverse'],'version'=>$row['version'],'ip_family'=>$row['ip_family']]);
        } else {
            abort_unless($existing,422,'روش موردنظر را از نتیجهٔ بررسی اتصال انتخاب کنید.');
            $iran=(int)$existing->iran_server_id;$foreign=(int)$existing->foreign_server_id;
            $method=['engine'=>$engine,'kind'=>$existing->kind,'transport'=>$existing->transport,'orientation'=>$existing->orientation];
        }
        if($existing)abort_unless($iran===(int)$existing->iran_server_id&&$foreign===(int)$existing->foreign_server_id,422,'سرورهای نتیجه با تونل یکسان نیستند.');
        abort_unless(TunnelCdn::supported($engine,$method['kind'],$method['transport']),422,'این روش برای CDN پشتیبانی نمی‌شود.');
        $request->merge(['connection_mode'=>'cdn','iran_server_id'=>$iran,'foreign_server_id'=>$foreign]);
        [$test,$op]=DB::transaction(function()use($request,$manager,$network,$existing,$method,$engine,$iran,$foreign){
            ManagedServer::whereIn('id',[$iran,$foreign])->orderBy('id')->lockForUpdate()->get();
            $settings=$engine==='backpack'?(new TunnelController())->settings($request,$method['kind'],$method['transport'],$existing,$method['orientation']):$network->prepare($request,$existing);
            $test=TunnelConnectionTest::create(['iran_server_id'=>$iran,'foreign_server_id'=>$foreign,'status'=>'queued','message'=>'بررسی مسیر CDN در صف قرار گرفت.']);
            $op=$manager->queue('cdn-test',null,$test,['method'=>$method,'settings'=>$settings,'fingerprint'=>TunnelCdn::fingerprint($settings,$method,$iran,$foreign),
                'remote_name'=>'sb-'.bin2hex(random_bytes(16)),'token'=>bin2hex(random_bytes(32)),'phase'=>'ensure','index'=>0,'subnet'=>random_int(1024,16000)]);
            return [$test,$op];
        });
        return response()->json(['data'=>$test,'operation'=>$op,'message'=>'بررسی اتصال CDN در صف قرار گرفت.'],202);
    }

    public function show(TunnelConnectionTest $tunnelTest)
    {
        $op=$tunnelTest->operations()->where('action','cdn-test')->latest()->firstOrFail();
        return response()->json(['data'=>$tunnelTest,'operation'=>$op])->header('Cache-Control','no-store');
    }
}
