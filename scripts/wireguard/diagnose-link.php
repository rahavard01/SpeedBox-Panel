<?php
if(function_exists('posix_geteuid') && posix_geteuid()===0){fwrite(STDERR,"Run this diagnostic as the web user (www), not root.\n");exit(1);}
// Read-only transport probes. Never print subscription tokens, configs or private keys.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!extension_loaded('curl')){fwrite(STDERR,"CURL_EXTENSION_MISSING\n");exit(1);}
$user=App\Models\SubscriptionAccount::where('service_type','wireguard')
    ->when(isset($argv[1]),fn($q)=>$q->where('id',(int)$argv[1]))->orderByDesc('id')->first();
if(!$user){echo "NO_WIREGUARD_ACCOUNT\n";exit(1);}
$url=App\Utils\Helper::getSubscribeUrl((string)$user->token);
$parts=parse_url($url);$configured=parse_url((string)config('app.url'));
if(!$parts || empty($parts['host']) || !in_array($parts['scheme']??'', ['http','https'],true)){
    echo "INVALID_SUBSCRIPTION_URL\n";exit(1);
}
echo 'ACCOUNT_ID='.$user->id."\n";
echo 'LINK_HOST='.$parts['host']."\n";
echo 'APP_HOST='.($configured['host']??'-')."\n";
echo 'HOSTS_MATCH='.(strcasecmp($parts['host'],$configured['host']??'')===0?'YES':'NO')."\n";
$probe=function(string $name,string $target,array $options=[])use($user,$url){
    $ch=curl_init($target);$bytes=0;$location=null;
    curl_setopt_array($ch,[
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>['Accept: text/html','Sec-Fetch-Dest: document'],
        CURLOPT_USERAGENT=>'Speedbox-Link-Diagnostic/1.0',
        CURLOPT_WRITEFUNCTION=>function($ch,$data)use(&$bytes){$bytes+=strlen($data);return strlen($data);},
        CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$location){if(str_starts_with(strtolower($line),'location:'))$location=trim(substr($line,9));return strlen($line);},
    ]);
    curl_setopt_array($ch,$options);
    curl_exec($ch);$code=curl_errno($ch);
    $error=str_replace([$url,(string)$user->token],['[subscription URL]','[redacted]'],curl_error($ch));
    echo "=== {$name} ===\n";
    echo 'HTTP_STATUS='.curl_getinfo($ch,CURLINFO_RESPONSE_CODE)."\n";
    echo 'REMOTE_IP='.(curl_getinfo($ch,CURLINFO_PRIMARY_IP)?:'-')."\n";
    echo 'CONTENT_TYPE='.(curl_getinfo($ch,CURLINFO_CONTENT_TYPE)?:'-')."\n";
    echo "PAGE_BYTES={$bytes}\nCURL_ERROR={$code}\n";
    if($error!=='')echo 'DETAIL='.$error."\n";
    if($location!==null)echo 'REDIRECT_HOST='.(parse_url($location,PHP_URL_HOST)?:'[relative]')."\n";
    curl_close($ch);
};
$probe('PUBLIC_URL',$url);
$port=(int)($parts['port']??(($parts['scheme']??'https')==='https'?443:80));
$probe('LOCAL_WEB_SERVER',$url,[CURLOPT_RESOLVE=>[$parts['host'].':'.$port.':127.0.0.1'],CURLOPT_PROXY=>'']);
$path=($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');
$probe('TEST_OCTANE','http://127.0.0.1:7011'.$path,[
    CURLOPT_PROXY=>'',
    CURLOPT_HTTPHEADER=>['Host: '.($configured['host']??$parts['host']),'X-Forwarded-Proto: https','Accept: text/html','Sec-Fetch-Dest: document'],
]);
