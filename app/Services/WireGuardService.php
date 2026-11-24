<?php
namespace App\Services;
use App\Models\{WireGuardInstance,WireGuardPeer,ManagedServer,VpnOperation};
use Illuminate\Support\Facades\{DB,Cache};
final class WireGuardService {
    public static function keyPair(): array {
        $private=random_bytes(32); $private[0]=chr(ord($private[0])&248); $private[31]=chr((ord($private[31])&127)|64);
        return [base64_encode($private),base64_encode(sodium_crypto_scalarmult_base($private))];
    }
    public function provision(object $user): WireGuardPeer {
        return DB::transaction(function() use($user) {
            // Renewals lock the account first; use the same lock order everywhere.
            $user=DB::table('v2_user')->where('id',$user->id)->lockForUpdate()->first();
            abort_unless($user && SubscriptionServiceType::of($user)==='wireguard',404);
            $i=WireGuardInstance::whereKey($user->wireguard_instance_id)->lockForUpdate()->firstOrFail();
            $rows=WireGuardPeer::where('user_id',$user->id)->where('instance_id',$i->id)->orderBy('id')->get();
            $limit=max(1,min(16,(int)($user->device_limit?:1)));
            $changed=false;
            foreach($rows as $n=>$peer) {
                $enabled=$n<$limit;
                if((bool)$peer->enabled!==$enabled){$peer->update(['enabled'=>$enabled]);$changed=true;}
            }
            while($rows->count()<$limit) {
                $rows->push($this->newPeer($i,$user,'دستگاه '.($rows->count()+1)));
                $changed=true;
            }
            if($changed) \App\Jobs\SyncWireGuard::dispatch((int)$i->id)->afterCommit();
            return $rows->first();
        },3);
    }
    public function profiles(object $user): \Illuminate\Support\Collection {
        $this->provision($user);
        return WireGuardPeer::where('user_id',$user->id)->where('instance_id',$user->wireguard_instance_id)
            ->where('enabled',true)->orderBy('id')->get();
    }
    public function profileNumber(WireGuardPeer $peer): int {
        return WireGuardPeer::where('user_id',$peer->user_id)->where('instance_id',$peer->instance_id)->where('id','<=',$peer->id)->count();
    }
    public function filename(object $user,WireGuardPeer $peer): string {
        $name=str_replace(['@','.com'],['-',''],(string)$user->email);
        $name=trim(preg_replace('/[^A-Za-z0-9_=+.-]/','-',$name),'.-');
        // Keep the tunnel name compatible with clients using a 15-character interface name.
        return substr($name!==''?$name:'user',0,12).'-'.$this->profileNumber($peer).'.conf';
    }
    public function newPeer(WireGuardInstance $i,object $user,string $name): WireGuardPeer {
        // Caller locks the instance, serializing address allocation and account peer caps.
        abort_if(in_array($i->status,['deleting','delete_failed'],true),422,'این سرویس در حال حذف است.');
        $count=WireGuardPeer::where('user_id',$user->id)->where('enabled',true)->count();
        $limit=max(1,min(16,(int)($user->device_limit?:1)));
        abort_if($count >= $limit,422,'ظرفیت پروفایل‌های این حساب تکمیل است.');
        $used=WireGuardPeer::where('instance_id',$i->id)->pluck('address')->all();
        abort_if(count($used)>=250,422,'ظرفیت ۲۵۰ پروفایل این سرویس تکمیل است.');
        $base=ip2long(explode('/',$i->network)[0]); $address=null;
        for($n=2;$n<=254;$n++) if(!in_array(long2ip($base+$n),$used,true)) {$address=long2ip($base+$n);break;}
        abort_unless($address,422,'ظرفیت آدرس‌های سرور WireGuard تکمیل است.');
        [$private,$public]=self::keyPair();
        return WireGuardPeer::create(['instance_id'=>$i->id,'user_id'=>$user->id,'name'=>$name,'address'=>$address,'private_key'=>$private,'public_key'=>$public,'preshared_key'=>base64_encode(random_bytes(32))]);
    }
    public function clientCandidates():array {
        $result=[];foreach(['10.87','172.29','192.168'] as $prefix)for($n=1;$n<=64;$n++)$result[]=$prefix.'.'.$n.'.0/24';return $result;
    }
    public function linkCandidates():array {
        $result=[];foreach(['10.250','172.28','192.168'] as $prefix)for($n=0;$n<32;$n++)$result[]=$prefix.'.'.($prefix==='192.168'?250:($n+1)).'.'.($prefix==='192.168'?$n*4:0).'/30';return $result;
    }
    public function freeNetwork(int $server):string {
        $used=WireGuardInstance::where('managed_server_id',$server)->pluck('network')->all();
        foreach($this->clientCandidates() as $candidate)if(!in_array($candidate,$used,true))return $candidate;
        throw new \DomainException('شبکه داخلی آزاد یافت نشد.');
    }
    public function freeLinkNetwork(array $servers):string {
        $used=WireGuardInstance::where(fn($q)=>$q->whereIn('managed_server_id',$servers)->orWhereIn('ingress_server_id',$servers))->pluck('link_network')->all();
        foreach($this->linkCandidates() as $candidate)if(!in_array($candidate,$used,true))return $candidate;
        throw new \DomainException('شبکه ارتباطی آزاد یافت نشد.');
    }
    public function freeLinkPort(array $servers,array $excluded=[]):int {
        $rows=WireGuardInstance::where(fn($q)=>$q->whereIn('managed_server_id',$servers)->orWhereIn('ingress_server_id',$servers))->get();
        foreach($rows as $row)$excluded=array_merge($excluded,[(int)$row->listen_port,(int)$row->endpoint_port,(int)$row->tunnel_port]);
        for($port=51821;$port<52000;$port++)if(!in_array($port,array_map('intval',$excluded),true))return $port;
        throw new \DomainException('پورت ارتباطی آزاد یافت نشد.');
    }
    public function reservedPorts(int $server,?int $except=null):array {
        $ports=[];
        foreach(WireGuardInstance::where(fn($q)=>$q->where('managed_server_id',$server)->orWhere('ingress_server_id',$server))->when($except,fn($q)=>$q->where('id','!=',$except))->get() as $row){
            if((int)$row->managed_server_id===$server){$ports[]=(int)$row->listen_port;if($row->mode==='tunnel')$ports[]=(int)$row->tunnel_port;}
            if($row->mode==='tunnel' && (int)$row->ingress_server_id===$server){$ports[]=(int)$row->endpoint_port;$ports[]=(int)$row->tunnel_port;}
        }
        return $ports;
    }
    public function assertUnused(WireGuardInstance $i):void {
        foreach(['v2_plan','v2_user'] as $table)abort_if(DB::table($table)->where('wireguard_instance_id',$i->id)->exists(),422,'این سرویس به پلن یا حساب متصل است و قابل حذف نیست.');
        abort_if($i->peers()->exists(),422,'این سرویس دارای سابقه پروفایل کاربران است و قابل حذف نیست.');
    }
    public function remove(int $id):void {
        $lock=Cache::lock('wireguard:instance:'.$id,1290);if(!$lock->get())return;
        try{
            $i=WireGuardInstance::find($id);if(!$i || $i->status!=='deleting')return;
            $operation = VpnOperation::start('wireguard', $id);
            $this->assertUnused($i);
            $operation?->recordProgress(25, 'حذف تنظیمات اختصاصی از سرورها.');
            // Keep the row and ownership keys until every owned host is clean.
            app(WireGuardRemote::class)->call($i,'remove',$this->policy($i));
            if($i->site_keys)foreach(['iran','foreign'] as $side)app(WireGuardRemote::class)->site($i,$side,'remove',$this->sitePolicy($i,$side));
            DB::transaction(function()use($i){$current=WireGuardInstance::whereKey($i->id)->lockForUpdate()->firstOrFail();$this->assertUnused($current);$current->delete();});
            $operation?->recordProgress(100, 'سرویس حذف شد.', 'succeeded');
        }catch(\Throwable $e){if (isset($operation)) $operation->fail($e->getMessage());WireGuardInstance::whereKey($id)->update(['status'=>'delete_failed','last_error'=>mb_substr($e->getMessage(),0,1000)]);throw $e;}
        finally{$lock->release();}
    }
    public function validateTunnel(array $data):void {
        if(($data['mode']??'direct')!=='tunnel')return;
        $iran=ManagedServer::find($data['ingress_server_id']??0);
        if(!$iran || (int)$iran->id===(int)$data['managed_server_id'])throw new \DomainException('سرور ورودی ایران و خروجی باید متفاوت باشند.');
        if(empty($data['link_network'])||empty($data['tunnel_port']))throw new \DomainException('تنظیمات مسیر اختصاصی WireGuard کامل نیست.');
        if((int)$data['mtu']>1300)throw new \DomainException('MTU مسیر تونلی حداکثر ۱۳۰۰ باشد.');
        if(filter_var($data['endpoint_host']??'',FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))throw new \DomainException('ورودی مسیر تونلی باید IPv4 یا دامنه با رکورد IPv4 باشد.');
    }
    private function sitePolicy(WireGuardInstance $i,string $side):array {
        $keys=$i->site_keys;$local=$keys[$side];$peer=$keys[$side==='iran'?'foreign':'iran'];
        return ['owner_id'=>$i->owner_id,'interface'=>'sbe'.substr($i->interface,3),'side'=>$side,'network'=>$i->link_network,
            'private_key'=>$local['private'],'public_key'=>$local['public'],'peer_key'=>$peer['public'],'preshared_key'=>$keys['psk'],
            'port'=>(int)$i->tunnel_port,'client_port'=>(int)$i->endpoint_port,'target_port'=>(int)$i->listen_port,'foreign_host'=>$i->server->host];
    }
    private function preparePath(WireGuardInstance $i):void {
        if(!$i->site_keys){
            [$a,$b]=self::keyPair();[$c,$d]=self::keyPair();
            $i->update(['site_keys'=>['iran'=>['private'=>$a,'public'=>$b],'foreign'=>['private'=>$c,'public'=>$d],'psk'=>base64_encode(random_bytes(32))]]);
        }
        $remote=app(WireGuardRemote::class);
        if(!$i->public_key && $i->auto_link_network){
            $candidates=array_values(array_unique([$i->link_network,...$this->linkCandidates()]));
            foreach(['foreign','iran'] as $side){$available=$remote->site($i,$side,'preflight',$this->sitePolicy($i,$side),$candidates);$candidates=array_values(array_intersect($candidates,$available['networks']));}
            DB::transaction(function()use($i,$candidates){
                ManagedServer::whereIn('id',[$i->managed_server_id,$i->ingress_server_id])->orderBy('id')->lockForUpdate()->get();
                $used=WireGuardInstance::where('id','!=',$i->id)->where(fn($q)=>$q->whereIn('managed_server_id',[$i->managed_server_id,$i->ingress_server_id])->orWhereIn('ingress_server_id',[$i->managed_server_id,$i->ingress_server_id]))->pluck('link_network')->all();
                foreach($candidates as $candidate)if(!in_array($candidate,$used,true)){$i->update(['link_network'=>$candidate]);return;}
                throw new \DomainException('شبکه خصوصی آزاد بین دو سرور یافت نشد.');
            });
        }
        foreach(['foreign','iran'] as $side)$remote->site($i,$side,'apply',$this->sitePolicy($i,$side));
        $remote->site($i,'iran','probe',$this->sitePolicy($i,'iran'));
    }
    private function stopPath(WireGuardInstance $i):void {
        if(!$i->site_keys)return;
        $errors=[];foreach(['iran','foreign'] as $side)try{app(WireGuardRemote::class)->site($i,$side,'disable',$this->sitePolicy($i,$side));}catch(\Throwable $e){$errors[]=$e->getMessage();}
        if($errors)throw new \RuntimeException(implode(' / ',$errors));
    }
    private function prepareNetwork(WireGuardInstance $i):void {
        if($i->public_key || !$i->auto_network)return;
        $candidates=array_values(array_unique([$i->network,...$this->clientCandidates()]));
        $report=app(WireGuardRemote::class)->call($i,'preflight',$this->policy($i),$candidates);
        DB::transaction(function()use($i,$report){
            ManagedServer::whereKey($i->managed_server_id)->lockForUpdate()->firstOrFail();
            $used=WireGuardInstance::where('managed_server_id',$i->managed_server_id)->where('id','!=',$i->id)->pluck('network')->all();
            foreach($report['networks'] as $candidate)if(!in_array($candidate,$used,true)){$i->update(['network'=>$candidate]);return;}
            throw new \DomainException('شبکه داخلی آزاد روی سرور خروجی یافت نشد.');
        });
    }
    public function policy(WireGuardInstance $i): array {
        $accounts=[]; $peers=[]; $retired=[];
        foreach(WireGuardPeer::where('instance_id',$i->id)->orderBy('id')->get()->groupBy('user_id') as $uid=>$rows) {
            $u=DB::table('v2_user')->find($uid); $remaining=$u?max(0,(int)$u->transfer_enable-(int)$u->u-(int)$u->d):0;
            $valid=$u && SubscriptionServiceType::of($u)==='wireguard' && (int)$u->wireguard_instance_id===(int)$i->id;
            $accounts[(string)$uid]=['enabled'=>$valid && !$u->banned,'expires_at'=>$u->expired_at??0,
                'ceiling'=>(int)$rows->sum(fn($p)=>(int)$p->rx_total+(int)$p->tx_total)+$remaining,'speed_mbps'=>(int)($u->speed_limit??0)];
            $cap=max(1,min(16,(int)($u->device_limit??1))); $ordinal=0;
            foreach($rows as $p) { foreach($p->retired_keys??[] as $key) $retired[$key]=(int)$uid; }
            foreach($rows as $p) $peers[]=['user_id'=>(int)$uid,'public_key'=>$p->public_key,'preshared_key'=>$p->preshared_key,'address'=>$p->address,'enabled'=>$p->enabled && ++$ordinal<=$cap];
        }
        return ['owner_id'=>$i->owner_id,'interface'=>$i->interface,'network'=>$i->network,'listen_port'=>(int)$i->listen_port,'mtu'=>(int)$i->mtu,'enabled'=>$i->enabled,'issued_at'=>time(),'accounts'=>$accounts,'peers'=>$peers,'retired'=>$retired];
    }
    public function sync(int $id,bool $install=false): void {
        $lock=Cache::lock('wireguard:instance:'.$id,1290);
        if(!$lock->get()) return;
        try {
            $i=WireGuardInstance::find($id);
            if(!$i || in_array($i->status,['deleting','delete_failed'],true))return;
            $operation = VpnOperation::start('wireguard', $id);
            $install = $install || $operation?->action === 'install';
            $operation?->recordProgress(15, 'آماده‌سازی شبکه و مسیر اتصال.');
            if($i->mode==='tunnel' && !$i->enabled && !$i->public_key){$this->stopPath($i);$i->update(['status'=>'disabled','last_error'=>null,'last_synced_at'=>now()]);$operation?->recordProgress(100, 'سرویس غیرفعال شد.', 'succeeded');return;}
            if($i->enabled){$this->prepareNetwork($i);if($i->mode==='tunnel'){$this->validateTunnel($i->getAttributes());$this->preparePath($i);}}
            // Read durable native totals, charge only their delta, then grant the
            // remaining balance anchored at those exact totals. Retry is idempotent.
            $operation?->recordProgress(35, $install ? 'نصب WireGuard روی سرور خروجی.' : 'دریافت وضعیت WireGuard از سرور خروجی.');
            $report=app(WireGuardRemote::class)->call($i,$install?'install':'status',$this->policy($i));
            $this->ingest($i,$report);
            $i->refresh();
            $operation?->recordProgress(65, 'اعمال تنظیمات سرویس و همگام‌سازی پروفایل‌ها.');
            app(WireGuardRemote::class)->call($i,'sync',$this->policy($i));
            $operation?->recordProgress(90, 'ثبت وضعیت نهایی سرویس.');
            if($i->mode==='tunnel'&&!$i->enabled)$this->stopPath($i);
            $i->update(['public_key'=>$report['public_key'],'status'=>$i->enabled?'ready':'disabled','last_error'=>null,'last_synced_at'=>now()]);
            $operation?->recordProgress(100, 'عملیات با موفقیت تکمیل شد.', 'succeeded');
        } catch(\Throwable $e) {
            if (isset($operation)) $operation->fail($e->getMessage());
            if(isset($i) && !$i->public_key){
                try{$stopped=clone $i;$stopped->enabled=false;app(WireGuardRemote::class)->call($stopped,'sync',$this->policy($stopped));}catch(\Throwable $ignored){}
                if($i->mode==='tunnel'&&$i->site_keys)try{$this->stopPath($i);}catch(\Throwable $ignored){}
            }
            $changes=['status'=>'failed','last_error'=>mb_substr($e->getMessage(),0,1000)];
            if(isset($i)&&!$i->public_key)$changes['enabled']=false;
            WireGuardInstance::whereKey($id)->update($changes);
            throw $e;
        } finally { $lock->release(); }
    }
    public function ingest(WireGuardInstance $i,array $report): void {
        DB::transaction(function() use($i,$report) {
            foreach(WireGuardPeer::where('instance_id',$i->id)->orderBy('user_id')->get()->groupBy('user_id') as $uid=>$rows) {
                $u=DB::table('v2_user')->where('id',$uid)->lockForUpdate()->first(); $rx=0;$tx=0;
                foreach($rows as $p) {
                    $s=['rx'=>0,'tx'=>0,'handshake'=>0];$found=false;
                    foreach(array_merge([$p->public_key],$p->retired_keys??[]) as $key) {
                        $part=$report['peers'][$key]??null;if(!$part)continue;$found=true;
                        $s['rx']+=(int)$part['rx'];$s['tx']+=(int)$part['tx'];
                        if($key===$p->public_key)$s['handshake']=$part['handshake']??0;
                    }
                    if(!$found)continue;
                    if((int)$s['rx']<(int)$p->rx_total || (int)$s['tx']<(int)$p->tx_total) throw new \RuntimeException('شمارنده مصرف کاهش یافته؛ همگام‌سازی متوقف شد.');
                    $rx+=(int)$s['rx']-(int)$p->rx_total; $tx+=(int)$s['tx']-(int)$p->tx_total;
                    $p->update(['rx_total'=>$s['rx'],'tx_total'=>$s['tx'],'last_handshake'=>$s['handshake']??0]);
                }
                if(!$u) continue;
                $update=['u'=>(int)$u->u+$rx,'d'=>(int)$u->d+$tx,'updated_at'=>time()];
                if($rx+$tx>0) $update['t']=time();
                DB::table('v2_user')->where('id',$uid)->update($update);
                app(SubscriptionReservationService::class)->activateIfDue((int)$uid);
            }
        });
    }
    public function config(object $user,WireGuardPeer $peer): string {
        if((int)$peer->user_id!==(int)$user->id || (int)$peer->instance_id!==(int)$user->wireguard_instance_id || SubscriptionServiceType::of($user)!=='wireguard') abort(404);
        abort_if($this->profileNumber($peer)>max(1,min(16,(int)($user->device_limit?:1))),422,'پروفایل خارج از ظرفیت فعلی حساب است.');
        abort_if($user->banned || !SubscriptionValidity::unexpired($user->expired_at) || (int)$user->u+(int)$user->d >= (int)$user->transfer_enable,403,'حساب فعال نیست.');
        $i=WireGuardInstance::findOrFail($peer->instance_id);
        abort_unless($i->enabled && $i->public_key && $peer->enabled,422,'پروفایل فعال نیست.');
        $host=str_contains($i->endpoint_host,':')?'['.$i->endpoint_host.']':$i->endpoint_host;
        return "[Interface]\nPrivateKey = {$peer->private_key}\nAddress = {$peer->address}/32\nDNS = ".implode(', ',$i->dns)."\nMTU = {$i->mtu}\n\n[Peer]\nPublicKey = {$i->public_key}\nPresharedKey = {$peer->preshared_key}\nAllowedIPs = 0.0.0.0/0, ::/0\nEndpoint = {$host}:{$i->endpoint_port}\nPersistentKeepalive = 25\n";
    }
    public function tick(): void {
        WireGuardInstance::where('status','deleting')->get(['id'])->each(fn($i)=>\App\Jobs\DeleteWireGuard::dispatch((int)$i->id));
        WireGuardInstance::whereNotIn('status',['deleting','delete_failed'])->where(fn($q)=>$q->whereIn('status',['queued','sync_queued'])->orWhere(fn($active)=>$active->whereNotNull('public_key')->where('enabled',true)))->get(['id','public_key'])->each(fn($i)=>\App\Jobs\SyncWireGuard::dispatch((int)$i->id,!$i->public_key));
    }
}
