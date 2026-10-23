<?php

// Upgrade existing WatTunnel pairs through the same durable, locked and
// rollback-capable operations as the panel. Update node agents first.
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (array_diff(array_slice($argv, 1), ['--apply-all'])) throw new RuntimeException('Unknown option.');
$apply = in_array('--apply-all', $argv, true);
$tunnels = App\Models\ServerTunnel::where('engine', 'wattunnel')->where('status', '!=', 'deleted')->orderBy('id')->get();
foreach ($tunnels as $tunnel) {
    $settings = App\Services\TrustedTunnelOrigin::upgrade($tunnel, $tunnel->settings);
    echo 'tunnel_id='.$tunnel->id.' source_identity='.($settings['source_identity'] ? 'enabled' : 'no-node-agent').PHP_EOL;
    // MySQL JSON can reorder object keys; compare settings by value.
    if (!$apply || $settings == $tunnel->settings) continue;
    $op = app(App\Services\TunnelManager::class)->queue('reapply', $tunnel, null, ['settings' => $settings]);
    $deadline = time() + 660;
    $last = null;
    do {
        $op->refresh();
        $phase = $op->payload['phase'] ?? 'queued';
        if ($phase !== $last) { echo 'operation_id='.$op->id.' phase='.$phase.PHP_EOL; $last = $phase; }
        if ($op->status === 'succeeded') break;
        if (!in_array($op->status, ['queued', 'running'], true)) {
            throw new RuntimeException('Tunnel upgrade failed; inspect operation '.$op->id.' in the panel. Previous settings were retained; verify its rollback report.');
        }
        if (time() >= $deadline) throw new RuntimeException('Operation '.$op->id.' is still pending; inspect the deployments queue before retrying.');
        sleep(2);
    } while (true);
    $fresh = $tunnel->fresh();
    if ($fresh->settings != $settings) throw new RuntimeException('Paired tunnel configuration was not committed.');
}
echo $apply ? 'TUNNEL ORIGIN UPGRADE COMPLETE'.PHP_EOL : 'PREVIEW ONLY: update node agents, then use --apply-all.'.PHP_EOL;
