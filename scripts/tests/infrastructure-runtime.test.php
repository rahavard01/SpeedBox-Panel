<?php
/** Runtime behavior with in-memory persistence and SSH doubles; no Laravel boot. */
namespace Illuminate\Bus { trait Queueable { public function onQueue($q){return $this;} } }
namespace Illuminate\Contracts\Queue { interface ShouldQueue {} }
namespace Illuminate\Foundation\Bus { trait Dispatchable {} }
namespace Illuminate\Queue { trait InteractsWithQueue {} trait SerializesModels {} }
namespace Illuminate\Support\Facades {
 class DB { public static function transaction($fn){return $fn();} }
 class File { public static function isFile($p){return is_file($p);} }
 class Cache {
  public static array $data=[];public static int $reads=0;public static bool $report=false;public static bool $forceError=false;
  public static function get($key,$default=null){
   if(self::$forceError&&str_ends_with($key,':metrics')&&++self::$reads>=2)throw new \RuntimeException('No healthy node report');
   if(self::$report&&str_ends_with($key,':metrics')){self::$reads++;if(self::$reads>=2){self::$data[$key]=['kernel_status'=>true];self::$data[str_replace(':metrics',':last_seen',$key)]=time();}}
   return self::$data[$key]??$default;
  }
  public static function forget($key){unset(self::$data[$key]);}
 }
}
namespace App\Models {
 #[\AllowDynamicProperties] class Row {
  public array $writes=[];
  public function __construct(array $data=[]){foreach($data as $k=>$v)$this->$k=$v;}
  public function update(array $data){$this->writes[]=$data;foreach($data as $k=>$v)$this->$k=$v;return true;}
  public function refresh(){return $this;}
 }
 class ManagedServer extends Row {public static array $rows=[];public static function with($x){return new Query(self::$rows);}public static function whereKey($id){return new Query(self::$rows,$id);} }
 class ServerDeployment extends Row {public static array $rows=[];public static function findOrFail($id){return self::$rows[$id];}public static function find($id){return self::$rows[$id]??null;} }
 class ProxyServer extends Row {public static array $rows=[];public static function find($id){return self::$rows[$id]??null;} }
 class ServerMachine extends Row {public static array $rows=[];public static function whereKey($id){return new Query(self::$rows,$id);} }
 class ServerMachineLoadHistory {public static array $rows=[];public static function create($row){self::$rows[]=$row;} }
 class Query {public function __construct(private array $rows,private ?int $id=null){}public function where($key,$value){$this->rows=array_filter($this->rows,fn($r)=>($r->$key??null)===$value);return $this;}public function update($data){$rows=$this->id!==null?array_filter([$this->rows[$this->id]??null]):$this->rows;foreach($rows as $r)$r->update($data);}public function lockForUpdate(){return $this;}public function first(){return $this->rows[$this->id]??null;}public function findOrFail($id){return $this->rows[$id];} }
}
namespace App\Services {
 class SshExecutor {
  public array $restarts=[];public bool $failOld=false;public bool $localSnapshot=false;public bool $badMetrics=false;public $onInstall=null;
  public function run(\App\Models\ManagedServer $server,string $command,int $timeout=120,?string $input=null,?callable $onOutput=null):string{
   if($command==='sh -s'){
    if($this->badMetrics)throw new \RuntimeException('unavailable');
    if($this->localSnapshot){$p=proc_open(['sh'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);if(proc_close($p))throw new \RuntimeException($err);return $out;}
    return '{"cpu":12.5,"mem":{"total":100,"used":20},"swap":{"total":0,"used":0},"disk":{"total":200,"used":50}}';
   }
   if(str_contains($command,'OS='))return "OS=linux\nARCH=x86_64";
   if(str_contains($command,'systemctl restart')){$this->restarts[]=$server->id;if($this->failOld&&$server->id===1)throw new \RuntimeException('old host unreachable');return '';}
   if(str_contains($command,'mktemp'))return '/tmp/panel-speedbox-deploy.abc123';
   if(str_contains($command,' install')){($this->onInstall)($onOutput);return '';}
   if(str_contains($command,'is-active'))return "active\npanel-speedbox-http-obfs";
   return '';
  }
  public function upload(...$args):void{}
 }
}
namespace {
 use App\Models\{ManagedServer,ServerDeployment,ProxyServer,ServerMachine,ServerMachineLoadHistory};
 use App\Services\{SshExecutor,MachineResourceSnapshot,DeploymentLogStream};
 use Illuminate\Support\Facades\Cache;
 function now(){return time();}
 function data_get($a,$key,$default=null){foreach(explode('.',$key)as $p){if(!is_array($a)||!array_key_exists($p,$a))return $default;$a=$a[$p];}return $a;}
 function config($key,$default=null){return $key==='app.url'?'https://panel.test':$default;}
 function storage_path($p=''){return $GLOBALS['tmp'].'/'.$p;}
 function base_path($p=''){return dirname(__DIR__,2).'/'.$p;}
 function app($class){return new $class;}
 function report($e){}
 if(!function_exists('mb_strcut')){function mb_strcut($s,$start,$length=null,$encoding=null){return preg_replace('/^[\x80-\xBF]+/','',substr($s,$start,$length));}}
 function check($condition,$message){if(!$condition)throw new \RuntimeException($message);}
 foreach(['Services/DeploymentLogStream','Services/MachineResourceSnapshot','Jobs/DeployManagedServer','Jobs/ApplyNodeAssignment']as $p)require base_path('app/'.$p.'.php');
 $tmp=sys_get_temp_dir().'/speedbox-infra-'.bin2hex(random_bytes(6));mkdir($tmp.'/app/agent',0700,true);$GLOBALS['tmp']=$tmp;
 try{
  foreach(['xboard-node','xbctl']as $binary)file_put_contents($tmp.'/app/agent/'.$binary.'-linux-amd64','fixture');
  $d=new ServerDeployment(['log'=>'start']);$stream=new DeploymentLogStream($d,['private-token','multi\nline']);
  $stream->append('out','phase 1\nprivate-');check(!str_contains($d->log,'private-'),'partial token persisted');
  $stream->append('out','token\nphase 2');$stream->finish();check(str_contains($d->log,'[REDACTED]')&&!str_contains($d->log,'private-token'),'split token not masked');
  $stream=new DeploymentLogStream($d,['trailing-secret']);$stream->append('out','trailing-');$stream->finish();check(!str_contains($d->log,'trailing-'),'partial token at EOF leaked');
  $unicode=new ServerDeployment(['log'=>'']);$stream=new DeploymentLogStream($unicode,['abcdef','abc']);$stream->append('out','abc');$stream->append('out','def');$stream->append('out',substr('سلام',0,1));$stream->append('out',substr('سلام',1));$stream->finish();check(str_contains($unicode->log,'سلام')&&!str_contains($unicode->log,'def'),'UTF-8 or overlapping credential chunks broke redaction');
  $stream=new DeploymentLogStream($d,[]);$stream->append('out',str_repeat('x',400000));$stream->finish();check(strlen($d->log)<=262144,'unbounded persisted log');
  $ssh=new SshExecutor();$server=new ManagedServer(['id'=>1,'machine_id'=>1]);$machine=new ServerMachine(['id'=>1,'load_status'=>['capabilities'=>['warp-egress-v1']],'last_seen_at'=>7]);ServerMachine::$rows=[1=>$machine];
  (new MachineResourceSnapshot())->collect($ssh,$server);check($machine->load_status['cpu']===12.5,'first metrics missing');check($machine->load_status['capabilities']===['warp-egress-v1']&&$machine->last_seen_at===7,'snapshot forged agent capabilities/liveness');
  $machine->load_status=['cpu'=>88,'updated_at'=>time()+1];(new MachineResourceSnapshot())->collect($ssh,$server);check($machine->load_status['cpu']===88,'SSH overwrote newer agent sample');
  $machine->load_status=[];$ssh->localSnapshot=true;(new MachineResourceSnapshot())->collect($ssh,$server);check($machine->load_status['mem']['total']>0&&$machine->load_status['disk']['total']>0,'Linux /proc/df collection failed');check($machine->load_status['cpu']>=0&&$machine->load_status['cpu']<=100,'invalid real CPU sample');$ssh->localSnapshot=false;
  $makeServer=fn($id)=>new ManagedServer(['id'=>$id,'machine_id'=>$id,'ssh_user'=>'root','password'=>'ssh-password','private_key'=>'','private_key_passphrase'=>'','machine'=>new ServerMachine(['id'=>$id,'token'=>'private-token'])]);
  ManagedServer::$rows=[1=>$makeServer(1),2=>$makeServer(2)];
  ServerDeployment::$rows=[1=>new ServerDeployment(['managed_server_id'=>1,'action'=>'install','kernel'=>'singbox','log'=>'']),2=>new ServerDeployment(['managed_server_id'=>2,'action'=>'restart','log'=>''])];
  $ssh->onInstall=function($emit)use($ssh){$emit('out',"install phase\nprivate-");check(str_contains(ServerDeployment::$rows[1]->log,'install phase'),'install output was not persisted during execution');$emit('out',"token\n");$emit('err','ssh-password');};
  (new \App\Jobs\DeployManagedServer(1))->handle($ssh);check(ServerDeployment::$rows[1]->status==='succeeded','install failed');check(!str_contains(ServerDeployment::$rows[1]->log,'private-token')&&!str_contains(ServerDeployment::$rows[1]->log,'ssh-password'),'credentials leaked');
  $ssh->badMetrics=true;(new \App\Jobs\DeployManagedServer(2))->handle($ssh);check(ServerDeployment::$rows[2]->status==='succeeded','metrics failure broke healthy service');
  echo "PASS: streamed redacted/bounded logs, install progress, real Linux resource sample, fresh-sample precedence, capabilities/liveness; node assignment behavior is covered by node-runtime-apply.test.php\n";
 }finally{foreach(glob($tmp.'/app/agent/*')as $f)unlink($f);rmdir($tmp.'/app/agent');rmdir($tmp.'/app');rmdir($tmp);}
}
