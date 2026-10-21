<?php
namespace App\Support;

use App\Models\ServerTunnel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The web boundary and the SSH helper both validate the normalized configuration. */
final class NetworkTunnelRules
{
    public const GRE = ['gre', 'gretap', 'ip6gre', 'ip6gretap'];
    public const VERSIONS = ['2.11.5', '3.0.0'];

    public static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function address(string $value, string $field, bool $domain = false): string
    {
        $value = trim($value);
        if (!filter_var($value, FILTER_VALIDATE_IP) && !($domain && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            self::fail($field, 'یک IP'.($domain ? ' یا دامنه' : '').' معتبر وارد کنید؛ بدون پروتکل و پورت.');
        }
        return $value;
    }

    public static function cidr(string $value, string $field): string
    {
        $parts = explode('/', trim($value));
        if (count($parts) !== 2 || !ctype_digit($parts[1]) || !filter_var($parts[0], FILTER_VALIDATE_IP)) self::fail($field, 'شبکه را به شکل IP/prefix وارد کنید.');
        $max = str_contains($parts[0], ':') ? 128 : 32;
        if ((int) $parts[1] > $max) self::fail($field, 'طول پیشوند شبکه معتبر نیست.');
        return $parts[0].'/'.(int) $parts[1];
    }

    public static function normalize(Request $request, ?ServerTunnel $existing = null): array
    {
        $data = $request->validate([
            'engine' => ['required', Rule::in(['gost', 'gre'])], 'name' => 'required|string|max:100',
            'iran_server_id' => 'required|integer|different:foreign_server_id|exists:speedbox_managed_servers,id',
            'foreign_server_id' => 'required|integer|exists:speedbox_managed_servers,id',
            'public_host' => 'required|string|max:253', 'peer_host' => 'required|string|max:253',
            'address_source'=>['sometimes',Rule::in(['servers','custom'])],
            'ip_family'=>['nullable',Rule::in(['ipv4','ipv6'])],
            'local_endpoint' => 'nullable|string|max:64', 'version' => ['required', Rule::in(self::VERSIONS)],
            'transport' => ['required', Rule::in(['tcp', 'grpc', ...self::GRE])],
            'protocol' => ['required', Rule::in(['tcp', 'udp', 'both'])],
            'tunnel_port' => 'nullable|integer|between:1,65535',
            'mappings' => 'required|array|min:1|max:40', 'mappings.*.public_port' => 'required|integer|between:1,65535',
            'mappings.*.target_port' => 'required|integer|between:1,65535',
            'mappings.*.public_end' => 'nullable|integer|between:1,65535', 'mappings.*.target_end' => 'nullable|integer|between:1,65535',
            'mappings.*.destinations' => 'sometimes|array|max:16',
            'mappings.*.destinations.*.address' => 'required|string|max:253',
            'mappings.*.destinations.*.port' => 'required|integer|between:1,65535',
            'mappings.*.destinations.*.weight' => 'required|integer|between:1,100',
            'mappings.*.destinations.*.health_port' => 'nullable|integer|between:1,65535',
            'mtu' => 'required|integer|between:576,9000', 'ttl' => 'required|integer|between:0,255',
            'gre_key' => 'required|integer|min:0|max:4294967295',
            'inner_iran' => 'nullable|string|max:80', 'inner_foreign' => 'nullable|string|max:80',
            'addresses' => 'sometimes|array|max:8', 'addresses.*.iran' => 'required|string|max:80', 'addresses.*.foreign' => 'required|string|max:80',
            'bind_device' => ['nullable','string','max:15','regex:/^[a-zA-Z0-9_.:-]+$/D'],
            'tos' => 'required|integer|between:0,255', 'tx_queue' => 'required|integer|between:0,100000',
            'checksum' => 'required|boolean', 'sequence' => 'required|boolean', 'pmtud' => 'required|boolean',
            'ignore_df' => 'required|boolean', 'hop_limit' => 'required|integer|between:0,255',
            'encap_limit' => 'required|integer|between:0,255', 'flow_label' => 'required|integer|between:0,1048575',
            'nat' => ['required', Rule::in(['masquerade','snat','none'])], 'snat_address' => 'nullable|string|max:64',
            'balance' => ['required', Rule::in(['round_robin','source_hash','weighted'])],
            'allowed_sources' => 'sometimes|array|max:128', 'allowed_sources.*' => 'required|string|max:80',
            'max_connections' => 'required|integer|between:0,100000', 'connection_rate' => 'required|integer|between:0,100000',
            'clamp_mss' => 'required|boolean', 'proxy_protocol' => 'required|boolean',
            'monitor' => 'required|boolean', 'monitor_interval' => 'required|integer|between:5,300',
            'monitor_port' => 'nullable|integer|between:1,65535', 'failover' => 'required|boolean',
            'failure_threshold' => 'required|integer|between:1,20', 'recovery_threshold' => 'required|integer|between:1,20',
            'timeout' => 'required|integer|between:1,10', 'keepalive' => 'required|boolean',
            'restart_hours' => 'required|integer|between:0,168',
            'quota_gb' => 'required|numeric|between:0,10000000', 'quota_period' => ['required',Rule::in(['total','daily','weekly','monthly'])],
            'quota_direction' => ['required',Rule::in(['both','rx','tx'])], 'quota_mode' => ['required',Rule::in(['warn','enforce'])],
            'persistence' => ['required',Rule::in(['systemd','networkd','runtime'])],
        ], [], ['name'=>'نام تونل','peer_host'=>'آدرس ارتباط خارج','public_host'=>'آدرس کاربران','mappings'=>'پورت‌ها']);
        if ($existing && ($existing->engine !== $data['engine'] || (int)$existing->iran_server_id !== (int)$data['iran_server_id'] || (int)$existing->foreign_server_id !== (int)$data['foreign_server_id'])) self::fail('engine','نوع ابزار و دو سرور را در ویرایش عوض نکنید؛ اتصال مستقلی بسازید.');
        $data['public_host'] = self::address($data['public_host'],'public_host',true);
        $data['peer_host'] = self::address($data['peer_host'],'peer_host',$data['engine']==='gost');
        if ($data['engine']==='gre') {
            if (!in_array($data['transport'],self::GRE,true)) self::fail('transport','یکی از انواع GRE را انتخاب کنید.');
            $data['local_endpoint']=self::address($data['local_endpoint']??'','local_endpoint');
            $v6 = str_starts_with($data['transport'],'ip6');
            foreach (['local_endpoint','peer_host'] as $key) if (str_contains($data[$key],':')!==$v6) self::fail($key,'خانواده آدرس با نوع GRE انتخاب‌شده سازگار نیست.');
            foreach (['inner_iran','inner_foreign'] as $key) if (!empty($data[$key])) $data[$key]=self::cidr($data[$key],$key);
            if (empty($data['inner_iran'])!==empty($data['inner_foreign'])) self::fail('inner_iran','هر دو آدرس داخلی را وارد کنید یا هر دو را خالی بگذارید.');
            foreach ($data['addresses']??[] as $i=>$pair) foreach (['iran','foreign'] as $side) $data['addresses'][$i][$side]=self::cidr($pair[$side],"addresses.$i.$side");
            if ($data['proxy_protocol']) self::fail('proxy_protocol','در فوروارد GRE از تنظیم NAT و مسیر برگشت برای آدرس مبدأ استفاده می‌شود.');
            if ($data['persistence']!=='systemd') self::fail('persistence','در این ادغام ماندگاری با systemd مدیریت می‌شود.');
        } else {
            if (!in_array($data['transport'],['tcp','grpc'],true)) self::fail('transport','روش GOST معتبر نیست.');
            if (!$existing && $data['transport']==='grpc') self::fail('transport','برای اتصال جدید GOST، انتقال مستقیم را انتخاب کنید.');
            if ($data['transport']==='grpc' && $data['version']!=='3.0.0') self::fail('version','انتقال gRPC به GOST نسخه ۳ نیاز دارد.');
            if ($data['transport']==='grpc' && empty($data['tunnel_port'])) self::fail('tunnel_port','پورت ارتباط gRPC را وارد کنید.');
            if ($data['proxy_protocol']) self::fail('proxy_protocol','ارسال PROXY در نسخه‌های بسته‌بندی‌شده پشتیبانی نمی‌شود.');
            if ($data['quota_gb']>0) self::fail('quota_gb','سهمیه در این نسخه برای GRE قابل انتخاب است؛ آمار عمومی سیستم جایگزین مصرف GOST نیست.');
            if ($data['nat']!=='masquerade' || $data['max_connections'] || $data['connection_rate'] || !empty($data['allowed_sources'])) self::fail('nat','کنترل NAT و محدودیت مبدأ مربوط به فوروارد GRE است.');
            if ($data['persistence']!=='systemd') self::fail('persistence','سرویس GOST با systemd مدیریت می‌شود.');
        }
        $family=$data['ip_family']??null;
        if ($family) {
            $flag=$family==='ipv6'?FILTER_FLAG_IPV6:FILTER_FLAG_IPV4;
            if (!filter_var($data['peer_host'],FILTER_VALIDATE_IP,$flag)) self::fail('peer_host','آدرس خارج با نوع IP انتخاب‌شده سازگار نیست.');
            if ($data['engine']==='gre' && str_starts_with($data['transport'],'ip6')!==($family==='ipv6')) self::fail('ip_family','نوع IP و روش GRE باید با هم سازگار باشند.');
            foreach ($data['engine']==='gost'?$data['mappings']:[] as $i=>$map) foreach ($map['destinations']??[] as $j=>$destination) {
                if (!filter_var($destination['address'],FILTER_VALIDATE_IP,$flag)) self::fail("mappings.$i.destinations.$j.address",'نوع IP مقصد با انتخاب شما سازگار نیست.');
            }
        }
        if($data['max_connections']||$data['connection_rate'])self::fail('max_connections','محدودیت اتصال به ازای مبدأ در این نسخه فعال نیست.');
        if(array_sum(array_map(fn($m)=>max(1,count($m['destinations']??[])),$data['mappings']))>64)self::fail('mappings','حداکثر ۶۴ مقصد سلامت در هر اتصال پشتیبانی می‌شود.');
        if ($data['nat']==='snat') $data['snat_address']=self::address($data['snat_address']??'','snat_address');
        foreach ($data['allowed_sources']??[] as $i=>$cidr) $data['allowed_sources'][$i]=self::cidr($cidr,"allowed_sources.$i");
        $used=[];$total=0;
        foreach ($data['mappings'] as $i=>&$map) {
            $map['public_end']=(int)($map['public_end']??$map['public_port']);
            $map['target_end']=(int)($map['target_end']??$map['target_port']);
            $length=$map['public_end']-$map['public_port'];
            if ($length<0 || $map['target_end']-$map['target_port']!==$length || ($total += $length+1)>256) self::fail("mappings.$i",'بازه‌ها باید هم‌اندازه و صعودی باشند؛ مجموع پورت‌های هر اتصال حداکثر ۲۵۶ است.');
            foreach(range($map['public_port'],$map['public_end']) as $port) {if(isset($used[$port])) self::fail('mappings','پورت ورودی تکراری است.');$used[$port]=true;}
            foreach($map['destinations']??[] as $j=>&$destination) {
                $destination['address']=self::address($destination['address'],"mappings.$i.destinations.$j.address",$data['engine']==='gost');
                if ($length>0 && $destination['port']+$length>65535) self::fail('mappings','انتهای پورت مقصد خارج از محدوده است.');
            }
        }
        unset($map,$destination);
        if ($data['engine']==='gost' && $data['transport']==='grpc' && isset($used[$data['tunnel_port']])) self::fail('tunnel_port','پورت ارتباط باید با ورودی کاربران متفاوت باشد.');
        if ($data['failover'] && !$data['monitor']) self::fail('failover','برای جایگزینی مقصد، پایش را روشن کنید.');
        if ($data['failover'] && $data['protocol']==='udp' && empty($data['monitor_port'])) self::fail('monitor_port','برای UDP یک پورت TCP سلامت جدا مشخص کنید؛ سکوت UDP اثبات قطع نیست.');
        foreach(['iran_server_id','foreign_server_id','mtu','ttl','gre_key','tos','tx_queue','hop_limit','encap_limit','flow_label','max_connections','connection_rate','monitor_interval','failure_threshold','recovery_threshold','timeout','restart_hours'] as $key)$data[$key]=(int)$data[$key];
        foreach(['tunnel_port','monitor_port'] as $key)$data[$key]=empty($data[$key])?null:(int)$data[$key];
        foreach(['checksum','sequence','pmtud','ignore_df','clamp_mss','proxy_protocol','monitor','failover','keepalive'] as $key)$data[$key]=(bool)$data[$key];
        foreach($data['mappings'] as &$m){$m['public_port']=(int)$m['public_port'];$m['target_port']=(int)$m['target_port'];foreach($m['destinations']??[] as &$d){foreach(['port','weight'] as $k)$d[$k]=(int)$d[$k];}unset($d);}unset($m);
        $data['quota_gb']=(float)$data['quota_gb'];
        if($data['engine']==='gre' && !$data['pmtud'] && !str_starts_with($data['transport'],'ip6') && $data['ttl']!==0)self::fail('ttl','TTL ثابت با غیرفعال‌کردن PMTUD سازگار نیست.');
        $data['accept_udp']=$data['protocol']!=='tcp';
        $data['bind_device']=$data['bind_device']??'';
        $data['snat_address']=$data['snat_address']??'';
        $data['addresses']=$data['addresses']??[];
        $data['allowed_sources']=$data['allowed_sources']??[];
        return $data;
    }
}
