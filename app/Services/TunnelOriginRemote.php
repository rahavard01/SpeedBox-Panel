<?php
namespace App\Services;

use App\Models\{ManagedServer, ProxyServer, ServerTunnel};
use RuntimeException;

/** The adapter has no public management API. Install and configure via SSH. */
final class TunnelOriginRemote
{
    private array $ready=[];
    public function __construct(private readonly SshExecutor $ssh) {}

    /** Expose a newly upgraded ingress only after the node loaded its policy. */
    public function activate(ServerTunnel $t,array $settings): void
    {
        if($t->engine==='wattunnel' || empty($settings['source_identity_port']) || !($settings['enabled']??true))return;
        $server=$t->iran;$backend=$t->engine==='backpack'?'backpack':'network';
        $request=['action'=>'origin-probe',...($backend==='backpack'?['name'=>$t->remote_name]:['config'=>['id'=>$t->remote_name]])];
        $command=($server->ssh_user==='root'?'':'sudo -n ').'python3 /opt/speedbox-origin/host.py '.$backend.' -';
        $probe=json_decode($this->ssh->run($server,$command,65,json_encode($request,JSON_THROW_ON_ERROR)),true,16,JSON_THROW_ON_ERROR);
        if(($probe['ok']??false)!==true || empty($probe['data']['ready']))throw new RuntimeException('مسیر انتقال هویت مبدأ پیش از فعال‌سازی تأیید نشد.');
        $request['action']='origin-activate';
        $answer=json_decode($this->ssh->run($server,$command,30,json_encode($request,JSON_THROW_ON_ERROR)),true,16,JSON_THROW_ON_ERROR);
        if(($answer['ok']??false)!==true || empty($answer['data']['active']))throw new RuntimeException('ورودی تونل پس از آماده‌شدن هویت نود فعال نشد.');
    }

    /** A node behind a newly upgraded mapping must load its committed policy.
     *  Existing adapters keep working; the agent broker revokes their old links
     *  on restart, so stale proxy-IP reservations are released normally. */
    public function refreshNodePolicy(ServerTunnel $t,array $before,array $after,bool $force=false): void
    {
        if($t->engine==='wattunnel')return;
        if(empty($before['source_identity']) && empty($after['source_identity']))return;
        if(!$force && (empty($after['source_identity']) || !($after['enabled']??true)))return;
        if(!$force && !empty($before['source_identity']) && ($before['enabled']??true)
            && TrustedTunnelOrigin::ports($before)===TrustedTunnelOrigin::ports($after))return;
        $server=$t->foreign;
        if(!$server?->machine_id)throw new RuntimeException('ماشین نود مقصد برای بازیابی هویت پیدا نشد.');
        $sudo=$server->ssh_user==='root'?'':'sudo -n ';
        $this->ssh->run($server,$sudo.'systemctl restart xboard-node.service',45);
        $script=<<<'PY'
import json,re,socket,subprocess,sys,time
wanted=set(json.loads(sys.argv[1]))
for _ in range(60):
    pid=subprocess.run(['systemctl','show','xboard-node.service','--property=MainPID','--value'],text=True,capture_output=True).stdout.strip()
    rows=subprocess.run(['ss','-H','-lntup'],text=True,capture_output=True).stdout.splitlines()
    ports=set()
    for row in rows:
        fields=row.split()
        if len(fields)>4 and 'pid='+pid+',' in row:
            match=re.search(r':([0-9]+)$',fields[4])
            if match:ports.add(int(match.group(1)))
    try:
        with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as c:
            c.settimeout(1);c.connect('/run/speedbox-origin/broker.sock');c.sendall(b'{"operation":"ping","version":1}\n')
            ready=c.makefile('rb').readline(32)==b'ORIGIN1\n'
    except OSError:ready=False
    if pid.isdigit() and int(pid)>0 and ready and wanted.issubset(ports):print('NODE ORIGIN POLICY READY');break
    time.sleep(.5)
else:raise SystemExit('Node did not reload its committed source-identity policy')
PY;
        $wanted=ProxyServer::where('machine_id',$server->machine_id)->where('enabled',true)->whereIn('server_port',TrustedTunnelOrigin::ports($after))->pluck('server_port')->map(fn($v)=>(int)$v)->all();
        $this->ssh->run($server,$sudo.'python3 -c '.escapeshellarg($script).' '.escapeshellarg(json_encode($wanted)),40);
    }

    public function allocate(ServerTunnel $t,array $settings,array $entries): int
    {
        $old=(int)($settings['source_identity_port']??$t->settings['source_identity_port']??0);
        $used=array_merge(array_column($entries,'public_port'),array_column($entries,'target_port'));
        foreach($settings['mappings']??[] as $map) {
            $used=[...$used,...range((int)$map['public_port'],(int)($map['public_end']??$map['public_port'])),
                ...range((int)$map['target_port'],(int)($map['target_end']??$map['target_port']))];
            foreach($map['destinations']??[] as $d)$used=[...$used,...range((int)$d['port'],(int)$d['port']+(int)($map['public_end']??$map['public_port'])-(int)$map['public_port'])];
        }
        $used[]=(int)($settings['tunnel_port']??0);
        if($t->engine==='backpack')$used[]=40000+(int)$t->subnet;
        if($old>=1024 && $old<=65535 && !in_array($old,$used,true))return $old;
        $ids=[(int)$t->iran_server_id,(int)$t->foreign_server_id];
        $servers=ManagedServer::whereIn('id',$ids)->get();
        foreach($servers as $server)$used=[...$used,...app(TunnelManager::class)->reservedPorts($server)];
        $machines=$servers->pluck('machine_id')->filter()->all();
        $used=[...$used,...ProxyServer::whereIn('machine_id',$machines)->pluck('server_port')->map(fn($v)=>(int)$v)->all()];
        $offset=hexdec(substr(hash('sha256',$t->remote_name),0,6))%10000;$candidates=[];
        for($n=0;$n<64;$n++){ $p=20000+($offset+$n)%10000;if(!in_array($p,$used,true))$candidates[]=$p; }
        $script=<<<'PY'
import json,socket,sys
free=[]
for p in json.loads(sys.argv[1]):
    sockets=[]
    try:
        for family,host in ((socket.AF_INET,'0.0.0.0'),(socket.AF_INET6,'::')):
            for kind in (socket.SOCK_STREAM,socket.SOCK_DGRAM):
                try:
                    s=socket.socket(family,kind);sockets.append(s)
                    if family==socket.AF_INET6:s.setsockopt(socket.IPPROTO_IPV6,socket.IPV6_V6ONLY,1)
                    s.bind((host,p))
                except OSError as e:
                    if family==socket.AF_INET6 and e.errno in (97,99):continue
                    raise
        free.append(p)
    except OSError: pass
    finally:
        for s in sockets:s.close()
print(json.dumps(free))
PY;
        foreach($servers as $server){
            $raw=$this->ssh->run($server,'python3 -c '.escapeshellarg($script).' '.escapeshellarg(json_encode($candidates)),25);
            $free=json_decode($raw,true,8,JSON_THROW_ON_ERROR);$candidates=array_values(array_intersect($candidates,$free));
            if(!$candidates)throw new RuntimeException('پورت داخلی آزاد برای بازیابی آی‌پی واقعی روی دو سرور پیدا نشد.');
        }
        return (int)$candidates[0];
    }

    public function ensure(ManagedServer $server): void
    {
        $manifest=json_decode(file_get_contents(base_path('origin-dist/manifest.json')),true,16,JSON_THROW_ON_ERROR);
        foreach($manifest['source_files'] as $file=>$hash)if(!hash_equals($hash,hash('sha256',str_replace("\r\n","\n",file_get_contents(base_path($file))))))throw new RuntimeException('سورس و بستهٔ بازیابی مبدأ هماهنگ نیستند.');
        $key=$server->id.':'.hash('sha256',json_encode($manifest));if(isset($this->ready[$key]))return;
        $arch=match(trim($this->ssh->run($server,'uname -m',15))){'x86_64'=>'amd64','aarch64','arm64'=>'arm64',default=>throw new RuntimeException('معماری بازیابی مبدأ پشتیبانی نمی‌شود.')};
        $entry=$manifest['archives'][$arch];$local=base_path('origin-dist/tunnel-origin-linux-'.$arch.'.gz');
        if(!hash_equals($entry['sha256'],hash_file('sha256',$local)))throw new RuntimeException('صحت بستهٔ بازیابی مبدأ تأیید نشد.');
        $sudo=$server->ssh_user==='root'?'':'sudo -n ';
        $dir=trim($this->ssh->run($server,'umask 077; mktemp -d /tmp/speedbox-origin.XXXXXXXX',15));
        if(!preg_match('~^/tmp/speedbox-origin\.[A-Za-z0-9]+$~D',$dir))throw new RuntimeException('مسیر موقت بازیابی مبدأ معتبر نیست.');
        try {
            $helper=base_path('scripts/tunnel-origin/host.py');$helperHash=hash_file('sha256',$helper);
            $this->ssh->upload($server,$helper,$dir.'/host.py');
            $test=$entry['selftest'];$testLocal=base_path('origin-dist/'.$test['file']);
            if(!hash_equals($test['sha256'],hash_file('sha256',$testLocal)))throw new RuntimeException('صحت تست محلی بازیابی مبدأ تأیید نشد.');
            $this->ssh->upload($server,$testLocal,$dir.'/selftest.gz');
            $current=trim($this->ssh->run($server,$sudo.'sh -c '.escapeshellarg('if [ -f /opt/speedbox-origin/tunnel-origin ]; then sha256sum /opt/speedbox-origin/tunnel-origin | cut -d " " -f 1; fi'),15));
            if($current!==$entry['binary_sha256'])$this->ssh->upload($server,$local,$dir.'/binary.gz');
            $script='set -eu; command -v python3 >/dev/null; command -v systemctl >/dev/null; install -d -m 0755 /opt/speedbox-origin; '
                .'test "$(sha256sum '.escapeshellarg($dir.'/selftest.gz').' | cut -d " " -f 1)" = '.escapeshellarg($test['sha256']).'; gzip -dc '.escapeshellarg($dir.'/selftest.gz').' > '.escapeshellarg($dir.'/selftest').'; '
                .'test "$(sha256sum '.escapeshellarg($dir.'/selftest').' | cut -d " " -f 1)" = '.escapeshellarg($test['binary_sha256']).'; chmod 0700 '.escapeshellarg($dir.'/selftest').'; '
                .'env SPEEDBOX_ORIGIN_REQUIRE_UNIX=1 TMPDIR=/tmp '.escapeshellarg($dir.'/selftest').' -test.v -test.count=1; '
                .'test "$(sha256sum '.escapeshellarg($dir.'/host.py').' | cut -d " " -f 1)" = '.escapeshellarg($helperHash).'; '
                .'install -m 0700 '.escapeshellarg($dir.'/host.py').' /opt/speedbox-origin/host.py.new; mv /opt/speedbox-origin/host.py.new /opt/speedbox-origin/host.py; ';
            if($current!==$entry['binary_sha256'])$script.='test "$(sha256sum '.escapeshellarg($dir.'/binary.gz').' | cut -d " " -f 1)" = '.escapeshellarg($entry['sha256']).'; gzip -dc '.escapeshellarg($dir.'/binary.gz').' > /opt/speedbox-origin/tunnel-origin.new; '
                .'test "$(sha256sum /opt/speedbox-origin/tunnel-origin.new | cut -d " " -f 1)" = '.escapeshellarg($entry['binary_sha256']).'; chmod 0755 /opt/speedbox-origin/tunnel-origin.new; mv /opt/speedbox-origin/tunnel-origin.new /opt/speedbox-origin/tunnel-origin; ';
            $this->ssh->run($server,$sudo.'sh -c '.escapeshellarg($script),90);$this->ready[$key]=true;
        } finally {try{$this->ssh->run($server,'rm -f -- '.implode(' ',array_map('escapeshellarg',[$dir.'/host.py',$dir.'/binary.gz',$dir.'/selftest.gz',$dir.'/selftest'])).'; rmdir -- '.escapeshellarg($dir),15);}catch(\Throwable){}}
    }
}
