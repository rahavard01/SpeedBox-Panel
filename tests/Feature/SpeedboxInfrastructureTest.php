<?php

namespace Tests\Feature;

use App\Models\ProxyServer;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpeedboxInfrastructureTest extends TestCase
{
    private ServerMachine $machine;
    private ProxyServer $node;
    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'app.url' => 'https://panel.test']);
        Schema::create('panel_users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email'); $t->string('password'); $t->integer('role'); $t->boolean('banned')->default(false); $t->timestamps(); });
        (require database_path('migrations/2025_01_01_000000_create_speedbox_core_tables.php'))->up();
        (require database_path('migrations/2026_10_08_000001_create_speedbox_infrastructure_tables.php'))->up();
        (require database_path('migrations/2026_10_10_000003_create_connection_admission_leases.php'))->up();
        $this->machine = ServerMachine::create(['name' => 'Test server', 'token' => str_repeat('t', 48), 'is_active' => true]);
        $group = ServerGroup::create(['name' => 'Test group']);
        $this->node = ProxyServer::create([
            'name' => '🇩🇪 Test', 'type' => 'vless', 'kernel' => 'xray', 'machine_id' => $this->machine->id,
            'host' => 'vpn.test', 'port' => '443', 'server_port' => 443, 'rate' => 2,
            'group_ids' => [$group->id], 'route_ids' => [], 'show' => true, 'enabled' => true,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp', 'flow' => '', 'network_settings' => []],
        ]);
        $this->uuid = '12345678-1234-1234-1234-123456789012';
        DB::table('v2_user')->insert(['email' => 'test@example.test', 'password' => 'test', 'uuid' => $this->uuid,
            'token' => str_repeat('u', 32), 'group_id' => $group->id, 'transfer_enable' => 1000000,
            'expired_at' => time() + 3600, 'created_at' => time(), 'updated_at' => time()]);
    }

    private function auth(): array
    {
        return ['machine_id' => $this->machine->id, 'token' => $this->machine->token, 'node_id' => $this->node->id];
    }

    public function test_machine_discovers_kernel_and_authentication_is_enforced(): void
    {
        $this->postJson('/api/v2/server/machine/nodes', $this->auth())->assertOk()->assertJsonPath('nodes.0.kernel', 'xray');
        $this->postJson('/api/v2/server/machine/nodes', [...$this->auth(), 'token' => 'bad'])->assertUnauthorized();
        $other = ServerMachine::create(['name' => 'Other', 'token' => 'other', 'is_active' => true]);
        $this->getJson('/api/v2/server/config?'.http_build_query(['machine_id' => $other->id, 'token' => 'other', 'node_id' => $this->node->id]))->assertNotFound();
    }

    public function test_active_users_config_etag_and_traffic_accounting(): void
    {
        $query = http_build_query($this->auth());
        $config = $this->getJson('/api/v2/server/config?'.$query)->assertOk()->assertJsonPath('protocol', 'vless');
        $this->withHeader('If-None-Match', $config->headers->get('ETag'))->getJson('/api/v2/server/config?'.$query)->assertStatus(304);
        $this->flushHeaders();
        $this->getJson('/api/v2/server/user?'.$query)->assertOk()->assertJsonPath('users.0.uuid', $this->uuid);
        $this->postJson('/api/v2/server/report', [...$this->auth(), 'traffic' => ['1' => [100, 200]]])->assertOk();
        $this->assertDatabaseHas('v2_user', ['id' => 1, 'u' => 200, 'd' => 400]);
        $this->assertDatabaseHas('v2_server', ['id' => $this->node->id, 'u' => 100, 'd' => 200]);
        DB::table('v2_user')->where('id', 1)->update(['banned' => 1]);
        $this->getJson('/api/v2/server/user?'.$query)->assertOk()->assertExactJson(['users' => []]);
    }

    public function test_subscription_preserves_original_smart_json_and_general_formats(): void
    {
        $url = '/s/'.str_repeat('u', 32);
        $general = $this->get($url.'?raw=1')->assertOk();
        $this->assertStringContainsString('vless://'.$this->uuid.'@vpn.test:443', $general->getContent());
        $smart = $this->withHeader('User-Agent', 'Streisand-Smart')->get($url)->assertOk();
        $json = json_decode($smart->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($json);
        $this->assertStringContainsString('SMART SERVER ALL', $smart->getContent());
    }

    public function test_admin_server_credentials_are_encrypted_and_install_is_queued(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = new \App\Models\User();
        $admin->forceFill(['name' => 'Admin', 'email' => 'admin@test.test', 'password' => 'password', 'role' => 1, 'banned' => false])->save();
        $token = $admin->createToken('test', ['*'], now()->addHour())->plainTextToken;
        $response = $this->withToken($token)->postJson('/api/admin/infrastructure/servers', ['name' => 'New server', 'host' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root', 'auth_type' => 'password', 'password' => 'private-secret']);
        $response->assertCreated()->assertJsonMissing(['password' => 'private-secret']);
        $stored = DB::table('speedbox_managed_servers')->value('password');
        $this->assertNotSame('private-secret', $stored);
        $this->assertSame('private-secret', \App\Models\ManagedServer::first()->password);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\DeployManagedServer::class);
        $this->withToken($token)->postJson('/api/admin/infrastructure/plans', ['name' => 'Basic', 'group_id' => 1, 'transfer_enable' => 100, 'speed_limit' => 0, 'device_limit' => 2])->assertOk();
        $this->assertDatabaseHas('v2_plan', ['name' => 'Basic', 'device_limit' => 2]);
        $this->withToken($token)->postJson('/api/admin/infrastructure/keys/reality')->assertOk()->assertJsonStructure(['data' => ['private_key', 'public_key', 'short_id']]);
        $admin->forceFill(['role' => 2])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/admin/infrastructure/servers')->assertForbidden();
    }

    public function test_browser_receives_subscriber_portal_and_custom_path_works(): void
    {
        $this->withHeaders(['Accept' => 'text/html', 'Sec-Fetch-Dest' => 'document'])->get('/s/'.str_repeat('u', 32))->assertOk()->assertSee('test-example.test');
        $this->flushHeaders();
        \App\Services\RuntimeSetting::set('subscribe_path', 'custom');
        $this->get('/custom/'.str_repeat('u', 32).'?raw=1')->assertOk()->assertSee('vless://', false);
    }

    public function test_migration_preserves_existing_subscription_configuration(): void
    {
        DB::table('v2_settings')->where('name', 'subscribe_url')->update(['value' => 'https://existing.test']);
        (require database_path('migrations/2026_10_08_000001_create_speedbox_infrastructure_tables.php'))->up();
        $this->assertDatabaseHas('v2_settings', ['name' => 'subscribe_url', 'value' => 'https://existing.test']);
    }
}

