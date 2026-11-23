<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\{WireGuardInstance,ManagedServer};
use App\Services\WireGuardService;
use App\Jobs\SyncWireGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Cache};
final class WireGuardController extends Controller {
    public function index() {
        return response()->json(['data'=>WireGuardInstance::with(['server:id,name,host','ingress:id,name,host'])->withCount('peers')->orderBy('id')->get(),
            'servers'=>ManagedServer::orderBy('id')->get(['id','name','host'])])->header('Cache-Control','no-store');
    }
    private function cidr(?string $value,int $prefix):void {
        if(!$value)return;
        $parts=explode('/',$value);
        abort_unless(count($parts)===2 && (int)$parts[1]===$prefix && filter_var($parts[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4),422,'شبکه داخلی معتبر نیست.');
        $n=ip2long($parts[0]);$mask=~((1<<(32-$prefix))-1);
        $private=($n&0xff000000)===ip2long('10.0.0.0') || ($n&0xfff00000)===ip2long('172.16.0.0') || ($n&0xffff0000)===ip2long('192.168.0.0');
        abort_unless($private && ($n&$mask)===$n,422,'یک شبکه خصوصی معتبر با پیشوند /'.$prefix.' وارد کنید.');
    }
    public function save(Request $r,?int $instance=null) {
        $data=$r->validate(['name'=>'required|string|max:100','managed_server_id'=>'required|integer|exists:speedbox_managed_servers,id',
            'network'=>'nullable|string|max:32','mode'=>'required|in:direct,tunnel','ingress_server_id'=>'nullable|required_if:mode,tunnel|integer|different:managed_server_id|exists:speedbox_managed_servers,id',
            'link_network'=>'nullable|string|max:32','tunnel_port'=>'nullable|integer|between:1024,65535','listen_port'=>'required|integer|between:1024,65535',
            'endpoint_host'=>'nullable|string|max:253','endpoint_port'=>'required|integer|between:1024,65535','dns'=>'required|array|min:1|max:3','dns.*'=>'required|ipv4','mtu'=>'required|integer|between:1280,1420']);
        $data['network']=trim($data['network']??'');$data['link_network']=trim($data['link_network']??'');
        $this->cidr($data['network'],24);$this->cidr($data['link_network'],30);
        if($data['mode']==='tunnel') abort_if($data['mtu']>1300,422,'MTU مسیر تونلی باید بین ۱۲۸۰ و ۱۳۰۰ باشد.');
        else { $data['ingress_server_id']=null;$data['link_network']=null;$data['tunnel_port']=null;abort_unless((int)$data['endpoint_port']===(int)$data['listen_port'],422,'پورت ورودی و خروجی در حالت مستقیم باید یکسان باشند.'); }
        $data['endpoint_host']=trim($data['endpoint_host']??'');
        if($data['endpoint_host']!=='')abort_unless(filter_var($data['endpoint_host'],FILTER_VALIDATE_IP) || filter_var($data['endpoint_host'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME),422,'آدرس ورودی معتبر نیست.');
        $lock=$instance?Cache::lock('wireguard:instance:'.$instance,20):null;
        if($lock)abort_unless($lock->get(),422,'عملیات این سرویس در حال اجراست؛ کمی صبر کنید.');
        try{$i=DB::transaction(function()use($instance,$data){
            $ids=array_filter([$data['managed_server_id'],$data['ingress_server_id']??null]);sort($ids);
            $hosts=ManagedServer::whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $i=$instance?WireGuardInstance::whereKey($instance)->lockForUpdate()->firstOrFail():new WireGuardInstance;
            abort_if(in_array($i->status,['queued','sync_queued','deleting','delete_failed'],true),422,'عملیات این سرویس در جریان است یا حذف آن باید تکمیل شود.');
            if($i->public_key || $i->site_keys)foreach(['managed_server_id','ingress_server_id','mode'] as $field)abort_if((string)$i->$field!==(string)($data[$field]??''),422,'برای تغییر مسیر یا سرورها، سرویس WireGuard جدید بسازید.');
            $data['auto_network']=$data['network']==='';
            if($data['auto_network'])$data['network']=$i->network?:app(WireGuardService::class)->freeNetwork((int)$data['managed_server_id']);
            $data['auto_link_network']=$data['mode']==='tunnel' && empty($data['link_network']);
            if($data['auto_link_network'])$data['link_network']=$i->link_network?:app(WireGuardService::class)->freeLinkNetwork($ids);
            if($i->public_key)foreach(['network','listen_port','mtu','link_network','tunnel_port'] as $field){
                if($field==='tunnel_port' && empty($data[$field]))$data[$field]=$i->$field;
                abort_if((string)$i->$field!==(string)($data[$field]??''),422,'برای تغییر شبکه، پورت یا MTU سرویس فعال، سرویس جدید بسازید.');
            }
            if($data['mode']==='tunnel' && empty($data['tunnel_port']))$data['tunnel_port']=$i->tunnel_port?:app(WireGuardService::class)->freeLinkPort($ids,[$data['listen_port'],$data['endpoint_port']]);
            $entry=$hosts[$data['mode']==='tunnel'?$data['ingress_server_id']:$data['managed_server_id']];
            if($data['endpoint_host']==='')$data['endpoint_host']=$entry->host;
            abort_if(WireGuardInstance::where('managed_server_id',$data['managed_server_id'])->when($instance,fn($q)=>$q->where('id','!=',$instance))->where(fn($q)=>$q->where('listen_port',$data['listen_port'])->orWhere('network',$data['network']))->exists(),422,'پورت یا شبکه روی سرور خروجی در حال استفاده است.');
            if($data['mode']==='tunnel'){
                abort_if(in_array((int)$data['tunnel_port'],[(int)$data['listen_port'],(int)$data['endpoint_port']],true),422,'پورت ارتباط دو سرور باید با پورت کاربران متفاوت باشد.');
                abort_if(WireGuardInstance::where('ingress_server_id',$data['ingress_server_id'])->where('mode','tunnel')->when($instance,fn($q)=>$q->where('id','!=',$instance))->where('endpoint_port',$data['endpoint_port'])->exists(),422,'پورت ورودی قبلاً استفاده شده است.');
            }
            $ports=app(WireGuardService::class);
            abort_if(in_array((int)$data['listen_port'],$ports->reservedPorts((int)$data['managed_server_id'],$instance),true),422,'پورت خروجی روی این سرور قبلاً رزرو شده است.');
            if($data['mode']==='tunnel'){
                abort_if(in_array((int)$data['endpoint_port'],$ports->reservedPorts((int)$data['ingress_server_id'],$instance),true),422,'پورت ورودی روی این سرور قبلاً رزرو شده است.');
                foreach($ids as $hostId)abort_if(in_array((int)$data['tunnel_port'],$ports->reservedPorts((int)$hostId,$instance),true),422,'پورت ارتباط دو سرور قبلاً رزرو شده است.');
            }
            app(WireGuardService::class)->validateTunnel($data);
            $data['tunnel_id']=null;$i->fill($data);
            if(!$instance)$i->interface='sbw'.random_int(10000000000,99999999999);
            $i->save();return $i;
        });}finally{$lock?->release();}
        if($i->public_key)SyncWireGuard::dispatch($i->id);
        return response()->json(['data'=>$i,'message'=>'سرویس WireGuard ذخیره شد.']);
    }
    public function destroy(int $instance) {
        $lock=Cache::lock('wireguard:instance:'.$instance,20);abort_unless($lock->get(),422,'عملیات این سرویس در حال اجراست؛ کمی صبر کنید.');
        try{$queued=DB::transaction(function()use($instance){
            $i=WireGuardInstance::whereKey($instance)->lockForUpdate()->firstOrFail();
            abort_if(in_array($i->status,['queued','sync_queued','deleting'],true),422,'عملیات این سرویس در صف است؛ کمی صبر کنید.');
            app(WireGuardService::class)->assertUnused($i);
            if($i->status==='draft' && !$i->public_key && !$i->site_keys){$i->delete();return false;}
            $i->update(['enabled'=>false,'status'=>'deleting','last_error'=>null]);return true;
        });}finally{$lock->release();}
        if($queued)\App\Jobs\DeleteWireGuard::dispatch($instance);
        return response()->json(['queued'=>$queued,'message'=>$queued?'حذف سرویس در صف قرار گرفت.':'سرویس حذف شد.']);
    }
    public function action(Request $r,int $instance) {
        $d=$r->validate(['action'=>'required|in:enable,disable,sync']);$lock=Cache::lock('wireguard:instance:'.$instance,20);abort_unless($lock->get(),422,'عملیات این سرویس در حال اجراست؛ کمی صبر کنید.');
        try{$i=WireGuardInstance::findOrFail($instance);abort_if(in_array($i->status,['queued','sync_queued','deleting','delete_failed'],true),422,'عملیات این سرویس در جریان است یا حذف آن باید تکمیل شود.');
        if($d['action']==='enable'){abort_unless(extension_loaded('sodium'),422,'افزونه sodium در PHP فعال نیست.');app(WireGuardService::class)->validateTunnel($i->getAttributes());$i->update(['enabled'=>true,'status'=>'queued']);}
        elseif($d['action']==='disable'){$i->update(['enabled'=>false,'status'=>($i->public_key||$i->site_keys)?'queued':'draft']);if(!$i->public_key&&!$i->site_keys)return response()->json(['queued'=>false,'message'=>'سرویس غیرفعال است.']);}
        abort_if(!$i->public_key && $d['action']==='sync',422,'ابتدا نصب و فعال‌سازی را اجرا کنید.');
        if($d['action']==='sync')$i->update(['status'=>'sync_queued','last_error'=>null]);
        }finally{$lock->release();}
        SyncWireGuard::dispatch($i->id,!$i->public_key);return response()->json(['queued'=>true,'message'=>'عملیات در صف قرار گرفت.']);
    }
}
