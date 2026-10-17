<?php

namespace App\Services;

use App\Models\ManagedServer;
use App\Models\ProxyServer;

class ManagedServerRemoval
{
    public function __construct(private readonly NodeControlService $nodes)
    {
    }

    /** The caller must hold the managed-server row lock inside a transaction. */
    public function removeLocked(ManagedServer $server): void
    {
        $machine = $server->machine;
        abort_if($machine?->managedServers()->where('id', '!=', $server->id)->exists(), 422, 'این سرور اتصال مشترک دارد؛ ابتدا ارتباط مشترک را اصلاح کنید.');
        $ids = $machine ? ProxyServer::where('machine_id', $machine->id)->pluck('id') : collect();
        if ($ids->isNotEmpty()) {
            ProxyServer::whereIn('id', $ids)->update(['machine_id' => null, 'enabled' => false]);
        }
        foreach ($ids as $id) $this->nodes->clearNodeCache((int) $id);
        $server->delete();
        // Deleting the machine also revokes its per-server authentication token.
        $machine?->delete();
    }
}
