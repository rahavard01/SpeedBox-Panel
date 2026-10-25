<?php
namespace App\Services;

use App\Jobs\SendTunnelOutageAlert;
use App\Models\{ServerTunnel,TunnelOperation};
use Illuminate\Support\Facades\{Cache,DB,Schema};
use Illuminate\Support\Str;
use Throwable;

/** Fresh, leased status requests run through the existing deployments worker. */
class TunnelOutageMonitor
{
    private const READ_ONLY = ['status','logs','maintenance-status','history','diagnose','preview'];

    public function eligible(ServerTunnel $tunnel): bool
    {
        return (bool) ($tunnel->settings['telegram_alerts'] ?? false)
            && ($tunnel->settings['enabled'] ?? true)
            && !in_array($tunnel->status, ['deleted','stopped','queued'], true);
    }

    public function quiet(ServerTunnel $tunnel, bool $ignoreReadOnly=false): bool
    {
        $ids = [$tunnel->iran_server_id, $tunnel->foreign_server_id];
        $leases = DB::table('speedbox_tunnel_leases as leases')->join('speedbox_tunnel_operations as operations','operations.id','=','leases.operation_id')->whereIn('leases.managed_server_id',$ids);
        if ($ignoreReadOnly) $leases->whereNotIn('operations.action',self::READ_ONLY);
        if ($leases->exists()) return true;
        $deployments = DB::table('speedbox_deployments')->whereIn('managed_server_id',$ids)->where(function ($query) {
            $query->whereIn('status',['queued','running']);
            if (Schema::hasColumn('speedbox_deployments','updated_at')) $query->orWhere('updated_at','>',now()->subMinutes(2));
        });
        if ($deployments->exists()) return true;
        // Also cover tests and maintenance of another tunnel on a shared server.
        $tunnelIds = ServerTunnel::where(fn ($q) => $q->whereIn('iran_server_id',$ids)->orWhereIn('foreign_server_id',$ids))->pluck('id');
        if (TunnelOperation::whereIn('tunnel_id',$tunnelIds)->whereNotIn('action',self::READ_ONLY)
            ->where('updated_at','>',now()->subMinutes(2))->exists()) return true;
        return \App\Models\TunnelConnectionTest::where(fn ($q) => $q->whereIn('iran_server_id',$ids)->orWhereIn('foreign_server_id',$ids))
            ->where('updated_at','>',now()->subMinutes(2))->exists();
    }

    public function tick(): void
    {
        if (config('queue.default') === 'sync' || !Schema::hasTable('speedbox_tunnel_alerts')) return;
        $lock = Cache::lock('speedbox-tunnel-alert-tick',55);
        if (!$lock->get()) return;
        try {
            // Oldest check first prevents tunnels sharing an Iran server from starving.
            $tunnels = ServerTunnel::leftJoin('speedbox_tunnel_alerts as alerts','alerts.tunnel_id','=','speedbox_tunnels.id')
                ->whereNotIn('speedbox_tunnels.status',['deleted','stopped','queued'])
                ->orderBy('alerts.next_probe_at')->orderBy('speedbox_tunnels.id')->get(['speedbox_tunnels.*']);
            foreach ($tunnels as $tunnel) {
                if (!($tunnel->settings['enabled']??true)) { $this->reset($tunnel->id); continue; }
                $state = DB::table('speedbox_tunnel_alerts')->where('tunnel_id',$tunnel->id)->first();
                if ($this->quiet($tunnel,true)) { $this->reset($tunnel->id); continue; }
                if ($this->eligible($tunnel) && app(TunnelAlertSettings::class)->capability()['available'] && $state?->incident && (!$state->next_delivery_at || now()->gte($state->next_delivery_at))) {
                    SendTunnelOutageAlert::dispatch($tunnel->id,$state->incident);
                }
                if ($state?->next_probe_at && now()->lt($state->next_probe_at)) continue;
                try {
                    app(TunnelManager::class)->queue('status',$tunnel,null,['outage_probe'=>true]);
                    DB::table('speedbox_tunnel_alerts')->updateOrInsert(['tunnel_id'=>$tunnel->id],
                        ['next_probe_at'=>now()->addMinute(),'updated_at'=>now()]);
                } catch (Throwable) {
                    // A concurrent deployment may win the host lease; try next minute.
                }
            }
        } finally { $lock->release(); }
    }

    public function record(TunnelOperation $operation): void
    {
        if ($operation->action !== 'status' || empty($operation->payload['outage_probe']) || !Schema::hasTable('speedbox_tunnel_alerts')) return;
        $tunnel = ServerTunnel::find($operation->tunnel_id);
        if (!$tunnel) return;
        if (!$this->eligible($tunnel) || $this->quiet($tunnel)) { $this->reset($tunnel->id); return; }
        // Lack of access has its own incident type; never label it confirmed tunnel loss.
        $health = $operation->status === 'succeeded' ? $this->health($tunnel) : null;
        $kind=TunnelHealth::unreachable($tunnel)?'unreachable':($health===false?'down':($health===true?'up':'unknown'));
        $operation->update(['result'=>[...($operation->result??[]),'observation'=>$kind]]);
        DB::transaction(function () use ($operation,$tunnel,$health,$kind) {
            DB::table('speedbox_tunnel_alerts')->insertOrIgnore(['tunnel_id'=>$tunnel->id,'created_at'=>now(),'updated_at'=>now()]);
            $state = DB::table('speedbox_tunnel_alerts')->where('tunnel_id',$tunnel->id)->lockForUpdate()->first();
            if ($state->last_operation_id && $operation->id <= $state->last_operation_id) return;
            $previous=$state->last_operation_id?TunnelOperation::find($state->last_operation_id):null;
            $previousKind=$previous?->result['observation']??null;
            $values = ['last_operation_id'=>$operation->id,'observed_at'=>now(),'updated_at'=>now()];
            if ($kind === 'up') $values += ['failures'=>0,'incident'=>null,'delivered_to'=>null,'next_delivery_at'=>null];
            elseif ($kind==='unknown') $values += ['failures'=>0,'incident'=>null,'delivered_to'=>null,'next_delivery_at'=>null];
            else {
                // Two independently scheduled checks; duplicate/rapid observations cannot trip it.
                $failures = $previousKind!==$kind?1:($state->observed_at && now()->diffInSeconds($state->observed_at,true) < 45
                    ? $state->failures : min(2,$state->failures + 1));
                $values['failures'] = $failures;
                if ($previousKind!==$kind) $values += ['incident'=>null,'delivered_to'=>null,'next_delivery_at'=>null];
                if ($failures >= 2 && (!$state->incident || $previousKind!==$kind)) {
                    $values += ['incident'=>(string) Str::uuid(),'delivered_to'=>null,'next_delivery_at'=>now()];
                }
            }
            DB::table('speedbox_tunnel_alerts')->where('tunnel_id',$tunnel->id)->update($values);
        });
        $state = DB::table('speedbox_tunnel_alerts')->where('tunnel_id',$tunnel->id)->first();
        if (app(TunnelAlertSettings::class)->capability()['available'] && $state?->incident && $state->failures >= 2 && (!$state->next_delivery_at || now()->gte($state->next_delivery_at))) {
            SendTunnelOutageAlert::dispatch($tunnel->id,$state->incident)->afterCommit();
        }
    }

    public function health(ServerTunnel $tunnel): ?bool
    {
        $states = [];
        foreach (['iran','foreign'] as $side) {
            $snapshot = $tunnel->remote_status[$side] ?? [];
            if (isset($snapshot['error'])) return null;
            if (!empty($tunnel->settings['restart_hours']) && !empty($snapshot['last_restart']) && time() - $snapshot['last_restart'] < 120) return null;
            if (($tunnel->settings['quota_mode'] ?? '') === 'enforce' && !empty($snapshot['quota_exhausted'])) return null;
            // GOST TCP/UDP forwards from Iran; the foreign side has no GOST unit.
            $needsService = !($tunnel->engine === 'gost' && $side === 'foreign' && $tunnel->transport !== 'grpc');
            if ($needsService && ($snapshot['active'] ?? null) === false) return false;
            if ($needsService && ($snapshot['active'] ?? null) !== true) return null;
            // Only Iran owns destination checks in GOST; foreign.connected is normally null.
            if ($tunnel->engine === 'gost' && $side === 'foreign') continue;
            $connected = $tunnel->engine === 'backpack' ? data_get($snapshot,'metrics.connected') : ($snapshot['connected'] ?? null);
            if ($connected === false) return false;
            $states[] = $connected;
        }
        return $states === ($tunnel->engine === 'gost' ? [true] : [true,true]) ? true : null;
    }

    public function reset(int $id): void
    {
        DB::table('speedbox_tunnel_alerts')->where('tunnel_id',$id)->update([
            'failures'=>0,'incident'=>null,'delivered_to'=>null,'next_delivery_at'=>null,'observed_at'=>null,'updated_at'=>now(),
        ]);
    }
}
