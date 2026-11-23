<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\{WireGuardInstance,WireGuardPeer};
use App\Services\{PanelAccountOwnership,WireGuardService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class WireGuardAccountController extends Controller {
    private function account(Request $r,int $id): object {
        $q=DB::table('v2_user')->where('id',$id)->where('service_type','wireguard');
        if((int)$r->user()->role!==1) {abort_if(trim((string)$r->user()->code)==='',403);PanelAccountOwnership::scope($q,(string)$r->user()->code);}
        $u=$q->first();abort_unless($u,404);return $u;
    }
    public function index(Request $r,int $id) {
        $u=$this->account($r,$id);
        $service=app(WireGuardService::class);
        $peers=$service->profiles($u)->map(function($peer)use($service,$u){
            return ['id'=>$peer->id,'name'=>'دستگاه '.$service->profileNumber($peer),'enabled'=>true,'filename'=>$service->filename($u,$peer)];
        });
        return response()->json(['data'=>$peers,'service_type'=>$u->service_type])->header('Cache-Control','no-store');
    }
    public function create(Request $r,int $id) {
        $u=$this->account($r,$id);$d=$r->validate(['name'=>'required|string|max:80']);
        $p=DB::transaction(function()use($u,$d){$i=WireGuardInstance::whereKey($u->wireguard_instance_id)->lockForUpdate()->firstOrFail();return app(WireGuardService::class)->newPeer($i,$u,$d['name']);});
        \App\Jobs\SyncWireGuard::dispatch($p->instance_id);
        return response()->json(['data'=>$p]);
    }
    public function update(Request $r,int $id,int $peer) {
        $this->account($r,$id);$d=$r->validate(['enabled'=>'required|boolean']);
        $p=DB::transaction(function()use($id,$peer,$d){
            $p=WireGuardPeer::where('user_id',$id)->findOrFail($peer);
            WireGuardInstance::whereKey($p->instance_id)->lockForUpdate()->firstOrFail();
            $u=DB::table('v2_user')->find($id);
            if($d['enabled'] && !$p->enabled)abort_if(WireGuardPeer::where('user_id',$id)->where('enabled',true)->count()>=max(1,min(16,(int)($u->device_limit?:1))),422,'ظرفیت پروفایل‌های فعال تکمیل است.');
            $p->update($d);return $p;
        });\App\Jobs\SyncWireGuard::dispatch($p->instance_id);
        return response()->json(['data'=>$p]);
    }
    public function config(Request $r,int $id,int $peer) {
        $u=$this->account($r,$id);$p=WireGuardPeer::where('user_id',$id)->findOrFail($peer);
        return response(app(WireGuardService::class)->config($u,$p))->header('Content-Type','application/x-wireguard-profile')->header('Content-Disposition','attachment; filename="'.app(WireGuardService::class)->filename($u,$p).'"')->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');
    }
}
