<?php
namespace App\Services;

use App\Models\ManagedServer;
use RuntimeException;

/** Structured stdin/file protocol; no menu scraping and no public management port. */
class BackpackRemote
{
    private array $testPaths = [];
    public function __construct(private readonly SshExecutor $ssh) {}

    /** Tests use an immutable binary of their own; active tunnels keep running. */
    private function testBinary(ManagedServer $server): string
    {
        if(isset($this->testPaths[$server->id]))return $this->testPaths[$server->id];
        $manifest=json_decode(file_get_contents(base_path('backpack-dist/manifest.json')),true,16,JSON_THROW_ON_ERROR);
        $arch=match(trim($this->ssh->run($server,'uname -m',20))){'x86_64'=>'amd64','aarch64','arm64'=>'arm64',default=>throw new RuntimeException('unsupported_arch')};
        $entry=$manifest['archives'][$arch];$local=base_path('backpack-dist/backpack-linux-'.$arch.'.gz');
        if(!hash_equals($entry['sha256'],hash_file('sha256',$local)))throw new RuntimeException('artifact_mismatch');
        $path='/opt/speedbox-backpack/tests/'.$entry['binary_sha256'].'/backpack';
        $sudo=$this->sudo($server);
        $current=trim($this->ssh->run($server,$sudo.'sh -c '.escapeshellarg('if [ -f '.$path.' ]; then sha256sum '.$path.' | cut -d " " -f 1; fi'),20));
        if($current!==$entry['binary_sha256'])$this->temporary($server,function($dir)use($server,$local,$path,$entry,$sudo){
            $this->ssh->upload($server,$local,$dir.'/binary.gz');
            $script='set -eu; test "$(sha256sum '.escapeshellarg($dir.'/binary.gz').' | cut -d " " -f 1)" = '.escapeshellarg($entry['sha256']).'; '
                .'install -d -m 0755 '.escapeshellarg(dirname($path)).'; gzip -dc '.escapeshellarg($dir.'/binary.gz').' > '.escapeshellarg($path.'.new').'; '
                .'test "$(sha256sum '.escapeshellarg($path.'.new').' | cut -d " " -f 1)" = '.escapeshellarg($entry['binary_sha256']).'; '
                .'chmod 0755 '.escapeshellarg($path.'.new').'; mv '.escapeshellarg($path.'.new').' '.escapeshellarg($path).';';
            $this->ssh->run($server,$sudo.'sh -c '.escapeshellarg($script),120);
        });
        return $this->testPaths[$server->id]=$path;
    }

    public function ensure(ManagedServer $server): void
    {
        $manifest = json_decode(file_get_contents(base_path('backpack-dist/manifest.json')), true, 16, JSON_THROW_ON_ERROR);
        $arch = trim($this->ssh->run($server, 'uname -m', 20));
        $arch = match ($arch) { 'x86_64' => 'amd64', 'aarch64', 'arm64' => 'arm64', default => throw new RuntimeException('unsupported_arch') };
        $local = base_path('backpack-dist/backpack-linux-'.$arch.'.gz');
        $entry = $manifest['archives'][$arch] ?? [];
        if (!is_file($local) || !hash_equals($entry['sha256'] ?? '', hash_file('sha256', $local))) throw new RuntimeException('artifact_mismatch');
        $sudo = $this->sudo($server);
        $path = '/opt/speedbox-backpack/backpack';
        $hash = trim($this->ssh->run($server, $sudo.'sh -c '.escapeshellarg('if [ -f '.$path.' ]; then sha256sum '.$path.' | cut -d " " -f 1; fi'), 30));
        if (hash_equals($entry['binary_sha256'], $hash)) return;
        if ($hash !== '') {
            // A host used only for connection tests has no card from which to
            // run maintenance. Replace its unused engine, keeping the old file.
            $configs=trim($this->ssh->run($server,$sudo.'sh -c '.escapeshellarg('if [ -d /etc/speedbox-backpack ]; then find /etc/speedbox-backpack -maxdepth 1 -name "sb-*.toml" -print; fi'),20));
            if($configs!==''||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new RuntimeException('موتور این سرور نیازمند به‌روزرسانی است؛ از عملیات کارت تونل، به‌روزرسانی را اجرا کنید.');
            $this->ssh->run($server,$sudo.'cp -p '.escapeshellarg($path).' '.escapeshellarg($path.'.before-'.$hash),20);
        }
        $this->temporary($server, function ($dir) use ($server, $local, $sudo, $entry, $path) {
            $this->ssh->upload($server, $local, $dir.'/binary.gz');
            $script = 'set -eu; test "$(uname -s)" = Linux; command -v systemctl >/dev/null; command -v systemd-run >/dev/null; '
                .'test "$(sha256sum '.escapeshellarg($dir.'/binary.gz').' | cut -d " " -f 1)" = '.escapeshellarg($entry['sha256']).'; '
                .'install -d -m 0755 /opt/speedbox-backpack; gzip -dc '.escapeshellarg($dir.'/binary.gz').' > /opt/speedbox-backpack/backpack.new; '
                .'test "$(sha256sum /opt/speedbox-backpack/backpack.new | cut -d " " -f 1)" = '.escapeshellarg($entry['binary_sha256']).'; '
                .'chmod 0755 /opt/speedbox-backpack/backpack.new; mv /opt/speedbox-backpack/backpack.new '.$path;
            $this->ssh->run($server, $sudo.'sh -c '.escapeshellarg($script), 120);
        });
    }

    public function call(ManagedServer $server, array $request): array
    {
        $binary=in_array($request['action']??'',['test-ready','spawn-host','spawn-peer','test-state','test-stop'],true)?$this->testBinary($server):'/opt/speedbox-backpack/backpack';
        return $this->temporary($server, function ($dir) use ($server, $request,$binary) {
            $local = tempnam(storage_path('app/private'), 'bp-');
            if ($local === false) throw new RuntimeException('temporary_file');
            try {
                chmod($local, 0600);
                file_put_contents($local, json_encode($request, JSON_THROW_ON_ERROR), LOCK_EX);
                $this->ssh->upload($server, $local, $dir.'/request');
                $raw = $this->ssh->run($server, $this->sudo($server).escapeshellarg($binary).' speedbox-json '.escapeshellarg($dir.'/request'), 100);
                $answer = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (($answer['ok'] ?? false) !== true || !is_array($answer['data'] ?? null)) throw new RuntimeException('invalid_response');
                return $answer['data'];
            } finally { @unlink($local); }
        });
    }

    private function sudo(ManagedServer $server): string { return $server->ssh_user === 'root' ? '' : 'sudo -n '; }

    /** Status is read-only; upgrades never go through ensure() on a shared host. */
    public function maintenance(ManagedServer $server, string $action, array $ports = [], ?string $revision = null): array
    {
        $manifest = json_decode(file_get_contents(base_path('backpack-dist/manifest.json')), true, 16, JSON_THROW_ON_ERROR);
        $machine = trim($this->ssh->run($server, 'uname -m', 20));
        $arch = match ($machine) { 'x86_64' => 'amd64', 'aarch64', 'arm64' => 'arm64', default => throw new RuntimeException('معماری این سرور پشتیبانی نمی‌شود.') };
        $entry = $manifest['archives'][$arch];
        $archive = base_path('backpack-dist/backpack-linux-'.$arch.'.gz');
        if (!is_file($archive) || !hash_equals($entry['sha256'], hash_file('sha256', $archive))) throw new RuntimeException('صحت بستهٔ موتور تأیید نشد.');
        return $this->temporary($server, function ($dir) use ($server,$action,$ports,$revision,$manifest,$entry,$archive) {
            $request = ['action'=>$action,'ports'=>array_values(array_unique(array_map('intval',$ports))), 'revision'=>$revision,
                'tuning'=>json_decode(file_get_contents(base_path('scripts/backpack/tuning.json')),true,16,JSON_THROW_ON_ERROR),
                'expected'=>['version'=>$manifest['upstream_version'],'protocol'=>$manifest['protocol'],
                    'binary_sha256'=>$entry['binary_sha256'],'archive_sha256'=>$entry['sha256']], 'archive'=>$dir.'/binary.gz'];
            $local = tempnam(storage_path('app/private'), 'bp-maintenance-');
            if ($local === false) throw new RuntimeException('ساخت فایل موقت کامل نشد.');
            try {
                chmod($local,0600);file_put_contents($local,json_encode($request,JSON_THROW_ON_ERROR));
                $this->ssh->upload($server,base_path('scripts/backpack/host-maintenance.py'),$dir.'/maintenance.py');
                $this->ssh->upload($server,$local,$dir.'/request');
                if ($action === 'stage') $this->ssh->upload($server,$archive,$dir.'/binary.gz');
                // Carry a JSON failure as data too, preserving per-key Optimize output.
                $script = 'command -v python3 >/dev/null || { echo "Python 3 is required on this server."; exit 1; }; '
                    .'python3 '.escapeshellarg($dir.'/maintenance.py').' '.escapeshellarg($dir.'/request');
                try { $raw=$this->ssh->run($server,$this->sudo($server).'sh -c '.escapeshellarg($script),180); }
                catch (RuntimeException $error) { $raw=$error->getMessage(); }
                $answer=json_decode($raw,true);
                if (!is_array($answer)) throw new RuntimeException('اجرای نگه‌داری سرور کامل نشد؛ اتصال SSH، دسترسی sudo و نصب Python 3 را بررسی کنید.');
                if (($answer['ok'] ?? false) !== true) throw new RuntimeException($answer['error'] ?? 'اجرای نگه‌داری سرور کامل نشد.');
                return $answer['data'];
            } finally { @unlink($local); }
        });
    }
    private function temporary(ManagedServer $server, callable $callback): mixed
    {
        $dir = $this->ssh->run($server, 'umask 077; mktemp -d /tmp/speedbox-bp.XXXXXXXX', 20);
        if (!preg_match('~^/tmp/speedbox-bp\.[A-Za-z0-9]+$~D', $dir)) throw new RuntimeException('invalid_temporary_path');
        try { return $callback($dir); }
        finally {
            try { $this->ssh->run($server, 'rm -f -- '.escapeshellarg($dir.'/request').' '.escapeshellarg($dir.'/binary.gz').' '.escapeshellarg($dir.'/maintenance.py').'; rmdir -- '.escapeshellarg($dir), 20); } catch (\Throwable) {}
        }
    }
}
