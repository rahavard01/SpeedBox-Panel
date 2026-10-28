<?php
namespace App\Services;

use App\Models\{ProxyServer,ManagedServer,ServerDeployment};
use App\Jobs\SyncNodeRuntime;
use Illuminate\Support\Facades\DB;

/** Compare effective root runtime + users; labels and billing never cause a restart. */
class NodeRuntimeApply
{
    public function __construct(private readonly NodeControlService $control) {}

    public function capture(array $ids): array
    {
        $out=[];
        foreach(ProxyServer::whereIn('id',array_values(array_unique(array_filter($ids))))->get() as $node){
            if($node->parent_id || !$node->enabled || !$node->machine_id)continue;
            $config=$this->control->configFor($node);
            unset($config['base_config'],$config['utls']);
            if(empty($config['multiplex']['enabled']))unset($config['multiplex']);
            if(($config['cert_config']['cert_mode']??'')==='none'||empty($config['cert_config']))unset($config['cert_config']);
            if(isset($config['tls_settings'])){
                if(($config['tls_settings']['server_name']??null)==='')unset($config['tls_settings']['server_name']);
                if(($config['tls_settings']['allow_insecure']??null)===false)unset($config['tls_settings']['allow_insecure']);
                if(isset($config['tls_settings']['ech'])&&empty($config['tls_settings']['ech']['enabled']))unset($config['tls_settings']['ech']);
                if(empty($config['tls_settings']))unset($config['tls_settings']);
            }
            $users=$this->control->usersFor($node)->sortBy('id')->values()->all();
            $out[$node->id]=['machine_id'=>(int)$node->machine_id,'hash'=>self::fingerprint(['config'=>$config,'users'=>$users])];
        }
        return $out;
    }

    public static function fingerprint(mixed $value): string
    {
        $normalize=function($v)use(&$normalize){
            if(is_object($v))$v=(array)$v;
            if(!is_array($v))return $v;
            $v=array_map($normalize,$v);
            if(!array_is_list($v))ksort($v,SORT_STRING);
            return $v;
        };
        return hash('sha256',json_encode($normalize($value),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
    }

    public function queue(array $before,array $after): void
    {
        $plans=[];$removals=[];
        foreach(array_unique([...array_keys($before),...array_keys($after)]) as $id){
            $old=$before[$id]??null;$new=$after[$id]??null;
            if($old===$new)continue;
            if($old && (!$new || $old['machine_id']!==$new['machine_id'])){$plans[$old['machine_id']][$id]='removed';$removals[$old['machine_id']]=true;}
            if($new)$plans[$new['machine_id']][$id]='present';
        }
        if(!$plans)return;
        $servers=ManagedServer::whereIn('machine_id',array_keys($plans))->orderBy('id')->lockForUpdate()->get();
        $operations=[];
        foreach($servers->sortBy(fn($s)=>isset($removals[$s->machine_id])?0:1) as $server){
            abort_if($server->status==='removing',409,'سرور در حال حذف است.');
            foreach(array_chunk($plans[$server->machine_id],128,true) as $nodes){
            $deployment=ServerDeployment::create(['managed_server_id'=>$server->id,'action'=>'sync_nodes','kernel'=>'singbox','status'=>'queued','log'=>'همگام‌سازی فوری نودهای تغییرکرده؛ بدون راه‌اندازی مجدد کل سرویس.']);
            $operations[]=['deployment_id'=>$deployment->id,'machine_id'=>(int)$server->machine_id,'nodes'=>$nodes];
            }
        }
        if($operations)SyncNodeRuntime::dispatch($operations)->afterCommit();
    }
}
