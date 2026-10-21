<?php
namespace App\Services;

use App\Jobs\{RunTunnelOperation, RunTunnelMaintenance, RunTunnelReconfigure, RunNetworkTunnelOperation, RunNetworkTunnelTest};
use App\Models\{ManagedServer, ServerTunnel, TunnelConnectionTest, TunnelOperation};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TunnelManager
{
    public const REVERSE = ['tcp','tcpmux','stealth','pck','ws','wss','wsmux','wssmux','kcp','quic','udp'];
    public const DIRECT = ['xdi','pck','udp','quic','sni'];

    public static function busy(int $serverId): bool
    {
        return Schema::hasTable('speedbox_tunnel_leases') && DB::table('speedbox_tunnel_leases')->where('managed_server_id', $serverId)->exists();
    }

    public static function protectServer(int $id, bool $removal = false): void
    {
        abort_if(self::busy($id), 409, 'عملیات تونل روی این سرور در حال اجراست؛ پس از پایان آن دوباره تلاش کنید.');
        if ($removal && Schema::hasTable('speedbox_tunnels')) {
            abort_if(ServerTunnel::where('status','!=','deleted')->where(fn($q)=>$q->where('iran_server_id',$id)->orWhere('foreign_server_id',$id))->exists(), 422, 'ابتدا تونل‌های مرتبط با این سرور را حذف کنید.');
            // Historical test records must not keep a retired server alive forever.
            $tests=TunnelConnectionTest::where(fn($q)=>$q->where('iran_server_id',$id)->orWhere('foreign_server_id',$id))->pluck('id');
            $tunnels=ServerTunnel::where('status','deleted')->where(fn($q)=>$q->where('iran_server_id',$id)->orWhere('foreign_server_id',$id))->pluck('id');
            TunnelOperation::whereIn('test_id',$tests)->orWhereIn('tunnel_id',$tunnels)->delete();
            TunnelConnectionTest::whereIn('id',$tests)->delete();
            ServerTunnel::whereIn('id',$tunnels)->delete();
        }
    }

    public function queue(string $action, ?ServerTunnel $tunnel, ?TunnelConnectionTest $test, array $payload = []): TunnelOperation
    {
        return DB::transaction(function () use ($action, $tunnel, $test, $payload) {
            abort_if(config('queue.default') === 'sync', 422, 'برای اجرای عملیات تونل، صف عملیات سرورها باید فعال باشد.');
            $entity = $tunnel ?? $test;
            $ids = [$entity->iran_server_id, $entity->foreign_server_id]; sort($ids);
            $network = $tunnel && $tunnel->engine !== 'backpack';
            $maintenance = !$network && in_array($action,['maintenance-status','optimize','engine-update'],true);
            if ($maintenance) {
                $scope=$this->maintenanceScope($tunnel);
                if($action==='engine-update')abort_unless(($payload['confirmed_server_ids']??[])===$scope['server_ids'],409,'سرورهای وابسته تغییر کرده‌اند؛ وضعیت را دوباره بررسی کنید.');
                $ids=$scope['server_ids'];
                $payload=[...$payload,...$scope,'package_hash'=>hash_file('sha256',base_path('backpack-dist/manifest.json'))];
            }
            $servers = ManagedServer::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($servers->count() === count($ids), 422, 'دو سرور متفاوت را از مدیریت سرورها انتخاب کنید.');
            abort_if($servers[0]->machine_id && $servers[0]->machine_id === $servers[1]->machine_id, 422, 'دو رکورد انتخاب‌شده متعلق به یک ماشین هستند.');
            abort_if($servers[0]->host === $servers[1]->host, 422, 'سرور ایران و خارج باید متفاوت باشند.');
            foreach ($servers as $server) {
                abort_if($server->status === 'removing' || self::busy($server->id) || $server->deployments()->whereIn('status', ['queued','running'])->exists(), 409, 'عملیات دیگری روی یکی از این سرورها در حال اجراست.');
            }
            if ($maintenance) abort_unless($this->maintenanceScope($tunnel,true) === $scope,409,'تونل‌های وابسته تغییر کرده‌اند؛ دوباره تلاش کنید.');
            $op = TunnelOperation::create(['action' => $action, 'tunnel_id' => $tunnel?->id, 'test_id' => $test?->id,
                'status' => 'queued', 'progress' => 0, 'payload' => $payload, 'deadline' => now()->addMinutes($test ? 40 : ($maintenance ? 45 : 10)), 'message' => 'در صف عملیات سرورها']);
            foreach ($ids as $id) DB::table('speedbox_tunnel_leases')->insert(['managed_server_id' => $id, 'operation_id' => $op->id]);
            if ($action==='network-test') RunNetworkTunnelTest::dispatch($op->id)->afterCommit();
            elseif ($network) RunNetworkTunnelOperation::dispatch($op->id)->afterCommit();
            elseif ($maintenance) RunTunnelMaintenance::dispatch($op->id)->afterCommit();
            elseif ($action==='reconfigure') RunTunnelReconfigure::dispatch($op->id)->afterCommit();
            else RunTunnelOperation::dispatch($op->id)->afterCommit();
            return $op;
        });
    }

    /** Connected component: upgrading a shared Iran cannot strand its other peers. */
    public function maintenanceScope(ServerTunnel $tunnel,bool $lock=false): array
    {
        $ids=[(int)$tunnel->iran_server_id,(int)$tunnel->foreign_server_id];sort($ids);
        $query=ServerTunnel::where('status','!=','deleted');
        if(Schema::hasColumn('speedbox_tunnels','engine'))$query->where('engine','backpack');
        if($lock)$query->lockForUpdate();
        $all=$query->get(['id','iran_server_id','foreign_server_id']);
        do {
            $before=$ids;
            foreach($all as $item) if(in_array((int)$item->iran_server_id,$ids,true)||in_array((int)$item->foreign_server_id,$ids,true)) {
                $ids[]=(int)$item->iran_server_id;$ids[]=(int)$item->foreign_server_id;
            }
            $ids=array_values(array_unique($ids));sort($ids);
        } while($before!==$ids);
        $tunnels=$all->filter(fn($item)=>in_array((int)$item->iran_server_id,$ids,true))->pluck('id')->map(fn($id)=>(int)$id)->sort()->values()->all();
        return ['server_ids'=>$ids,'tunnel_ids'=>$tunnels];
    }

    public function reservedPorts(ManagedServer $server): array
    {
        $ports=[(int)$server->ssh_port];
        foreach(ServerTunnel::where('status','!=','deleted')->where(fn($q)=>$q->where('iran_server_id',$server->id)->orWhere('foreign_server_id',$server->id))->get() as $t) {
            $iran=(int)$t->iran_server_id===(int)$server->id;
            if($t->engine!=='backpack') {
                if(!$iran && $t->engine==='gost' && $t->transport==='grpc')$ports[]=(int)$t->settings['tunnel_port'];
                foreach($t->settings['mappings'] as $map)$ports=[...$ports,...range((int)$map[$iran?'public_port':'target_port'],(int)($map[$iran?'public_end':'target_end']??$map[$iran?'public_port':'target_port']))];
                continue;
            }
            $listenerIran=$t->kind==='reverse'||$t->orientation==='swapped';
            if($iran===$listenerIran)$ports[]=(int)$t->settings['tunnel_port'];
            foreach($t->settings['mappings'] as $map)$ports[]=(int)$map[$iran?'public_port':'target_port'];
        }
        if($server->machine_id) foreach(\App\Models\ProxyServer::where('machine_id',$server->machine_id)->pluck('server_port') as $port)$ports[]=(int)$port;
        return array_values(array_unique(array_filter($ports,fn($port)=>$port>0&&$port<=65535)));
    }

    public function saveHostStatus(int $id,array $snapshot): void
    {
        DB::table('speedbox_tunnel_host_status')->updateOrInsert(['managed_server_id'=>$id],
            ['snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'checked_at'=>now()]);
    }

    public function rows(array $raw, string $orientation): array
    {
        $rows = [];
        foreach ($raw as $r) {
            $kind = $r['k'] ?? ''; $transport = $r['t'] ?? '';
            if (!in_array($kind, ['reverse','direct'], true) || !is_string($transport)) continue;
            $supported = in_array($transport, $kind === 'reverse' ? self::REVERSE : self::DIRECT, true);
            // A reversed reverse tunnel exposes ports abroad. It cannot silently be
            // relabelled as an Iran-entry tunnel. Reversed L3 is handled by the bridge.
            if ($orientation === 'swapped' && $kind === 'reverse') $supported = false;
            $status = in_array($r['s'] ?? '', ['ok','unstable','down','skipped','testing'], true) ? $r['s'] : 'down';
            $rows[] = ['id' => $orientation.':'.$kind.':'.$transport, 'kind' => $kind, 'transport' => $transport,
                'label' => ($kind === 'reverse' ? 'Reverse ' : 'Direct ').strtoupper($transport), 'orientation' => $orientation,
                'status' => $status, 'selectable' => $supported && in_array($status, ['ok','unstable'], true),
                'reason' => !$supported ? ($orientation === 'swapped' && $kind === 'reverse'
                    ? 'این نتیجه ورود کاربران از سرور خارج را آزمایش کرده است؛ برای ورود از ایران قابل استفاده نیست.'
                    : 'ساخت خودکار این روش به تنظیمات تکمیلی نیاز دارد و در این نسخه فعال نیست.')
                    : ($status === 'skipped' ? 'این روش روی دو سرور آمادهٔ آزمایش نبود.' : ''),
                'ok' => max(0, (int) ($r['o'] ?? 0)), 'total' => max(0, (int) ($r['z'] ?? $r['n'] ?? 0)),
                'rtt' => max(0, (int) ($r['r'] ?? 0)), 'download' => max(0, (float) ($r['m'] ?? 0)), 'upload' => max(0, (float) ($r['upload_mbps'] ?? 0)),
                'recommended' => false];
        }
        return $rows;
    }

    public function recommend(array $rows): array
    {
        $eligible = array_filter($rows, fn ($r) => $r['selectable'] && $r['status'] === 'ok');
        usort($eligible, fn ($a,$b) => ($a['rtt'] ?: PHP_INT_MAX) <=> ($b['rtt'] ?: PHP_INT_MAX) ?: $b['download'] <=> $a['download']);
        $id = $eligible[0]['id'] ?? null;
        return array_map(fn ($r) => [...$r, 'recommended' => $r['id'] === $id], $rows);
    }

    public function finish(TunnelOperation $op, string $status, string $message, array $result = []): void
    {
        DB::transaction(function () use ($op,$status,$message,$result) {
            $op->update(['status' => $status, 'progress' => 100, 'message' => $message, 'result' => $result]);
            DB::table('speedbox_tunnel_leases')->where('operation_id', $op->id)->delete();
        });
        // Notification failures must never change the result of a tunnel operation.
        try { app(TunnelOutageMonitor::class)->record($op->fresh()); }
        catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Tunnel health observation could not be saved.', ['operation_id'=>$op->id]); }
    }
}
