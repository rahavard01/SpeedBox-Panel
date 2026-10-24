<?php
namespace Tests\Feature;

use App\Jobs\RunNetworkTunnelOperation;
use App\Models\{ManagedServer,ProxyServer,ServerMachine,ServerTunnel};
use App\Services\{NetworkTunnelManager,NetworkTunnelRemote,NodeControlService,TrustedTunnelOrigin,TunnelManager};
use App\Support\WatTunnelRules;
use Illuminate\Support\Facades\{Queue,DB};
use Tests\TestCase;

class TrustedTunnelOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('t',32)),'queue.default'=>'database','cache.default'=>'array']);
        Queue::fake();
        foreach(['2025_01_01_000000_create_speedbox_core_tables.php','2026_10_08_000001_create_speedbox_infrastructure_tables.php','2026_10_18_000001_add_node_warp_egress.php','2026_10_18_000002_create_speedbox_tunnels.php','2026_10_18_000003_create_speedbox_tunnel_host_status.php','2026_10_20_000001_add_network_tunnel_engines.php','2026_10_23_000001_add_reverse_ssh_route.php'] as $file)(require database_path('migrations/'.$file))->up();
    }
    private function pair(): array
    {
        $servers=[];
        foreach(['iran'=>'192.0.2.1','foreign'=>'192.0.2.2'] as $side=>$host) {
            $m=ServerMachine::create(['name'=>$side,'token'=>$side.'-token','is_active'=>true]);
            $servers[$side]=ManagedServer::create(['name'=>$side,'host'=>$host,'ssh_port'=>22,'ssh_user'=>'root','auth_type'=>'password','password'=>'test-only','machine_id'=>$m->id,'status'=>'ready']);
        }
        $settings=[...WatTunnelRules::defaults(),'version'=>'2.10.9-sb1','enabled'=>true,'peer_host'=>'192.0.2.2','local_endpoint'=>'192.0.2.1','metrics_port'=>50002,'mappings'=>[['public_port'=>2087,'public_end'=>2088,'target_port'=>2087,'target_end'=>2088]]];
        $tunnel=ServerTunnel::create(['engine'=>'wattunnel','name'=>'Wat fixture','remote_name'=>'sb-'.str_repeat('d',32),'iran_server_id'=>$servers['iran']->id,'foreign_server_id'=>$servers['foreign']->id,'kind'=>'direct','transport'=>'h2','orientation'=>'normal','subnet'=>50002,'secret'=>str_repeat('b',64),'settings'=>$settings]);
        return [$servers,$tunnel];
    }
    public function test_policy_uses_committed_mapping_and_keeps_direct_nodes_unchanged(): void
    {
        [$servers,$tunnel]=$this->pair();
        $node=new ProxyServer(['machine_id'=>$servers['foreign']->machine_id,'server_port'=>2087]);
        $this->assertFalse(TrustedTunnelOrigin::required($node));
        $updated=TrustedTunnelOrigin::upgrade($tunnel,$tunnel->settings);
        $this->assertSame(WatTunnelRules::VERSION,$updated['version']);
        $this->assertSame([2087,2088],TrustedTunnelOrigin::ports($updated));
        $this->assertFalse(TrustedTunnelOrigin::required($node)); // planned is not committed
        $tunnel->update(['settings'=>$updated]);
        $this->assertTrue(TrustedTunnelOrigin::required($node));
        $node->server_port=2088;$this->assertTrue(TrustedTunnelOrigin::required($node));
        $node->server_port=443;$this->assertFalse(TrustedTunnelOrigin::required($node));
        $node->machine_id=$servers['iran']->machine_id;$node->server_port=2087;$this->assertFalse(TrustedTunnelOrigin::required($node));
        foreach(['iran','foreign'] as $side)$this->assertSame([2087,2088],app(NetworkTunnelManager::class)->config($tunnel,$side)['origin_ports']);
        $tunnel->update(['settings'=>[...$updated,'enabled'=>false]]);
        $node->machine_id=$servers['foreign']->machine_id;$this->assertFalse(TrustedTunnelOrigin::required($node));
    }
    public function test_upgrade_is_paired_and_rollback_never_enables_unapplied_origin_policy(): void
    {
        [$servers,$tunnel]=$this->pair();$old=$tunnel->settings;$rolled=[];$seen=[];
        $op=app(TunnelManager::class)->queue('reapply',$tunnel,null);
        $remote=\Mockery::mock(NetworkTunnelRemote::class);$remote->shouldReceive('ensure')->andReturnNull();
        $remote->shouldReceive('call')->andReturnUsing(function($server,$q)use(&$rolled,&$seen){
            if($q['action']==='prepare'){$seen[]=$q['config'];return ['checks'=>[]];}
            if($q['action']==='reapply'&&$q['config']['side']==='iran')throw new \RuntimeException('simulated second-host failure');
            if($q['action']==='rollback')$rolled[]=$q['config']['side'];
            return ['active'=>true,'connected'=>true];
        });
        for($i=0;$i<25&&in_array($op->fresh()->status,['queued','running']);$i++)(new RunNetworkTunnelOperation($op->id))->handle($remote,app(NetworkTunnelManager::class),app(TunnelManager::class));
        $this->assertSame('failed',$op->fresh()->status);$this->assertSame($old,$tunnel->fresh()->settings);
        $this->assertEqualsCanonicalizing(['iran','foreign'],$rolled);
        foreach($seen as $config){$this->assertSame(WatTunnelRules::VERSION,$config['settings']['version']);$this->assertSame([2087,2088],$config['origin_ports']);}
        $this->assertSame(0,DB::table('speedbox_tunnel_leases')->count());
    }
    public function test_engine_update_commits_both_peers_even_when_binaries_are_already_current(): void
    {
        [$servers,$tunnel]=$this->pair();$old=[...$tunnel->settings,'version'=>'2.10.9-sb2','source_identity'=>true,'enabled'=>false];$tunnel->update(['settings'=>$old]);$executed=[];
        $op=app(TunnelManager::class)->queue('engine-update',$tunnel,null);
        $remote=\Mockery::mock(NetworkTunnelRemote::class);
        $remote->shouldReceive('maintenanceStatus')->andReturn(['up_to_date'=>true]);
        $remote->shouldReceive('ensure')->andReturnNull();
        $remote->shouldReceive('call')->andReturnUsing(function($server,$q)use(&$executed){
            if($q['action']==='prepare')return ['checks'=>[]];
            if($q['action']==='reapply')$executed[]=$q['config']['side'];
            return ['active'=>false,'connected'=>false];
        });
        for($i=0;$i<25&&in_array($op->fresh()->status,['queued','running']);$i++)(new RunNetworkTunnelOperation($op->id))->handle($remote,app(NetworkTunnelManager::class),app(TunnelManager::class));
        $this->assertSame('succeeded',$op->fresh()->status);
        $this->assertEqualsCanonicalizing(['iran','foreign'],$executed);
        $this->assertSame(WatTunnelRules::VERSION,$tunnel->fresh()->settings['version']);
        $this->assertTrue($tunnel->fresh()->settings['source_identity']);
        $this->assertFalse($tunnel->fresh()->settings['enabled']);
        $this->assertSame('stopped',$tunnel->fresh()->status);
    }
    public function test_trusted_origin_removes_legacy_proxy_parser_only_from_mapped_nodes(): void
    {
        [$servers,$tunnel]=$this->pair();
        $tunnel->update(['settings'=>TrustedTunnelOrigin::upgrade($tunnel,$tunnel->settings)]);
        $settings=['network'=>'tcp','network_settings'=>['acceptProxyProtocol'=>true,'header'=>['type'=>'none']]];
        $node=new ProxyServer(['machine_id'=>$servers['foreign']->machine_id,'server_port'=>2087,'type'=>'vless','kernel'=>'xray','protocol_settings'=>$settings]);
        $mapped=app(NodeControlService::class)->configFor($node);
        $this->assertTrue($mapped['trusted_tunnel_origin']);
        $this->assertFalse($mapped['accept_proxy_protocol']);
        $this->assertArrayNotHasKey('acceptProxyProtocol',(array)$mapped['networkSettings']);
        $this->assertSame(['type'=>'none'],((array)$mapped['networkSettings'])['header']);
        $this->assertSame($settings,$node->protocol_settings);
        $node->server_port=443;
        $direct=app(NodeControlService::class)->configFor($node);
        $this->assertFalse($direct['trusted_tunnel_origin']);
        $this->assertTrue($direct['accept_proxy_protocol']);
        $this->assertTrue(((array)$direct['networkSettings'])['acceptProxyProtocol']);
    }
}

