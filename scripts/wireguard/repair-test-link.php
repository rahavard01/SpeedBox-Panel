<?php
// Correct only the confirmed hostname typo in this isolated test installation.
if(function_exists('posix_geteuid') && posix_geteuid()===0){fwrite(STDERR,"Run as www.\n");exit(1);}
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::connection()->getConfig();
if(realpath(base_path())!=='/www/wwwroot/panel-speedbox-test'
    || ($db['database']??'')!=='speedbox_test'
    || !in_array($db['host']??'', ['127.0.0.1','localhost'],true)
    || strtolower((string)parse_url((string)config('app.url'),PHP_URL_HOST))!=='io.filmdo.ir') {
    echo "TEST_LINK_REPAIR_NOT_APPLICABLE\n";exit(0);
}
$value=App\Services\RuntimeSetting::get('subscribe_url','');
if(!is_string($value)){echo "SUBSCRIPTION_HOST_SETTING_UNCHANGED\n";exit(0);}
$corrected=preg_replace('~(?<=://)io\\.filmdoo\\.ir(?=[:/,]|$)~i','io.filmdo.ir',$value);
if($corrected!==$value){
    App\Services\RuntimeSetting::set('subscribe_url',$corrected);
    echo "SUBSCRIPTION_HOST_REPAIRED\n";
}else echo "SUBSCRIPTION_HOST_SETTING_UNCHANGED\n";
