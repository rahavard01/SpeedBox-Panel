<?php
namespace App\Models {
 #[\AllowDynamicProperties]class Row{public function __construct($d){foreach($d as $k=>$v)$this->$k=$v;}}
 class Rows extends \ArrayObject{public function sortBy($key){$a=$this->getArrayCopy();uasort($a,is_callable($key)?fn($a,$b)=>$key($a)<=>$key($b):fn($a,$b)=>$a->$key<=>$b->$key);return new self($a);}public function values(){return new self(array_values($this->getArrayCopy()));}public function all(){return $this->getArrayCopy();}}
 class Query{public function __construct(private array $rows){}public function orderBy($x){return $this;}public function lockForUpdate(){return $this;}public function get(){return new Rows($this->rows);}}
 class ProxyServer extends Row{public static array $rows=[];public static function whereIn($k,$ids){return new Query(array_filter(self::$rows,fn($r)=>in_array($r->$k,$ids)));}}
 class ManagedServer extends Row{public static array $rows=[];public static function whereIn($k,$ids){return new Query(array_filter(self::$rows,fn($r)=>in_array($r->$k,$ids)));}}
 class ServerDeployment extends Row{public static array $rows=[];public static function create($d){$r=new self(['id'=>count(self::$rows)+1,...$d]);self::$rows[]=$r;return $r;}}
}
namespace App\Services{
 class NodeControlService{public function configFor($n){return ['protocol'=>'vless','server_port'=>$n->server_port,'warp_enabled'=>$n->warp_enabled,...$n->extra];}public function usersFor($n){return new \App\Models\Rows($n->users);}}
}
namespace App\Jobs{class SyncNodeRuntime{public static array $calls=[];public static function dispatch($ops){self::$calls[]=$ops;return new self;}public function afterCommit(){return $this;}}}
namespace{
 use App\Models\{ProxyServer,ManagedServer,ServerDeployment,Row};use App\Services\{NodeControlService,NodeRuntimeApply};use App\Jobs\SyncNodeRuntime;
 function abort_if($test,$code,$message){if($test)throw new \RuntimeException($message,$code);}function check($b,$m){if(!$b)throw new \RuntimeException($m);}
 require dirname(__DIR__,2).'/app/Services/NodeRuntimeApply.php';
 ManagedServer::$rows=[new ManagedServer(['id'=>1,'machine_id'=>10,'status'=>'online']),new ManagedServer(['id'=>2,'machine_id'=>20,'status'=>'online'])];
 $n=new ProxyServer(['id'=>8,'machine_id'=>10,'parent_id'=>null,'enabled'=>true,'server_port'=>443,'warp_enabled'=>false,'extra'=>[],'users'=>[new Row(['id'=>1,'uuid'=>'u','device_limit'=>1])]]);ProxyServer::$rows=[$n];$apply=new NodeRuntimeApply(new NodeControlService);
 $before=$apply->capture([8]);$n->name='new';$n->tags=['new'];$n->rate=5;$n->rate_time_ranges=[['rate'=>10]];$apply->queue($before,$apply->capture([8]));check(!SyncNodeRuntime::$calls,'billing/metadata queued an apply');
 $n->extra=['multiplex'=>['enabled'=>false,'max_connections'=>4],'cert_config'=>['cert_mode'=>'none']];$apply->queue($before,$apply->capture([8]));check(!SyncNodeRuntime::$calls,'disabled editor defaults triggered apply');
 $n->warp_enabled=true;$after=$apply->capture([8]);$apply->queue($before,$after);check(SyncNodeRuntime::$calls[0][0]['nodes']===[8=>'present'],'WARP did not queue targeted apply');
 SyncNodeRuntime::$calls=[];$n->machine_id=20;$apply->queue($after,$apply->capture([8]));$ops=SyncNodeRuntime::$calls[0];check($ops[0]['machine_id']===10&&$ops[0]['nodes']===[8=>'removed']&&$ops[1]['machine_id']===20,'transfer did not remove source before target');
 SyncNodeRuntime::$calls=[];$before=$apply->capture([8]);$n->enabled=false;$apply->queue($before,$apply->capture([8]));check(SyncNodeRuntime::$calls[0][0]['nodes']===[8=>'removed'],'disable did not stop runtime');
 SyncNodeRuntime::$calls=[];$n->enabled=true;$apply->queue([],$apply->capture([8]));check(SyncNodeRuntime::$calls[0][0]['nodes']===[8=>'present'],'creation/enable not immediate');
 SyncNodeRuntime::$calls=[];$before=$apply->capture([8]);$n->users=[];$apply->queue($before,$apply->capture([8]));check(count(SyncNodeRuntime::$calls)===1,'effective user change ignored');
 echo "PASS: no-op/name/tags/rate/schedules preserve runtime; disabled defaults canonicalize; WARP/transfer/enable/disable/users apply only affected roots\n";
}
