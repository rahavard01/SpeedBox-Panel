<?php
namespace App\Services;

use App\Models\ServerTunnel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

final class BackpackManaged
{
    public const METHODS = ['naive','xhttp','reality'];

    public static function supported(string $transport): bool { return in_array($transport,self::METHODS,true); }

    public static function normalize(Request $request,string $kind,string $transport,string $orientation,?ServerTunnel $existing): array
    {
        if (!self::supported($transport)) return [];
        abort_unless($kind==='reverse' && $orientation==='normal',422,'این روش HTTPS فقط در جهت معمول Reverse قابل استفاده است.');
        abort_if($request->boolean('accept_udp'),422,'این روش HTTPS فقط TCP را منتقل می‌کند.');
        abort_if($request->input('connection_mode','direct')!=='direct',422,'برای این روش HTTPS، حالت CDN در پنل فعال نیست.');
        $validated=$request->validate([
            'managed.server_name'=>['required','string','max:253',function($a,$v,$fail)use($transport){
                $ip=(bool)filter_var($v,FILTER_VALIDATE_IP);
                if(($ip&&$transport!=='naive')||(!$ip&&!filter_var($v,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)))$fail('نام میزبان TLS معتبر نیست.');
            }],
            'managed.target'=>['nullable','string','max:259','regex:/^[a-zA-Z0-9.-]+:[0-9]{1,5}$/'],
            'managed.path'=>['nullable','string','max:128','regex:~^/[a-zA-Z0-9/_-]+$~'],
            'managed.connections'=>'sometimes|integer|between:1,16',
        ]);
        $m=$validated['managed'];$m['mode']=$transport;
        if($transport==='reality') {
            $m['target']=$m['target']??$m['server_name'].':443';
            $port=(int)substr(strrchr($m['target'],':'),1);
            abort_unless($port>=1&&$port<=65535,422,'پورت مقصد REALITY معتبر نیست.');
        } else unset($m['target']);
        if($transport==='xhttp')$m['path']=$m['path']??($existing?->settings['managed']['path']??'/sb-'.bin2hex(random_bytes(12)));
        else unset($m['path']);
        $m['connections']=$transport==='naive'?(int)($m['connections']??4):1;
        $old=$existing?->settings['managed']??[];
        if(($old['mode']??'')===$transport && ($old['server_name']??'')===$m['server_name'] && !empty($old['sealed']))$m['sealed']=$old['sealed'];
        else {
            $secret=['uuid'=>(string)Str::uuid(),'username'=>'sb-'.bin2hex(random_bytes(8)),
                'password'=>bin2hex(random_bytes(32)),'short_id'=>bin2hex(random_bytes(8))];
            if($transport==='reality') {
                if(!function_exists('sodium_crypto_box_keypair'))throw new RuntimeException('برای ساخت کلید REALITY افزونهٔ sodium باید روی پنل فعال باشد.');
                $pair=sodium_crypto_box_keypair();
                $encode=fn($s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');
                $secret['private_key']=$encode(sodium_crypto_box_secretkey($pair));
                $secret['public_key']=$encode(sodium_crypto_box_publickey($pair));
            } else $secret=[...$secret,...self::certificate($m['server_name'])];
            $m['sealed']=Crypt::encryptString(json_encode($secret,JSON_THROW_ON_ERROR));
        }
        return ['managed'=>$m];
    }

    private static function certificate(string $host): array
    {
        $dir=storage_path('app/private/backpack-helpers');
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('آماده‌سازی گواهی تونل کامل نشد.');
        $file=tempnam($dir,'tls-');if($file===false)throw new RuntimeException('آماده‌سازی گواهی تونل کامل نشد.');
        try {
            chmod($file,0600);$san=(filter_var($host,FILTER_VALIDATE_IP)?'IP:':'DNS:').$host;
            file_put_contents($file,"[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ext]\nsubjectAltName=$san\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
            $options=['config'=>$file,'digest_alg'=>'sha256','x509_extensions'=>'ext'];
            $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
            $csr=$key?openssl_csr_new(['commonName'=>$host],$key,$options):false;
            $cert=$csr?openssl_csr_sign($csr,null,$key,3650,$options):false;
            if(!$cert||!openssl_x509_export($cert,$pem)||!openssl_pkey_export($key,$private))throw new RuntimeException('ساخت گواهی خصوصی تونل کامل نشد.');
            return ['certificate'=>$pem,'key'=>$private];
        } finally { @unlink($file); }
    }

    public static function publicSettings(array $settings): array
    {
        if(isset($settings['managed'])) {
            $settings['managed']['has_saved_secret']=!empty($settings['managed']['sealed']);
            unset($settings['managed']['sealed']);
        }
        return $settings;
    }

    public static function request(array $settings,string $side): array
    {
        $m=$settings['managed']??[];
        if(empty($m['sealed']))throw new RuntimeException('اطلاعات امن روش HTTPS در دسترس نیست.');
        $secret=json_decode(Crypt::decryptString($m['sealed']),true,16,JSON_THROW_ON_ERROR);
        unset($m['sealed']);
        // Only the listener receives the private certificate/REALITY key.
        if($side!=='iran')unset($secret['key'],$secret['private_key']);
        return [...$m,...$secret];
    }
}
