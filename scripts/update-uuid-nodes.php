<?php

// No option lists registered machines only. --apply-all explicitly deploys the
// panel-built, verified node binary over each machine's existing SSH connection.
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (array_diff(array_slice($argv, 1), ['--apply-all'])) throw new RuntimeException('Unknown option.');
$apply = in_array('--apply-all', $argv, true);
$servers = App\Models\ManagedServer::whereNotNull('machine_id')->orderBy('id')->get();
$failed = false;
$ssh = app(App\Services\SshExecutor::class);
foreach ($servers as $server) {
    echo 'server_id='.$server->id.' name='.json_encode($server->name, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    if (!$apply) continue;
    $sudo = $server->ssh_user === 'root' ? '' : 'sudo -n ';
    $arch = $ssh->run($server, 'uname -m', 25);
    $arch = match ($arch) { 'x86_64', 'amd64' => 'amd64', 'aarch64', 'arm64' => 'arm64', default => throw new RuntimeException('Unsupported node architecture.') };
    $binary = storage_path('app/agent/xboard-node-linux-'.$arch);
    if (!is_file($binary)) throw new RuntimeException('Panel-built node is missing; stopped.');
    $remote = $ssh->run($server, 'mktemp -d /tmp/speedbox-uuid-node.XXXXXXXX', 25);
    if (!preg_match('~^/tmp/speedbox-uuid-node\.[A-Za-z0-9]+$~D', $remote)) throw new RuntimeException('Invalid remote staging path.');
    try {
        $ssh->upload($server, $binary, $remote.'/xboard-node');
        $ssh->upload($server, $root.'/scripts/update-uuid-node.sh', $remote.'/update.sh');
        $hash = hash_file('sha256', $binary);
        echo $ssh->run($server, $sudo.'bash '.escapeshellarg($remote.'/update.sh').' '.escapeshellarg($remote.'/xboard-node').' '.escapeshellarg($hash), 90).PHP_EOL;
    } catch (Throwable $error) {
        // SSH command output can include internal connection details.
        fwrite(STDERR, 'Node update stopped at server_id='.$server->id.'; inspect its service and /var/backups/speedbox-uuid-node before continuing.'.PHP_EOL);
        $failed = true;
    } finally {
        try { $ssh->run($server, 'rm -f -- '.escapeshellarg($remote.'/xboard-node').' '.escapeshellarg($remote.'/update.sh').'; rmdir -- '.escapeshellarg($remote), 25); } catch (Throwable) {}
    }
    if ($failed) exit(1);
}
echo $apply ? 'UUID NODES UPDATE COMPLETE: '.$servers->count().PHP_EOL : 'UUID NODES PREVIEW ONLY: '.$servers->count().PHP_EOL;
