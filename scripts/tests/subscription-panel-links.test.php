<?php

// Existing fixtures only boot an isolated Laravel application and SQLite :memory:.
require __DIR__.'/subscription-link-consumers.test.php';
$checks = 0;

use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\Api\PartnerAccountReadController;
use App\Services\RuntimeSetting;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

if (!class_exists('DB')) class_alias(DB::class, 'DB');

Schema::table('v2_user', function ($t) {
    foreach (['t', 'speed_limit', 'account_tariff_group_id', 'reset_count', 'last_reset_at',
        'remind_expire', 'remind_traffic'] as $column) $t->bigInteger($column)->nullable();
    foreach (['remarks', 'account_plan_name', 'account_group_type', 'account_plan_key'] as $column) $t->string($column)->nullable();
});
DB::table('v2_user')->insert([
    'id'=>2, 'token'=>str_repeat('b',32), 'uuid'=>'22222222-2222-4222-8222-222222222222',
    'email'=>'account@owner.com', 'created_at'=>time(), 'updated_at'=>time(),
]);
$admin = (object) ['id'=>1, 'role'=>1, 'code'=>'admin'];
$partner = (object) ['id'=>2, 'role'=>2, 'code'=>'owner'];
$readRequest = function (object $actor, string $email = 'account@owner.com') use ($app) {
    $r = Request::create('http://panel.example/api/users/by-email', 'GET', ['email'=>$email]);
    $app->instance('request', $r);
    $r->setUserResolver(fn () => $actor);
    return $r;
};
$lookup = new PanelController;
$details = new PartnerAccountReadController;
$urlToken = str_repeat('b',32);
foreach ([
    ['url'=>'', 'path'=>'', 'https'=>false, 'expected'=>['http://panel.example/s/'.$urlToken]],
    ['url'=>' http://sub.example/base/// ', 'path'=>'///custom/sub///', 'https'=>true,
        'expected'=>['https://sub.example/base/custom/sub/'.$urlToken]],
    ['url'=>'https://node[2-2].example', 'path'=>'s', 'https'=>false,
        'expected'=>['https://node2.example/s/'.$urlToken]],
    ['url'=>' https://one.example , , https://two.example/base// , ', 'path'=>'s', 'https'=>true,
        'expected'=>['https://one.example/s/'.$urlToken, 'https://two.example/base/s/'.$urlToken]],
] as $case) {
    RuntimeSetting::set('subscribe_url', $case['url']);
    RuntimeSetting::set('subscribe_path', $case['path']);
    RuntimeSetting::set('force_https', $case['https']);
    foreach ([$admin, $partner] as $actor) {
        $r = $readRequest($actor);
        $byEmail = $lookup->findByEmail($r)->getData(true)['data'][0];
        $byId = $details->details($r, 2)->getData(true)['data'];
        foreach ([$byEmail, $byId] as $item) {
            $check($item['token'] === $urlToken, 'Read keeps the actual account token');
            $check(in_array($item['subscribe_url'], $case['expected'], true), 'Authorized read uses Helper domain/path/HTTPS selection');
        }
    }
}
RuntimeSetting::set('subscribe_url', 'http://sub.example');
RuntimeSetting::set('subscribe_path', 'custom/sub');
RuntimeSetting::set('force_https', true);
$hookInputs = [];
HookManager::registerFilter('subscribe.url', function ($url) use (&$hookInputs) {
    $hookInputs[] = $url;
    return 'https://gateway.example/import?url='.rawurlencode($url).'&flag=shadowrocket#profile';
});
$expectedInput = 'https://sub.example/custom/sub/'.$urlToken;
$expectedOutput = 'https://gateway.example/import?url='.rawurlencode($expectedInput).'&flag=shadowrocket#profile';
foreach ([$admin, $partner] as $actor) {
    $r = $readRequest($actor);
    $before = count($hookInputs);
    $byEmail = $lookup->findByEmail($r)->getData(true)['data'][0];
    $byId = $details->details($r, 2)->getData(true)['data'];
    $check($byEmail['subscribe_url'] === $expectedOutput, 'By-email preserves the entire hook output');
    $check($byId['subscribe_url'] === $expectedOutput, 'Details preserves the entire hook output after renewal/topup');
    $check(array_slice($hookInputs, $before) === [$expectedInput, $expectedInput], 'Each read hooks exactly once after inserting the real token');
}
$before = count($hookInputs);
$check($lookup->findByEmail($readRequest($partner, 'user@example.com'))->getData(true)['data'] === [], 'Partner cannot read another account link');
$check($lookup->findByEmail($readRequest($partner, 'missing@owner.com'))->getData(true)['data'] === [], 'Missing account has no URL');
foreach ([$partner, (object) ['id'=>3, 'role'=>2, 'code'=>'']] as $actor) {
    try {
        $details->details($readRequest($actor), $actor === $partner ? 1 : 2);
        throw new RuntimeException('Unauthorized details accepted');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $check(in_array($e->getStatusCode(), [403,404], true), 'Details enforces existing ownership');
    }
}
$check(count($hookInputs) === $before, 'Denied/missing reads never send a token to the hook');
DB::table('v2_user')->where('id',2)->update(['token'=>'']);
$r = $readRequest($admin);
$check($lookup->findByEmail($r)->getData(true)['data'] === [], 'By-email does not return a tokenless account link');
$check($details->details($r, 2)->getData(true)['data']['subscribe_url'] === '', 'Details returns an empty URL for an empty token');
$check(count($hookInputs) === $before, 'Empty token never invokes a base URL hook');
HookManager::reset();
echo "SUBSCRIPTION PANEL LINKS OK: {$checks} checks passed; SQLite :memory: only.\n";
