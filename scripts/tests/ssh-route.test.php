<?php
/** Called by ssh-route.test.py against temporary, real local SSH daemons. */
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
spl_autoload_register(function($class)use($root){if(str_starts_with($class,'App\\'))require_once $root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';});
$app=new Illuminate\Foundation\Application($root);
$app->instance('config',new Illuminate\Config\Repository(['app'=>['key'=>'base64:'.base64_encode(str_repeat('t',32)),'cipher'=>'AES-256-CBC']]));
$app->useStoragePath(getenv('SPEEDBOX_SSH_TEST_STORAGE'));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->register(Illuminate\Filesystem\FilesystemServiceProvider::class);
$app->register(Illuminate\Encryption\EncryptionServiceProvider::class);
$fixture=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);
$server=new App\Models\ManagedServer();$server->forceFill($fixture['server']);
$ssh=new App\Services\SshExecutor();
try {
    if(($fixture['mode']??'')==='upload'){
        $ssh->upload($server,$fixture['source'],$fixture['destination'],15);
        echo $ssh->run($server,'cat '.escapeshellarg($fixture['destination']),15);
    }else echo $ssh->run($server,$fixture['command'],15,$fixture['input']??null);
}catch(Throwable $error){fwrite(STDERR,$error->getMessage());exit(1);}
