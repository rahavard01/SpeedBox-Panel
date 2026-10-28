<?php
/** Exercise the controller's transfer transaction with isolated ORM/queue doubles. */
namespace Illuminate\Bus {trait Queueable{public function onQueue($q){return $this;}}}
namespace Illuminate\Contracts\Queue {interface ShouldQueue{}}
namespace Illuminate\Foundation\Bus {
 trait Dispatchable{public static function dispatch(...$args){$pending=new \TestPending(new static(...$args));$GLOBALS['dispatches'][]=$pending;return $pending;}}
}
namespace Illuminate\Queue {trait InteractsWithQueue{} trait SerializesModels{}}
namespace Illuminate\Support\Facades {class DB{public static function transaction($fn){return $fn();}}}
namespace Illuminate\Http {
 class Request{public function __construct(public array $data){}public function user(){return (object)['id'=>7];}}
 class JsonResponse{public function __construct(public array $data){}public function header(...$a){return $this;}}
}
namespace App\Http\Controllers {class Controller{}}
namespace App\Support {class InfrastructureNodeRules{public static function validate($request,$node){return $request->data;}}}
namespace App\Services {
 class NodeRuntimeApply{
  public function capture($ids){$out=[];foreach(\App\Models\ProxyServer::$rows as $n)if(in_array($n->id,$ids)&&!$n->parent_id&&$n->machine_id&&($n->enabled??true))$out[$n->id]=[$n->machine_id,$n->kernel];return $out;}
  public function queue($before,$after){if($before!==$after)$GLOBALS['plans'][]=[$before,$after];}
 }
 class NodeControlService{public array $cleared=[];public function clearNodeCache($id){$this->cleared[]=$id;}}
 class TunnelManager{public static function protectServer($id){$GLOBALS['protected'][]=$id;}}
}
namespace App\Models {
 #[\AllowDynamicProperties] class Row {
  public function __construct(array $data){foreach($data as $k=>$v)$this->$k=$v;}
  public function update(array $data){foreach($data as $k=>$v)$this->$k=$v;}
  public function fresh(...$args){return $this;}
 }
 class Rows extends \ArrayObject{
  public function first($fn=null){foreach($this as $row)if(!$fn||$fn($row))return $row;return null;}
  public function sortBy($fn){$a=$this->getArrayCopy();uasort($a,fn($a,$b)=>$fn($a)<=>$fn($b));return new self($a);}
 }
 class Query{
  public function __construct(private array $rows){}
  public function whereIn($key,$values){$this->rows=array_filter($this->rows,fn($r)=>in_array($r->$key,$values,true));return $this;}
  public function lockForUpdate(){$GLOBALS['locks']++;return $this;}
  public function orderBy($key){uasort($this->rows,fn($a,$b)=>$a->$key<=>$b->$key);return $this;}
  public function get(){return new Rows($this->rows);}
  public function firstOrFail(){return array_values($this->rows)[0]??throw new \RuntimeException('missing');}
  public function update($data){foreach($this->rows as $r)$r->update($data);}
  public function pluck($key){return array_map(fn($r)=>$r->$key,$this->rows);}
  public function exists(){return count($this->rows)>0;}
 }
 class ProxyServer extends Row{
  public static array $rows=[];
  public static function whereKey($id){return new Query(isset(self::$rows[$id])?[$id=>self::$rows[$id]]:[]);}
  public static function where($key,$value){return new Query(array_filter(self::$rows,fn($r)=>($r->$key??null)===$value));}
 }
 class ManagedServer extends Row{
  public static array $rows=[];
  public static function whereIn($key,$values){return (new Query(self::$rows))->whereIn($key,$values);}
  public function deployments(){return new Query(array_filter(ServerDeployment::$rows,fn($r)=>$r->managed_server_id===$this->id));}
 }
 class ServerDeployment extends Row{
  public static array $rows=[];
  public static function create($data){$r=new self(['id'=>count(self::$rows)+1,...$data]);self::$rows[$r->id]=$r;return $r;}
 }
}
namespace {
 use App\Models\{ProxyServer,ManagedServer,ServerDeployment};
 class TestPending{public bool $after=false;public function __construct(public object $job){}public function afterCommit(){$this->after=true;return $this;}}
 function abort_if($condition,$code,$message){if($condition)throw new \RuntimeException($message,$code);}
 function app($class){return new $class;}
 function response(){return new class{public function json($data){return new \Illuminate\Http\JsonResponse($data);}};}
 function check($v,$m){if(!$v)throw new \RuntimeException($m);}
 require dirname(__DIR__,2).'/app/Jobs/ApplyNodeAssignment.php';
 require dirname(__DIR__,2).'/app/Http/Controllers/Api/Admin/InfrastructureController.php';
 $GLOBALS['locks']=0;$GLOBALS['dispatches']=[];$GLOBALS['protected']=[];
 $service=new \App\Services\NodeControlService();$controller=new \App\Http\Controllers\Api\Admin\InfrastructureController($service);
 ManagedServer::$rows=[1=>new ManagedServer(['id'=>1,'machine_id'=>10,'status'=>'online']),2=>new ManagedServer(['id'=>2,'machine_id'=>20,'status'=>'online'])];
 $node=new ProxyServer(['id'=>8,'machine_id'=>10,'kernel'=>'singbox','parent_id'=>null,'rate'=>1,'warp_enabled'=>false]);
 $child=new ProxyServer(['id'=>9,'machine_id'=>10,'kernel'=>'singbox','parent_id'=>8]);ProxyServer::$rows=[8=>$node,9=>$child];
 $request=fn($data)=>new \Illuminate\Http\Request($data);
 $GLOBALS['plans']=[];
 $controller->updateNode($request(['machine_id'=>20,'kernel'=>'xray']),$node);
 check($node->machine_id===20&&$child->machine_id===20&&$child->kernel==='xray','assignment did not propagate');
 check(count($GLOBALS['plans'])===1&&$GLOBALS['plans'][0][0][8]===[10,'singbox']&&$GLOBALS['plans'][0][1][8]===[20,'xray'],'effective before/after not captured');
 check($service->cleared===[],'save cleared online state');
 $controller->updateNode($request(['name'=>'rename only']),$node);check(count($GLOBALS['plans'])===1,'rename queued runtime work');
 $controller->updateNode($request(['rate'=>4]),$node);check(count($GLOBALS['plans'])===1,'billing queued runtime work');
 $controller->updateNode($request(['machine_id'=>null]),$node);check($node->machine_id===null&&$child->machine_id===null,'unassignment ignored explicit null');check($GLOBALS['plans'][1][1]===[],'unassignment kept old runtime');
 echo "PASS: controller captures affected roots transactionally, propagates child assignment, preserves online cache and skips runtime work for name/rate changes\n";
}
