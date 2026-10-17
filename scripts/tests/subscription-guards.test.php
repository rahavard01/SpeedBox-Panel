<?php

// Real Laravel controllers/exporters; SQLite :memory: only, no .env or live DB.
require __DIR__.'/subscription-panel-links.test.php';
$checks = 0;

use App\Http\Controllers\Api\Admin\EmergencyConfigController;
use App\Http\Controllers\Api\Admin\InfrastructureController;
use App\Http\Controllers\Api\SettingController;
use App\Models\EmergencyConfig;
use App\Models\ProxyServer;
use App\Protocols\ClashMeta;
use App\Protocols\SingBox;
use App\Protocols\Stash;
use App\Protocols\XrayJson;
use App\Services\EmergencyConfigParser;
use App\Services\EmergencyConfigurations;
use App\Services\NodeControlService;
use App\Services\Plugin\HookManager;
use App\Services\RuntimeSetting;
use App\Support\AbstractProtocol;
use App\Support\InfrastructureNodeRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

$expectedSource = realpath($root.'/app/Support/AbstractProtocol.php');
$loadedSource = realpath((new ReflectionClass(AbstractProtocol::class))->getFileName());
$normalizeSourcePath = static function (string $path): string {
    $path = str_replace('\\', '/', $path);
    return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
};
$check($expectedSource !== false && $loadedSource !== false
    && $normalizeSourcePath($expectedSource) === $normalizeSourcePath($loadedSource),
    'Checks run against this project source');
HookManager::reset();
$user = ['uuid'=>'11111111-1111-4111-8111-111111111111', 'u'=>0, 'd'=>0, 'transfer_enable'=>1000000, 'expired_at'=>time()+3600];
$base = ['id'=>900, 'name'=>'🇩🇪 Valid node', 'type'=>'vless', 'host'=>'node.example', 'port'=>443, 'password'=>$user['uuid'], 'tags'=>[]];
$tls = ['network'=>'tcp', 'tls'=>1, 'tls_settings'=>['server_name'=>'cert.example', 'ech'=>['enabled'=>true, 'config'=>'AQIDBA==']]];
$filtered = static function (string $class, array $server, string $client, ?string $version): array {
    $exporter = new $class($GLOBALS['user'], [$server], $client, $version);
    return (new ReflectionProperty(AbstractProtocol::class, 'servers'))->getValue($exporter);
};
foreach ([true, 1, '1'] as $flag) {
    $server = $base + ['protocol_settings'=>array_replace_recursive($tls, ['tls_settings'=>['ech'=>['enabled'=>$flag]]])];
    $check($filtered(ClashMeta::class, $server, 'meta', '1.19.8') === [], 'Meta filters every enabled ECH flag below minimum version');
    $check(count($filtered(ClashMeta::class, $server, 'meta', '1.19.9')) === 1, 'Meta keeps ECH at supported version');
    $vmess = array_replace($server, ['type'=>'vmess']);
    $check($filtered(SingBox::class, $vmess, 'sing-box', '1.4.0') === [], 'SingBox filters boolean/numeric ECH below minimum');
    $check(count($filtered(SingBox::class, $vmess, 'sing-box', '1.5.0')) === 1, 'SingBox retains supported ECH');
}
foreach ([false, 0, '0', null] as $flag) {
    $server = $base + ['protocol_settings'=>array_replace_recursive($tls, ['tls_settings'=>['ech'=>['enabled'=>$flag]]])];
    $check(count($filtered(ClashMeta::class, $server, 'meta', '1.19.8')) === 1, 'Disabled/missing ECH remains available on old Meta');
}
$server = $base + ['protocol_settings'=>$tls];
$check(count($filtered(ClashMeta::class, $server, 'meta', null)) === 1, 'Unknown client version keeps previous policy');
foreach ([null, ''] as $version) {
    $trojanReality = array_replace($base, ['type'=>'trojan', 'protocol_settings'=>['tls'=>2, 'network'=>'tcp']]);
    $vmessUpgrade = array_replace($base, ['type'=>'vmess', 'protocol_settings'=>['tls'=>1, 'network'=>'httpupgrade']]);
    $ordinary = $base + ['protocol_settings'=>['tls'=>1,'network'=>'tcp']];
    $check($filtered(Stash::class, $trojanReality, 'stash', $version) === [], 'Global Trojan Reality exclusion remains enforced without a version');
    $check($filtered(Stash::class, $vmessUpgrade, 'stash', $version) === [], 'Global VMess HTTPUpgrade exclusion remains enforced without a version');
    $check(count($filtered(Stash::class, $ordinary, 'stash', $version)) === 1, 'Unknown version still keeps otherwise supported input');
}
$hookSeen = [];
HookManager::registerFilter('protocol.servers.filtered', function ($servers) use (&$hookSeen) { $hookSeen = $servers; return $servers; });
$check($filtered(ClashMeta::class, $server, 'meta', '1.19.8') === [] && $hookSeen === [], 'Restored hook still runs after corrected version filtering');
HookManager::reset();
// Exercise the strict whitelist branch with a numeric allowed-value list.
$strict = new class($user, [$base + ['protocol_settings'=>['enabled'=>true]]], 'fixture', '1.0.0') extends AbstractProtocol {
    protected $protocolRequirements = ['fixture.vless.protocol_settings.enabled'=>['whitelist'=>[1], 'strict'=>true]];
    public function handle() { return $this->servers; }
};
$check(count($strict->handle()) === 1, 'Strict whitelist accepts true as numeric one');
$strictFalse = new ($strict::class)($user, [$base + ['protocol_settings'=>['enabled'=>false]]], 'fixture', '1.0.0');
$check($strictFalse->handle() === [], 'Strict whitelist rejects false when numeric zero is not allowed');

$parser = new EmergencyConfigParser;
$uri = 'vless://22222222-2222-4222-8222-222222222222@external.example:443?security=reality&pbk=public-key&sni=cert.example&type=';
$reality = ['tls'=>2, 'network'=>'tcp', 'reality_settings'=>['public_key'=>'public-key', 'server_name'=>'cert.example', 'short_id'=>'abcd']];
$valid = $base + ['protocol_settings'=>$reality];
$bad = array_replace_recursive($valid, ['id'=>901, 'name'=>'Invalid Reality WS', 'protocol_settings'=>['network'=>'ws']]);
$decode = static fn ($response) => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
foreach (['tcp','raw','xhttp','grpc'] as $network) {
    $external = $parser->parse($uri.$network, 'Reality '.$network);
    $check(in_array($external['protocol_settings']['network'], ['tcp','xhttp','grpc'], true), 'Importer accepts supported Reality transport');
    $native = array_replace_recursive($valid, ['protocol_settings'=>['network'=>$network]]);
    $configs = $decode((new XrayJson($user, [$native]))->handle());
    $check(count($configs) === 3, 'Valid Reality keeps ALL SMART, country SMART and individual node');
    $outbound = $configs[2]['outbounds'][0];
    $check($outbound['streamSettings']['security'] === 'reality' && $outbound['streamSettings']['network'] === $network, 'Export never silently rewrites the peer transport');
}
foreach (['ws','httpupgrade','h2','http','kcp','quic'] as $network) {
    try { $parser->parse($uri.$network, 'Invalid'); throw new RuntimeException('Invalid import accepted'); }
    catch (InvalidArgumentException $error) { $check(str_contains($error->getMessage(), 'Reality'), 'Unsupported Reality import rejected with useful error'); }
    $invalid = array_replace_recursive($bad, ['protocol_settings'=>['network'=>$network]]);
    $response = (new XrayJson($user, [$valid, $invalid]))->handle();
    $check(count($decode($response)) === 3 && !str_contains($response->getContent(), 'Invalid'), 'Bad legacy native node cannot break JSON or enter SMART');
    $check($response->headers->get('X-Smart-Skipped-Protocols') === 'vless', 'Existing skipped-protocol diagnostic reports omitted native input');
}
$tlsWs = $parser->parse(str_replace('security=reality', 'security=tls', $uri).'ws', 'TLS WS');
$check($tlsWs['protocol_settings']['tls'] === 1 && $tlsWs['protocol_settings']['network'] === 'ws', 'Ordinary TLS WS remains supported');
foreach (['top','bottom'] as $position) {
    $externalBad = $bad + ['_emergency'=>true, '_emergency_position'=>$position];
    $externalValid = $parser->parse($uri.'tcp', 'External valid') + ['_emergency_position'=>$position];
    $response = (new XrayJson($user, [$externalBad, $valid, $externalValid]))->handle();
    $configs = $decode($response);
    $check(count($configs) === 4, 'Bad emergency transport does not break valid emergency or native profiles');
    $check(($position === 'top' ? $configs[0] : $configs[3])['remarks'] === 'External valid', 'Emergency top/bottom placement stays intact');
    $check(!str_contains(json_encode(array_slice($configs, $position === 'top' ? 1 : 0, 2)), '22222222-2222'), 'Emergency credentials stay outside SMART pools');
}
$info = array_replace($bad, ['name'=>'Account information']);
$configs = $decode((new XrayJson($user, [$info, $bad, $valid]))->handle());
$check($configs[0]['remarks'] === 'Account information' && count($configs) === 4, 'Information clones use a valid replacement template');
$response = (new XrayJson($user, [$bad]))->handle();
$check($response->getStatusCode() === 200 && !str_contains(base64_decode($response->getContent()), 'security=reality'), 'All-invalid input is safely empty and never re-exported through fallback');
$tuic = $base + ['protocol_settings'=>[]];
$tuic['type'] = 'tuic'; $tuic['name'] = 'Valid TUIC';
$tuic['protocol_settings'] = ['version'=>5,'password'=>'tuic-secret','tls'=>['server_name'=>'cert.example']];
$response = (new XrayJson($user, [$bad, $tuic]))->handle();
$check(str_contains(base64_decode($response->getContent()), 'tuic://') && !str_contains(base64_decode($response->getContent()), 'security=reality'), 'Fallback preserves valid non-Xray input while removing invalid Reality');

// Validate the same API boundary used by both native node create and update.
Schema::table('v2_server', function ($t) {
    foreach (['kernel','code'] as $field) $t->string($field)->nullable();
    foreach (['machine_id','server_port'] as $field) $t->integer($field)->nullable();
});
Schema::create('v2_server_machine', function ($t) { $t->id(); $t->string('name'); });
$nodePayload = ['name'=>'Native test','type'=>'vless','kernel'=>'xray','host'=>'native.example','port'=>'443','server_port'=>10443,
    'rate'=>1,'rate_time_enable'=>false,'protocol_settings'=>$reality,'show'=>true,'enabled'=>true,'machine_id'=>null,'parent_id'=>null];
foreach (['tcp','raw','grpc','xhttp'] as $network) {
    $payload = array_replace_recursive($nodePayload, ['protocol_settings'=>['network'=>$network]]);
    $data = InfrastructureNodeRules::validate(Request::create('/api/admin/infrastructure/nodes', 'POST', $payload));
    $check($data['protocol_settings']['network'] === $network, 'Native validation accepts supported Reality without rewrite');
}
$infrastructure = new InfrastructureController(new NodeControlService);
$badPayload = array_replace_recursive($nodePayload, ['protocol_settings'=>['network'=>'ws']]);
$before = ProxyServer::count();
try { $infrastructure->storeNode(Request::create('/nodes', 'POST', $badPayload)); throw new RuntimeException('Invalid node saved'); }
catch (ValidationException $error) { $check(isset($error->errors()['protocol_settings.network']), 'Native creation rejects Reality WS before persistence'); }
$check(ProxyServer::count() === $before, 'Rejected native node creates no database row');
$oldNode = ProxyServer::create(['name'=>'Existing native','type'=>'vless','kernel'=>'xray','host'=>'existing.example','port'=>'443','protocol_settings'=>['tls'=>1,'network'=>'tcp']]);
try { $infrastructure->updateNode(Request::create('/nodes/'.$oldNode->id, 'PUT', $badPayload), $oldNode); throw new RuntimeException('Invalid update saved'); }
catch (ValidationException $error) { $check(isset($error->errors()['protocol_settings.network']), 'Native update rejects the same invalid transport'); }
$check($oldNode->fresh()->protocol_settings['tls'] !== 2, 'Rejected update leaves existing native settings intact');
$oldParent = ProxyServer::create(['name'=>'Legacy parent','type'=>'vless','kernel'=>'xray','host'=>'parent.example','port'=>'443','rate'=>1,'protocol_settings'=>$bad['protocol_settings']]);
$child = array_replace_recursive($nodePayload, ['parent_id'=>$oldParent->id, 'protocol_settings'=>['network'=>'tcp']]);
try { InfrastructureNodeRules::validate(Request::create('/nodes', 'POST', $child)); throw new RuntimeException('Bad inherited transport accepted'); }
catch (ValidationException $error) { $check(isset($error->errors()['protocol_settings.network']), 'Child validation checks effective inherited parent settings'); }
$plainWs = array_replace_recursive($nodePayload, ['protocol_settings'=>['tls'=>1,'network'=>'ws']]);
$check(InfrastructureNodeRules::validate(Request::create('/nodes', 'POST', $plainWs))['protocol_settings']['network'] === 'ws', 'Native TLS WS still validates');

$emergencyController = new EmergencyConfigController;
$before = EmergencyConfig::count();
try { $emergencyController->store(Request::create('/emergency-configs', 'POST', ['name'=>'Rejected external','config_uri'=>$uri.'ws','show'=>true]), $parser); throw new RuntimeException('Bad external saved'); }
catch (ValidationException $error) { $check(isset($error->errors()['config_uri']), 'Emergency create returns validation error on the link field'); }
$check(EmergencyConfig::count() === $before, 'Rejected import persists nothing');
$oldConfig = EmergencyConfig::create(['name'=>'Legacy invalid','protocol'=>'vless','config_uri'=>$uri.'ws','show'=>true,'sort'=>1]);
$goodConfig = EmergencyConfig::create(['name'=>'Valid import','protocol'=>'vless','config_uri'=>$uri.'tcp','show'=>true,'sort'=>2]);
try { $emergencyController->update(Request::create('/emergency-configs/'.$goodConfig->id, 'PUT', ['name'=>'Valid import','config_uri'=>$uri.'ws','show'=>true]), $goodConfig, $parser); throw new RuntimeException('Bad external update saved'); }
catch (ValidationException $error) { $check(isset($error->errors()['config_uri']), 'Emergency update also rejects invalid combination'); }
$check($goodConfig->fresh()->config_uri === $uri.'tcp', 'Rejected import update leaves saved link intact');
$logger = new class extends Psr\Log\AbstractLogger {
    public array $records = [];
    public function log($level, string|Stringable $message, array $context = []): void { $this->records[] = [$level,$message,$context]; }
};
$app->instance('log', $logger);
Illuminate\Support\Facades\Log::clearResolvedInstance('log');
// Restrict fixtures to these rows so all legacy invalid input is tested explicitly.
EmergencyConfig::whereNotIn('id', [$oldConfig->id, $goodConfig->id])->update(['show'=>false]);
foreach (['top','bottom'] as $position) {
    RuntimeSetting::set('emergency_configs_position', $position);
    $merged = (new EmergencyConfigurations)->merge([$valid]);
    $check(count($merged) === 2 && !in_array('Legacy invalid', array_column($merged,'name'), true), 'Legacy invalid emergency row is omitted without failing subscriptions');
    $check(($position === 'top' ? $merged[0] : $merged[1])['name'] === 'Valid import', 'Valid saved import preserves block placement');
}
$check(count($logger->records) === 2 && $logger->records[0][2]['id'] === $oldConfig->id, 'Invalid saved input is visible by ID in logs');
$check(!str_contains(json_encode($logger->records), '22222222-2222') && !str_contains(json_encode($logger->records), 'vless://'), 'Logs never expose proxy URI credentials');
$check($oldConfig->fresh()->config_uri === $uri.'ws', 'Export protection does not delete or rewrite old data');

// Existing link-parts callers get correct plain parts, or a clear error.
$settings = new SettingController;
HookManager::reset();
$app->instance('request', Request::create('http://legacy.example/api/settings/link-parts'));
foreach ([
    ['url'=>'','path'=>'','https'=>false,'part1'=>'http://legacy.example','part2'=>'s'],
    ['url'=>' http://sub.example/base/// ','path'=>'///custom/sub///','https'=>true,'part1'=>'https://sub.example/base','part2'=>'custom/sub'],
    ['url'=>'https://node[2-2].example','path'=>'s','https'=>false,'part1'=>'https://node2.example','part2'=>'s'],
] as $case) {
    RuntimeSetting::set('subscribe_url', $case['url']); RuntimeSetting::set('subscribe_path', $case['path']); RuntimeSetting::set('force_https', $case['https']);
    $response = $settings->linkParts(); $data = $response->getData(true);
    $check($response->getStatusCode() === 200 && $data['data']['part1'] === $case['part1'] && $data['data']['part2'] === $case['part2'], 'Plain legacy parts retain domain/path/HTTPS behavior');
    $check($data['data']['part1'].'/'.$data['data']['part2'].'/'.$user['uuid'] === App\Utils\Helper::getSubscribeUrl($user['uuid']), 'Plain part reconstruction equals complete Helper output');
}
foreach ([
    static fn ($url) => $url.'?flag=shadowrocket',
    static fn ($url) => $url.'#profile',
    static fn ($url) => 'https://gateway.example/import?url='.rawurlencode($url),
    static fn ($url) => str_replace('/s/', '/rewritten/', $url),
    static fn ($url) => $url.'?next=/s/',
    static fn ($url) => 'not-an-absolute-url',
] as $callback) {
    HookManager::reset(); HookManager::registerFilter('subscribe.url', $callback);
    $response = $settings->linkParts(); $data = $response->getData(true);
    $check($response->getStatusCode() === 422 && $data['code'] === 'subscription_url_not_splittable', 'Unsplittable hooks are rejected instead of truncated');
    $check(!isset($data['data']['part1']), 'Failed splitting never returns a misleading legacy base');
    $check(App\Utils\Helper::getSubscribeUrl($user['uuid']) === $callback('https://node2.example/s/'.$user['uuid']), 'Complete Helper output still retains the hook unchanged');
}
HookManager::reset();
echo "SUBSCRIPTION GUARDS OK: {$checks} checks passed; SQLite :memory: only.\n";
