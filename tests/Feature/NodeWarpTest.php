<?php

namespace Tests\Feature;

use App\Jobs\ConfigureMachineWarp;
use App\Models\ManagedServer;
use App\Models\ProxyServer;
use App\Models\ServerDeployment;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeControlService;
use App\Services\SshExecutor;
use App\Services\EnsureMachineWarpSupport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NodeWarpTest extends TestCase
{
    private ServerMachine $machine;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Schema::create('panel_users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email'); $t->string('password'); $t->integer('role'); $t->boolean('banned')->default(false); $t->timestamps(); });
        foreach (['2025_01_01_000000_create_speedbox_core_tables.php', '2026_10_08_000001_create_speedbox_infrastructure_tables.php', '2026_10_10_000003_create_connection_admission_leases.php', '2026_10_18_000001_add_node_warp_egress.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        // Other feature fixtures rebuild this table without the additive migration.
        // Eloquent's process-wide schema cache must follow the fresh test schema.
        $columns = new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, 'guardableColumns');
        $cached = $columns->getValue();
        unset($cached[ProxyServer::class]);
        $columns->setValue(null, $cached);
        $this->machine = ServerMachine::create(['name' => 'Host', 'token' => 'machine-secret', 'is_active' => true,
            'load_status' => ['capabilities' => ['warp-egress-v1']], 'warp_status' => ['ready' => true, 'account' => 'free', 'checked_at' => time()]]);
        ManagedServer::create(['machine_id' => $this->machine->id, 'name' => 'Host', 'host' => '192.0.2.10', 'ssh_user' => 'root', 'ssh_port' => 22, 'auth_type' => 'password', 'password' => 'ssh-secret']);
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@test.test', 'password' => 'password', 'role' => 1, 'banned' => false]);
        $this->adminToken = $admin->createToken('test', ['*'], now()->addHour())->plainTextToken;
    }

    private function payload(array $changes = []): array
    {
        return [...[
            'name' => 'WARP node', 'type' => 'vless', 'kernel' => 'xray', 'machine_id' => $this->machine->id,
            'host' => 'node.test', 'port' => '443', 'server_port' => 443, 'rate' => 1,
            'rate_time_enable' => false, 'show' => true, 'enabled' => true, 'warp_enabled' => true,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp', 'flow' => '', 'network_settings' => []],
            'custom_outbounds' => [['tag' => 'my-socks', 'protocol' => 'socks', 'settings' => ['servers' => [['address' => '127.0.0.1', 'port' => 1080]]]]],
            'custom_routes' => [['type' => 'field', 'domain' => ['example.test'], 'outboundTag' => 'my-socks']],
        ], ...$changes];
    }

    public function test_toggle_preserves_custom_fields_and_only_affects_selected_node(): void
    {
        $body = $this->payload();
        $id = $this->withToken($this->adminToken)->postJson('/api/admin/infrastructure/nodes', $body)->assertCreated()->json('data.id');
        $node = ProxyServer::findOrFail($id);
        $other = ProxyServer::create($this->payload(['name' => 'Direct node', 'server_port' => 444, 'warp_enabled' => false]));
        $control = app(NodeControlService::class);
        $this->assertTrue($control->configFor($node)['warp_enabled']);
        $this->assertFalse($control->configFor($other)['warp_enabled']);
        $this->withToken($this->adminToken)->putJson('/api/admin/infrastructure/nodes/'.$id, [...$body, 'warp_enabled' => false])->assertOk();
        $this->assertFalse($control->configFor($node->fresh())['warp_enabled']);
        $this->assertSame($body['custom_outbounds'], $node->fresh()->custom_outbounds);
        $this->assertSame($body['custom_routes'], $node->fresh()->custom_routes);
        $this->assertFalse($other->fresh()->warp_enabled);
    }

    public function test_old_agent_and_unverified_proxy_cannot_be_enabled(): void
    {
        $this->machine->update(['load_status' => []]);
        $this->withToken($this->adminToken)->postJson('/api/admin/infrastructure/nodes', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('warp_enabled');
        $this->machine->update(['load_status' => ['capabilities' => ['warp-egress-v1']], 'warp_status' => ['ready' => true, 'checked_at' => time() - 301]]);
        $this->postJson('/api/admin/infrastructure/nodes', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('warp_enabled');
        $this->postJson('/api/admin/infrastructure/nodes', $this->payload(['warp_enabled' => false]))->assertCreated();
    }

    public function test_parent_setting_is_inherited_and_synchronized(): void
    {
        $body = $this->payload();
        $parent = ProxyServer::create($body);
        $id = $this->withToken($this->adminToken)->postJson('/api/admin/infrastructure/nodes', $this->payload(['parent_id' => $parent->id, 'name' => 'Child', 'warp_enabled' => false]))->assertCreated()->json('data.id');
        $this->assertTrue(ProxyServer::findOrFail($id)->warp_enabled);
        $this->putJson('/api/admin/infrastructure/nodes/'.$parent->id, [...$body, 'warp_enabled' => false])->assertOk();
        $this->assertFalse(ProxyServer::findOrFail($id)->warp_enabled);
    }

    public function test_reserved_tag_is_rejected_and_old_agents_do_not_discover_warp_nodes(): void
    {
        $this->withToken($this->adminToken)->postJson('/api/admin/infrastructure/nodes', $this->payload([
            'custom_outbounds' => [['tag' => 'Speedbox-Warp', 'protocol' => 'socks', 'settings' => ['server' => 'localhost']]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('custom_outbounds');
        ProxyServer::create($this->payload());
        ProxyServer::create($this->payload(['server_port' => 444, 'warp_enabled' => false]));
        $this->machine->update(['load_status' => []]);
        $this->assertCount(1, app(NodeControlService::class)->machineNodes($this->machine->id));
        $this->machine->update(['load_status' => ['capabilities' => ['warp-egress-v1']]]);
        $this->assertCount(2, app(NodeControlService::class)->machineNodes($this->machine->id));
        // A stale earlier capability report must not let a downgraded agent
        // discover a WARP node during its first request after restarting.
        $auth = ['machine_id' => $this->machine->id, 'token' => $this->machine->token];
        $this->postJson('/api/v2/server/machine/nodes', $auth)->assertOk()->assertJsonCount(1, 'nodes');
        $this->withHeader('X-Speedbox-Capabilities', 'warp-egress-v1')->postJson('/api/v2/server/machine/nodes', $auth)->assertOk()->assertJsonCount(2, 'nodes');
        $warp = ProxyServer::where('warp_enabled', true)->firstOrFail();
        $this->flushHeaders();
        $query = http_build_query([...$auth, 'node_id' => $warp->id]);
        $this->getJson('/api/v2/server/config?'.$query)->assertStatus(426);
        $this->withHeader('X-Speedbox-Capabilities', 'warp-egress-v1')->getJson('/api/v2/server/config?'.$query)->assertOk()->assertJsonPath('warp_enabled', true);
    }

    public function test_prepare_is_admin_only_encrypted_queued_and_serialized_per_host(): void
    {
        $realQueue = app('queue')->connection('database');
        Queue::fake();
        $url = '/api/admin/infrastructure/machines/'.$this->machine->id.'/warp';
        $this->postJson($url, ['action' => 'test'])->assertUnauthorized();
        $this->withToken($this->adminToken)->postJson($url, ['action' => 'prepare', 'account' => 'plus', 'license' => 'secret-license-123'])
            ->assertAccepted()->assertDontSee('secret-license-123');
        Queue::assertPushed(ConfigureMachineWarp::class, fn ($job) => $job instanceof \Illuminate\Contracts\Queue\ShouldBeEncrypted && $job->queue === 'deployments');
        $queuePayload = (new \ReflectionMethod($realQueue, 'createPayload'))->invoke($realQueue, new ConfigureMachineWarp(1, 'plus', 'secret-license-123'), 'deployments');
        $this->assertStringNotContainsString('secret-license-123', $queuePayload);
        $this->postJson($url, ['action' => 'test'])->assertConflict();
        $this->getJson($url)->assertOk()->assertDontSee('secret-license-123')->assertDontSee('ssh-secret');
        $this->assertStringNotContainsString('secret-license-123', DB::table('speedbox_deployments')->first()->log);
        $this->assertArrayNotHasKey('license', $this->machine->fresh()->toArray());
    }

    public function test_job_uses_secret_file_and_sanitizes_remote_output(): void
    {
        $server = ManagedServer::firstOrFail();
        $deployment = ServerDeployment::create(['managed_server_id' => $server->id, 'action' => 'warp_prepare', 'kernel' => 'xray', 'status' => 'queued']);
        $ssh = \Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('run')->once()->withArgs(fn ($s, $cmd, $timeout) => str_contains($cmd, 'mktemp'))->andReturn('/tmp/speedbox-warp.ABC12345');
        $ssh->shouldReceive('upload')->twice()->withArgs(function ($s, $local, $remote) {
            if (str_ends_with($remote, '/license')) $this->assertSame('secret-license-123', file_get_contents($local));
            return true;
        });
        $ssh->shouldReceive('run')->once()->withArgs(fn ($s, $cmd, $timeout) => str_starts_with($cmd, 'bash ') && !str_contains($cmd, 'secret-license-123'))
            ->andReturn('{"installed":true,"ready":true,"account":"plus","ip":"203.0.113.2","license":"secret-license-123"}');
        $ssh->shouldReceive('run')->once()->withArgs(fn ($s, $cmd, $timeout) => str_starts_with($cmd, 'rm -f'))->andReturn('');
        (new ConfigureMachineWarp($deployment->id, 'plus', 'secret-license-123'))->handle($ssh);
        $this->assertSame('succeeded', $deployment->fresh()->status);
        $this->assertTrue($this->machine->fresh()->warp_status['ready']);
        $this->assertSame('plus', $this->machine->fresh()->warp_status['account']);
        $this->assertStringNotContainsString('secret-license-123', json_encode($this->machine->fresh()->toArray()));
    }
    public function test_setup_queues_encrypted_continuation_after_software_is_ready(): void
    {
        Queue::fake();
        $this->machine->update(['load_status' => []]);
        $deployment = ServerDeployment::create(['managed_server_id' => ManagedServer::first()->id, 'action' => 'warp_prepare', 'kernel' => 'singbox', 'status' => 'queued']);
        $support = \Mockery::mock(EnsureMachineWarpSupport::class);
        $support->shouldReceive('ensure')->once()->andReturnUsing(function () {
            $this->machine->update(['load_status' => ['capabilities' => ['warp-egress-v1']]]);
        });
        $ssh = \Mockery::mock(SshExecutor::class);
        $ssh->shouldNotReceive('run');
        (new ConfigureMachineWarp($deployment->id, 'plus', 'private-key'))->handle($ssh, $support);
        Queue::assertPushed(ConfigureMachineWarp::class, fn ($job) => $job->deploymentId === $deployment->id
            && $job instanceof \Illuminate\Contracts\Queue\ShouldBeEncrypted);
        $this->assertSame('queued', $deployment->fresh()->status);
        $this->assertStringNotContainsString('private-key', $deployment->fresh()->log);
    }

    public function test_matching_running_binary_waits_for_report_without_reinstalling(): void
    {
        $this->machine->update(['load_status' => []]);
        $deployment = ServerDeployment::create(['managed_server_id' => ManagedServer::first()->id, 'action' => 'warp_prepare', 'kernel' => 'singbox', 'status' => 'running']);
        $manifest = json_decode(file_get_contents(base_path('agent-dist/manifest.json')), true);
        $hash = $manifest['archives']['amd64']['binaries']['xboard-node-linux-amd64']['sha256'];
        $ssh = \Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('run')->once()->withArgs(fn ($server, $cmd, $timeout) => str_contains($cmd, '/proc/$pid/exe'))->andReturn($hash.'  /proc/123/exe');
        $support = \Mockery::mock(EnsureMachineWarpSupport::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $support->shouldNotReceive('upgrade');
        $support->shouldReceive('pause')->once()->andReturnUsing(function () {
            $this->machine->update(['load_status' => ['capabilities' => ['warp-egress-v1']]]);
        });
        $support->ensure($ssh, ManagedServer::with('machine')->first(), $deployment);
        $this->assertTrue($this->machine->fresh()->supportsWarp());
        $this->assertSame('running', $deployment->fresh()->status);
    }

    public function test_old_binary_is_upgraded_before_warp_preparation(): void
    {
        $this->machine->update(['load_status' => []]);
        $deployment = ServerDeployment::create(['managed_server_id' => ManagedServer::first()->id, 'action' => 'warp_prepare', 'kernel' => 'singbox', 'status' => 'running']);
        $ssh = \Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('run')->once()->andReturn(str_repeat('0', 64).'  /proc/123/exe');
        $support = \Mockery::mock(EnsureMachineWarpSupport::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $support->shouldReceive('upgrade')->once()->andReturnUsing(function () {
            $this->machine->update(['load_status' => ['capabilities' => ['warp-egress-v1']]]);
        });
        $support->shouldNotReceive('pause');
        $support->ensure($ssh, ManagedServer::with('machine')->first(), $deployment);
        $this->assertTrue($this->machine->fresh()->supportsWarp());
    }

    public function test_unconfirmed_software_stops_setup_without_installing_warp_or_leaking_license(): void
    {
        Queue::fake();
        $this->machine->update(['load_status' => []]);
        $deployment = ServerDeployment::create(['managed_server_id' => ManagedServer::first()->id, 'action' => 'warp_prepare', 'kernel' => 'singbox', 'status' => 'queued']);
        $support = \Mockery::mock(EnsureMachineWarpSupport::class);
        $support->shouldReceive('ensure')->once()->andThrow(new \RuntimeException('private-key'));
        $ssh = \Mockery::mock(SshExecutor::class);
        $ssh->shouldNotReceive('run');
        (new ConfigureMachineWarp($deployment->id, 'plus', 'private-key'))->handle($ssh, $support);
        Queue::assertNothingPushed();
        $this->assertSame('failed', $deployment->fresh()->status);
        $this->assertFalse($this->machine->fresh()->warp_status['ready']);
        $this->assertStringNotContainsString('private-key', $deployment->fresh()->log);
    }

    public function test_pending_setup_is_visible_after_nested_upgrade_finishes(): void
    {
        $server = ManagedServer::first();
        $setup = ServerDeployment::create(['managed_server_id' => $server->id, 'action' => 'warp_prepare', 'kernel' => 'singbox', 'status' => 'running']);
        ServerDeployment::create(['managed_server_id' => $server->id, 'action' => 'upgrade', 'kernel' => 'singbox', 'status' => 'succeeded']);
        $this->withToken($this->adminToken)->getJson('/api/admin/infrastructure/machines/'.$this->machine->id.'/warp')
            ->assertOk()->assertJsonPath('data.operation.id', $setup->id)->assertJsonPath('data.operation.status', 'running');
    }

}
