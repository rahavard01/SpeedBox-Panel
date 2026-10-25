<?php
namespace App\Services;

use App\Models\{ManagedServer,ServerTunnel};
use App\Support\NetworkTunnelRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NetworkTunnelManager
{
    public function defaults(): array
    {
        return ['engine'=>'gost','name'=>'','iran_server_id'=>null,'foreign_server_id'=>null,'public_host'=>'','peer_host'=>'','local_endpoint'=>'',
            'version'=>'3.0.0','transport'=>'tcp','protocol'=>'tcp','tunnel_port'=>8443,
            'mappings'=>[['public_port'=>443,'target_port'=>443]],'mtu'=>1400,'ttl'=>64,'gre_key'=>0,
            'inner_iran'=>'','inner_foreign'=>'','addresses'=>[],'bind_device'=>'','tos'=>0,'tx_queue'=>1000,
            'checksum'=>false,'sequence'=>false,'pmtud'=>true,'ignore_df'=>false,'hop_limit'=>64,'encap_limit'=>4,'flow_label'=>0,
            'nat'=>'masquerade','snat_address'=>'','balance'=>'round_robin','allowed_sources'=>[],
            'max_connections'=>0,'connection_rate'=>0,'clamp_mss'=>true,'proxy_protocol'=>false,
            'monitor'=>true,'monitor_interval'=>15,'monitor_port'=>null,'failover'=>false,'failure_threshold'=>3,'recovery_threshold'=>2,'timeout'=>3,
            'keepalive'=>true,'restart_hours'=>0,'quota_gb'=>0,'quota_period'=>'monthly','quota_direction'=>'both','quota_mode'=>'warn','persistence'=>'systemd'];
    }

    public function prepare(Request $request, ?ServerTunnel $existing=null): array
    {
        $alerts = app(TunnelAlertSettings::class)->resolve($request,$existing);
        $wat=$request->input('engine')==='wattunnel';
        $request->replace([...($wat?\App\Support\WatTunnelRules::defaults():$this->defaults()),...$request->all()]);
        $request->validate([
            'engine'=>['required',\Illuminate\Validation\Rule::in(['gost','gre','wattunnel'])],
            'iran_server_id'=>'required|integer|different:foreign_server_id|exists:speedbox_managed_servers,id',
            'foreign_server_id'=>'required|integer|exists:speedbox_managed_servers,id',
            'address_source'=>['sometimes',\Illuminate\Validation\Rule::in(['servers','custom'])],
            'ip_family'=>['nullable',\Illuminate\Validation\Rule::in(['ipv4','ipv6'])],
        ]);
        $ids=[(int)$request->input('iran_server_id'),(int)$request->input('foreign_server_id')];sort($ids);
        $servers=ManagedServer::whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($request->input('address_source')==='servers') {
            $family=$request->input('ip_family') ?: 'ipv4';
            $iran=$servers[(int)$request->input('iran_server_id')];
            $foreign=$servers[(int)$request->input('foreign_server_id')];
            $values=['ip_family'=>$family,'public_host'=>$wat&&$request->filled('public_host')?$request->input('public_host'):trim($iran->host),
                'peer_host'=>$this->serverAddress($foreign,$family,'foreign_server_id')];
            if (in_array($request->input('engine'),['gre','wattunnel'],true)) {
                $values['local_endpoint']=$this->serverAddress($iran,$family,'iran_server_id');
            }
            $request->merge($values);
        }
        $data=$wat?\App\Support\WatTunnelRules::normalize($request,$existing):NetworkTunnelRules::normalize($request,$existing);
        if($wat && $existing)$data['metrics_port']=(int)$existing->subnet;
        if($wat)$data['source_identity']=(bool)$servers[(int)$data['foreign_server_id']]->machine_id;
        $data['telegram_alerts']=$alerts;
        $data['enabled']=$existing?(bool)($existing->settings['enabled']??true):true;
        $this->assertPorts($data,(int)$data['iran_server_id'],(int)$data['foreign_server_id'],$existing?->id);
        return $data;
    }

    /** Resolve only the registered host; never guess another address for an IP. */
    public function serverAddress(ManagedServer $server,string $family,string $field): string
    {
        $host=trim($server->host);
        $flag=$family==='ipv6'?FILTER_FLAG_IPV6:FILTER_FLAG_IPV4;
        if (filter_var($host,FILTER_VALIDATE_IP,$flag)) return $host;
        $label=$family==='ipv6'?'IPv6':'IPv4';
        if (!filter_var($host,FILTER_VALIDATE_IP) && filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)) {
            foreach ($this->addressRecords($host,$family) as $record) {
                $value=$record[$family==='ipv6'?'ipv6':'ip']??'';
                if (filter_var($value,FILTER_VALIDATE_IP,$flag)) return $value;
            }
        }
        NetworkTunnelRules::fail($field,'برای سرور «'.$server->name.'» آدرس '.$label.' در دسترس نیست؛ آدرس همین سرور را در مدیریت سرورها بررسی کنید.');
    }

    protected function addressRecords(string $host,string $family): array
    {
        return @dns_get_record($host,$family==='ipv6'?DNS_AAAA:DNS_A) ?: [];
    }

    /** Logical reservations supplement the actual listener check on each host. */
    public function assertPorts(array $settings,int $iran,int $foreign,?int $except=null): void
    {
        $wanted=[];
        if(($settings['engine']??'')==='wattunnel')\App\Services\WatTunnelPorts::assertAvailable($settings,$iran,$foreign,$except);
        foreach($settings['mappings'] as $map) $wanted=[...$wanted,...range((int)$map['public_port'],(int)($map['public_end']??$map['public_port']))];
        foreach(ManagedServer::whereIn('id',[$iran,$foreign])->get() as $server) {
            if($server->id===$iran && in_array((int)$server->ssh_port,$wanted,true)) NetworkTunnelRules::fail('mappings','پورت SSH سرور ایران قابل استفاده نیست.');
        }
        $all=ServerTunnel::where('status','!=','deleted')->when($except,fn($q)=>$q->whereKeyNot($except))->lockForUpdate()->get();
        foreach($all as $other) {
            if($other->engine==='wattunnel' && in_array((int)$other->subnet,$wanted,true) && in_array($iran,[(int)$other->iran_server_id,(int)$other->foreign_server_id],true))NetworkTunnelRules::fail('mappings','پورت برای پایش WatTunnel رزرو است.');
            if($other->engine==='wattunnel' && \App\Services\WatTunnelPorts::listener($other->settings)===$iran && in_array((int)$other->settings['tunnel_port'],$wanted,true) && $other->transport!=='icmp')NetworkTunnelRules::fail('mappings','پورت ورودی با ارتباط WatTunnel دیگری تداخل دارد.');
            if($other->engine==='gost'&&$other->transport==='grpc'&&(int)$other->foreign_server_id===$iran&&in_array((int)($other->settings['tunnel_port']??0),$wanted,true))NetworkTunnelRules::fail('mappings','ورودی با پورت ارتباط GOST دیگری تداخل دارد.');
            if($other->engine==='backpack'){
                $listener=($other->kind==='reverse'||$other->orientation==='swapped')?(int)$other->iran_server_id:(int)$other->foreign_server_id;
                if($listener===$iran&&in_array((int)($other->settings['tunnel_port']??0),$wanted,true))NetworkTunnelRules::fail('mappings','ورودی با پورت ارتباط بک‌پک تداخل دارد.');
            }
            if((int)$other->iran_server_id!==$iran)continue;
            foreach($other->settings['mappings']??[] as $map) {
                $occupied=range((int)$map['public_port'],(int)($map['public_end']??$map['public_port']));
                if(array_intersect($wanted,$occupied)) NetworkTunnelRules::fail('mappings','پورت ورودی در اتصال «'.$other->name.'» استفاده می‌شود.');
            }
            if(($other->engine==='backpack'&&($other->kind==='reverse'||$other->orientation==='swapped')) && in_array((int)($other->settings['tunnel_port']??0),$wanted,true)) NetworkTunnelRules::fail('mappings','پورت ورودی با پورت ارتباط بک‌پک تداخل دارد.');
        }
        if(($settings['engine']??null)==='gost'&&($settings['transport']??null)==='grpc') {
            $port=(int)$settings['tunnel_port'];
            $server=ManagedServer::findOrFail($foreign);
            if($port===(int)$server->ssh_port) NetworkTunnelRules::fail('tunnel_port','پورت SSH خارج قابل استفاده نیست.');
            foreach($all as $other) {
                if($other->engine==='wattunnel'&&(((int)$other->iran_server_id===$foreign||(int)$other->foreign_server_id===$foreign)&&(int)$other->subnet===$port||\App\Services\WatTunnelPorts::listener($other->settings)===$foreign&&$other->transport!=='icmp'&&(int)$other->settings['tunnel_port']===$port))NetworkTunnelRules::fail('tunnel_port','پورت با WatTunnel دیگری تداخل دارد.');
                if($other->engine==='backpack'){ $listener=($other->kind==='reverse'||$other->orientation==='swapped')?(int)$other->iran_server_id:(int)$other->foreign_server_id; if($listener===$foreign&&(int)($other->settings['tunnel_port']??0)===$port)NetworkTunnelRules::fail('tunnel_port','پورت ارتباط با بک‌پک تداخل دارد.');}
                if((int)$other->foreign_server_id===$foreign&&$other->engine==='gost'&&$other->transport==='grpc'&&(int)($other->settings['tunnel_port']??0)===$port) NetworkTunnelRules::fail('tunnel_port','این پورت ارتباط روی خارج رزرو است.');
                if((int)$other->iran_server_id===$foreign)foreach($other->settings['mappings']??[] as $map)if($port>=$map['public_port']&&$port<=($map['public_end']??$map['public_port'])) NetworkTunnelRules::fail('tunnel_port','پورت ارتباط با ورودی اتصال دیگری تداخل دارد.');
            }
        }
    }

    public function allocate(array $data, int $subnet): array
    {
        if($data['engine']==='wattunnel'){$data['metrics_port']=$subnet;return $data;}
        if($data['engine']!=='gre')return $data;
        if(!$data['inner_iran']&&!$data['inner_foreign']) {
            $base=ip2long('10.224.0.0')+$subnet*4;
            $data['inner_iran']=long2ip($base+1).'/30';$data['inner_foreign']=long2ip($base+2).'/30';
        }
        foreach(ServerTunnel::where('engine','gre')->where('status','!=','deleted')->get() as $other) {
            if(($other->settings['inner_iran']??'')===$data['inner_iran'] && (int)($data['id']??0)!==$other->id) NetworkTunnelRules::fail('inner_iran','این آدرس داخلی به تونل دیگری اختصاص دارد.');
        }
        return $data;
    }

    public function config(ServerTunnel $tunnel,string $side,?array $settings=null): array
    {
        $settings=$settings??$tunnel->settings;
        return ['id'=>$tunnel->remote_name,'engine'=>$tunnel->engine,'side'=>$side,'secret'=>$tunnel->secret,
            'origin_ports'=>$tunnel->engine==='wattunnel'?TrustedTunnelOrigin::ports($settings):[],
            'enabled'=>($settings['enabled']??true),'subnet'=>(int)$tunnel->subnet,'settings'=>$settings];
    }

    public function export(ServerTunnel $tunnel): array
    {
        return ['format'=>'speedbox-network-v1','engine'=>$tunnel->engine,'name'=>$tunnel->name,
            'settings'=>array_diff_key($tunnel->settings,array_flip(['enabled']))];
    }

    public function saveSnapshot(ServerTunnel $tunnel,array $snapshots): void
    {
        $snapshots['_checked_at']=now()->timestamp;
        $iran=$snapshots['iran']??[];$foreign=$snapshots['foreign']??[];
        $status=!($tunnel->settings['enabled']??true)?'stopped':
            ((empty($iran['active'])||((in_array($tunnel->engine,['gre','wattunnel'],true)||$tunnel->transport==='grpc')&&empty($foreign['active'])))?'offline':
                (($iran['connected']??null)===true?'connected':'degraded'));
        $message=match($status){'connected'=>'سرویس و دسترسی TCP مقصد بررسی شد؛ اتصال پروتکل نود را با کلاینت هم آزمایش کنید.',
            'stopped'=>'اتصال متوقف است.','offline'=>'سرویس یکی از سمت‌ها فعال نیست.',default=>'سرویس بررسی شد؛ ارتباط کامل هنوز تأیید نشده است.'};
        if (isset($iran['error'])||isset($foreign['error'])) {$status='needs_review';$message='دسترسی به یکی از سرورها تأیید نشد.';}
        if($tunnel->engine==='wattunnel'&&$status==='connected')$message='ارتباط رمز‌شدهٔ دو سرور برقرار است؛ دسترسی سرویس مقصد را با کلاینت هم آزمایش کنید.';
        if($tunnel->engine==='gre' && (int)($tunnel->settings['quota_gb']??0)>0 && (!empty($iran['quota_exhausted'])||!empty($foreign['quota_exhausted']))) {
            $stopped=($tunnel->settings['quota_mode']??'warn')==='enforce';
            if(!$stopped && $status==='connected')$status='degraded';
            $quotaMessage=$stopped?'سقف مصرف ترافیک پر شده است؛ توقف تونل درخواست شده است.':'سقف مصرف ترافیک پر شده است؛ این هشدار در پنل نمایش داده می‌شود و تونل را خودکار متوقف نمی‌کند.';
            $message=in_array($status,['offline','stopped'],true)?$message.' '.$quotaMessage:$quotaMessage;
        }
        $tunnel->update(['remote_status'=>$snapshots,'status'=>$status,'message'=>$message]);
        DB::table('speedbox_network_samples')->insert(['tunnel_id'=>$tunnel->id,'taken_at'=>time(),'snapshot'=>json_encode($snapshots,JSON_THROW_ON_ERROR)]);
        DB::table('speedbox_network_samples')->where('tunnel_id',$tunnel->id)->where('taken_at','<',time()-31*86400)->delete();
    }
}

