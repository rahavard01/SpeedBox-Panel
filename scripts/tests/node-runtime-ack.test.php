<?php
/** Real deployment job with isolated SSH, cache and persistence doubles. */
namespace Illuminate\Bus {trait Queueable{public function onQueue($q){return $this;}}}
namespace Illuminate\Contracts\Queue {interface ShouldQueue{}}
namespace Illuminate\Foundation\Bus {trait Dispatchable{}}
namespace Illuminate\Queue {
 trait InteractsWithQueue{public $job;public array $released=[];public function release($delay){$this->released[]=$delay;}public function setJob($job){$this->job=$job;return $this;}}
 trait SerializesModels{}
}
namespace Illuminate\Support\Facades {
 class Cache{public static bool $available=true;public static function lock(...$args){return new class{public function get(){return Cache::$available;}public function release(){}};}}
}
namespace App\Models {
 #[\AllowDynamicProperties]class Row{public function __construct($data){foreach($data as $k=>$v)$this->$k=$v;}public function update($data){foreach($data as $k=>$v)$this->$k=$v;}}
 class ServerDeployment extends Row{public static array $rows=[];public static function findOrFail($id){return self::$rows[$id]??throw new \RuntimeException('missing');}}
 class ManagedServer extends Row{public static array $rows=[];public static function findOrFail($id){return self::$rows[$id]??throw new \RuntimeException('missing');}}
 class ProxyServer extends Row{public static array $rows=[];public static function find($id){return self::$rows[$id]??null;}}
}
namespace App\Services {
 class SshExecutor{
  public array $calls=[];public array $answers=[];
  public function run($server,$command,$timeout){$this->calls[]=[$server->id,$command,$timeout];$answer=array_shift($this->answers);if($answer instanceof \Throwable)throw $answer;return json_encode(['states'=>$answer]);}
 }
}
namespace {
 use App\Models\{ServerDeployment,ManagedServer,ProxyServer};use App\Jobs\SyncNodeRuntime;use App\Services\SshExecutor;use Illuminate\Support\Facades\Cache;
 function now(){return new \DateTimeImmutable();}function config($key){return 'https://panel.example/';}function check($ok,$why){if(!$ok)throw new \RuntimeException($why);}
 require dirname(__DIR__,2).'/app/Jobs/SyncNodeRuntime.php';
 function setup():array{
  ServerDeployment::$rows=[1=>new ServerDeployment(['id'=>1,'managed_server_id'=>11,'status'=>'queued','log'=>'']),2=>new ServerDeployment(['id'=>2,'managed_server_id'=>22,'status'=>'queued','log'=>''])];
  ManagedServer::$rows=[11=>new ManagedServer(['id'=>11,'ssh_user'=>'root']),22=>new ManagedServer(['id'=>22,'ssh_user'=>'speedbox'])];
  ProxyServer::$rows=[8=>new ProxyServer(['id'=>8,'enabled'=>true,'parent_id'=>null,'machine_id'=>20])];
  return [['deployment_id'=>1,'machine_id'=>10,'nodes'=>[8=>'removed']],['deployment_id'=>2,'machine_id'=>20,'nodes'=>[8=>'present']]];
 }
 $ops=setup();$ssh=new SshExecutor;$ssh->answers=[[['node_id'=>8,'state'=>'removed']],[['node_id'=>8,'state'=>'running']]];(new SyncNodeRuntime($ops))->handle($ssh);
 check(array_column($ssh->calls,0)===[11,22],'source/destination order lost');check(ServerDeployment::$rows[2]->status==='succeeded','valid applied state rejected');
 check(str_contains($ssh->calls[1][1],'sudo -n /usr/local/bin/xbctl node-tools sync')&&!str_contains(implode(' ',array_column($ssh->calls,1)),'systemctl'),'whole agent restarted');
 $ops=setup();$ssh=new SshExecutor;$ssh->answers=[new \RuntimeException('ssh failed'),[['node_id'=>8,'state'=>'waiting_for_users']]];
 try{(new SyncNodeRuntime($ops))->handle($ssh);throw new \LogicException('source failure acknowledged');}catch(\RuntimeException){}
 check(count($ssh->calls)===2&&ServerDeployment::$rows[1]->status==='failed'&&ServerDeployment::$rows[2]->status==='succeeded','source failure skipped destination');
 $ops=setup();$ssh=new SshExecutor;$ssh->answers=[[]];try{(new SyncNodeRuntime([$ops[1]]))->handle($ssh);throw new \LogicException('missing acknowledgment accepted');}catch(\RuntimeException){}
 check(ServerDeployment::$rows[2]->status==='failed','missing state reported succeeded');
 $ops=setup();$ssh=new SshExecutor;Cache::$available=false;$job=new SyncNodeRuntime($ops);$job->handle($ssh);check($job->released===[2]&&$ssh->calls===[],'lock contention did not release queue job');Cache::$available=true;
 $ops=setup();unset(ProxyServer::$rows[8]);$ssh=new SshExecutor;$ssh->answers=[[['node_id'=>8,'state'=>'removed']]];(new SyncNodeRuntime([$ops[1]]))->handle($ssh);check(ServerDeployment::$rows[2]->status==='succeeded','newer removal did not converge');
 echo "PASS: acknowledgments required, source failure preserves destination attempt, lock contention retries, newer edits converge, no whole-agent restart\n";
}
