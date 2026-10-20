<?php

namespace App\Services;

use App\Jobs\DeployManagedServer;
use App\Models\ManagedServer;
use App\Models\ServerDeployment;
use RuntimeException;
use Throwable;

class EnsureMachineWarpSupport
{
    public function ensure(SshExecutor $ssh, ManagedServer $server, ServerDeployment $operation): void
    {
        $machine = $server->machine->fresh();
        if ($machine->supportsWarp()) return;

        // A missing panel report alone does not prove that the installed release is old.
        // Compare the running executable with the reviewed bundled release first.
        $manifest = json_decode(file_get_contents(base_path('agent-dist/manifest.json')), true, 32, JSON_THROW_ON_ERROR);
        $hashes = [];
        foreach (['amd64', 'arm64'] as $arch) {
            $hash = $manifest['archives'][$arch]['binaries']['xboard-node-linux-'.$arch]['sha256'] ?? '';
            if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('Invalid agent manifest');
            $hashes[] = $hash;
        }
        $operation->update(['progress' => 10, 'log' => 'در حال بررسی نرم‌افزار سرور…']);
        $sudo = $server->ssh_user === 'root' ? '' : 'sudo -n ';
        $probe = 'pid=$(systemctl show xboard-node --property=MainPID --value); '
            .'case "$pid" in ""|0|*[!0-9]*) exit 1;; esac; '
            .'systemctl is-active --quiet xboard-node && sha256sum "/proc/$pid/exe"';
        try {
            $output = $ssh->run($server, $sudo.'sh -c '.escapeshellarg($probe), 30);
        } catch (Throwable) {
            $output = '';
        }
        preg_match('/^([a-f0-9]{64})\s/m', $output, $match);
        if (!in_array($match[1] ?? '', $hashes, true)) {
            $operation->update(['progress' => 15, 'log' => 'در حال به‌روزرسانی نرم‌افزار سرور…']);
            $this->upgrade($ssh, $server, $operation);
        }
        $operation->update(['progress' => 25, 'log' => 'در انتظار تأیید ارتباط نرم‌افزار سرور با پنل…']);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if ($machine->fresh()->supportsWarp()) return;
            $this->pause();
        }
        throw new RuntimeException('WARP capability report not received');
    }

    protected function upgrade(SshExecutor $ssh, ManagedServer $server, ServerDeployment $operation): void
    {
        // The parent remains running throughout, so other operations cannot enter
        // between the upgrade and WARP preparation. Keep a normal upgrade history.
        $upgrade = ServerDeployment::create([
            'managed_server_id' => $server->id, 'requested_by' => $operation->requested_by,
            'action' => 'upgrade', 'kernel' => $operation->kernel,
            'status' => 'queued', 'progress' => 0,
        ]);
        (new DeployManagedServer($upgrade->id))->handle($ssh);
        if ($upgrade->fresh()->status !== 'succeeded') throw new RuntimeException('Agent upgrade failed');
    }

    protected function pause(): void
    {
        sleep(3);
    }
}
