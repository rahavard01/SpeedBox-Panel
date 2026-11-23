<?php
// Standalone, isolated contract checks. No Laravel boot, network, or live database.
namespace Illuminate\Support\Facades {
    final class DB {
        public static int $credit = 1000;
        public static array $accounts = [];
        public static int $transactions = 0;
        public static array $snapshots = [];
        public static array $plans = [];
        public static function table($name) { return new \BotQuery($name); }
        public static function transaction($callback) { return $callback(); }
        public static function beginTransaction() { self::$snapshots[] = [self::$credit, self::$accounts, self::$transactions]; }
        public static function commit() { array_pop(self::$snapshots); }
        public static function rollBack() { [self::$credit, self::$accounts, self::$transactions] = array_pop(self::$snapshots); }
    }
    final class Http {
        public static array $calls = [];
        public static ?string $fail = null;
        public static function timeout($seconds) { return new \BotHttpRequest; }
    }
}
namespace App\Models {
    class User {
        public int $id = 1, $role = 2;
        public string $partner_plan_type = 'both', $email = 'partner@example.test', $name = 'Partner', $password = 'valid';
        public ?string $telegram_user_id = null;
        public bool $partner_bot_has_logged_in = false;
        public static $current;
        public static function find($id) { return self::$current; }
        public static function where(...$args) {
            return new class {
                public function where(...$args) { return $this; }
                public function first() { return null; }
            };
        }
        public function save() {}
    }
    class PanelBotSession {
        public string $state = 'logged_in';
        public ?int $panel_user_id = 1;
        public ?array $payload = [];
        public $last_activity;
        public $temp_username;
        public function save() {}
        public function update($values) { foreach ($values as $key=>$value) $this->$key=$value; }
    }
    class PanelPlan { public static function where(...$args) { return new \BotQuery('panel_plan'); } }
    class WireGuardInstance { public static $instance; public static function find($id) { return self::$instance; } }
}
namespace App\Http\Controllers { class Controller {} }
namespace App\Support {
    final class JalaliDateFormatter {
        public static function forTelegram(...$args) { return '12:00 01-01-1405'; }
        public static function persianDigits($text) { return strtr($text, ['1'=>'۱', '2'=>'۲']); }
    }
}
namespace Endroid\QrCode\Writer { class PngWriter {} }
namespace Endroid\QrCode\Builder {
    class Builder {
        public static function create() { return new self; }
        public function __call($name, $args) { return $this; }
        public function saveToFile($path) { file_put_contents($path, 'fake-png'); }
    }
}
namespace App\Services {
    function extension_loaded($extension) { return $extension === 'sodium' || \extension_loaded($extension); }
    class WireGuardService {
        public function profiles($account) { return [(object)['id'=>1], (object)['id'=>2]]; }
        public function profileNumber($peer) { return $peer->id; }
        public function config($account, $peer) { return '[Interface] private-test-data ' . $peer->id; }
        public function filename($account, $peer) { return 'account-' . $peer->id . '.conf'; }
    }
    class PartnerTariffAccessService {
        public function canCreateType(...$args) { return true; }
        public function canSeeTariffGroup(...$args) { return true; }
    }
    class PanelPricingService {
        public function ensureBalanceAndCharge($id, $key, $quantity, $group) {
            $cost = 100 * $quantity;
            if (\Illuminate\Support\Facades\DB::$credit < $cost) return ['ok'=>false, 'reason'=>'INSUFFICIENT_CREDIT'];
            \Illuminate\Support\Facades\DB::$credit -= $cost;
            return ['ok'=>true, 'transaction_id'=>++\Illuminate\Support\Facades\DB::$transactions];
        }
    }
    class TransactionLogger { public function finalize(...$args) {} }
    class V2UserCreator {
        public static int $made = 2;
        public function createUsers(...$args) {
            $created = [];
            for ($i=1; $i<=self::$made; $i++) $created[] = ['id'=>$i, 'email'=>'account'.$i];
            \Illuminate\Support\Facades\DB::$accounts = $created;
            return ['created'=>$created, 'errors'=>self::$made === 2 ? [] : ['one account failed']];
        }
    }
    class AccountTariffResolver { public function name(object $account): string { return (string) ($account->account_plan_name ?? ''); } }
    class SubscriptionValidity { public static function hasExpiry($at) { return $at !== null && $at > 0; } }
    class RuntimeSetting {
        public static int $renewMode = 2;
        public static function get($key, $default) { return $key === 'renew_order_event_id' ? self::$renewMode : $default; }
    }
    class SubscriptionReservationService {
        const RESERVE_KEEP = 2;
        const RESERVE_RESET = 3;
        public function periodic(object $account): bool { return $account->plan_id === 10; }
        public function ended(object $account): bool { return false; }
    }
}
namespace {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
    function app($name) { return new $name; }
    function now() { return new class { public function subMinutes($n) { return $this; } }; }
    function check($condition, $message) { if (!$condition) throw new \RuntimeException($message); }
    class BotQuery {
        public function __construct(public string $table) {}
        public function __call($name, $args) { return $this; }
        public function exists() { return true; }
        public function first() {
            if ($this->table === 'panel_plan') return (object)['v2_plan_id'=>1];
            return (object)['id'=>1, 'name'=>'VIP', 'group_type'=>'time', 'enable'=>1];
        }
        public function find($id) { return \Illuminate\Support\Facades\DB::$plans[$id] ?? null; }
        public function pluck($column, $key = null) {
            return $this->table === 'panel_plan' && $column === 'v2_plan_id' && $key === 'plan_key'
                ? ['1m' => 11, '3m' => 10] : $this;
        }
        public function update($values) { return 1; }
    }
    class BotHttpRequest {
        public function attach(...$args) { return $this; }
        public function post($url, $payload) {
            $method = basename($url);
            \Illuminate\Support\Facades\Http::$calls[] = [$method, $payload];
            if (\Illuminate\Support\Facades\Http::$fail === $method) throw new \RuntimeException('simulated delivery failure');
            return new class {
                public function throw() { return $this; }
                public function json($key = null) { return $key === 'ok' ? true : ['ok'=>true, 'result'=>['message_id'=>1]]; }
            };
        }
    }
    $root = dirname(__DIR__, 2);
    require $root . '/app/Services/SubscriptionServiceType.php';
    require $root . '/app/Services/PurchasePlanDuration.php';
    require $root . '/app/Services/BotAccountMessages.php';
    require $root . '/app/Services/UserBot/UserBotTelegram.php';
    require $root . '/app/Services/UserBot/UserBotAccountResolver.php';
    require $root . '/app/Services/PanelAccountActionService.php';
    require $root . '/app/Http/Controllers/Api/TelegramWebhookController.php';

    $account = (object)['id'=>1, 'email'=>'test@owner.com', 'service_type'=>'wireguard', 'expired_at'=>2000000000];
    $success = \App\Services\BotAccountMessages::created($account, 'https://example.invalid/sub');
    check(substr_count($success, 'برنامه رسمی') === 1 && !str_contains($success, 'دستگاه'), 'single purchase instruction');
    $bot = new \App\Services\UserBot\UserBotTelegram('fake-token');
    $bot->sendMessage('1', $success);
    $bot->sendWireGuardProfiles('1', $account);
    $calls = \Illuminate\Support\Facades\Http::$calls;
    check(array_column($calls, 0) === ['sendMessage','sendMessage','sendPhoto','sendDocument','sendMessage','sendPhoto','sendDocument'], 'two-device message sequence');
    check($calls[1][1]['text'] === 'دستگاه ۱' && $calls[4][1]['text'] === 'دستگاه ۲', 'device headings');
    foreach ([2,3,5,6] as $i) check(empty($calls[$i][1]['caption']), 'no attachment captions');
    check(!str_contains(json_encode($calls[1]), 'private-test-data'), 'no raw config in headings');
    \Illuminate\Support\Facades\Http::$fail = 'sendPhoto';
    $failed = false;
    try { $bot->sendWireGuardProfiles('1', $account); } catch (\RuntimeException $e) { $failed = true; }
    check($failed, 'QR failure must not be reported as successful delivery');
    \Illuminate\Support\Facades\Http::$fail = null;

    $snapshot = ['email'=>$account->email,'account_plan_name'=>'VIP','transfer_enable'=>10737418240,'u'=>0,'d'=>0,'expired_at'=>null,'service_type'=>'wireguard'];
    $info = (new \App\Services\UserBot\UserBotAccountResolver)->accountInfoText($snapshot, 'https://example.invalid/sub');
    check(strpos($info, 'سرویس:') < strpos($info, 'طرح فعال:'), 'service before plan');
    check(substr_count($info, 'برنامه رسمی') === 1, 'one info instruction');
    $snapshot['service_type'] = 'v2ray';
    $info = (new \App\Services\UserBot\UserBotAccountResolver)->accountInfoText($snapshot, 'https://example.invalid/sub');
    check(str_contains($info, 'V2Ray') && !str_contains($info, 'برنامه رسمی'), 'V2Ray service label');
    $reserve = ['reserved'=>true,'reservation'=>['title'=>'VIP','traffic_label'=>'60 GB','activation_label'=>'پس از پایان اعتبار طرح فعلی، طرح رزرو فعال خواهد شد.']];
    $text = \App\Services\BotAccountMessages::renewal($account, $reserve, 'VIP');
    check(str_contains($text, 'طرح رزرو شما') && str_contains($text, $reserve['reservation']['activation_label']), 'reservation output');
    check(!str_contains($text, 'هزینه پرداخت شده') && !str_contains($text, 'با موفقیت تمدید شد'), 'reservation is not immediate renewal');
    check(str_contains(\App\Services\BotAccountMessages::renewal($account, [], 'VIP [#12]'), 'با موفقیت تمدید شد'), 'immediate renewal');
    check(!str_contains(\App\Services\BotAccountMessages::renewal($account, [], 'VIP [#12]'), '[#'), 'selection IDs stay out of result titles');

    class BotPartnerProbe extends \App\Http\Controllers\Api\TelegramWebhookController {
        public array $groups = [];
        protected function enabledPlanKeysForGroup(int $id, array $keys, ?object $account = null): array {
            return ['1m', '3m'];
        }
        protected function availableTariffGroupsForBot(\App\Models\User $user, string $action, ?string $filter=null, ?string $service=null, ?object $account=null): array {
            return $this->groups;
        }
        public function keyboard($session) { return $this->buildTariffGroupKeyboard(new \App\Models\User, 'buy', session: $session); }
        public function pick($text, $session=null, $action='buy') { return $this->detectTariffGroupFromText($text, new \App\Models\User, $action, session: $session); }
    }
    $probe = new BotPartnerProbe;
    $probe->groups = [['id'=>10,'name'=>'VIP','service_type'=>'v2ray','type'=>'time']];
    $selectionSession = new \App\Models\PanelBotSession;
    $selectionSession->payload = ['action'=>'buy_account','service_type'=>'v2ray'];
    $keyboard = $probe->keyboard($selectionSession);
    check($keyboard['keyboard'][0][0]['text'] === '📁 VIP', 'unique tariff label has no database identifier');
    check($selectionSession->payload['action']==='buy_account' && $selectionSession->payload['service_type']==='v2ray', 'selection map preserves purchase state');
    check($probe->pick('📁 VIP', $selectionSession)['id']===10, 'clean label resolves the stored tariff ID');
    $probe->groups[0]['name']='VIP ECO';
    check($probe->pick('📁 VIP', $selectionSession)['id']===10, 'rename after keyboard delivery keeps the selected identity');
    $probe->groups = [['id'=>10,'name'=>'VIP','service_type'=>'v2ray','type'=>'time'],['id'=>11,'name'=>'VIP','service_type'=>'v2ray','type'=>'time']];
    $keyboard = $probe->keyboard($selectionSession);
    $labels = array_map(fn($row)=>$row[0]['text'], array_slice($keyboard['keyboard'],0,-1));
    check(count(array_unique($labels))===2 && !str_contains(implode(' ', $labels),'[#'), 'same-name buttons are distinct without database IDs');
    check($probe->pick($labels[1], $selectionSession)['id']===11, 'duplicate label selects the intended tariff');
    $probe->groups = array_reverse($probe->groups);
    check($probe->pick($labels[1], $selectionSession)['id']===11, 'reordering does not remap a displayed selection');
    $probe->groups = [$probe->groups[0]];
    check($probe->pick($labels[0], $selectionSession)===null, 'removed or inaccessible tariff cannot select a remaining duplicate');
    check($probe->pick($labels[1], $selectionSession, 'renew')===null, 'purchase labels cannot be reused for renewal');
    $probe->groups = [];
    check($probe->pick($labels[1], $selectionSession)===null, 'unavailable service is checked again at selection');
    $probe->groups = [['id'=>10,'name'=>'VIP'],['id'=>11,'name'=>'VIP']];
    check($probe->pick('📁 VIP [#11]')['id']===11 && $probe->pick('📁 VIP')===null, 'pre-update keyboard works and ambiguous text is rejected');
    check($probe->pick('📁 VIP [#11]', $selectionSession)===null, 'new selection map does not accept unlisted button text');
    $probe->groups = [
        ['id'=>10,'name'=>'VIP','type'=>'time','service_type'=>'v2ray'],
        ['id'=>11,'name'=>'VIP','type'=>'volume','service_type'=>'v2ray'],
        ['id'=>12,'name'=>'VIP','type'=>'time','service_type'=>'wireguard'],
        ['id'=>13,'name'=>'VIP — V2Ray، زمانی','type'=>'time','service_type'=>'v2ray'],
    ];
    $keyboard=$probe->keyboard($selectionSession);
    $labels=array_map(fn($row)=>$row[0]['text'],array_slice($keyboard['keyboard'],0,-1));
    check(count(array_unique($labels))===4, 'service/type qualifiers and literal-name collisions stay unambiguous');
    foreach($labels as $index=>$label) check($probe->pick($label,$selectionSession)['id']===$probe->groups[$index]['id'], 'every displayed button resolves its own ID');
    echo "PASS: clean tariff labels, duplicate names, stable session IDs, live eligibility and legacy keyboards.\n";

    $instance = (object)['enabled'=>true,'status'=>'ready','last_synced_at'=>new class { public bool $stale=false; public function lt($value) { return $this->stale; } }];
    \App\Models\WireGuardInstance::$instance = $instance;
    $plan = (object)['service_type'=>'wireguard','wireguard_instance_id'=>1];
    check(\App\Services\SubscriptionServiceType::available($plan), 'ready service');
    $instance->enabled=false; check(!\App\Services\SubscriptionServiceType::available($plan), 'disabled service');
    $instance->enabled=true; $instance->last_synced_at->stale=true;
    check(!\App\Services\SubscriptionServiceType::available($plan), 'stale service');
    check(\App\Services\SubscriptionServiceType::available((object)['service_type'=>'v2ray']), 'V2Ray does not depend on WireGuard readiness');
    \Illuminate\Support\Facades\DB::$plans[5] = (object)['service_type'=>'v2ray'];
    $blocked = false;
    try { \App\Services\SubscriptionServiceType::same($account, 5); } catch (\DomainException $e) { $blocked = true; }
    check($blocked, 'cross-service renewal blocked');
    $account->wireguard_instance_id=1;
    \Illuminate\Support\Facades\DB::$plans[6] = (object)['service_type'=>'wireguard','wireguard_instance_id'=>2];
    $blocked = false;
    try { \App\Services\SubscriptionServiceType::same($account, 6); } catch (\DomainException $e) { $blocked = true; }
    check($blocked, 'cross-instance renewal blocked');

    $owner = (object)['id'=>1,'role'=>2,'partner_plan_type'=>'time','code'=>'owner'];
    $service = new \App\Services\PanelAccountActionService;
    \App\Services\V2UserCreator::$made=1;
    $result=$service->createAccountForPanelUser($owner, '1m', 2, 1);
    check(!$result['ok'] && \Illuminate\Support\Facades\DB::$credit===1000 && !\Illuminate\Support\Facades\DB::$accounts && \Illuminate\Support\Facades\DB::$transactions===0, 'partial bulk creation rolls back charge and accounts');
    \App\Services\V2UserCreator::$made=2;
    $result=$service->createAccountForPanelUser($owner, '1m', 2, 1);
    check($result['ok'] && \Illuminate\Support\Facades\DB::$credit===800 && count(\Illuminate\Support\Facades\DB::$accounts)===2, 'complete bulk creation charges once');
    \Illuminate\Support\Facades\DB::$credit=100;
    $result=$service->createAccountForPanelUser($owner, '1m', 2, 1);
    check(!$result['ok'] && \Illuminate\Support\Facades\DB::$credit===100, 'insufficient credit is not deducted');
    echo "PASS: bot delivery, templates, tariff identity, readiness, and atomic bulk purchase.\n";
    class BotFlowAudit extends \App\Http\Controllers\Api\TelegramWebhookController {
        public $session;
        public array $groups=[];
        public array $messages=[];
        public array $planKeys = ['1m', '3m'];
        protected function enabledPlanKeysForGroup(int $id, array $keys, ?object $account = null): array {
            return $this->planKeys;
        }
        public function __construct() { $this->session=new \App\Models\PanelBotSession; }
        protected function getOrCreateSession(int $chatId): \App\Models\PanelBotSession { return $this->session; }
        protected function availableTariffGroupsForBot(\App\Models\User $user, string $action, ?string $filter=null, ?string $service=null, ?object $account=null): array {
            return array_values(array_filter($this->groups, fn($g)=>!$service || $g['service_type']===$service));
        }
        protected function sendMsgKb(string $id, string $text, array $kb): void { $this->messages[]=[$text,$kb]; }
        protected function buildBuyPlanKeyboard(\App\Models\User $user, ?int $id=null): array { return ['keyboard'=>[[['text'=>'یک‌ماهه']]]]; }
        protected function sendMsg(string $id, string $text): void { $this->messages[]=[$text, []]; }
        protected function findPanelUserByUsername(string $email): ?\App\Models\User { return \App\Models\User::find(1); }
        protected function checkPasswordFlexible(string $input, string $stored): bool { return $input === $stored; }
        protected function findV2UserByEmailSafe(string $email): ?object { return (object) ['id' => 7, 'plan_id' => 10]; }
        public function renewalKeyboard() {
            return $this->buildTariffGroupKeyboard(new \App\Models\User, 'renew', account: (object) ['id' => 7], session: $this->session);
        }
        public function send($text) { $this->handleLoginMessage(['chat'=>['id'=>1],'text'=>$text]); }
    }
    $partner = new \App\Models\User;
    $partner->role=2; $partner->partner_plan_type='both';
    \App\Models\User::$current=$partner;
    $v2=['id'=>1,'name'=>'VIP','type'=>'time','service_type'=>'v2ray'];
    $wg=['id'=>2,'name'=>'VIP','type'=>'time','service_type'=>'wireguard'];
    foreach ([['1m'], ['1m', '3m']] as $keys) {
        $flow = new BotFlowAudit;
        $flow->groups = [$v2 + ['renewal_keys' => $keys]];
        $flow->planKeys = $keys;
        $flow->session->state = 'renew_tariff_group';
        $flow->session->payload = ['target_email' => 'fixture@example.test'];
        $label = $flow->renewalKeyboard()['keyboard'][0][0]['text'];
        check($label === (count($keys) === 1 ? '📁 VIP / یک‌ماهه' : '📁 VIP'), 'renewal group label reflects eligible durations');
        $flow->send($label);
        check($flow->session->state === 'renew_plan', 'representative still explicitly selects the renewal duration');
        $buttons = array_column(array_column(end($flow->messages)[1]['keyboard'], 0), 'text');
        check(count($buttons) === count($keys) + 1 && $buttons[0] === '📅 تمدید یک‌ماهه', 'renewal offers eligible durations and back');
    }
    \App\Services\RuntimeSetting::$renewMode = 0;
    $flow = new BotFlowAudit;
    $flow->groups = [$v2 + ['renewal_keys' => ['3m']]];
    $flow->session->state = 'renew_tariff_group';
    $flow->session->payload = ['target_email' => 'fixture@example.test'];
    $label = $flow->renewalKeyboard()['keyboard'][0][0]['text'];
    $flow->send($label);
    $buttons = array_column(array_column(end($flow->messages)[1]['keyboard'], 0), 'text');
    check($label === '📁 VIP / سه‌ماهه' && $buttons === ['📅 تمدید سه‌ماهه', '↩️ بازگشت به منو'],
        'immediate renewal keeps only the compatible reset method');
    \App\Services\RuntimeSetting::$renewMode = 2;
    foreach ([['V2Ray only',[$v2],'buy_tariff_group'],['WireGuard only',[$wg],'buy_tariff_group'],['both',[$v2,$wg],'buy_service'],['none',[],'logged_in']] as [$label,$groups,$expected]) {
        $flow=new BotFlowAudit; $flow->groups=$groups;
        $flow->send('🛒 خرید اکانت جدید');
        check($flow->session->state===$expected, $label.' initial state');
        if(count($groups)===1)check(!str_contains($flow->messages[0][0],'سرویس مورد نظر'),$label.' skips service selection');
        if(count($groups)===2){
            $flow->send('🛡 WireGuard');
            check($flow->session->state==='buy_tariff_group' && $flow->session->payload['service_type']==='wireguard','service selection');
            $flow->send('📁 VIP');
            check($flow->session->state==='buy_account' && $flow->session->payload['tariff_group_id']===2,'correct same-name group continues to duration');
        }
        echo 'PASS flow: '.$label."\n";
    }
    $flow=new BotFlowAudit;$flow->groups=[$v2,$wg];$flow->send('🛒 خرید اکانت جدید');
    $flow->groups=[$v2];$flow->send('🛡 WireGuard');
    check($flow->session->state==='logged_in' && $flow->session->payload===null, 'unavailable service clears purchase state');
    $flow->send('🛒 خرید اکانت جدید');
    check($flow->session->state==='buy_tariff_group' && $flow->session->payload['service_type']==='v2ray', 'new purchase works after unavailable service');
    $flow->send('↩️ بازگشت به منو');
    check($flow->session->state==='logged_in', 'explicit Back recovers service state');
    echo "PASS: unavailable-service recovery clears state and permits a new purchase.\n";

    foreach ([
        [['6m'], '📁 VIP / شش‌ماهه', 'buy_count'],
        [['1m', '12m'], '📁 VIP', 'buy_account'],
        [['test', '1m'], '📁 VIP', 'buy_account'],
    ] as [$keys, $label, $state]) {
        $flow = new BotFlowAudit;
        $flow->groups = [$v2];
        $flow->planKeys = $keys;
        $flow->send('🛒 خرید اکانت جدید');
        check($flow->messages[0][1]['keyboard'][0][0]['text'] === $label, 'the initial purchase label matches available choices');
        $flow->send($label);
        check($flow->session->state === $state, 'single or manual purchase continues at the expected step');
        if ($state === 'buy_count') {
            check($flow->session->payload['plan_key'] === '6m', 'single duration is set before asking for quantity');
            check(str_contains($flow->messages[1][0], 'تعداد اکانت'), 'the regular quantity prompt is sent');
        }
    }
    echo "PASS: representative purchase labels and duration selection.\n";

    $flow = new BotFlowAudit;
    $flow->session->state = 'idle';
    $flow->session->panel_user_id = null;
    $flow->send('/start');
    check(!$partner->partner_bot_has_logged_in, 'Starting the bot is not a successful login');
    $flow->send($partner->email);
    $flow->send('wrong');
    check(!$partner->partner_bot_has_logged_in, 'A wrong password leaves onboarding available');
    $flow->send($partner->email);
    $flow->send('valid');
    check($flow->session->state === 'logged_in' && $partner->partner_bot_has_logged_in, 'A successful login records onboarding');
    $flow->send('/logout');
    check($flow->session->state === 'idle' && $partner->telegram_user_id === null && $partner->partner_bot_has_logged_in, 'Logout clears the active session but keeps onboarding recorded');
    echo "PASS: representative first-login history survives logout.\n";

}

