<?php

namespace Tests\Feature;

use App\Models\EmergencyConfig;
use App\Models\ProxyServer;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\EmergencyConfigParser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmergencyConfigurationsTest extends TestCase
{
    private string $api = '/api/admin/infrastructure/emergency-configs';
    private string $token = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private string $accountUuid = '11111111-1111-4111-8111-111111111111';
    private string $externalUuid = '22222222-2222-4222-8222-222222222222';
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('a',32)), 'app.url'=>'https://panel.test']);
        Schema::create('panel_users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email'); $t->string('password'); $t->integer('role'); $t->boolean('banned')->default(false); $t->timestamps(); });
        foreach (['2025_01_01_000000_create_speedbox_core_tables.php','2026_10_08_000001_create_speedbox_infrastructure_tables.php','2026_10_10_000003_create_connection_admission_leases.php','2026_10_13_000001_create_emergency_configurations.php'] as $file) (require database_path('migrations/'.$file))->up();
        $group=ServerGroup::create(['name'=>'Subscribers']);
        ProxyServer::create(['name'=>'Normal node','type'=>'vless','kernel'=>'xray','host'=>'normal.test','port'=>'443','server_port'=>443,'rate'=>1,'show'=>true,'enabled'=>true,'group_ids'=>[$group->id],'protocol_settings'=>['tls'=>0,'network'=>'tcp','network_settings'=>[]]]);
        DB::table('v2_user')->insert(['email'=>'test@example.test','password'=>'test','uuid'=>$this->accountUuid,'token'=>$this->token,'group_id'=>$group->id,'transfer_enable'=>1000000,'u'=>5,'d'=>10,'expired_at'=>time()+3600,'created_at'=>time(),'updated_at'=>time()]);
        $this->admin=new User();
        $this->admin->forceFill(['name'=>'Admin','email'=>'admin@example.test','password'=>'password','role'=>1,'banned'=>false])->save();
        $this->withToken($this->admin->createToken('test')->plainTextToken);
    }

    private function create(string $name='Emergency A', ?string $uri=null): int
    {
        return $this->postJson($this->api,['name'=>$name,'config_uri'=>$uri ?? "vless://{$this->externalUuid}@external.test:443?security=tls&type=ws&sni=cert.test&host=ws.test&path=%2Fconnect#old",'show'=>true])->assertCreated()->json('data.id');
    }

    private function subscription(string $flag='general', array $query=[])
    {
        return $this->get('/api/v1/client/subscribe?'.http_build_query(['token'=>$this->token,'flag'=>$flag,...$query]))->assertOk();
    }

    private function links(): array
    {
        return preg_split('/\r?\n/',trim(base64_decode($this->subscription()->getContent())));
    }

    public function test_crud_hides_secrets_from_lists_and_encrypts_storage(): void
    {
        $id=$this->create('مسیر اضطراری');
        $this->getJson($this->api)->assertOk()->assertJsonPath('position','bottom')->assertJsonMissingPath('data.0.config_uri');
        $raw=DB::table('speedbox_emergency_configs')->where('id',$id)->value('config_uri');
        $this->assertStringNotContainsString($this->externalUuid,$raw);
        $detail=$this->getJson($this->api.'/'.$id)->assertOk();
        $this->assertStringContainsString($this->externalUuid,$detail->json('data.config_uri'));
        $this->putJson($this->api.'/'.$id,['name'=>'Updated','config_uri'=>'trojan://outside-secret@new.test:443?sni=cert.test','show'=>true])->assertOk()->assertJsonPath('data.protocol','trojan');
        $this->assertStringContainsString('outside-secret@new.test',$this->links()[1]);
        $this->deleteJson($this->api.'/'.$id)->assertOk();
        $this->assertCount(1,$this->links());
    }

    public function test_visibility_and_account_state_only_control_future_subscription_output(): void
    {
        $id=$this->create();
        $this->assertCount(2,$this->links());
        $this->patchJson($this->api.'/'.$id.'/flags',['show'=>false])->assertOk();
        $this->assertCount(1,$this->links());
        $this->patchJson($this->api.'/'.$id.'/flags',['show'=>true])->assertOk();
        foreach ([['banned'=>1],['banned'=>0,'expired_at'=>time()-10],['expired_at'=>time()+3600,'u'=>1000000]] as $state) {
            DB::table('v2_user')->where('token',$this->token)->update($state);
            $this->assertStringNotContainsString('external.test',base64_decode($this->subscription()->getContent()));
        }
    }

    public function test_block_placement_and_internal_order_do_not_change_normal_nodes_or_usage(): void
    {
        $first=$this->create('First');$second=$this->create('Second');
        $names=fn()=>array_map(fn($uri)=>rawurldecode(explode('#',$uri,2)[1] ?? ''),$this->links());
        $this->assertSame(['Normal node','First','Second'],$names());
        $this->putJson($this->api.'/settings',['position'=>'top'])->assertOk();
        $this->assertSame(['First','Second','Normal node'],$names());
        $this->putJson($this->api.'/order',['ids'=>[$second,$first]])->assertOk();
        $this->assertSame(['Second','First','Normal node'],$names());
        $this->putJson($this->api.'/order',['ids'=>[$first,$first]])->assertUnprocessable();
        $this->putJson($this->api.'/order',['ids'=>[$first]])->assertUnprocessable();
        $this->putJson($this->api.'/settings',['position'=>'middle'])->assertUnprocessable();
        $this->assertDatabaseHas('v2_user',['token'=>$this->token,'u'=>5,'d'=>10,'transfer_enable'=>1000000]);
        $this->assertDatabaseCount('v2_server',1);
    }

    public function test_external_configs_work_without_managed_nodes_and_follow_filters(): void
    {
        $this->create('External VLESS');
        $this->create('External Trojan','trojan://secret@trojan.test:443?sni=cert.test');
        DB::table('v2_server')->delete();
        $this->assertCount(2,$this->links());
        $this->assertStringNotContainsString('external.test',base64_decode($this->subscription('general',['types'=>'trojan'])->getContent()));
        $this->assertStringNotContainsString('trojan.test',base64_decode($this->subscription('general',['filter'=>'vless'])->getContent()));
    }

    public function test_foreign_credentials_are_preserved_in_native_client_formats(): void
    {
        $this->create('External VLESS');
        $sing=$this->subscription('sing-box')->json();
        $external=collect($sing['outbounds'])->firstWhere('tag','External VLESS');
        $this->assertSame($this->externalUuid,$external['uuid']);
        $this->assertSame('cert.test',$external['tls']['server_name']);
        $this->assertSame('/connect',$external['transport']['path']);
        $normal=collect($sing['outbounds'])->firstWhere('tag','Normal node');
        $this->assertSame($this->accountUuid,$normal['uuid']);
        $clash=\Symfony\Component\Yaml\Yaml::parse($this->subscription('meta')->getContent());
        $this->assertSame($this->externalUuid,collect($clash['proxies'])->firstWhere('name','External VLESS')['uuid']);
        $this->assertStringContainsString($this->externalUuid,base64_decode($this->subscription('shadowrocket')->getContent()));
    }

    public function test_xray_smart_profiles_keep_emergency_configs_in_the_requested_block(): void
    {
        $this->create('External VLESS');
        $json=$this->subscription('xray-json')->json();
        $this->assertSame('External VLESS',end($json)['remarks']);
        foreach ($json as $config) if (str_contains($config['remarks'],'SMART')) $this->assertStringNotContainsString('external.test',json_encode($config));
        $this->putJson($this->api.'/settings',['position'=>'top'])->assertOk();
        $json=$this->subscription('xray-json')->json();
        $this->assertSame('External VLESS',$json[0]['remarks']);
        $this->assertSame($this->externalUuid,$json[0]['outbounds'][0]['settings']['vnext'][0]['users'][0]['id']);
    }

    public function test_distinct_tuic_and_http_credentials_survive_native_export(): void
    {
        $this->create('External TUIC',"tuic://{$this->externalUuid}:tuic-secret@tuic.test:443?sni=cert.test");
        $this->create('External HTTP','http://proxy-user:proxy-secret@proxy.test:8080');
        $this->create('External SOCKS','socks://sock-user:sock-secret@socks.test:1080');
        $configs=$this->subscription('sing-box')->json('outbounds');
        $tuic=collect($configs)->firstWhere('tag','External TUIC');
        $this->assertSame($this->externalUuid,$tuic['uuid']);$this->assertSame('tuic-secret',$tuic['password']);
        $http=collect($configs)->firstWhere('tag','External HTTP');
        $this->assertSame('proxy-user',$http['username']);$this->assertSame('proxy-secret',$http['password']);
        $socks=collect($configs)->firstWhere('tag','External SOCKS');
        $this->assertSame('sock-user',$socks['username']);$this->assertSame('sock-secret',$socks['password']);
    }

    public function test_invalid_links_and_duplicate_names_are_rejected_without_partial_records(): void
    {
        foreach (['https://example.test/subscription','javascript:alert(1)','vless://secret@example.test','vmess://broken','ss://broken','vless://secret@host.test:443?security=reality','vless://secret@host.test:443?type=ws&path[]=bad'] as $uri) {
            $this->postJson($this->api,['name'=>'Invalid','config_uri'=>$uri,'show'=>true])->assertUnprocessable()->assertJsonValidationErrors('config_uri');
        }
        $this->assertDatabaseCount('speedbox_emergency_configs',0);
        $this->create('Same name');
        $this->postJson($this->api,['name'=>'Same name','config_uri'=>'trojan://secret@host.test:443','show'=>true])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($this->api,['name'=>'Normal node','config_uri'=>'trojan://secret@host.test:443','show'=>true])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('speedbox_emergency_configs',1);
    }

    public function test_reality_parameters_and_fingerprint_survive_native_export(): void
    {
        $this->create('Reality',"vless://{$this->externalUuid}@reality.test:443?security=reality&sni=cert.test&pbk=public-key&sid=abcd&fp=firefox&type=tcp&flow=xtls-rprx-vision");
        $config=$this->subscription('xray-json')->json();
        $settings=end($config)['outbounds'][0]['streamSettings']['realitySettings'];
        $this->assertSame('public-key',$settings['publicKey']);
        $this->assertSame('abcd',$settings['shortId']);
        $this->assertSame('firefox',$settings['fingerprint']);
        $outbound=collect($this->subscription('sing-box')->json('outbounds'))->firstWhere('tag','Reality');
        $this->assertSame('firefox',$outbound['tls']['utls']['fingerprint']);
        $this->assertSame('public-key',$outbound['tls']['reality']['public_key']);
    }

    public function test_encoded_passwords_ipv6_and_literal_fragment_spaces_are_preserved(): void
    {
        $parser=app(EmergencyConfigParser::class);
        $server=$parser->parse('trojan://user%3Asecret@[2001:db8::1]:443?sni=cert.test#Old name','New');
        $this->assertSame('user:secret',$server['password']);
        $this->assertSame('2001:db8::1',$server['host']);
        $server=$parser->parse('trojan://user:secret@host.test:443#Old','New');
        $this->assertSame('user:secret',$server['password']);
        $this->postJson($this->api,['name'=>['invalid'],'config_uri'=>'vless://user@host.test:443','show'=>true])->assertUnprocessable();
        $this->postJson($this->api,['name'=>'Invalid','config_uri'=>'trojan://user%0Asecret@host.test:443','show'=>true])->assertUnprocessable();
    }

    public function test_partner_and_guest_cannot_manage_emergency_configs(): void
    {
        $this->admin->forceFill(['role'=>2])->save();$this->app['auth']->forgetGuards();
        $this->getJson($this->api)->assertForbidden();
        $this->postJson($this->api,['name'=>'No','config_uri'=>'trojan://secret@host.test:443','show'=>true])->assertForbidden();
        $this->flushHeaders();$this->app['auth']->forgetGuards();
        $this->getJson($this->api)->assertUnauthorized();
    }

    public function test_installation_without_the_new_table_still_serves_normal_subscriptions(): void
    {
        Schema::drop('speedbox_emergency_configs');
        $this->assertCount(1,$this->links());
    }

    #[DataProvider('proxyLinks')]
    public function test_parser_preserves_original_uri_settings_and_changes_only_the_label(string $uri,string $type): void
    {
        $parsed=app(EmergencyConfigParser::class)->parse($uri,'نام جدید');
        $this->assertSame($type,$parsed['type']);
        $this->assertSame('نام جدید',$parsed['name']);
        $this->assertSame(443,$parsed['port']);
        if ($type==='vmess') $this->assertSame('نام جدید',json_decode(base64_decode(substr($parsed['_emergency_uri'],8)),true)['ps']);
        else $this->assertSame(explode('#',$uri,2)[0].'#'.rawurlencode('نام جدید'),$parsed['_emergency_uri']);
    }

    public static function proxyLinks(): array
    {
        return [
            ['vless://uuid@host.test:443?security=tls&type=ws&path=%2Fa&custom=keep#old','vless'],
            ['trojan://pass%3Aword@host.test:443?sni=cert.test#old','trojan'],
            ['ss://'.base64_encode('chacha20-ietf-poly1305:password').'@host.test:443#old','shadowsocks'],
            ['ss://'.base64_encode('aes-256-gcm:password@host.test:443').'#old','shadowsocks'],
            ['hy2://secret@host.test:443?sni=cert.test&obfs=salamander&obfs-password=test#old','hysteria'],
            ['tuic://uuid:password@host.test:443?sni=cert.test#old','tuic'],
            ['anytls://secret@host.test:443?sni=cert.test#old','anytls'],
            ['socks://user:password@host.test:443#old','socks'],
            ['http://user:password@host.test:443#old','http'],
            ['vmess://'.base64_encode(json_encode(['v'=>'2','ps'=>'Old','add'=>'host.test','port'=>'443','id'=>'uuid','aid'=>'0','net'=>'ws','path'=>'/x','tls'=>'tls'])),'vmess'],
        ];
    }
}
