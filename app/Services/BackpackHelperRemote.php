<?php
namespace App\Services;

use App\Models\ManagedServer;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class BackpackHelperRemote
{
    public function __construct(private readonly SshExecutor $ssh,private readonly RemoteScriptRunner $runner) {}

    public function ensure(ManagedServer $server,string $transport,string $role): void
    {
        $tool=$transport==='naive'?($role==='server'?'sing-box':'naive'):'xray';
        $manifest=json_decode(file_get_contents(base_path('scripts/backpack/helpers.json')),true,32,JSON_THROW_ON_ERROR);
        $entry=$manifest[$tool];$arch=match(trim($this->ssh->run($server,'uname -m',20))){'x86_64'=>'amd64','arm64','aarch64'=>'arm64',default=>throw new RuntimeException('unsupported_arch')};
        $expected=[...$entry['archives'][$arch],'tool'=>$tool,'version'=>$entry['version'],'arch'=>$arch];
        $reply=$this->runner->run($server,'scripts/backpack/helper-install.py',['action'=>'status','expected'=>$expected],40);
        if(!empty($reply['ok'])&&!empty($reply['data']['current']))return;
        $dir=storage_path('app/private/backpack-helpers');
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('آماده‌سازی ابزار HTTPS کامل نشد.');
        $archive=$dir.'/'.$expected['sha256'].'.archive';
        $lock=fopen($archive.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('دریافت ابزار HTTPS در دسترس نیست.');
        try {
            if(!is_file($archive)||!hash_equals($expected['sha256'],hash_file('sha256',$archive))) {
                $temp=tempnam($dir,'download-');
                try {
                    Http::timeout(180)->connectTimeout(20)->withOptions(['sink'=>$temp])->get($expected['url'])->throw();
                    if(!hash_equals($expected['sha256'],hash_file('sha256',$temp)))throw new RuntimeException('صحت ابزار HTTPS تأیید نشد.');
                    chmod($temp,0600);if(!rename($temp,$archive))throw new RuntimeException('ذخیرهٔ ابزار HTTPS کامل نشد.');
                } finally { if(isset($temp)&&is_file($temp))@unlink($temp); }
            }
        } finally { flock($lock,LOCK_UN);fclose($lock); }
        $sudo=$server->ssh_user==='root'?'':'sudo -n ';
        $remote=trim($this->ssh->run($server,$sudo.'mktemp -d /tmp/speedbox-managed.XXXXXX',20));
        if(!preg_match('~^/tmp/speedbox-managed\.[a-zA-Z0-9]+$~D',$remote))throw new RuntimeException('آماده‌سازی مسیر ابزار HTTPS کامل نشد.');
        try {
            if($sudo!=='')$this->ssh->run($server,$sudo.'chown '.escapeshellarg($server->ssh_user).' '.escapeshellarg($remote),20);
            $this->ssh->upload($server,$archive,$remote.'/helper.archive');
            $reply=$this->runner->run($server,'scripts/backpack/helper-install.py',['action'=>'install','archive'=>$remote.'/helper.archive','expected'=>$expected],120);
            if(empty($reply['ok'])||empty($reply['data']['current']))throw new RuntimeException('نصب ابزار HTTPS کامل نشد.');
        } finally { $this->ssh->run($server,$sudo.'rm -rf -- '.escapeshellarg($remote),20); }
    }
}
