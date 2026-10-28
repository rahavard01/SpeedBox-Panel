<?php

namespace App\Services;

use App\Models\ManagedServer;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MachineResourceSnapshot
{
    public function collect(SshExecutor $ssh, ManagedServer $server): void
    {
        if (!$server->machine_id) return;
        $sampledAt = time();
        $script = <<<'SH'
set -eu
export LC_ALL=C
first=$(awk '/^cpu / {t=0;for(i=2;i<=9;i++)t+=$i;print t,$5+$6;exit}' /proc/stat)
sleep 0.25
printf '{"cpu":'
awk -v first="$first" '/^cpu / {split(first,a," ");t=0;for(i=2;i<=9;i++)t+=$i;d=t-a[1];idle=$5+$6-a[2];v=d>0?100*(d-idle)/d:0;if(v<0)v=0;if(v>100)v=100;printf "%.2f",v;exit}' /proc/stat
awk '/^MemTotal:/ {t=$2} /^MemAvailable:/ {a=$2;has=1} /^MemFree:/ {f=$2} /^Buffers:/ {b=$2} /^Cached:/ {c=$2} /^SReclaimable:/ {r=$2} /^Shmem:/ {s=$2} /^SwapTotal:/ {st=$2} /^SwapFree:/ {sf=$2} END {if(!has)a=f+b+c+r-s;u=t-a;if(u<0)u=0;printf ",\"mem\":{\"total\":%.0f,\"used\":%.0f},\"swap\":{\"total\":%.0f,\"used\":%.0f}",t*1024,u*1024,st*1024,(st-sf)*1024}' /proc/meminfo
df -Pk / | awk 'NR==2 {printf ",\"disk\":{\"total\":%.0f,\"used\":%.0f}",$2*1024,$3*1024}'
printf '}\n'
SH;
        $load = json_decode($ssh->run($server, 'sh -s', 30, $script), true, 16, JSON_THROW_ON_ERROR);
        if (!isset($load['cpu'], $load['mem']['total'], $load['mem']['used'], $load['disk']['total']) || $load['mem']['total'] <= 0) {
            throw new RuntimeException('نمونه منابع سرور معتبر نیست.');
        }
        DB::transaction(function () use ($server, $sampledAt, $load) {
            $machine = ServerMachine::whereKey($server->machine_id)->lockForUpdate()->first();
            if (!$machine || (int) data_get($machine->load_status, 'updated_at', 0) >= $sampledAt) return;
            // SSH proves host reachability, not agent liveness or capabilities.
            $machine->update(['load_status' => [...$load, 'capabilities' => data_get($machine->load_status, 'capabilities', []), 'updated_at' => $sampledAt]]);
            ServerMachineLoadHistory::create([
                'machine_id' => $machine->id, 'cpu' => $load['cpu'],
                'mem_total' => $load['mem']['total'], 'mem_used' => $load['mem']['used'],
                'disk_total' => $load['disk']['total'], 'disk_used' => $load['disk']['used'],
                'recorded_at' => $sampledAt,
            ]);
        });
    }
}
