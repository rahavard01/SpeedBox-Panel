<?php
namespace App\Services;

use App\Models\ManagedServer;
use RuntimeException;

/** Versioned artifacts over the existing SSH connection; no public control API. */
class NetworkTunnelRemote
{
    public function __construct(private readonly SshExecutor $ssh) {}

    /** Inspect the engine actually selected by this tunnel; never install during a check. */
    public function maintenanceStatus(ManagedServer $server,string $engine,string $version): array
    {
        $expected = ['helper'=>hash_file('sha256',base_path('scripts/network-tunnels/host.py')),'binaries'=>[]];
        if ($engine==='gost') {
            $manifest=json_decode(file_get_contents(base_path('gost-dist/manifest.json')),true,32,JSON_THROW_ON_ERROR);
            foreach ($manifest['artifacts'][$version] ?? [] as $arch=>$entry) $expected['binaries'][$arch]=$entry['binary_sha256'];
        }
        $script = <<<'PY'
import hashlib,json,pathlib,platform,subprocess,sys,time
expected=json.loads(sys.argv[1]);engine=sys.argv[2];version=sys.argv[3]
def digest(path):
    p=pathlib.Path(path)
    if not p.is_file(): return None
    with p.open('rb') as f: return hashlib.file_digest(f,'sha256').hexdigest() if hasattr(hashlib,'file_digest') else hashlib.sha256(f.read()).hexdigest()
helper=digest('/opt/speedbox-network/host.py')
arch={'x86_64':'amd64','aarch64':'arm64','arm64':'arm64'}.get(platform.machine(),'unsupported')
binary=digest('/opt/speedbox-network/gost-'+version) if engine=='gost' else None
compatible=engine!='gost' or (expected['binaries'].get(arch) is not None and binary==expected['binaries'][arch])
worker=subprocess.run(['systemctl','is-active','--quiet','speedbox-network-worker.service']).returncode==0
current=helper==expected['helper'] and compatible and worker
print(json.dumps({'checked_at':int(time.time()),'installed':helper is not None,'compatible':compatible,'up_to_date':current,'version':(version if compatible and binary else 'نصب نشده یا متفاوت') if engine=='gost' else ('ابزار فعلی' if helper==expected['helper'] else 'ابزار قبلی یا نصب نشده'),'target_version':version if engine=='gost' else 'ابزار همراه پنل','helper_up_to_date':helper==expected['helper'],'worker_active':worker}))
PY;
        $command=($server->ssh_user==='root'?'':'sudo -n ').'python3 -c '.escapeshellarg($script).' '
            .escapeshellarg(json_encode($expected,JSON_THROW_ON_ERROR)).' '.escapeshellarg($engine).' '.escapeshellarg($version);
        $status=json_decode($this->ssh->run($server,$command,40),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($status) || !array_key_exists('up_to_date',$status)) throw new RuntimeException('وضعیت نسخهٔ ابزار قابل بررسی نبود.');
        return $status;
    }

    public function ensure(ManagedServer $server,string $engine,string $version): void
    {
        $sudo=$server->ssh_user==='root'?'':'sudo -n ';
        $this->temporary($server,function($dir)use($server,$sudo,$engine,$version){
            $this->ssh->upload($server,base_path('scripts/network-tunnels/host.py'),$dir.'/host.py');
            $helperHash=hash_file('sha256',base_path('scripts/network-tunnels/host.py'));
            $script='set -eu; command -v python3 >/dev/null; command -v systemctl >/dev/null; test -d /run/systemd/system; '
                .'install -d -m 0700 /etc/speedbox-network /var/lib/speedbox-network; install -d -m 0755 /opt/speedbox-network; '
                .'test "$(sha256sum '.escapeshellarg($dir.'/host.py').' | cut -d " " -f 1)" = '.escapeshellarg($helperHash).'; '
                .'install -m 0700 '.escapeshellarg($dir.'/host.py').' /opt/speedbox-network/host.py.new; mv /opt/speedbox-network/host.py.new /opt/speedbox-network/host.py;';
            $this->ssh->run($server,$sudo.'sh -c '.escapeshellarg($script),45);
            if($engine==='gost')$this->installGost($server,$dir,$sudo,$version);
            $unit="[Unit]\nDescription=SpeedBox network tunnel monitoring\nAfter=network-online.target\nWants=network-online.target\n[Service]\nType=simple\nExecStart=/usr/bin/python3 /opt/speedbox-network/host.py daemon\nRestart=always\nRestartSec=5\nUMask=0077\n[Install]\nWantedBy=multi-user.target\n";
            $local=tempnam(storage_path('app/private'),'net-unit-');
            try {
                file_put_contents($local,$unit);$this->ssh->upload($server,$local,$dir.'/worker.service');
                $script='set -eu; install -m 0644 '.escapeshellarg($dir.'/worker.service').' /etc/systemd/system/speedbox-network-worker.service; systemctl daemon-reload; systemctl enable speedbox-network-worker.service >/dev/null; systemctl restart speedbox-network-worker.service;';
                $this->ssh->run($server,$sudo.'sh -c '.escapeshellarg($script),45);
            } finally {@unlink($local);}
        });
    }

    private function installGost(ManagedServer $server,string $dir,string $sudo,string $version): void
    {
                $manifest=json_decode(file_get_contents(base_path('gost-dist/manifest.json')),true,32,JSON_THROW_ON_ERROR);
                $arch=match(trim($this->ssh->run($server,'uname -m',20))){'x86_64'=>'amd64','aarch64','arm64'=>'arm64',default=>throw new RuntimeException('معماری سرور پشتیبانی نمی‌شود.')};
                $entry=$manifest['artifacts'][$version][$arch]??throw new RuntimeException('بستهٔ این نسخه موجود نیست.');
                $local=base_path('gost-dist/'.$entry['file']);
                if(!is_file($local)||!hash_equals($entry['sha256'],hash_file('sha256',$local)))throw new RuntimeException('صحت بسته GOST تأیید نشد.');
                $target='/opt/speedbox-network/gost-'.$version;
                $current=trim($this->ssh->run($server,$sudo.'sh -c '.escapeshellarg('if [ -f '.$target.' ]; then sha256sum '.$target.' | cut -d " " -f 1; fi'),20));
                if($current!==''&&!hash_equals($entry['binary_sha256'],$current))throw new RuntimeException('فایل نسخه نصب‌شده با بسته بررسی‌شده متفاوت است؛ فایل موجود خودکار جایگزین نشد.');
                if($current==='') {
                    $this->ssh->upload($server,$local,$dir.'/binary.gz');
                    $script='set -eu; test "$(sha256sum '.escapeshellarg($dir.'/binary.gz').' | cut -d " " -f 1)" = '.escapeshellarg($entry['sha256']).'; '
                        .'gzip -dc '.escapeshellarg($dir.'/binary.gz').' > '.escapeshellarg($target.'.new').'; '
                        .'test "$(sha256sum '.escapeshellarg($target.'.new').' | cut -d " " -f 1)" = '.escapeshellarg($entry['binary_sha256']).'; '
                        .'chmod 0755 '.escapeshellarg($target.'.new').'; mv '.escapeshellarg($target.'.new').' '.escapeshellarg($target).';';
                    $this->ssh->run($server,$sudo.'sh -c '.escapeshellarg($script),90);
                }
    }

    /** Test helper and immutable GOST artifacts only: never restart the tunnel daemon. */
    public function ensureProbe(ManagedServer $server,string $engine): void
    {
        $sudo=$server->ssh_user==='root'?'':'sudo -n ';
        $this->temporary($server,function($dir)use($server,$sudo,$engine){
            $source=base_path('scripts/network-tunnels/probe.py');$hash=hash_file('sha256',$source);
            $this->ssh->upload($server,$source,$dir.'/probe.py');
            $target='/opt/speedbox-network-tests/'.$hash;
            $script='set -eu; command -v python3 >/dev/null; command -v systemd-run >/dev/null; test -d /run/systemd/system; '
                .'test "$(sha256sum '.escapeshellarg($dir.'/probe.py').' | cut -d " " -f 1)" = '.escapeshellarg($hash).'; '
                .'install -d -m 0755 /opt/speedbox-network '.escapeshellarg($target).'; install -d -m 0700 /var/lib/speedbox-network-tests; '
                .'install -m 0700 '.escapeshellarg($dir.'/probe.py').' '.escapeshellarg($target.'/probe.py').';';
            $this->ssh->run($server,$sudo.'sh -c '.escapeshellarg($script),45);
            if($engine==='gost')foreach(\App\Support\NetworkTunnelRules::VERSIONS as $version)$this->installGost($server,$dir,$sudo,$version);
        });
        $this->probe($server,['action'=>'recover']);
    }

    public function probe(ManagedServer $server,array $request): array
    {
        return $this->temporary($server,function($dir)use($server,$request){
            $local=tempnam(storage_path('app/private'),'probe-');
            if($local===false)throw new RuntimeException('ساخت فایل موقت تست کامل نشد.');
            try {
                chmod($local,0600);file_put_contents($local,json_encode($request,JSON_THROW_ON_ERROR),LOCK_EX);
                $this->ssh->upload($server,$local,$dir.'/request.json');
                $helper='/opt/speedbox-network-tests/'.hash_file('sha256',base_path('scripts/network-tunnels/probe.py')).'/probe.py';
                $command=($server->ssh_user==='root'?'':'sudo -n ').'python3 '.escapeshellarg($helper).' '.escapeshellarg($dir.'/request.json');
                try{$raw=$this->ssh->run($server,$command,120);}catch(RuntimeException $error){$raw=$error->getMessage();}
                $reply=json_decode($raw,true,64);
                if(!is_array($reply)||($reply['ok']??false)!==true)throw new RuntimeException($reply['error']??'اجرای تست شبکه کامل نشد؛ گزارش SSH را بررسی کنید.');
                return $reply['data'];
            } finally {@unlink($local);}
        });
    }

    public function call(ManagedServer $server,array $request): array
    {
        return $this->temporary($server,function($dir)use($server,$request){
            $local=tempnam(storage_path('app/private'),'network-');
            if($local===false)throw new RuntimeException('ساخت فایل موقت کامل نشد.');
            try {
                chmod($local,0600);file_put_contents($local,json_encode($request,JSON_THROW_ON_ERROR),LOCK_EX);
                $this->ssh->upload($server,$local,$dir.'/request.json');
                $command=($server->ssh_user==='root'?'':'sudo -n ').'python3 /opt/speedbox-network/host.py '.escapeshellarg($dir.'/request.json');
                try{$raw=$this->ssh->run($server,$command,150);}catch(RuntimeException $e){$raw=$e->getMessage();}
                $reply=json_decode($raw,true,64);
                if(!is_array($reply)||($reply['ok']??false)!==true)throw new RuntimeException($reply['error']??'پاسخ ابزار شبکه معتبر نیست؛ Python 3، systemd و دسترسی SSH را بررسی کنید.');
                return $reply['data'];
            } finally {@unlink($local);}
        });
    }

    private function temporary(ManagedServer $server,callable $callback): mixed
    {
        $dir=trim($this->ssh->run($server,'umask 077; mktemp -d /tmp/speedbox-network.XXXXXXXX',20));
        if(!preg_match('~^/tmp/speedbox-network\.[A-Za-z0-9]+$~D',$dir))throw new RuntimeException('مسیر موقت معتبر نیست.');
        try{return $callback($dir);}finally{
            try{$this->ssh->run($server,'rm -f -- '.implode(' ',array_map('escapeshellarg',[$dir.'/host.py',$dir.'/probe.py',$dir.'/request.json',$dir.'/binary.gz',$dir.'/worker.service'])).'; rmdir -- '.escapeshellarg($dir),20);}catch(\Throwable){}
        }
    }
}
