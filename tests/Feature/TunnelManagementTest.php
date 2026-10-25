<?php
namespace Tests\Feature;

use App\Jobs\RunTunnelOperation;
use App\Models\{ManagedServer, ServerMachine, ServerTunnel, TunnelConnectionTest, TunnelOperation, User};
use App\Services\{BackpackRemote, TunnelManager};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Queue, Schema};
use Tests\TestCase;

class TunnelManagementTest extends TestCase
{
    private ManagedServer $iran;
    private ManagedServer $foreign;
    private string $token;
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('t',32)), 'queue.default'=>'database', 'cache.default'=>'array']);
        Queue::fake();
        Schema::create('panel_users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email');$t->string('password');$t->integer('role');$t->boolean('banned')->default(false);$t->timestamps();});
        foreach(['2025_01_01_000000_create_speedbox_core_tables.php','2026_10_08_000001_create_speedbox_infrastructure_tables.php','2026_10_18_000001_add_node_warp_egress.php','2026_10_18_000002_create_speedbox_tunnels.php','2026_10_18_000003_create_speedbox_tunnel_host_status.php','2026_10_20_000001_add_network_tunnel_engines.php','2026_10_23_000001_add_reverse_ssh_route.php'] as $file)(require database_path('migrations/'.$file))->up();
        foreach(['iran'=>'192.0.2.1','foreign'=>'192.0.2.2'] as $side=>$host){
            $machine=ServerMachine::create(['name'=>$side,'token'=>$side.'-token','is_active'=>true]);
            $this->$side=ManagedServer::create(['name'=>$side,'host'=>$host,'ssh_port'=>22,'ssh_user'=>'root','auth_type'=>'password','password'=>'private-password','machine_id'=>$machine->id,'status'=>'ready']);
        }
        $user=User::forceCreate(['name'=>'admin','email'=>'admin@test.test','password'=>'pw','role'=>1,'banned'=>false]);
        $this->token=$user->createToken('test',['*'],now()->addHour())->plainTextToken;
    }
    private function testRecord(array $rows=[]): TunnelConnectionTest
    {
        if(!$rows)$rows=app(TunnelManager::class)->rows([['k'=>'reverse','t'=>'tcp','s'=>'ok','o'=>60,'n'=>60,'z'=>60,'r'=>40,'m'=>15],['k'=>'reverse','t'=>'pck','s'=>'ok','o'=>60,'n'=>60,'z'=>60,'r'=>50,'m'=>12]],'normal');
        return TunnelConnectionTest::create(['iran_server_id'=>$this->iran->id,'foreign_server_id'=>$this->foreign->id,'status'=>'completed','results'=>app(TunnelManager::class)->recommend($rows)]);
    }
    private function payload(TunnelConnectionTest $test,array $changes=[]):array
    {
        return [...['test_id'=>$test->id,'result_id'=>'normal:reverse:pck','name'=>'Tunnel','public_host'=>'ir.example.com','tunnel_port'=>8000,'accept_udp'=>false,'mappings'=>[['public_port'=>443,'target_port'=>5790]]],...$changes];
    }
    private function createTunnel():ServerTunnel
    {
        return ServerTunnel::create(['name'=>'Old name','remote_name'=>'sb-'.str_repeat('a',32),'iran_server_id'=>$this->iran->id,'foreign_server_id'=>$this->foreign->id,'kind'=>'reverse','transport'=>'tcp','orientation'=>'normal','secret'=>str_repeat('secret',11),'settings'=>['public_host'=>'ir.example.com','peer_host'=>'','tunnel_port'=>8000,'accept_udp'=>false,'mappings'=>[['public_port'=>443,'target_port'=>5790]]],'subnet'=>1500]);
    }
    public function test_requires_admin_and_never_exposes_host_passwords():void
    {
        $this->getJson('/api/admin/infrastructure/tunnels')->assertUnauthorized();
        $this->withToken($this->token)->getJson('/api/admin/infrastructure/tunnels')->assertOk()->assertJsonMissing(['password'=>'private-password']);
        $user=User::forceCreate(['name'=>'viewer','email'=>'viewer@test.test','password'=>'pw','role'=>0,'banned'=>false]);
        $token=$user->createToken('test',['*'],now()->addHour())->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/admin/infrastructure/tunnels')->assertForbidden();
    }
    public function test_edit_uses_recent_test_for_same_pair_and_preserves_card_until_verified():void
    {
        $t=$this->createTunnel();$test=$this->testRecord();
        $this->withToken($this->token)->getJson('/api/admin/infrastructure/tunnels/'.$t->id.'/tests')->assertOk()->assertJsonCount(1,'data');
        $this->putJson('/api/admin/infrastructure/tunnels/'.$t->id,$this->payload($test))->assertAccepted();
        $this->assertSame('tcp',$t->fresh()->transport);
        $this->assertSame('reconfigure',TunnelOperation::first()->action);
        Queue::assertPushed(\App\Jobs\RunTunnelReconfigure::class);
        $this->assertSame(1,ServerTunnel::count());
    }
    public function test_edit_rejects_untested_or_expired_method_changes():void
    {
        $t=$this->createTunnel();$test=$this->testRecord();$this->withToken($this->token);
        $this->putJson('/api/admin/infrastructure/tunnels/'.$t->id,$this->payload($test,['transport'=>'quic']))->assertUnprocessable();
        DB::table('speedbox_tunnel_tests')->where('id',$test->id)->update(['updated_at'=>now()->subDays(2)]);
        $this->putJson('/api/admin/infrastructure/tunnels/'.$t->id,$this->payload($test))->assertUnprocessable();
        $this->assertSame('tcp',$t->fresh()->transport);$this->assertSame(0,TunnelOperation::count());
    }
    public function test_serializes_both_hosts_and_rejects_same_host():void
    {
        $this->withToken($this->token)->postJson('/api/admin/infrastructure/tunnel-tests',['iran_server_id'=>$this->iran->id,'foreign_server_id'=>$this->iran->id])->assertUnprocessable();
        $this->postJson('/api/admin/infrastructure/tunnel-tests',['iran_server_id'=>$this->iran->id,'foreign_server_id'=>$this->foreign->id])->assertAccepted();
        $this->assertSame(2,DB::table('speedbox_tunnel_leases')->count());Queue::assertPushed(RunTunnelOperation::class);
        $this->postJson('/api/admin/infrastructure/tunnel-tests',['iran_server_id'=>$this->foreign->id,'foreign_server_id'=>$this->iran->id])->assertConflict();
        $this->assertSame(1,TunnelConnectionTest::count());
        $this->postJson('/api/admin/infrastructure/servers/'.$this->iran->id.'/deploy',['action'=>'restart'])->assertConflict();
        $this->postJson('/api/admin/infrastructure/machines/'.$this->iran->machine_id.'/warp',['action'=>'test'])->assertConflict();
    }
    public function test_any_successful_method_can_be_selected_not_only_recommendation():void
    {
        $test=$this->testRecord();$this->assertTrue($test->results[0]['recommended']);$this->assertFalse($test->results[1]['recommended']);
        $response=$this->withToken($this->token)->postJson('/api/admin/infrastructure/tunnels',$this->payload($test))->assertAccepted();
        $this->assertSame('pck',$response->json('data.transport'));
        $raw=DB::table('speedbox_tunnels')->first();$model=ServerTunnel::first();
        $this->assertNotSame($raw->secret,$model->secret);$this->assertStringNotContainsString($model->secret,$response->getContent());
        $this->getJson('/api/admin/infrastructure/tunnels')->assertOk()->assertJsonMissingPath('data.0.secret');
    }
    public function test_unstable_selection_requires_explicit_acknowledgement():void
    {
        $rows=app(TunnelManager::class)->rows([['k'=>'reverse','t'=>'tcp','s'=>'unstable','o'=>40,'n'=>60]],'normal');$test=$this->testRecord($rows);
        $body=$this->payload($test,['result_id'=>'normal:reverse:tcp']);
        $this->withToken($this->token)->postJson('/api/admin/infrastructure/tunnels',$body)->assertUnprocessable();
        $this->postJson('/api/admin/infrastructure/tunnels',[...$body,'accept_unstable'=>true])->assertAccepted();
    }
    public function test_rejects_failed_forged_stale_and_invalid_address_or_ports():void
    {
        $test=$this->testRecord();$this->withToken($this->token);
        foreach([['result_id'=>'normal:reverse:not-real'],['public_host'=>'https://bad.test/path'],['mappings'=>[['public_port'=>8000,'target_port'=>5790]]],['mappings'=>[['public_port'=>443,'target_port'=>5790],['public_port'=>443,'target_port'=>5791]]],['tunnel_port'=>65536]] as $change)$this->postJson('/api/admin/infrastructure/tunnels',$this->payload($test,$change))->assertUnprocessable();
        DB::table('speedbox_tunnel_tests')->where('id',$test->id)->update(['updated_at'=>now()->subDays(2)]);
        $this->postJson('/api/admin/infrastructure/tunnels',$this->payload($test))->assertUnprocessable();$this->assertSame(0,ServerTunnel::count());
    }
    public function test_sync_queue_does_not_recurse_or_contact_hosts():void
    {
        config(['queue.default'=>'sync']);
        $this->withToken($this->token)->postJson('/api/admin/infrastructure/tunnel-tests',['iran_server_id'=>$this->iran->id,'foreign_server_id'=>$this->foreign->id])->assertUnprocessable();
        $this->assertSame(0,TunnelConnectionTest::count());
    }
    public function test_swapped_results_use_the_iran_ingress_adapter():void
    {
        $manager=app(TunnelManager::class);$rows=$manager->rows([['k'=>'reverse','t'=>'tcp','s'=>'ok'],['k'=>'direct','t'=>'pck','s'=>'ok'],['k'=>'reverse','t'=>'tcp','s'=>'skipped']],'swapped');
        $this->assertTrue($rows[0]['selectable']);$this->assertTrue($rows[1]['selectable']);$this->assertFalse($rows[2]['selectable']);
        $this->assertSame('',$rows[0]['reason']);
    }
    public function test_paired_apply_rolls_back_both_ends_and_preserves_database_settings():void
    {
        $t=$this->createTunnel();$old=$t->settings;$new=[...$old,'public_host'=>'new.example.com'];
        $op=app(TunnelManager::class)->queue('apply',$t,null,['settings'=>$new]);
        $calls=[];$remote=\Mockery::mock(BackpackRemote::class);$remote->shouldReceive('ensure')->twice();
        $remote->shouldReceive('call')->andReturnUsing(function($server,$r)use(&$calls){$calls[]=[$server->id,$r['action']];if($r['action']==='apply'&&$server->id===$this->foreign->id)throw new \RuntimeException('private-token-must-not-leak');return ['active'=>true];});
        (new RunTunnelOperation($op->id))->handle($remote,app(TunnelManager::class));
        $this->assertSame($old,$t->fresh()->settings);$this->assertSame('failed',$op->fresh()->status);
        $this->assertContains([$this->iran->id,'rollback'],$calls);$this->assertContains([$this->foreign->id,'rollback'],$calls);
        $this->assertSame(0,DB::table('speedbox_tunnel_leases')->count());$this->assertStringNotContainsString('private-token',$op->fresh()->toJson());
    }
    public function test_partial_delete_retires_tunnel_and_releases_hosts():void
    {
        $t=$this->createTunnel();$op=app(TunnelManager::class)->queue('delete',$t,null);
        $remote=\Mockery::mock(BackpackRemote::class);$remote->shouldReceive('call')->andReturnUsing(function($s,$r){if($s->id===$this->foreign->id)throw new \RuntimeException('offline');return ['deleted'=>true];});
        (new RunTunnelOperation($op->id))->handle($remote,app(TunnelManager::class));
        $this->assertSame('deleted',$t->fresh()->status);$this->assertSame('succeeded',$op->fresh()->status);$this->assertSame(0,DB::table('speedbox_tunnel_leases')->count());
        $this->assertTrue($op->fresh()->result['cleanup']['iran']['cleaned']);
        $this->assertFalse($op->fresh()->result['cleanup']['foreign']['cleaned']);
        $this->withToken($this->token)->deleteJson('/api/admin/infrastructure/servers/'.$this->foreign->id)->assertAccepted();
    }

    public function test_both_unreachable_hosts_do_not_keep_a_tunnel_active():void
    {
        $t=$this->createTunnel();$op=app(TunnelManager::class)->queue('delete',$t,null);
        $remote=\Mockery::mock(BackpackRemote::class);$remote->shouldReceive('call')->twice()->andThrow(new \RuntimeException('offline',1001));
        (new RunTunnelOperation($op->id))->handle($remote,app(TunnelManager::class));
        $this->assertSame('deleted',$t->fresh()->status);
        $this->assertCount(2,$op->fresh()->result['cleanup']);
        $this->assertSame(0,DB::table('speedbox_tunnel_leases')->count());
    }

    public function test_network_deletion_and_failed_status_do_not_revert_to_green():void
    {
        foreach(['gost','gre','wattunnel'] as $i=>$engine){
            $t=$this->createTunnel();$t->update(['engine'=>$engine,'status'=>'connected','remote_name'=>'sb-'.str_repeat((string)($i+1),32),'subnet'=>1600+$i]);
            $network=app(\App\Services\NetworkTunnelManager::class);$manager=app(TunnelManager::class);
            $remote=\Mockery::mock(\App\Services\NetworkTunnelRemote::class);
            $calls=[];$remote->shouldReceive('call')->andReturnUsing(function($server,$request)use(&$calls){$calls[]=$server->id;if($server->id===$this->foreign->id)throw new \RuntimeException('offline',1001);return ['active'=>true,'connected'=>true,'deleted'=>true];});
            $op=$manager->queue('status',$t,null);
            (new \App\Jobs\RunNetworkTunnelOperation($op->id))->handle($remote,$network,$manager);
            $this->assertSame('needs_review',$t->fresh()->status,$engine);
            $this->assertCount(2,$calls);$calls=[];
            $op=$manager->queue('delete',$t->fresh(),null);
            (new \App\Jobs\RunNetworkTunnelOperation($op->id))->handle($remote,$network,$manager);
            $this->assertSame('deleted',$t->fresh()->status,$engine);
            $this->assertTrue($op->fresh()->result['cleanup']['iran']['cleaned']);
            $this->assertFalse($op->fresh()->result['cleanup']['foreign']['cleaned']);
            $this->assertSame([$this->iran->id,$this->foreign->id],$calls);
        }
    }

    public function test_old_green_status_is_projected_as_unverified():void
    {
        $t=$this->createTunnel();$t->update(['status'=>'connected','remote_status'=>['_checked_at'=>time()-181]]);
        $this->withToken($this->token)->getJson('/api/admin/infrastructure/tunnels')->assertOk()->assertJsonPath('data.0.status','needs_review');
        $this->assertSame('connected',$t->fresh()->status); // Projection must not falsify the stored observation.
    }

    public function test_retired_server_is_removed_even_when_ssh_fails_and_nodes_are_detached():void
    {
        $node=\App\Models\ProxyServer::create(['name'=>'kept','type'=>'vless','kernel'=>'xray','machine_id'=>$this->iran->machine_id,'host'=>'192.0.2.1','port'=>'443','server_port'=>443,'enabled'=>true]);
        $response=$this->withToken($this->token)->deleteJson('/api/admin/infrastructure/servers/'.$this->iran->id)->assertAccepted();
        $id=$response->json('data.id');
        $ssh=\Mockery::mock(\App\Services\SshExecutor::class)->makePartial();
        $ssh->shouldReceive('run')->once()->andThrow(new \RuntimeException('offline'));
        (new \App\Jobs\UninstallManagedServer($id))->handle($ssh,app(\App\Services\ManagedServerRemoval::class));
        $this->assertNull($this->iran->fresh());
        $this->assertNotNull($node->fresh());$this->assertNull($node->fresh()->machine_id);$this->assertFalse($node->fresh()->enabled);
        $this->getJson('/api/admin/infrastructure/server-removals/'.$id)->assertOk()->assertJsonPath('data.status','succeeded')->assertJsonPath('data.cleanup_confirmed',false);
    }

    public function test_ssh_cleanup_budget_caps_the_entire_call_and_restores_normal_options():void
    {
        $dir=sys_get_temp_dir().'/speedbox-budget-'.bin2hex(random_bytes(8));mkdir($dir,0700);
        file_put_contents($dir.'/sshpass',"#!/bin/sh\nshift\nexec \"\$@\"\n");
        file_put_contents($dir.'/ssh',"#!/bin/sh\ncase \"\$*\" in *budget-sleep*) exec sleep 3;; *ConnectTimeout=5*) printf short;; *) printf normal;; esac\n");
        chmod($dir.'/sshpass',0700);chmod($dir.'/ssh',0700);
        $path=getenv('PATH');$serverPath=$_SERVER['PATH']??null;$envPath=$_ENV['PATH']??null;
        putenv('PATH='.$dir.':'.$path);$_SERVER['PATH']=$_ENV['PATH']=$dir.':'.$path;
        try {
            $ssh=app(\App\Services\SshExecutor::class);$started=microtime(true);$timedOut=false;
            try {$ssh->withinBudget(1,fn()=>$ssh->run($this->iran,'budget-sleep',15));}
            catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {$timedOut=true;}
            $this->assertTrue($timedOut);$this->assertLessThan(2.5,microtime(true)-$started);
            $this->assertSame('short',$ssh->withinBudget(2,fn()=>$ssh->run($this->iran,'budget-ok',15)));
            $this->assertSame('normal',$ssh->run($this->iran,'budget-ok',15));
        } finally {putenv('PATH='.$path);if($serverPath===null)unset($_SERVER['PATH']);else $_SERVER['PATH']=$serverPath;if($envPath===null)unset($_ENV['PATH']);else $_ENV['PATH']=$envPath;unlink($dir.'/sshpass');unlink($dir.'/ssh');rmdir($dir);}
    }

    public function test_bounded_tunnel_requests_use_one_stdin_connection_without_sftp():void
    {
        foreach([BackpackRemote::class,\App\Services\NetworkTunnelRemote::class] as $class){
            $ssh=\Mockery::mock(\App\Services\SshExecutor::class)->makePartial();
            $ssh->shouldNotReceive('upload');
            $ssh->shouldReceive('run')->once()->andReturnUsing(function($server,$command,$timeout,$input){
                $this->assertSame(15,$timeout);$this->assertSame('delete',json_decode($input,true)['action']);
                return json_encode(['ok'=>true,'data'=>['deleted'=>true]]);
            });
            $this->assertTrue((new $class($ssh))->call($this->iran,['action'=>'delete','name'=>'fixture'])['deleted']);
        }
    }
    public function test_cancel_cleans_both_temporary_tests_and_releases_hosts():void
    {
        $test=$this->testRecord();$op=app(TunnelManager::class)->queue('test',null,$test,['test_id'=>str_repeat('b',32),'orientation'=>'normal','peer_started'=>true]);$op->update(['cancel_requested'=>true]);
        $remote=\Mockery::mock(BackpackRemote::class);$remote->shouldReceive('call')->twice()->withArgs(fn($server,$r)=>$r['action']==='test-stop')->andReturn(['stopped'=>true]);
        (new RunTunnelOperation($op->id))->handle($remote,app(TunnelManager::class));
        $this->assertSame('cancelled',$test->fresh()->status);$this->assertSame(0,DB::table('speedbox_tunnel_leases')->count());
    }
    public function test_normal_failure_schedules_swapped_test_without_erasing_results():void
    {
        $test=$this->testRecord();$op=app(TunnelManager::class)->queue('test',null,$test,['test_id'=>str_repeat('c',32),'orientation'=>'normal','peer_started'=>true]);
        $remote=\Mockery::mock(BackpackRemote::class);$remote->shouldReceive('call')->andReturnUsing(fn($s,$r)=>$r['action']==='test-state'?['state'=>'completed','rows'=>[['k'=>'reverse','t'=>'tcp','s'=>'down','o'=>0,'n'=>60]]]:['stopped'=>true]);
        (new RunTunnelOperation($op->id))->handle($remote,app(TunnelManager::class));
        $this->assertSame('swapped',$op->fresh()->payload['orientation']);$this->assertArrayNotHasKey('test_id',$op->fresh()->payload);$this->assertCount(1,$test->fresh()->results);$this->assertSame('normal',$test->fresh()->results[0]['orientation']);
        $this->assertSame(2,DB::table('speedbox_tunnel_leases')->count());
    }
    public function test_mapping_keeps_foreign_runtime_and_distinguishes_public_port():void
    {
        $t=$this->createTunnel()->load(['iran','foreign']);$job=new RunTunnelOperation(1);
        $iran=$job->applyRequest($t,$t->settings,'iran');$foreign=$job->applyRequest($t,$t->settings,'foreign');
        $this->assertSame('server',$iran['role']);$this->assertSame('client',$foreign['role']);$this->assertSame('443=5790',$iran['ports']);$this->assertSame(8000,$iran['port']);$this->assertSame($this->iran->host,$foreign['host']);
        $this->assertMatchesRegularExpression('~^10\.203\.[0-9]{1,3}\.[0-9]{1,3}/30$~',$iran['local_ip']);
    }
    public function test_server_with_tunnel_cannot_be_removed():void
    {
        $this->createTunnel();
        $this->withToken($this->token)->deleteJson('/api/admin/infrastructure/servers/'.$this->iran->id,['panel_only'=>true])->assertUnprocessable();
        $this->assertNotNull($this->iran->fresh());
    }
    public function test_connected_without_backend_is_reported_separately():void
    {
        $t=$this->createTunnel();$op=app(TunnelManager::class)->queue('status',$t,null);
        $remote=\Mockery::mock(BackpackRemote::class);$remote->shouldReceive('call')->andReturn(['active'=>true,'metrics'=>['connected'=>true],'targets'=>['5790'=>false]]);
        (new RunTunnelOperation($op->id))->handle($remote,app(TunnelManager::class));
        $this->assertSame('connected',$t->fresh()->status);$this->assertStringContainsString('سرویس مقصد هنوز پاسخ نمی‌دهد',$t->fresh()->message);
    }
    public function test_service_running_without_engine_evidence_is_not_connected():void
    {
        $t=$this->createTunnel();$op=app(TunnelManager::class)->queue('status',$t,null);
        $remote=\Mockery::mock(BackpackRemote::class);$remote->shouldReceive('call')->andReturn(['active'=>true,'targets'=>['5790'=>true]]);
        (new RunTunnelOperation($op->id))->handle($remote,app(TunnelManager::class));
        $this->assertSame('checking',$t->fresh()->status);
    }
    public function test_tunnel_logs_redact_token_and_setup_links():void
    {
        $t=$this->createTunnel();$op=app(TunnelManager::class)->queue('logs',$t,null);
        $remote=\Mockery::mock(BackpackRemote::class);$remote->shouldReceive('call')->andReturn(['text'=>'token='.$t->secret.' backpack://private-link']);
        (new RunTunnelOperation($op->id))->handle($remote,app(TunnelManager::class));
        $this->assertStringNotContainsString($t->secret,$op->fresh()->toJson());
        $this->assertStringNotContainsString('backpack://private-link',$op->fresh()->toJson());
    }
    public function test_completed_stable_test_does_not_force_other_orientation():void
    {
        $test=$this->testRecord();$op=app(TunnelManager::class)->queue('test',null,$test,['test_id'=>str_repeat('d',32),'orientation'=>'normal','peer_started'=>true]);
        $remote=\Mockery::mock(BackpackRemote::class);$remote->shouldReceive('call')->andReturnUsing(fn($s,$r)=>$r['action']==='test-state'?['state'=>'completed','rows'=>[['k'=>'reverse','t'=>'tcp','s'=>'ok','o'=>60,'n'=>60,'r'=>40]]]:['stopped'=>true]);
        (new RunTunnelOperation($op->id))->handle($remote,app(TunnelManager::class));
        $this->assertSame('completed',$test->fresh()->status);$this->assertSame('succeeded',$op->fresh()->status);
        $this->assertTrue($test->fresh()->results[0]['recommended']);$this->assertSame(0,DB::table('speedbox_tunnel_leases')->count());
    }
    public function test_server_creation_uses_direct_ssh_and_ignores_legacy_reverse_route():void
    {
        $body=['name'=>'Direct host','host'=>'192.0.2.90','ssh_port'=>22,'ssh_user'=>'root','auth_type'=>'password','password'=>'private-password','reverse_ssh_port'=>45221];
        $response=$this->withToken($this->token)->postJson('/api/admin/infrastructure/servers',$body)->assertCreated();
        $response->assertJsonPath('data.host','192.0.2.90')->assertJsonMissingPath('data.reverse_ssh_port')->assertJsonMissingPath('data.password');
        $this->assertNull(ManagedServer::where('host','192.0.2.90')->first()->getRawOriginal('reverse_ssh_port'));
    }

    private function watFixture(array $changes=[]): array
    {
        if(!Schema::hasColumn('speedbox_tunnels','engine'))(require database_path('migrations/2026_10_20_000001_add_network_tunnel_engines.php'))->up();
        $tester=app(\App\Services\NetworkTunnelTest::class);
        $rows=$tester->candidates('wattunnel',$this->iran,$this->foreign,app(\App\Services\NetworkTunnelManager::class),['carriers'=>['tcp']]);
        $rows=array_map(fn($r)=>[...$r,'status'=>'ok','selectable'=>true,'protocols'=>['tcp'=>['status'=>'ok'],'udp'=>['status'=>'ok']]],$rows);
        $test=TunnelConnectionTest::create(['iran_server_id'=>$this->iran->id,'foreign_server_id'=>$this->foreign->id,'status'=>'completed','results'=>$rows]);
        $test->operations()->create(['action'=>'network-test','status'=>'succeeded','payload'=>['engine'=>'wattunnel'],'deadline'=>now()->addMinutes(40)]);
        return [...\App\Support\WatTunnelRules::defaults(),'iran_server_id'=>$this->iran->id,'foreign_server_id'=>$this->foreign->id,
            'name'=>'Wat fixture','transport'=>'tcp','test_id'=>$test->id,'result_id'=>$rows[0]['id'],...$changes];
    }

    public function test_wattunnel_has_independent_schema_and_requires_matching_measurement(): void
    {
        $d=$this->watFixture(['public_host'=>'ir.example.test']);$this->withToken($this->token);
        $this->postJson('/api/admin/infrastructure/network-tunnels',[...$d,'reverse'=>true])->assertUnprocessable();
        $this->postJson('/api/admin/infrastructure/network-tunnels',[...$d,'transport'=>'udp','reverse'=>true])->assertUnprocessable();
        $answer=$this->postJson('/api/admin/infrastructure/network-tunnels',$d)->assertAccepted();
        $t=ServerTunnel::findOrFail($answer->json('data.id'));
        $this->assertSame('wattunnel',$t->engine);$this->assertSame('direct',$t->kind);
        $this->assertSame('ir.example.test',$t->settings['public_host']);
        $this->assertArrayNotHasKey('nat',$t->settings);$this->assertArrayNotHasKey('gre_key',$t->settings);
        $this->assertSame((int)$t->subnet,$t->settings['metrics_port']);
        $this->assertStringNotContainsString($t->secret,$answer->getContent());
        $this->postJson('/api/admin/infrastructure/network-tunnels/'.$t->id.'/actions',['action'=>'reset-quota','confirm'=>true])->assertUnprocessable();
        $this->postJson('/api/admin/infrastructure/network-tunnels/'.$t->id.'/actions',['action'=>'optimize','confirm'=>true,'sides'=>['iran']])->assertUnprocessable();
        Queue::assertPushed(\App\Jobs\RunNetworkTunnelOperation::class);
    }

    public function test_wattunnel_candidates_respect_carrier_direction_and_ipv4_icmp(): void
    {
        $this->watFixture();$this->withToken($this->token);
        $answer=$this->postJson('/api/admin/infrastructure/network-tunnel-tests',['engine'=>'wattunnel','iran_server_id'=>$this->iran->id,'foreign_server_id'=>$this->foreign->id,'direction'=>'both','carriers'=>['icmp','udp','tcp']])->assertAccepted();
        $rows=$answer->json('data.results');$this->assertCount(5,$rows);
        $this->assertCount(2,array_filter($rows,fn($r)=>$r['reverse']));
        foreach($rows as $r)$this->assertSame('ipv4',$r['ip_family']);
        Queue::assertPushed(\App\Jobs\RunNetworkTunnelTest::class);
    }

    public function test_wattunnel_reserves_normal_reverse_and_private_metrics_ports(): void
    {
        $d=$this->watFixture();$manager=app(\App\Services\NetworkTunnelManager::class);
        $request=\Illuminate\Http\Request::create('/','POST',$d);
        $prepared=$manager->prepare($request);$prepared=$manager->allocate($prepared,50001);
        $t=ServerTunnel::create(['engine'=>'wattunnel','name'=>'Existing Wat','remote_name'=>'sb-'.str_repeat('f',32),'iran_server_id'=>$this->iran->id,'foreign_server_id'=>$this->foreign->id,'kind'=>'direct','transport'=>'tcp','orientation'=>'normal','subnet'=>50001,'secret'=>str_repeat('a',64),'settings'=>$prepared]);
        foreach([[$this->foreign->id,$prepared['tunnel_port']],[$this->iran->id,50001],[$this->foreign->id,50001]] as [$id,$port])$this->assertContains($port,app(TunnelManager::class)->reservedPorts(ManagedServer::findOrFail($id)));
        $this->withToken($this->token)->postJson('/api/admin/infrastructure/network-tunnels',[...$d,'mappings'=>[['public_port'=>50001,'target_port'=>8443]]])->assertUnprocessable();
        $this->postJson('/api/admin/infrastructure/network-tunnels',[...$d,'tunnel_port'=>22,'mappings'=>[['public_port'=>8443,'target_port'=>5790]]])->assertUnprocessable();
        $t->update(['kind'=>'reverse','settings'=>[...$prepared,'reverse'=>true]]);
        $this->assertContains($prepared['tunnel_port'],app(TunnelManager::class)->reservedPorts($this->iran));
    }

    public function test_wattunnel_two_host_apply_rolls_back_after_second_host_failure(): void
    {
        $d=$this->watFixture();$s=app(\App\Services\NetworkTunnelManager::class)->prepare(\Illuminate\Http\Request::create('/','POST',$d));
        $s=app(\App\Services\NetworkTunnelManager::class)->allocate($s,50002);
        $t=ServerTunnel::create(['engine'=>'wattunnel','name'=>'Wat fixture','remote_name'=>'sb-'.str_repeat('d',32),'iran_server_id'=>$this->iran->id,'foreign_server_id'=>$this->foreign->id,'kind'=>'direct','transport'=>'tcp','orientation'=>'normal','subnet'=>50002,'secret'=>str_repeat('b',64),'settings'=>$s]);
        $op=app(TunnelManager::class)->queue('apply',$t,null,['settings'=>[...$s,'conns'=>4]]);$rolled=[];
        $remote=\Mockery::mock(\App\Services\NetworkTunnelRemote::class);
        $remote->shouldReceive('ensure')->andReturnNull();
        $remote->shouldReceive('call')->andReturnUsing(function($server,$q)use(&$rolled){
            if($q['action']==='prepare')return ['checks'=>[]];
            if($q['action']==='apply'&&$q['config']['side']==='iran')throw new \RuntimeException('simulated second host failure');
            if($q['action']==='rollback')$rolled[]=$q['config']['side'];
            return ['active'=>true,'connected'=>true];
        });
        for($i=0;$i<20&&in_array($op->fresh()->status,['queued','running']);$i++)(new \App\Jobs\RunNetworkTunnelOperation($op->id))->handle($remote,app(\App\Services\NetworkTunnelManager::class),app(TunnelManager::class));
        $this->assertSame('failed',$op->fresh()->status);$this->assertSame(8,$t->fresh()->settings['conns']);
        $this->assertEqualsCanonicalizing(['iran','foreign'],$rolled);$this->assertSame(0,DB::table('speedbox_tunnel_leases')->count());
    }
}
