<?php
namespace App\Support;

use App\Models\ServerTunnel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** WatTunnel has its own schema; GRE/GOST settings never become engine flags. */
final class WatTunnelRules
{
    public const VERSION = '2.10.9-sb3';
    public const CARRIERS = ['icmp','udp','tcp','tls','ws','wss','h2','h2c','grpc'];

    public static function defaults(): array
    {
        return ['engine'=>'wattunnel','version'=>self::VERSION,'name'=>'','iran_server_id'=>null,'foreign_server_id'=>null,
            'public_host'=>'','peer_host'=>'','local_endpoint'=>'','address_source'=>'servers','ip_family'=>'ipv4',
            'transport'=>'icmp','reverse'=>false,'protocol'=>'both','tunnel_port'=>4443,
            'mappings'=>[['public_port'=>443,'target_port'=>443,'public_end'=>443,'target_end'=>443]],
            'mtu'=>0,'conns'=>8,'sessions'=>1,'lanes'=>4,'icmp_type'=>'echo','fec'=>'off','max_mbps'=>0,
            'tcp_cc'=>'system','path'=>'/wat','sni'=>'','tls_pin'=>'','fingerprint'=>'go','host_header'=>'',
            'tls_small_ch'=>false,'tls_frag'=>0,'hedge'=>false,'ping_every'=>2,'udp_idle'=>60,
            'monitor'=>true,'monitor_interval'=>15,'restart_hours'=>0];
    }

    public static function normalize(Request $request, ?ServerTunnel $existing=null): array
    {
        if(in_array($request->input('version'),['2.10.9-sb1','2.10.9-sb2'],true))$request->merge(['version'=>self::VERSION]);
        $request->replace([...self::defaults(),...$request->all()]);
        $d=$request->validate([
            'engine'=>['required',Rule::in(['wattunnel'])],'version'=>['required',Rule::in([self::VERSION])],
            'name'=>'required|string|max:100','iran_server_id'=>'required|integer|different:foreign_server_id|exists:speedbox_managed_servers,id',
            'foreign_server_id'=>'required|integer|exists:speedbox_managed_servers,id','public_host'=>'required|string|max:253',
            'peer_host'=>'required|string|max:64','local_endpoint'=>'required|string|max:64',
            'address_source'=>['required',Rule::in(['servers'])],'ip_family'=>['required',Rule::in(['ipv4','ipv6'])],
            'transport'=>['required',Rule::in(self::CARRIERS)],'reverse'=>'required|boolean',
            'protocol'=>['required',Rule::in(['tcp','udp','both'])],'tunnel_port'=>'required|integer|between:1,65535',
            'mappings'=>'required|array|min:1|max:40','mappings.*.public_port'=>'required|integer|between:1,65535',
            'mappings.*.target_port'=>'required|integer|between:1,65535','mappings.*.public_end'=>'nullable|integer|between:1,65535',
            'mappings.*.target_end'=>'nullable|integer|between:1,65535',
            'mtu'=>'required|integer|between:0,16384','conns'=>'required|integer|between:1,64','sessions'=>'required|integer|between:1,16',
            'lanes'=>'required|integer|between:1,16','icmp_type'=>['required',Rule::in(['echo','timestamp','info','mask'])],
            'fec'=>['required',Rule::in(['off','auto'])],'max_mbps'=>'required|numeric|between:0,1250',
            'tcp_cc'=>['required',Rule::in(['system','bbr','cubic'])],'path'=>['required','string','max:120','regex:~^/[A-Za-z0-9_/-]+$~D'],
            'sni'=>'nullable|string|max:253','tls_pin'=>['nullable','string','regex:/^[a-fA-F0-9]{64}$/D'],
            'fingerprint'=>['required',Rule::in(['go','chrome','firefox','safari','edge','random'])],'host_header'=>'nullable|string|max:253',
            'tls_small_ch'=>'required|boolean','tls_frag'=>'required|integer|between:0,16384','hedge'=>'required|boolean',
            'ping_every'=>'required|integer|between:1,300','udp_idle'=>'required|integer|between:5,3600',
            'monitor'=>'required|boolean','monitor_interval'=>'required|integer|between:5,300','restart_hours'=>'required|integer|between:0,168',
        ]);
        if($existing && ($existing->engine!=='wattunnel'||(int)$existing->iran_server_id!==(int)$d['iran_server_id']||(int)$existing->foreign_server_id!==(int)$d['foreign_server_id']))NetworkTunnelRules::fail('engine','نوع موتور و دو سرور در ویرایش قابل تغییر نیستند.');
        $flag=$d['ip_family']==='ipv6'?FILTER_FLAG_IPV6:FILTER_FLAG_IPV4;
        foreach(['peer_host','local_endpoint'] as $k)if(!filter_var($d[$k],FILTER_VALIDATE_IP,$flag))NetworkTunnelRules::fail($k,'خانوادهٔ آدرس با روش انتخاب‌شده سازگار نیست.');
        $d['public_host']=NetworkTunnelRules::address($d['public_host'],'public_host',true);
        foreach(['sni','host_header'] as $k){$d[$k]=trim($d[$k]??'');if($d[$k]!==''&&!filter_var($d[$k],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))NetworkTunnelRules::fail($k,'یک نام دامنهٔ معتبر وارد کنید.');}
        $d['tls_pin']=strtolower($d['tls_pin']??'');
        if($d['reverse']&&$d['transport']==='udp')NetworkTunnelRules::fail('reverse','حالت معکوس برای حامل UDP پشتیبانی نمی‌شود.');
        if($d['sessions']>1&&($d['reverse']||$d['transport']==='icmp'))NetworkTunnelRules::fail('sessions','در حالت معکوس یا ICMP تعداد نشست باید یک باشد.');
        if($d['transport']==='icmp'&&$d['ip_family']!=='ipv4')NetworkTunnelRules::fail('ip_family','حامل ICMP این نسخه فقط IPv4 را پشتیبانی می‌کند.');
        $limit=in_array($d['transport'],['icmp','udp'],true)?1400:16384;
        if((int)$d['mtu']!==0&&($d['mtu']<256||$d['mtu']>$limit))NetworkTunnelRules::fail('mtu','MTU باید صفر (خودکار) یا بین ۲۵۶ و '.$limit.' باشد.');
        $used=[];$count=0;
        foreach($d['mappings'] as &$m){
            foreach(['public_port','target_port'] as $k)$m[$k]=(int)$m[$k];
            $m['public_end']=(int)($m['public_end']??$m['public_port']);$m['target_end']=(int)($m['target_end']??$m['target_port']);
            $n=$m['public_end']-$m['public_port'];
            if($n<0||$m['target_end']-$m['target_port']!==$n||($count+=$n+1)>256)NetworkTunnelRules::fail('mappings','بازه‌های پورت باید هم‌اندازه و صعودی باشند؛ حداکثر ۲۵۶ پورت.');
            foreach(range($m['public_port'],$m['public_end']) as $p){if(isset($used[$p]))NetworkTunnelRules::fail('mappings','پورت ورودی تکراری است.');$used[$p]=true;}
        }unset($m);
        if($d['reverse']&&$d['transport']!=='icmp'&&isset($used[$d['tunnel_port']]))NetworkTunnelRules::fail('tunnel_port','پورت ارتباط و ورودی کاربران باید متفاوت باشند.');
        foreach(['iran_server_id','foreign_server_id','tunnel_port','mtu','conns','sessions','lanes','tls_frag','ping_every','udp_idle','monitor_interval','restart_hours'] as $k)$d[$k]=(int)$d[$k];
        foreach(['reverse','tls_small_ch','hedge','monitor'] as $k)$d[$k]=(bool)$d[$k];
        $d['max_mbps']=(float)$d['max_mbps'];
        return $d;
    }
}


