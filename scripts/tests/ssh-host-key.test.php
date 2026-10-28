<?php
/** Real SSH/SCP fixture runner with isolated model/filesystem doubles. */
namespace Illuminate\Support\Facades {
    class File {
        public static function ensureDirectoryExists($path,$mode=0700) { if (!is_dir($path)) mkdir($path,$mode,true); }
        public static function isFile($path) { return is_file($path); }
    }
}
namespace App\Models {
    #[\AllowDynamicProperties]
    class ManagedServer {
        public bool $exists = false;
        public $private_key_passphrase = null;
        public $password = null;
        public $host_fingerprint = null;
    }
}
namespace {
    $root=dirname(__DIR__,2);
    $autoload=getenv('SPEEDBOX_PROCESS_SOURCE');
    if ($autoload) spl_autoload_register(function($class)use($autoload){
        $prefix='Symfony\\Component\\Process\\';
        if(str_starts_with($class,$prefix))require_once $autoload.'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    });
    else require $root.'/vendor/autoload.php';
    function storage_path($path=''){return getenv('SPEEDBOX_SSH_TEST_STORAGE').'/'.$path;}
    function abort_unless($condition,$status,$message){if(!$condition)throw new \RuntimeException($message);}
    require $root.'/app/Services/SshExecutor.php';
    $fixture=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);
    $server=new \App\Models\ManagedServer();foreach($fixture['server'] as $key=>$value)$server->$key=$value;
    $ssh=new \App\Services\SshExecutor();
    try {
        if(($fixture['mode']??'')==='upload'){
            $ssh->upload($server,$fixture['source'],$fixture['destination'],15);
            echo $ssh->run($server,'cat '.escapeshellarg($fixture['destination']),15);
        }else {
            $stream=isset($fixture['stream_path'])?static function($type,$chunk)use($fixture){file_put_contents($fixture['stream_path'],$chunk,FILE_APPEND);}:null;
            echo $ssh->run($server,$fixture['command'],15,$fixture['input']??null,$stream);
        }
    }catch(\Throwable $error){fwrite(STDERR,$error->getMessage());exit(1);}
}
