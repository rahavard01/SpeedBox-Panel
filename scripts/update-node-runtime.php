<?php
// Run as the website user after the normal panel updater. Only managed hosts.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{ManagedServer,ServerDeployment};use App\Jobs\DeployManagedServer;use App\Services\SshExecutor;
$failed=false;
foreach(ManagedServer::whereNotNull('machine_id')->orderBy('id')->get() as $server){
 try{
  $sudo=$server->ssh_user==='root'?'':'sudo -n ';
  try{$ready=app(SshExecutor::class)->run($server,$sudo.'/usr/local/bin/xboard-node -v && '.$sudo.'systemctl is-active --quiet xboard-node && '.$sudo.'test -S /run/speedbox-origin/control.sock',30);if(str_contains($ready,'node-sync-v1')){echo "server={$server->id} runtime=current\n";continue;}}catch(Throwable){}
  $d=Illuminate\Support\Facades\DB::transaction(function()use($server){$s=ManagedServer::whereKey($server->id)->lockForUpdate()->firstOrFail();if($s->status==='removing'||$s->deployments()->whereIn('status',['queued','running'])->where('action','!=','sync_nodes')->exists())throw new RuntimeException('Another server operation is pending.');return ServerDeployment::create(['managed_server_id'=>$s->id,'action'=>'upgrade','kernel'=>'singbox','status'=>'queued']);});
  DeployManagedServer::dispatch($d->id);echo "server={$server->id} operation={$d->id} phase=queued\n";
  $deadline=time()+1300;
  do{sleep(1);$d->refresh();if(time()>$deadline)throw new RuntimeException('Upgrade still pending; inspect the operation in the panel.');}while(in_array($d->status,['queued','running'],true));
  if($d->status!=='succeeded')throw new RuntimeException('Upgrade failed; inspect the server operation log.');
  echo "server={$server->id} operation={$d->id} phase=succeeded\n";
 }catch(Throwable $e){$failed=true;echo "server={$server->id} FAILED: ".$e->getMessage()."\n";}
}
exit($failed?1:0);
