<?php

namespace App\Services;

use App\Models\{ManagedServer, ProxyServer, ServerTunnel};
use App\Support\WatTunnelRules;
use Illuminate\Support\Facades\Schema;

/** Keep identity policy tied to the committed, paired tunnel configuration. */
final class TrustedTunnelOrigin
{
    public static function udpOnlyBackpack(ServerTunnel $tunnel): bool
    {
        return $tunnel->engine==='backpack'&&($tunnel->kind??'')==='reverse'&&($tunnel->transport??'')==='udp'&&($tunnel->orientation??'')==='normal';
    }
    public static function upgrade(ServerTunnel $tunnel, array $settings): array
    {
        if ($tunnel->engine !== 'wattunnel') {
            $entries = self::entries($tunnel, $settings);
            $settings['source_identity_policy_version']=3;
            $settings['source_identity'] = $entries !== [];
            $settings['source_identity_targets'] = array_values(array_unique(array_column($entries, 'target_port')));
            if ($entries || $tunnel->engine==='gost') $settings['source_identity_port'] = app(TunnelOriginRemote::class)->allocate($tunnel, $settings, $entries);
            else unset($settings['source_identity_port']);
            $settings['source_identity_runtime_version']=3;
            $settings['source_identity_topology']=self::topology($tunnel,$settings);
            return $settings;
        }
        $settings['version'] = WatTunnelRules::VERSION;
        $settings['source_identity'] = (bool) ManagedServer::find($tunnel->foreign_server_id)?->machine_id;
        return $settings;
    }

    public static function topology(ServerTunnel $t,array $settings): string
    {
        $server=ManagedServer::find($t->foreign_server_id);$nodes=[];
        if($server?->machine_id)foreach(ProxyServer::where('machine_id',$server->machine_id)->get() as $n)
            if(empty($n->parent_id)&&self::covers($t,$settings,(int)$n->server_port))$nodes[(int)$n->id]=[(int)$n->server_port,(bool)$n->enabled,(string)($n->type??'')];
        ksort($nodes,SORT_NUMERIC);return hash('sha256',json_encode($nodes,JSON_THROW_ON_ERROR));
    }

    public static function ports(array $settings): array
    {
        if (empty($settings['source_identity'])) return [];
        if (isset($settings['source_identity_targets'])) return array_values(array_map('intval', $settings['source_identity_targets']));
        $ports = [];
        foreach ($settings['mappings'] ?? [] as $map) {
            foreach (range((int) $map['target_port'], (int) ($map['target_end'] ?? $map['target_port'])) as $port) $ports[$port] = $port;
        }
        sort($ports, SORT_NUMERIC);
        return array_values($ports);
    }

    public static function required(ProxyServer $node): bool
    {
        if (!$node->machine_id || !Schema::hasTable('speedbox_tunnels')) return false;
        $serverIds = ManagedServer::where('machine_id', $node->machine_id)->pluck('id');
        foreach (ServerTunnel::where('status', '!=', 'deleted')->whereIn('foreign_server_id', $serverIds)->get() as $tunnel) {
            $settings = $tunnel->settings;
            if (($settings['enabled'] ?? true) && in_array((int) $node->server_port, self::ports($settings), true)) return true;
            // New nodes fail closed while their paired enrollment is queued.
            if(($settings['enabled']??true) && ($settings['source_identity_policy_version']??0)>=3
                && self::covers($tunnel,$settings,(int)$node->server_port))return true;
        }
        return false;
    }

    public static function covers(ServerTunnel $tunnel,array $settings,int $port): bool
    {
        $server=ManagedServer::find($tunnel->foreign_server_id);if(!$server?->machine_id)return false;
        $local=['127.0.0.1','localhost','::1',$server->host,explode('/',$settings['inner_foreign']??'')[0]];
        foreach($settings['mappings']??[] as $m){
            $length=(int)($m['public_end']??$m['public_port'])-(int)$m['public_port'];
            foreach($m['destinations']??[['address'=>$server->host,'port'=>$m['target_port']]] as $d)
                if(in_array($d['address'],$local,true)&&$port>=(int)$d['port']&&$port<=(int)$d['port']+$length)return true;
            if(empty($m['destinations'])&&$port>=(int)$m['target_port']&&$port<=(int)$m['target_port']+$length)return true;
        }
        return false;
    }

    /** Only destinations actually owned by this pair's node agent are aliases. */
    public static function entries(ServerTunnel $tunnel, array $settings): array
    {
        $server = ManagedServer::find($tunnel->foreign_server_id);
        if (!$server?->machine_id) return [];
        $ports = ProxyServer::where('machine_id', $server->machine_id)
            ->where(fn($q) => $q->whereNull('parent_id')->orWhere('parent_id', 0))->pluck('server_port')->map(fn($v)=>(int)$v)->all();
        $local = ['127.0.0.1', 'localhost', '::1', $server->host, explode('/', $settings['inner_foreign'] ?? '')[0]];
        $entries=[];
        foreach ($settings['mappings'] ?? [] as $map) {
            $destinations=$map['destinations'] ?? [];
            if ($destinations && (count($destinations)!==1 || !in_array($destinations[0]['address'],$local,true))) {
                $length=(int)($map['public_end']??$map['public_port'])-(int)$map['public_port'];
                foreach($destinations as $d)foreach($ports as $p)if(in_array($d['address'],$local,true) && $p>=(int)$d['port']&&$p<=(int)$d['port']+$length)
                    throw new \RuntimeException('مسیر چندمقصدیِ نود برای بازیابی آی‌پی پشتیبانی نمی‌شود؛ مقصد نود را در مسیر جدا تنظیم کنید. تنظیمات فعلی تغییر نکرد.');
                continue;
            }
            $target=(int)($destinations[0]['port'] ?? $map['target_port']);
            for($n=0;$n<=(int)($map['public_end']??$map['public_port'])-(int)$map['public_port'];$n++)
                if(in_array($target+$n,$ports,true))$entries[]=['public_port'=>(int)$map['public_port']+$n,'target_port'=>$target+$n];
        }
        return $entries;
    }

    public static function plan(ServerTunnel $tunnel, array $settings, string $side): ?array
    {
        if ($tunnel->engine==='wattunnel' || (empty($settings['source_identity'])&&$tunnel->engine!=='gost')) return null;
        if(empty($settings['source_identity_port']) && empty($settings['source_identity']))return null;
        $entries=self::entries($tunnel,$settings);
        if((!$entries && $tunnel->engine!=='gost') || empty($settings['source_identity_port']))throw new \RuntimeException('تنظیمات بازیابی آی‌پی واقعی نیازمند اعمال مجدد است.');
        $udpOnly=self::udpOnlyBackpack($tunnel);
        $protocol=$tunnel->engine==='backpack'?($udpOnly?'udp':'tcp'):($settings['protocol']??'tcp');
        $port=(int)$settings['source_identity_port'];
        $inner=explode('/',(string)($settings['inner_foreign']??''))[0];
        if($tunnel->engine==='gre' && !filter_var($inner,FILTER_VALIDATE_IP))throw new \RuntimeException('آدرس داخلی GRE برای بازیابی مبدأ معتبر نیست.');
        $old=$tunnel->settings;
        $staged=empty($old['source_identity']) || !($old['enabled']??true)
            || self::ports($old)!==self::ports($settings) || empty($old['source_identity_port']);
        $server=ManagedServer::find($tunnel->foreign_server_id);$probe=[];
        if($server?->machine_id)foreach(ProxyServer::where('machine_id',$server->machine_id)->get() as $node)
            if(empty($node->parent_id)&&$node->enabled&&!in_array($node->type??'',['hysteria','tuic'],true)&&in_array((int)$node->server_port,array_column($entries,'target_port'),true))$probe[]=(int)$node->server_port;
        return ['version'=>2,'side'=>$side,'port'=>$port,'secret'=>$tunnel->secret,'entries'=>$entries,
            'probe_targets'=>array_values(array_unique($probe)),
            'health_only'=>$entries===[],
            'staged'=>$staged,
            'tcp'=>$protocol!=='udp','udp'=>$tunnel->engine==='backpack'?($udpOnly||(bool)($settings['accept_udp']??false)):$protocol!=='tcp',
            'backend'=>$tunnel->engine==='gre'?((str_contains($inner,':')?'['.$inner.']':$inner).':'.$port):'127.0.0.1:'.$port,
            'allowed'=>$settings['allowed_sources']??[], 'mark'=>$tunnel->engine==='gre'?((int)$tunnel->subnet<<12)+4095:0];
    }
}

