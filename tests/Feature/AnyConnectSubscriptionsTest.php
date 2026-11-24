<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\{AnyConnectController, WireGuardController};
use App\Models\{AnyConnectAccount, AnyConnectInstance, ManagedServer, VpnOperation, WireGuardInstance};
use App\Services\{AnyConnectCertificate, AnyConnectNetwork, AnyConnectService, BotAccountMessages, PanelAccountActionService, SubscriptionReservationService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Support\Str;

final class AnyConnectSubscriptionsTest extends ReservationTestCase
{
    private function setupAccount(): object
    {
        Queue::fake();
        (require database_path('migrations/2026_11_24_000001_add_anyconnect_service.php'))->up();
        (require database_path('migrations/2026_11_24_000002_add_anyconnect_certificate_settings.php'))->up();

        $instance = AnyConnectInstance::create([
            'name' => 'Fixture AnyConnect', 'owner_id' => (string) Str::uuid(), 'managed_server_id' => 1,
            'endpoint_host' => 'vpn.example.test', 'endpoint_port' => 443, 'listen_port' => 443,
            'network' => '10.90.1.0/24', 'mtu' => 1300, 'dns' => ['1.1.1.1'], 'radius_secret' => str_repeat('a', 64),
            'status' => 'ready', 'enabled' => 1, 'last_synced_at' => now(),
        ]);
        DB::table('v2_plan')->whereIn('id', [10, 20])->update([
            'service_type' => 'anyconnect', 'anyconnect_instance_id' => $instance->id,
        ]);
        DB::table('panel_tariff_groups')->where('id', 1)->update(['service_type' => 'anyconnect']);
        DB::table('v2_user')->insert([
            'id' => 100, 'email' => 'alice@rep.com', 'password' => 'unused',
            'uuid' => (string) Str::uuid(), 'token' => str_repeat('a', 32), 'group_id' => 0,
            'plan_id' => 10, 'service_type' => 'anyconnect', 'anyconnect_instance_id' => $instance->id,
            'transfer_enable' => 250 * 1073741824, 'u' => 0, 'd' => 0,
            'expired_at' => time() + 86400, 'account_group_type' => 'time', 'account_tariff_group_id' => 1,
            'account_plan_key' => '1m', 'created_at' => time(), 'updated_at' => time(),
        ]);
        $account = DB::table('v2_user')->find(100);
        app(AnyConnectService::class)->provision($account);
        return $account;
    }

    public function test_vpn_management_lists_include_the_latest_operation_for_each_service(): void
    {
        (require database_path('migrations/2026_10_08_000001_create_speedbox_infrastructure_tables.php'))->up();
        (require database_path('migrations/2026_11_24_000003_create_speedbox_vpn_operations.php'))->up();

        $server = ManagedServer::create(['name' => 'Fixture server', 'host' => 'vpn.example.test']);
        $account = $this->setupAccount();
        $anyConnectId = (int) $account->anyconnect_instance_id;
        $wireGuard = WireGuardInstance::create([
            'name' => 'Fixture WireGuard', 'managed_server_id' => $server->id,
            'interface' => 'sbw123456789', 'network' => '10.91.1.0/24',
            'mode' => 'direct', 'endpoint_host' => $server->host,
            'endpoint_port' => 51820, 'listen_port' => 51820, 'dns' => ['1.1.1.1'],
        ]);

        $anyConnectController = app(AnyConnectController::class);
        $wireGuardController = app(WireGuardController::class);
        $this->assertNull($anyConnectController->index()->getData(true)['data'][0]['operation']);
        $this->assertNull($wireGuardController->index()->getData(true)['data'][0]['operation']);

        VpnOperation::record('anyconnect', $anyConnectId, 'install');
        VpnOperation::start('anyconnect', $anyConnectId)->recordProgress(100, 'Installed', 'succeeded');
        VpnOperation::record('anyconnect', $anyConnectId, 'sync');
        VpnOperation::record('wireguard', $wireGuard->id, 'install');
        VpnOperation::start('wireguard', $wireGuard->id)->recordProgress(35, 'Installing');

        $anyConnect = $anyConnectController->index()->getData(true)['data'][0];
        $wireGuard = $wireGuardController->index()->getData(true)['data'][0];
        $this->assertSame('sync', $anyConnect['operation']['action']);
        $this->assertSame('queued', $anyConnect['operation']['status']);
        $this->assertSame('install', $wireGuard['operation']['action']);
        $this->assertSame('running', $wireGuard['operation']['status']);
        $this->assertSame(35, $wireGuard['operation']['progress']);
        $this->assertNotEmpty($anyConnect['operation']['updated_at']);
        $this->assertNotEmpty($wireGuard['operation']['updated_at']);
    }

    public function test_certificate_secret_is_encrypted_and_absent_from_the_api(): void
    {
        $user = $this->setupAccount();
        $instance = AnyConnectInstance::findOrFail($user->anyconnect_instance_id);
        $this->assertSame('existing', $instance->certificate['mode']);
        $input = Request::create('/', 'POST', ['certificate' => [
            'mode' => 'dns', 'dns_provider' => 'cloudflare',
            'dns_env' => ['CLOUDFLARE_DNS_API_TOKEN' => 'fixture-certificate-secret'],
        ]]);
        $config = AnyConnectCertificate::settings($input, $instance->endpoint_host);
        $instance->update(['certificate_config' => $config]);
        $fresh = $instance->refresh();
        $this->assertSame($config, $fresh->certificate_config);
        $this->assertStringNotContainsString('fixture-certificate-secret', $fresh->getRawOriginal('certificate_config'));
        $this->assertStringNotContainsString('fixture-certificate-secret', $fresh->toJson());
        $this->assertArrayNotHasKey('certificate_config', $fresh->toArray());
        $this->assertSame(['CLOUDFLARE_DNS_API_TOKEN'], $fresh->certificate['saved_dns_fields']);
    }

    public function test_tunnel_allocates_network_and_port_using_both_managed_servers(): void
    {
        $this->setupAccount();
        $allocator = app(AnyConnectNetwork::class);
        $settings = [
            'managed_server_id' => 1, 'ingress_server_id' => 2, 'listen_port' => 9443, 'endpoint_port' => 8443,
        ];
        $first = $allocator->allocate($settings);
        $this->assertSame('10.90.2.0/24', $first['network']);
        $this->assertSame('10.251.1.0/30', $first['link_network']);
        $this->assertSame(51821, $first['link_port']);
        AnyConnectInstance::create($first + [
            'name' => 'Tunnel Fixture', 'owner_id' => (string) Str::uuid(), 'endpoint_host' => 'tunnel.example.test',
            'dns' => ['1.1.1.1'], 'mtu' => 1300, 'radius_secret' => str_repeat('b', 64),
        ]);
        $second = $allocator->allocate(array_replace($settings, ['listen_port' => 9444, 'endpoint_port' => 8444]));
        $this->assertSame('10.90.3.0/24', $second['network']);
        $this->assertSame('10.251.1.4/30', $second['link_network']);
        $this->assertSame(51822, $second['link_port']);
    }

    public function test_http_certificate_defaults_to_port_80_and_preserves_custom_port(): void
    {
        $request = Request::create('/', 'POST', ['certificate' => ['mode' => 'http']]);
        $this->assertSame(80, AnyConnectCertificate::settings($request, 'vpn.example.test')['http_port']);
        $this->assertSame(8080, AnyConnectCertificate::settings(
            $request, 'vpn.example.test', ['mode' => 'http', 'http_port' => 8080]
        )['http_port']);
        $request = Request::create('/', 'POST', ['certificate' => ['mode' => 'http', 'http_port' => 8443]]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        AnyConnectCertificate::settings($request, 'vpn.example.test', endpointPort: 8443);
    }

    public function test_delivery_uses_the_encrypted_connection_password_and_the_existing_message_layout(): void
    {
        $user = $this->setupAccount();
        $credentials = app(AnyConnectService::class)->credentials($user);
        $this->assertSame(4, strlen($credentials['password']));
        $this->assertNotSame($credentials['password'], DB::table('speedbox_anyconnect_accounts')->value('password'));
        $this->assertTrue(password_verify($credentials['password'], AnyConnectAccount::findOrFail(100)->password_hash));
        $text = BotAccountMessages::created($user, 'https://panel.example.test/subscribe/fixture');
        $this->assertStringStartsWith('✅ اکانت شما با موفقیت ساخته شد', $text);
        $this->assertStringContainsString('<code>alice-rep</code>', $text);
        $this->assertStringContainsString('<code>vpn.example.test</code>', $text);
        $this->assertStringContainsString('🛡 سرویس: AnyConnect', $text);
    }

    public function test_repeated_accounting_is_idempotent_and_reserved_renewal_keeps_credentials(): void
    {
        $user = $this->setupAccount();
        $service = app(AnyConnectService::class);
        $password = $service->credentials($user)['password'];
        $instance = AnyConnectInstance::findOrFail($user->anyconnect_instance_id);
        $report = [100 => ['uploaded' => 400, 'downloaded' => 800, 'online' => 2]];
        $service->ingest($instance, $report);
        $service->ingest($instance, $report);
        $this->assertDatabaseHas('v2_user', ['id' => 100, 'u' => 400, 'd' => 800]);
        $this->assertDatabaseHas('speedbox_anyconnect_accounts', ['user_id' => 100, 'online' => 2]);

        $result = app(PanelAccountActionService::class)->renewAccountForPanelUser(
            DB::table('panel_users')->find(1), $user->email, '3m', 1,
        );
        $this->assertTrue($result['reserved'] ?? false, json_encode($result, JSON_UNESCAPED_UNICODE));
        DB::table('v2_user')->where('id', 100)->update(['expired_at' => time() - 1]);
        $this->assertTrue(app(SubscriptionReservationService::class)->activateIfDue(100));
        $fresh = DB::table('v2_user')->find(100);
        $this->assertSame($password, $service->credentials($fresh)['password']);
        $service->ingest($instance, $report);
        $this->assertDatabaseHas('v2_user', ['id' => 100, 'u' => 0, 'd' => 0, 'plan_id' => 20]);
    }
}
