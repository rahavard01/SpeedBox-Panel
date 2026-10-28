<?php
/** Execute the production model events, without loading database credentials. */
namespace Illuminate\Database\Eloquent {
 #[\AllowDynamicProperties] class Model {
  public static array $events=[];public bool $wasRecentlyCreated=false;public array $original=[],$changed=[];
  public static function saved($fn){self::$events['saved']=$fn;}public static function deleted($fn){self::$events['deleted']=$fn;}
  public function getOriginal($key){return $this->original[$key]??null;}
  public function wasChanged($keys){return (bool)array_intersect($keys,$this->changed);}
 }
}
namespace Illuminate\Support\Facades {class Schema {public static bool $present=true;public static function hasTable($t){return self::$present;}}}
namespace App\Jobs {class SyncTunnelOrigins {public static array $calls=[];public static function dispatch($ids){self::$calls[]=$ids;return new class {public function afterCommit(){SyncTunnelOrigins::$calls[array_key_last(SyncTunnelOrigins::$calls)]['committed']=true;}};}}}
namespace {
 require dirname(__DIR__,2).'/app/Models/ProxyServer.php';use App\Models\ProxyServer;use App\Jobs\SyncTunnelOrigins;
 (new ReflectionMethod(ProxyServer::class,'booted'))->invoke(null);$events=ProxyServer::$events;$checks=0;
 function check($v,$label){if(!$v)throw new RuntimeException($label);$GLOBALS['checks']++;}
 $n=new ProxyServer;$n->machine_id=15;$n->wasRecentlyCreated=true;$events['saved']($n);check(SyncTunnelOrigins::$calls===[[15,'committed'=>true]],'new node enrolls only after commit');
 SyncTunnelOrigins::$calls=[];$n->wasRecentlyCreated=false;$n->original=['machine_id'=>1];$n->changed=['machine_id'];$events['saved']($n);check(SyncTunnelOrigins::$calls===[[1,15,'committed'=>true]],'move synchronizes old and new machines');
 foreach(['server_port','enabled','parent_id','type'] as $field){SyncTunnelOrigins::$calls=[];$n->original=['machine_id'=>15];$n->changed=[$field];$events['saved']($n);check(count(SyncTunnelOrigins::$calls)===1,"$field synchronizes");}
 SyncTunnelOrigins::$calls=[];$n->changed=['u','d','updated_at'];$events['saved']($n);check(SyncTunnelOrigins::$calls===[],'traffic accounting does not enqueue');
 $events['deleted']($n);check(SyncTunnelOrigins::$calls===[[15,'committed'=>true]],'deletion removes obsolete enrollment');
 SyncTunnelOrigins::$calls=[];Illuminate\Support\Facades\Schema::$present=false;$events['deleted']($n);check(SyncTunnelOrigins::$calls===[],'installation before tunnel schema has no job');
 echo "TUNNEL NODE EVENTS: $checks checks passed\n";
}
