<?php

namespace App\Services;

use App\Models\ManagedServer;
use RuntimeException;

/** Reuse verified helper bytes; replay only an explicit pre-execution cache miss. */
final class RemoteScriptRunner
{
    public function __construct(private readonly SshExecutor $ssh) {}

    public function run(ManagedServer $server, string $source, array $request, int $timeout = 180, array $architectureFields = []): array
    {
        $content = file_get_contents(base_path($source));
        if ($content === false) throw new RuntimeException('خواندن ابزار سرور کامل نشد.');
        $hash = hash('sha256', $content);
        $packet = ['hash' => $hash, 'request' => $request, 'architecture_fields' => (object) $architectureFields];
        $command = ($server->ssh_user === 'root' ? '' : 'sudo -n ').'python3 -c '.escapeshellarg(self::BOOTSTRAP);
        $invoke = function (array $input) use ($server, $command, $timeout): array {
            try { $raw = $this->ssh->run($server, $command, $timeout, json_encode($input, JSON_THROW_ON_ERROR)); }
            catch (RuntimeException $error) {
                $raw = $error->getMessage();
                if (!is_array(json_decode($raw, true))) throw $error;
            }
            $answer = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($answer)) throw new RuntimeException('پاسخ ابزار سرور معتبر نیست.');
            return $answer;
        };
        $answer = $invoke($packet);
        if (is_array($answer) && ($answer['speedbox_helper_missing'] ?? null) === $hash && count($answer) === 1) {
            // No action ran. A timeout, invalid response or failed action is never retried.
            $answer = $invoke([...$packet, 'content' => base64_encode($content)]);
        }
        if (!is_array($answer) || !array_key_exists('ok', $answer)) throw new RuntimeException('پاسخ ابزار سرور معتبر نیست.');
        return $answer;
    }

    /** Fresh remote hashes, never a time-only cache of a host's installation. */
    public function filesCurrent(ManagedServer $server, array $files, array $commands = [], array $directories = []): bool
    {
        $script = <<<'PY'
import hashlib,json,os,platform,shutil,sys
from pathlib import Path
p=json.load(sys.stdin)
arch={'x86_64':'amd64','aarch64':'arm64','arm64':'arm64'}.get(platform.machine())
def current(name,spec):
 path=Path(name)
 wanted=spec['sha256']
 if isinstance(wanted,dict):wanted=wanted.get(arch)
 if not wanted or path.is_symlink() or not path.is_file():return False
 if spec.get('executable') and not os.access(path,os.X_OK):return False
 h=hashlib.sha256()
 with path.open('rb') as f:
  for block in iter(lambda:f.read(1048576),b''):h.update(block)
 return h.hexdigest()==wanted
print(json.dumps({'current':all(current(k,v) for k,v in p['files'].items()) and all(shutil.which(c) for c in p['commands']) and all(Path(d).is_dir() for d in p['directories'])}))
PY;
        $command = ($server->ssh_user === 'root' ? '' : 'sudo -n ').'python3 -c '.escapeshellarg($script);
        $reply = json_decode($this->ssh->run($server, $command, 40, json_encode(['files'=>(object)$files,'commands'=>$commands,'directories'=>$directories], JSON_THROW_ON_ERROR)), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($reply) || !is_bool($reply['current'] ?? null)) throw new RuntimeException('بررسی صحت ابزار سرور کامل نشد.');
        return $reply['current'];
    }

    private const BOOTSTRAP = <<<'PY'
import base64,hashlib,json,os,platform,re,stat,subprocess,sys,tempfile
from pathlib import Path
p=json.load(sys.stdin)
h=p['hash']
if not isinstance(h,str) or not re.fullmatch('[a-f0-9]{64}',h):raise ValueError('invalid helper identity')
root=Path('/opt/speedbox-remote-scripts')
if root.is_symlink():raise ValueError('unsafe helper directory')
if root.exists() and (not root.is_dir() or root.stat().st_uid!=os.geteuid() or stat.S_IMODE(root.stat().st_mode)&0o022):raise ValueError('unsafe helper ownership')
target=root/(h+'.py')
if target.is_symlink():raise ValueError('unsafe helper file')
current=target.is_file() and hashlib.sha256(target.read_bytes()).hexdigest()==h
if not current:
 if 'content' not in p:
  print(json.dumps({'speedbox_helper_missing':h}));sys.exit(0)
 content=base64.b64decode(p['content'],validate=True)
 if hashlib.sha256(content).hexdigest()!=h:raise ValueError('helper checksum mismatch')
 root.mkdir(mode=0o700,parents=False,exist_ok=True)
 fd,name=tempfile.mkstemp(prefix='.helper-',dir=root)
 try:
  with os.fdopen(fd,'wb') as f:f.write(content);f.flush();os.fsync(f.fileno())
  os.chmod(name,0o700);os.replace(name,target)
 finally:
  if os.path.exists(name):os.unlink(name)
request=p['request']
arch={'x86_64':'amd64','aarch64':'arm64','arm64':'arm64'}.get(platform.machine())
for key,choices in p.get('architecture_fields',{}).items():
 if arch not in choices:raise ValueError('unsupported architecture')
 request[key]=choices[arch]
fd,name=tempfile.mkstemp(prefix='.request-',dir=root)
try:
 with os.fdopen(fd,'w') as f:json.dump(request,f)
 result=subprocess.run([sys.executable,str(target),name])
 sys.exit(result.returncode)
finally:os.unlink(name)
PY;
}
