<?php
namespace App\Services;

use App\Models\{ManagedServer,ServerTunnel};
use RuntimeException;

/** Root-owned certificate helper, shipped with the panel over authenticated SSH. */
class TunnelCdnRemote
{
    private array $ready=[];
    public function __construct(private readonly SshExecutor $ssh) {}

    public function ensure(ManagedServer $server): void
    {
        if(isset($this->ready[$server->id]))return;
        $sudo=$server->ssh_user==='root'?'':'sudo -n ';
        $arch=match(trim($this->ssh->run($server,'uname -m',20))){'x86_64'=>'amd64','aarch64','arm64'=>'arm64',default=>throw new RuntimeException('معماری سرور پشتیبانی نمی‌شود.')};
        $manifest=json_decode(file_get_contents(base_path('tunnel-cdn-dist/manifest.json')),true,32,JSON_THROW_ON_ERROR);
        $entry=$manifest['archives'][$arch];$binary=base_path('tunnel-cdn-dist/'.$entry['file']);
        if(!hash_equals($entry['sha256'],hash_file('sha256',$binary)))throw new RuntimeException('صحت بستهٔ گواهی تأیید نشد.');
        $helper=base_path('scripts/tunnel-cdn/host.py');$hash=hash_file('sha256',$helper);
        $dir=trim($this->ssh->run($server,'umask 077; mktemp -d /tmp/speedbox-cdn.XXXXXXXX',20));
        if(!preg_match('~^/tmp/speedbox-cdn\.[A-Za-z0-9]+$~D',$dir))throw new RuntimeException('مسیر موقت معتبر نیست.');
        try {
            $this->ssh->upload($server,$helper,$dir.'/host.py');$this->ssh->upload($server,$binary,$dir.'/certificate.gz');
            $script='set -eu; command -v python3 >/dev/null; command -v openssl >/dev/null; command -v ss >/dev/null; test -d /run/systemd/system; '
                .'install -d -m 0755 /opt/speedbox-tunnel-cdn; install -d -m 0700 /etc/speedbox-tunnel-cdn /var/lib/speedbox-tunnel-cdn; '
                .'test "$(sha256sum '.escapeshellarg($dir.'/host.py').' | cut -d " " -f 1)" = '.escapeshellarg($hash).'; '
                .'test "$(sha256sum '.escapeshellarg($dir.'/certificate.gz').' | cut -d " " -f 1)" = '.escapeshellarg($entry['sha256']).'; '
                .'gzip -dc '.escapeshellarg($dir.'/certificate.gz').' > /opt/speedbox-tunnel-cdn/certificate.new; '
                .'test "$(sha256sum /opt/speedbox-tunnel-cdn/certificate.new | cut -d " " -f 1)" = '.escapeshellarg($entry['binary_sha256']).'; '
                .'chmod 0755 /opt/speedbox-tunnel-cdn/certificate.new; mv /opt/speedbox-tunnel-cdn/certificate.new /opt/speedbox-tunnel-cdn/certificate; '
                .'install -m 0755 '.escapeshellarg($dir.'/host.py').' /opt/speedbox-tunnel-cdn/host.py.new; mv /opt/speedbox-tunnel-cdn/host.py.new /opt/speedbox-tunnel-cdn/host.py;';
            $this->ssh->run($server,$sudo.'sh -c '.escapeshellarg($script),90);
            $this->ready[$server->id]=true;
        } finally {try{$this->ssh->run($server,'rm -f -- '.escapeshellarg($dir.'/host.py').' '.escapeshellarg($dir.'/certificate.gz').'; rmdir -- '.escapeshellarg($dir),20);}catch(\Throwable){}}
    }

    public function call(ManagedServer $server,array $request): array
    {
        $sudo=$server->ssh_user==='root'?'':'sudo -n ';
        $timeout=$request['action']==='certificate'?450:75;
        $raw=$this->ssh->run($server,$sudo.'python3 /opt/speedbox-tunnel-cdn/host.py',$timeout,json_encode($request,JSON_THROW_ON_ERROR));
        $reply=json_decode($raw,true,64);
        if(!is_array($reply)||($reply['ok']??false)!==true)throw new RuntimeException('بررسی CDN یا گواهی کامل نشد؛ تنظیمات و دسترسی سرور را بررسی کنید.');
        return $reply['data'];
    }

    public static function listenerIran(ServerTunnel $t,array $settings): bool
    {
        return $t->engine==='backpack'?$t->orientation==='normal':(bool)($settings['reverse']??false);
    }

    public static function request(ServerTunnel $t,array $settings): array
    {
        $s=TunnelCdn::reveal($settings);
        return ['id'=>$t->remote_name,'engine'=>$t->engine,'cdn'=>$s['cdn'],'origins'=>[$t->iran->host,$t->foreign->host]];
    }

    public function prepare(ServerTunnel $t,array $settings,string $side,bool $temporary=false): array
    {
        if(($settings['connection_mode']??'direct')!=='cdn')return [];
        $server=$t->$side;$this->ensure($server);$r=self::request($t,$settings);
        if(($side==='iran')===self::listenerIran($t,$settings)&&TunnelCdn::secure($settings['transport']??$t->transport)) {
            return $this->call($server,[...$r,'action'=>'certificate','temporary'=>$temporary]);
        }
        return [];
    }

    public function revision(ServerTunnel $t,array $settings,string $side,string $action,string $revision,bool $force=false): void
    {
        if(!$force&&($settings['connection_mode']??'direct')!=='cdn'&&($t->settings['connection_mode']??'direct')!=='cdn')return;
        $this->ensure($t->$side);$this->call($t->$side,['action'=>$action,'id'=>$t->remote_name,'revision'=>$revision]);
    }

    public function status(ServerTunnel $t,array $settings,string $side): array
    {
        if(($settings['connection_mode']??'direct')!=='cdn')return [];
        $r=self::request($t,$settings);$r['action']='info';
        if(($side==='iran')!==self::listenerIran($t,$settings)) {
            $r['action']='peers';
            if($t->engine==='backpack'&&$t->orientation==='swapped')$r['carrier_id']='sb-'.substr(hash('sha256','speedbox-carrier:'.$t->remote_name),0,32);
        }
        $info=$this->call($t->$side,$r);unset($info['cert_file'],$info['key_file']);return $info;
    }

    public function commit(ServerTunnel $t,array $settings): void
    {
        foreach(['iran','foreign'] as $side) {
            if(($settings['connection_mode']??'direct')==='cdn') {
                $server=$t->$side;$r=self::request($t,$settings);
                if(($side==='iran')===self::listenerIran($t,$settings)){
                    if($t->engine==='backpack'&&$t->orientation==='swapped')$r['carrier_id']='sb-'.substr(hash('sha256','speedbox-carrier:'.$t->remote_name),0,32);
                    $this->call($server,[...$r,'action'=>'renewal']);
                }
            } elseif(($t->settings['connection_mode']??'direct')==='cdn') {
                $this->ensure($t->$side);$this->call($t->$side,['action'=>'delete','id'=>$t->remote_name]);
            }
        }
    }
}
