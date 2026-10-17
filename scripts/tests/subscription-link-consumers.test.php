<?php

// Reuse the real isolated Laravel/SQLite fixtures; never boot .env or the live DB.
require __DIR__.'/subscription-fork-compatibility.test.php';
$checks = 0;

use App\Utils\Helper;
use App\Services\RuntimeSetting;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

$botBuilders = [
    'partner bot' => [(new ReflectionClass(App\Http\Controllers\Api\TelegramWebhookController::class))->newInstanceWithoutConstructor(), 'buildSubscribeUrl'],
    'user bot' => [(new ReflectionClass(App\Services\UserBot\UserBotOrderProcessor::class))->newInstanceWithoutConstructor(), 'makeSubscribeUrl'],
];
$consumerToken = str_repeat('a', 32);
$buildBot = function (array $builder, string $token) {
    return (new ReflectionMethod($builder[0], $builder[1]))->invoke($builder[0], $token);
};
$app->instance('request', Request::create('http://consumer.example/telegram/webhook'));
RuntimeSetting::set('force_https', false);
foreach ($botBuilders as $label => $builder) {
    $check($buildBot($builder, '') === '', $label.' keeps an empty token from becoming an account link');
    $check($buildBot($builder, $consumerToken) === 'http://consumer.example/s/'.$consumerToken, $label.' uses the request/default-path fallback');
}
RuntimeSetting::set('subscribe_url', ' http://subscriptions.example/base/// ');
RuntimeSetting::set('subscribe_path', '///custom/sub///');
RuntimeSetting::set('force_https', true);
foreach ($botBuilders as $label => $builder) {
    $check($buildBot($builder, $consumerToken) === 'https://subscriptions.example/base/custom/sub/'.$consumerToken, $label.' applies domain trimming, custom path and HTTPS');
}
RuntimeSetting::set('subscribe_url', 'https://node[2-2].example');
foreach ($botBuilders as $label => $builder) {
    $check($buildBot($builder, $consumerToken) === 'https://node2.example/custom/sub/'.$consumerToken, $label.' expands configured domain patterns');
}
RuntimeSetting::set('subscribe_url', ' https://one.example , , https://two.example/base// , ');
foreach ($botBuilders as $label => $builder) {
    for ($i = 0; $i < 8; $i++) {
        $check(in_array($buildBot($builder, $consumerToken), [
            'https://one.example/custom/sub/'.$consumerToken,
            'https://two.example/base/custom/sub/'.$consumerToken,
        ], true), $label.' selects one cleaned domain instead of returning a comma-separated URL');
    }
}

RuntimeSetting::set('subscribe_url', 'http://subscriptions.example');
$consumerHookInputs = [];
HookManager::registerFilter('subscribe.url', function ($url) use (&$consumerHookInputs) {
    $consumerHookInputs[] = $url;
    return $url.'?source=compatibility';
});
foreach ($botBuilders as $label => $builder) {
    $before = count($consumerHookInputs);
    $expected = 'https://subscriptions.example/custom/sub/'.$consumerToken;
    $check($buildBot($builder, $consumerToken) === $expected.'?source=compatibility', $label.' uses the complete hooked URL');
    $check(count($consumerHookInputs) === $before + 1 && end($consumerHookInputs) === $expected, $label.' invokes the URL hook once with the real token');
}

// Invoke the actual create controllers. Only account creation/pricing are stubbed:
// those operations are unrelated to this change and must not charge any wallet.
Schema::create('panel_tariff_groups', function ($t) {
    $t->id(); $t->string('name'); $t->string('group_type'); $t->boolean('enable');
    $t->boolean('is_default'); $t->integer('sort_order')->default(0);
});
Schema::create('panel_plan', function ($t) {
    $t->id(); $t->integer('tariff_group_id'); $t->integer('v2_plan_id');
    $t->string('plan_key'); $t->boolean('enable');
});
Schema::create('panel_users', function ($t) {
    $t->id(); $t->string('email'); $t->string('code'); $t->integer('role');
    $t->string('partner_plan_type'); $t->integer('credit')->default(1000);
});
DB::table('panel_tariff_groups')->insert(['id'=>1, 'name'=>'Fixture time plan', 'group_type'=>'time', 'enable'=>1, 'is_default'=>1]);
DB::table('panel_plan')->insert(['id'=>1, 'tariff_group_id'=>1, 'v2_plan_id'=>1, 'plan_key'=>'1m', 'enable'=>1]);
DB::table('panel_users')->insert(['id'=>1, 'email'=>'owner@panel.example', 'code'=>'owner', 'role'=>2, 'partner_plan_type'=>'time']);
$owner = DB::table('panel_users')->where('id', 1)->first();
$creator = new class extends App\Services\V2UserCreator {
    public function createUsers(string $panelCode, int $v2PlanId, int $count, string $planKey, bool $noExpire = false,
        ?int $customTrafficGb = null, ?array $accountSnapshot = null, ?int $panelOwnerId = null): array
    {
        return ['created'=>[
            ['id'=>501, 'email'=>'first@owner.com', 'token'=>str_repeat('a',32)],
            ['id'=>502, 'email'=>'second@owner.com', 'user'=>['token'=>str_repeat('b',32)]],
        ], 'errors'=>[]];
    }
};
$pricing = new class extends App\Services\PanelPricingService {
    public function ensureBalanceAndCharge(int $panelUserId, string $planKey, int $quantity = 1,
        ?int $tariffGroupId = null, ?int $subscriptionUserId = null): array
    {
        return ['ok'=>true, 'unit_price'=>10, 'total_price'=>20, 'transaction_id'=>null];
    }
};
$createRequest = function () use ($app, $owner, $check) {
    $r = Request::create('http://consumer.example/api/accounts', 'POST', [
        'panel_code'=>'owner', 'plan_key'=>'1m', 'count'=>2, 'tariff_group_id'=>1, 'download_excel'=>false,
    ]);
    $app->instance('request', $r);
    // AuthServiceProvider resets the user resolver when request is rebound.
    $r->setUserResolver(fn () => $owner);
    $check($r->user() === $owner, 'Fixture request retains its owner after container rebinding');
    return $r;
};
foreach (['admin', 'partner'] as $label) {
    $before = count($consumerHookInputs);
    $response = $label === 'admin'
        ? (new App\Http\Controllers\Api\Admin\UserAdminController)->create($createRequest(), $creator)
        : (new App\Http\Controllers\Api\V2UserController)->create($createRequest(), $creator, $pricing);
    $payload = $response->getData(true);
    $check($response->getStatusCode() === 200 && count($payload['created'] ?? []) === 2,
        $label.' create returns both fixture accounts; HTTP '.$response->getStatusCode().' payload='.json_encode($payload, JSON_UNESCAPED_UNICODE));
    foreach ($payload['created'] as $index => $item) {
        $token = str_repeat($index === 0 ? 'a' : 'b', 32);
        $check($item['subscribe_url'] === 'https://subscriptions.example/custom/sub/'.$token.'?source=compatibility', $label.' create hooks each account after appending its own token');
    }
    $check(array_slice($consumerHookInputs, $before) === [
        'https://subscriptions.example/custom/sub/'.str_repeat('a',32),
        'https://subscriptions.example/custom/sub/'.str_repeat('b',32),
    ], $label.' create never invokes the hook with an empty-token base URL');
}
$check(DB::table('panel_users')->where('id',1)->value('credit') === 1000, 'The isolated URL fixture never charges a real or fixture wallet');

// Real resolver lookups include query/fragment links and preserve ownership checks.
$resolver = new App\Services\UserBot\UserBotAccountResolver;
$adminSetting = new App\Models\UserBotSetting(['owner_type'=>'admin', 'owner_id'=>1]);
foreach (['?flag=shadowrocket', '?raw=1#profile', '#profile', '?token%5B%5D=invalid'] as $suffix) {
    $link = 'https://subscriptions.example/custom/sub/'.$consumerToken.$suffix;
    $resolved = $resolver->resolve($adminSetting, $link);
    $check(($resolved['token'] ?? null) === $consumerToken && ($resolved['snapshot']['id'] ?? null) === 1, 'Resolver extracts the path token with suffix '.$suffix);
    $check($resolver->findCurrent($adminSetting, $link)['token'] === $consumerToken, 'Stored session link remains readable with suffix '.$suffix);
}
$check($resolver->resolve($adminSetting, 'https://subscriptions.example/api/v1/client/subscribe?token='.$consumerToken)['token'] === $consumerToken, 'Legacy query-token subscription URLs remain supported');
$partnerSetting = new App\Models\UserBotSetting(['owner_type'=>'partner', 'owner_id'=>1]);
$check($resolver->resolve($partnerSetting, 'https://subscriptions.example/s/'.$consumerToken.'?flag=shadowrocket') === null, 'Query-bearing URL does not bypass partner account ownership');
$check($resolver->resolve($adminSetting, 'https://subscriptions.example/api/subscribe?token%5B%5D=invalid') === null, 'Array query token without a valid path token is rejected cleanly');
HookManager::reset();

echo "SUBSCRIPTION LINK CONSUMERS OK: {$checks} checks passed; SQLite :memory: only.\n";
