<?php
namespace App\Services;

use App\Models\{ManagedServer,ServerTunnel,TunnelConnectionTest};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/** CDN configuration belongs to a selected method, not to the initial direct scan. */
final class TunnelCdn
{
    public static function supported(string $engine,string $kind,string $transport): bool
    {
        return ($engine==='backpack' && $kind==='reverse' && in_array($transport,['ws','wss','wsmux','wssmux'],true))
            || ($engine==='wattunnel' && in_array($transport,['ws','wss','grpc'],true));
    }

    public static function secure(string $transport): bool { return in_array($transport,['wss','wssmux','grpc'],true); }

    public static function fail(string $field,string $message): never { throw ValidationException::withMessages([$field=>$message]); }

    public static function normalize(Request $request,string $engine,string $kind,string $transport,?ServerTunnel $existing=null): array
    {
        $mode=$request->input('connection_mode','direct');
        if(!in_array($mode,['direct','cdn'],true))self::fail('connection_mode','مسیر ارتباط معتبر نیست.');
        if($mode==='direct')return ['connection_mode'=>'direct'];
        if(!self::supported($engine,$kind,$transport))self::fail('connection_mode','این روش از اتصال CDN پشتیبانی نمی‌کند.');
        $data=$request->validate([
            'cdn'=>'required|array','cdn.domain'=>'required|string|max:253','tunnel_port'=>'required|integer|between:1,65535','cdn.port'=>'nullable|integer|between:1,65535',
            'cdn.edge_ip'=>'nullable|ip','cdn.test_id'=>'nullable|integer',
            'cdn.cert_config'=>'nullable|array','cdn.cert_config.cert_mode'=>'nullable|in:content,file,http,dns',
            'cdn.cert_config.cert_content'=>'nullable|string|max:65536','cdn.cert_config.key_content'=>'nullable|string|max:32768',
            'cdn.cert_config.cert_file'=>'nullable|string|max:1024','cdn.cert_config.key_file'=>'nullable|string|max:1024',
            'cdn.cert_config.email'=>'nullable|email|max:253','cdn.cert_config.http_port'=>'nullable|integer|between:1,65535',
            'cdn.cert_config.dns_provider'=>'nullable|string|max:50','cdn.cert_config.dns_env'=>'nullable|array|max:8',
            'cdn.cert_config.dns_env.*'=>'nullable|string|max:4096',
        ])['cdn'];
        $data=array_intersect_key($data,array_flip(['domain','port','edge_ip','test_id','cert_config']));
        $data['domain']=strtolower(rtrim(trim($data['domain']),'.'));
        if(filter_var($data['domain'],FILTER_VALIDATE_IP)||!filter_var($data['domain'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))self::fail('cdn.domain','دامنهٔ معتبر وارد کنید؛ بدون شماره پورت و نشانی وب.');
        $data['port']=(int)$request->input('tunnel_port');$data['edge_ip']=trim($data['edge_ip']??'');
        $ports=self::secure($transport)?[443,2053,2083,2087,2096,8443]:[80,8080,8880,2052,2082,2086,2095];
        if(!in_array($data['port'],$ports,true))self::fail('tunnel_port','برای ارتباط این روش با CDN یکی از این پورت‌ها را انتخاب کنید: '.implode('، ',$ports));
        if($data['edge_ip']!==''&&!filter_var($data['edge_ip'],FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))self::fail('cdn.edge_ip','IP لبه باید یک نشانی عمومی باشد.');
        $cert=array_intersect_key($data['cert_config']??[],array_flip(['cert_mode','cert_content','key_content','cert_file','key_file','email','http_port','dns_provider','dns_env']));
        if(self::secure($transport)) {
            $method=$cert['cert_mode']??'content';
            if(!in_array($method,['content','file','http','dns'],true))self::fail('cdn.cert_config.cert_mode','روش گواهی را انتخاب کنید.');
            $old=self::reveal($existing?->settings??[])['cdn']['cert_config']??[];
            if(($old['cert_mode']??null)===$method&&empty($cert['key_content'])&&!empty($old['key_content']))$cert['key_content']=$old['key_content'];
            $cert=['cert_mode'=>$method,'domain'=>$data['domain'],...$cert];$cert['domain']=$data['domain'];
            if($method==='content') {
                if(empty($cert['cert_content'])||empty($cert['key_content']))self::fail('cdn.cert_config','محتوای گواهی و محتوای کلید خصوصی را وارد کنید.');
                self::validateCertificate($cert['cert_content'],$cert['key_content'],$data['domain']);
            } elseif($method==='file') {
                foreach(['cert_file','key_file'] as $key)if(empty($cert[$key])||!preg_match('~^/[A-Za-z0-9_./-]+$~D',$cert[$key])||in_array('..',explode('/',$cert[$key]),true))self::fail('cdn.cert_config.'.$key,'مسیر مطلق و معتبر فایل روی سرور شنونده را وارد کنید.');
            } elseif($method==='dns') {
                $provider=strtolower(trim($cert['dns_provider']??''));
                $provider=match($provider){'arvan','آروان'=>'arvancloud','cf'=>'cloudflare',default=>$provider};
                $allowed=match($provider){'arvancloud'=>['ARVANCLOUD_API_KEY'],'cloudflare'=>['CLOUDFLARE_DNS_API_TOKEN','CF_DNS_API_TOKEN','CLOUDFLARE_ZONE_API_TOKEN'],default=>[]};
                if(!$allowed)self::fail('cdn.cert_config.dns_provider','ارائه‌دهندهٔ پشتیبانی‌شده را انتخاب کنید: آروان یا کلادفلر.');
                $env=$cert['dns_env']??[];
                foreach($env as $key=>$value)if(str_contains((string)$value,'********'))self::fail('cdn.cert_config.dns_env.'.$key,'کلید کامل را وارد کنید؛ مقدار ستاره‌دار فقط برای نمایش است.');
                if(($old['cert_mode']??null)==='dns'&&($old['dns_provider']??null)===$provider){
                    $previous=$old['dns_env']??[];
                    if(array_key_exists('CLOUDFLARE_DNS_API_TOKEN',$env)||array_key_exists('CF_DNS_API_TOKEN',$env))unset($previous['CLOUDFLARE_DNS_API_TOKEN'],$previous['CF_DNS_API_TOKEN']);
                    $env=[...$previous,...$env];
                }
                $cert['dns_env']=$env;
                if(!$env||array_diff(array_keys($env),$allowed))self::fail('cdn.cert_config.dns_env','اطلاعات دسترسی DNS با ارائه‌دهندهٔ انتخاب‌شده هماهنگ نیست.');
                if($provider==='arvancloud'&&empty($env['ARVANCLOUD_API_KEY'])||$provider==='cloudflare'&&empty($env['CLOUDFLARE_DNS_API_TOKEN'])&&empty($env['CF_DNS_API_TOKEN']))self::fail('cdn.cert_config.dns_env','کلید دسترسی DNS وارد نشده است.');
                $cert['dns_provider']=$provider;
            }
            $fields=match($method){'content'=>['cert_mode','domain','cert_content','key_content'],'file'=>['cert_mode','domain','cert_file','key_file'],'http'=>['cert_mode','domain','email','http_port'],'dns'=>['cert_mode','domain','email','dns_provider','dns_env']};
            $cert=array_intersect_key($cert,array_flip($fields));
            if(in_array($method,['http','dns'],true))$cert['email']=$cert['email']??'';
            if($method==='http')$cert['http_port']=(int)($cert['http_port']??80);
            $private=[];foreach(['key_content','dns_env'] as $key)if(isset($cert[$key])){$private[$key]=$cert[$key];unset($cert[$key]);}
            if($private)$cert['sealed']=Crypt::encryptString(json_encode($private,JSON_THROW_ON_ERROR));
            $data['cert_config']=$cert;
        } else $data['cert_config']=['cert_mode'=>'none'];
        if($engine==='wattunnel'&&$request->filled('tls_pin'))self::fail('tls_pin','اثر انگشت گواهی اتصال مستقیم را برای مسیر CDN خالی کنید.');
        return ['connection_mode'=>'cdn','cdn'=>$data];
    }

    public static function validateCertificate(string $pem,string $key,string $domain): void
    {
        $x=@openssl_x509_read($pem);$p=@openssl_pkey_get_private($key);
        if(!$x||!$p||!@openssl_x509_check_private_key($x,$p))self::fail('cdn.cert_config','گواهی و کلید خصوصی معتبر یا متناظر نیستند.');
        $info=openssl_x509_parse($x);$now=time();
        if(($info['validFrom_time_t']??PHP_INT_MAX)>$now||($info['validTo_time_t']??0)<=$now+3600)self::fail('cdn.cert_config','گواهی منقضی شده یا هنوز معتبر نشده است.');
        $names=[];foreach(explode(',',$info['extensions']['subjectAltName']??'') as $san)if(str_starts_with(trim($san),'DNS:'))$names[]=strtolower(substr(trim($san),4));
        $match=false;foreach($names as $name)if($name===$domain||str_starts_with($name,'*.')&&substr($domain,strpos($domain,'.'))===substr($name,1))$match=true;
        if(!$match)self::fail('cdn.cert_config','گواهی برای آدرس دامنهٔ واردشده معتبر نیست.');
    }

    public static function reveal(array $settings): array
    {
        $sealed=$settings['cdn']['cert_config']['sealed']??null;
        if($sealed){$private=json_decode(Crypt::decryptString($sealed),true,16,JSON_THROW_ON_ERROR);unset($settings['cdn']['cert_config']['sealed']);$settings['cdn']['cert_config']=[...$settings['cdn']['cert_config'],...$private];}
        return $settings;
    }

    private static function dnsSecretPreview(string $value): string
    {
        $value=trim($value);
        if($value==='')return '';
        return mb_strlen($value)<=12?'********':mb_substr($value,0,4).'********'.mb_substr($value,-4);
    }

    public static function publicSettings(array $settings): array
    {
        if(isset($settings['cdn']['cert_config'])) {
            $c=&$settings['cdn']['cert_config'];$c['has_saved_secret']=!empty($c['sealed']);
            unset($c['saved_dns_fields'],$c['saved_dns_previews']);
            if($c['has_saved_secret']&&($c['cert_mode']??null)==='dns'){
                $env=self::reveal($settings)['cdn']['cert_config']['dns_env']??[];
                $c['saved_dns_fields']=array_keys($env);
                $c['saved_dns_previews']=array_map(fn($value)=>self::dnsSecretPreview((string)$value),$env);
            }
            unset($c['sealed'],$c['key_content'],$c['dns_env']);
        }
        return $settings;
    }

    public static function fingerprint(array $settings,array $method,int $iran,int $foreign): string
    {
        $s=self::reveal($settings);unset($s['name'],$s['telegram_alerts'],$s['enabled'],$s['metrics_port'],$s['source_identity'],$s['cdn']['test_id']);
        // Fields unrelated to the route are intentionally not part of the receipt.
        $keys=['connection_mode','cdn','tunnel_port','accept_udp','protocol','path','ip_family','reverse','transport','version','sni','host_header','tls_pin','fingerprint','tls_small_ch','tls_frag','mappings','mtu','conns','sessions','lanes','fec','max_mbps','tcp_cc','hedge','ping_every','udp_idle'];
        $s=array_intersect_key($s,array_flip($keys));
        $hosts=ManagedServer::whereIn('id',[$iran,$foreign])->orderBy('id')->get(['id','host','ssh_port','machine_id'])->toArray();
        $sort=function(&$v)use(&$sort){if(!is_array($v))return;if(!array_is_list($v))ksort($v);foreach($v as &$x)$sort($x);};
        $value=[$s,$method,$iran,$foreign,$hosts];$sort($value);
        return hash_hmac('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(string)config('app.key'));
    }

    public static function assertReceipt(array $settings,array $method,int $iran,int $foreign,?ServerTunnel $existing=null): void
    {
        if(($settings['connection_mode']??'direct')!=='cdn')return;
        $fingerprint=self::fingerprint($settings,$method,$iran,$foreign);
        if($existing&&($existing->settings['connection_mode']??'direct')==='cdn'&&hash_equals(self::fingerprint($existing->settings,['engine'=>$existing->engine,'kind'=>$existing->kind,'transport'=>$existing->transport,'orientation'=>$existing->orientation],$iran,$foreign),$fingerprint))return;
        $test=TunnelConnectionTest::find($settings['cdn']['test_id']??0);
        $op=$test?->operations()->where('action','cdn-test')->latest()->first();
        if(!$test||$test->status!=='completed'||!$test->updated_at->greaterThan(now()->subHours(24))||!$op||$op->status!=='succeeded'||empty($op->result['cdn_verified'])||!hash_equals($op->payload['fingerprint']??'',$fingerprint))self::fail('cdn.test_id','ابتدا بررسی اتصال CDN را با همین روش، دو سرور و تنظیمات فعلی کامل کنید.');
    }
}
