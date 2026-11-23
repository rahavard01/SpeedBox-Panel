<?php
namespace App\Services;
use App\Models\{ManagedServer,WireGuardInstance};
use Illuminate\Support\Facades\Cache;
final class WireGuardRemote {
    public function __construct(private readonly SshExecutor $ssh) {}
    private function deploy(ManagedServer $server,string $helper):void {
        if(!in_array($helper,['host.py','site.py'],true))throw new \RuntimeException('Invalid helper');
        $local=base_path('scripts/wireguard/'.$helper);$hash=hash_file('sha256',$local);
        $key='wireguard-helper:'.$server->id.':'.$hash.':'.hash('sha256',(string)$server->updated_at);
        if(Cache::get($key))return;
        $sudo=($server->ssh_user?:'root')==='root'?'':'sudo -n ';
        $dir=trim($this->ssh->run($server,'mktemp -d /tmp/speedbox-wg.XXXXXXXX',15));
        if(!preg_match('~^/tmp/speedbox-wg\.[A-Za-z0-9]+$~D',$dir))throw new \RuntimeException('مسیر موقت معتبر نیست.');
        try{
            $this->ssh->upload($server,$local,$dir.'/'.$helper);
            $command='set -eu; test "$(sha256sum '.escapeshellarg($dir.'/'.$helper).' | cut -d " " -f 1)" = '.escapeshellarg($hash).'; install -d -m 0755 /opt/speedbox-wireguard; install -m 0755 '.escapeshellarg($dir.'/'.$helper).' '.escapeshellarg('/opt/speedbox-wireguard/'.$helper);
            $this->ssh->run($server,$sudo.'sh -c '.escapeshellarg($command),30);Cache::put($key,true,3600);
        }finally{$this->ssh->run($server,'rm -rf -- '.escapeshellarg($dir),15);}
    }
    private function request(ManagedServer $server,string $helper,array $request,int $timeout):array {
        $this->deploy($server,$helper);$sudo=($server->ssh_user?:'root')==='root'?'':'sudo -n ';
        $raw=$this->ssh->run($server,$sudo.'python3 '.escapeshellarg('/opt/speedbox-wireguard/'.$helper),$timeout,json_encode($request,JSON_THROW_ON_ERROR));
        $answer=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(($answer['ok']??false)!==true||!is_array($answer['data']??null))throw new \RuntimeException('پاسخ کنترل WireGuard معتبر نیست.');
        return $answer['data'];
    }
    public function call(WireGuardInstance $i,string $action,array $policy,array $candidates=[]):array {
        if(!$i->server)throw new \RuntimeException('سرور خروجی حذف شده است.');
        return $this->request($i->server,'host.py',['action'=>$action,'policy'=>$policy,'candidates'=>$candidates],in_array($action,['install','remove'],true)?300:30);
    }
    public function site(WireGuardInstance $i,string $side,string $action,array $policy,array $candidates=[]):array {
        $server=$side==='iran'?$i->ingress:$i->server;
        if(!$server)throw new \RuntimeException('سرور مسیر WireGuard یافت نشد.');
        return $this->request($server,'site.py',['action'=>$action,'policy'=>$policy,'candidates'=>$candidates],in_array($action,['apply','remove'],true)?300:30);
    }
}
