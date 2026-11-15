<?php
require __DIR__.'/network-tunnels.test.php';

use App\Models\ServerTunnel;
use App\Services\BackpackManaged;
use Illuminate\Http\Request;

$checks=0;
$assert=function($value,$message)use(&$checks){if(!$value)throw new RuntimeException($message);$checks++;};
foreach(['naive','xhttp','reality'] as $mode) {
    $request=Request::create('/','POST',['accept_udp'=>false,'managed'=>['server_name'=>'tunnel.example.com']]);
    $settings=BackpackManaged::normalize($request,'reverse',$mode,'normal',null);
    $server=BackpackManaged::request($settings,'iran');$client=BackpackManaged::request($settings,'foreign');
    $assert($server['mode']===$mode&&$client['mode']===$mode,'mode is preserved');
    $public=BackpackManaged::publicSettings($settings);
    $assert($public['managed']['has_saved_secret']&&!isset($public['managed']['sealed']),'sealed credentials never appear in public settings');
    $assert(!str_contains(json_encode($settings),$server['password']),'credentials are encrypted at rest');
    $model=new ServerTunnel(['settings'=>$settings]);
    $assert(!str_contains(json_encode($model->attributesToArray()),$settings['managed']['sealed']),'model serialization redacts encrypted credentials');
    $again=BackpackManaged::normalize($request,'reverse',$mode,'normal',$model);
    $assert($again['managed']['sealed']===$settings['managed']['sealed'],'unrelated edits preserve identity');
    if($mode==='reality') {
        $assert(isset($server['private_key'])&&!isset($client['private_key']),'REALITY private key stays on Iran');
        $decode=fn($v)=>base64_decode(strtr($v,'-_','+/'));
        $assert(hash_equals(sodium_crypto_scalarmult_base($decode($server['private_key'])),$decode($client['public_key'])),'REALITY keys match');
    } else {
        $assert(isset($server['key'])&&!isset($client['key']),'TLS private key stays on Iran');
        $cert=openssl_x509_parse($server['certificate']);
        $assert(str_contains($cert['extensions']['subjectAltName'],'DNS:tunnel.example.com'),'TLS certificate authenticates the configured host');
    }
    foreach([['swapped',false],['normal',true]] as [$orientation,$udp]) {
        $bad=Request::create('/','POST',['accept_udp'=>$udp,'managed'=>['server_name'=>'tunnel.example.com']]);
        try {BackpackManaged::normalize($bad,'reverse',$mode,$orientation,null);$assert(false,'unsupported mode accepted');}
        catch(Symfony\Component\HttpKernel\Exception\HttpException $error){$assert($error->getStatusCode()===422,'unsupported modes fail before any server mutation');}
    }
}
echo "PASS: $checks managed BackPack assertions\n";
