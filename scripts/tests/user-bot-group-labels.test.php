<?php
// Isolated user-bot group selection scenarios; no real database or Telegram calls.
namespace Illuminate\Http {
    class Request {
        public function __construct(private array $data) {}
        public function input($key) { return $this->data[$key] ?? null; }
    }
}
namespace Illuminate\Support\Facades {
    class DB { public static function table($name) { return new \GroupQuery; } }
}
namespace App\Http\Controllers { class Controller {} }
namespace App\Models {
    class UserBotSetting {
        public static $current;
        public int $id=1, $owner_id=1;
        public bool $is_enabled=true, $force_channel_join_enabled=false, $card_payment_enabled=true, $crypto_payment_enabled=false;
        public string $bot_token='fake', $owner_telegram_chat_id='999';
        public static function where(...$args) { return new \GroupQuery; }
    }
    class UserBotSession {
        public static $current;
        public string $state='buy_select_group', $telegram_chat_id='123';
        public bool $is_logged_in=false;
        public ?string $account_email=null;
        public ?array $state_data=[];
        public static function updateOrCreate(...$args) { return self::$current; }
        public function update($values) { foreach($values as $key=>$value) $this->$key=$value; }
    }
}
namespace App\Services {
    class SubscriptionServiceType { public static function botPlanReady($key) { return true; } }
    class PartnerTariffAccessService { public function canSeeTariffGroup($owner, $id) { return true; } }
    class VolumeTrafficPolicy {
        public static function minimumForPlanKey($key) { return 1; }
        public static function label($gb) { return 'حداقل یک گیگ'; }
    }
}
namespace App\Services\UserBot {
    class UserBotTelegram {
        public static array $sent=[];
        public function __construct($token) {}
        public function sendMessage($chat,$text,$keyboard=null) { self::$sent[]=[$text,$keyboard]; return []; }
    }
    class GlobalUserBotFaq { public function active() { return new \GroupCollection([]); } }
}
namespace App\Support {
    class JalaliDateFormatter {
        public static function persianDigits($text) { return strtr($text,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }
    }
}
namespace {
    function e($text) { return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8'); }
    function app($name) { return new $name; }
    function collect($data=[]) { return new GroupCollection($data); }
    function data_get($data,$path,$default=null) { foreach(explode('.',$path) as $key) { if(!is_array($data)||!array_key_exists($key,$data))return $default; $data=$data[$key]; } return $data; }
    function response() { return new class { public function json($data) { return $data; } }; }
    function check($ok,$label) { if(!$ok)throw new \RuntimeException($label); }
    class GroupQuery {
        public function join(...$args) { return $this; }
        public function when($apply, $fn) { return $apply ? $fn($this) : $this; }
        public function whereNotNull($key) { return $this; }
        public function where(...$args) { return $this; }
        public function first() { return \App\Models\UserBotSetting::$current; }
        public function exists() { return true; }
        public function value($key) { return 'both'; }
    }
    class GroupCollection {
        public function __construct(private array $items) {}
        public function isEmpty() { return !$this->items; }
        public function count() { return count($this->items); }
        public function pluck($key) { return new self(array_column($this->items, $key)); }
        public function first() { return array_values($this->items)[0] ?? null; }
        public function all() { return $this->items; }
        public function filter($fn) { return new self(array_filter($this->items,$fn)); }
        public function firstWhere($key, $value) { return $this->filter(fn ($item) => $item[$key] === $value)->first(); }
        public function values() { return new self(array_values($this->items)); }
    }
    $root=dirname(__DIR__,2);
    require $root.'/app/Services/PurchasePlanDuration.php';
    require $root.'/app/Services/UserBot/UserBotPlanGroups.php';
    require $root.'/app/Services/UserBot/UserBotKeyboard.php';
    require $root.'/app/Services/UserBot/UserBotOrderDeliveryService.php';
    require $root.'/app/Http/Controllers/Api/UserBotWebhookController.php';
    use App\Services\UserBot\UserBotPlanGroups as Groups;
    use App\Services\UserBot\UserBotTelegram as Bot;
    use App\Models\UserBotSession as Session;
    use App\Http\Controllers\Api\UserBotWebhookController as Controller;

    $plans=[
        ['id'=>1,'plan_key'=>'7_1m','group_name'=>'۳۰ گیگ - VIP','title'=>'یک‌ماهه','price'=>100,'description'=>''],
        ['id'=>2,'plan_key'=>'7_3m','group_name'=>'۳۰ گیگ - VIP','title'=>'سه‌ماهه','price'=>250,'description'=>''],
        ['id'=>3,'plan_key'=>'4_1m','group_name'=>'۶۰ گیگ - VIP','title'=>'یک‌ماهه','price'=>200,'description'=>''],
    ];
    $options=Groups::options($plans);
    check(array_keys($options)===['۳۰ گیگ - VIP','۶۰ گیگ - VIP'],'clean group names and durations stay grouped by tariff');
    check(array_column(Groups::select($plans,$options,'📁 ۳۰ گیگ - VIP'),'plan_key')===['7_1m','7_3m'],'clean button selects the intended tariff and all its periods');
    $reordered=array_reverse($plans);
    check(Groups::select($reordered,$options,'📁 ۶۰ گیگ - VIP')[0]['plan_key']==='4_1m','group selection survives reordering');
    check(Groups::select(array_slice($plans,0,2),$options,'📁 ۶۰ گیگ - VIP')===[],'removed tariff does not select a different group');
    $duplicates=[
        ['plan_key'=>'1_1m','group_name'=>'VIP'],
        ['plan_key'=>'2_1m','group_name'=>'VIP'],
        ['plan_key'=>'3_volume_gig','group_name'=>'VIP'],
        ['plan_key'=>'4_1m','group_name'=>'VIP — زمانی (گزینه ۱)'],
    ];
    $dupOptions=Groups::options($duplicates);
    check(count($dupOptions)===4 && !str_contains(implode(' ',array_keys($dupOptions)),'[#'),'duplicate and literal-collision names have clean distinct buttons');
    foreach($dupOptions as $label=>$identity) check(count(Groups::select($duplicates,$dupOptions,'📁 '.$label))===1,'each duplicate button selects one tariff');
    check(Groups::select($duplicates,null,'📁 VIP')===[],'ambiguous names are never guessed');
    $legacy=[['plan_key'=>'7_1m','group_name'=>'VIP [#7]'],['plan_key'=>'4_1m','group_name'=>'VIP [#4]']];
    check(Groups::select($legacy,null,'📁 VIP [#4]')[0]['plan_key']==='4_1m','pre-update keyboards still select by their original identity');
    check(!str_contains(implode(' ',array_keys(Groups::options($legacy))),'[#'),'rerendering legacy snapshots removes identifiers');

    \App\Models\UserBotSetting::$current=new \App\Models\UserBotSetting;
    $controller=new Controller;
    $keyboardMethod=new \ReflectionMethod(Controller::class,'planGroupKeyboard');
    $send=function($text)use($controller) { return $controller->handle(new \Illuminate\Http\Request(['message'=>['chat'=>['id'=>123],'from'=>['id'=>123],'text'=>$text]]),'admin',1,'secret'); };
    foreach(['buy_select_group'=>'buy_select_plan','renew_select_group'=>'renew_select_plan'] as $state=>$next) {
        $session=Session::$current=new Session;
        $session->state=$state; $session->state_data=['plans'=>$plans];
        $keyboard=$keyboardMethod->invoke($controller,$session);
        check($keyboard['keyboard'][0][0]==='📁 ۳۰ گیگ - VIP','multi-duration group keeps its name');
        check($keyboard['keyboard'][1][0] === '📁 ۶۰ گیگ - VIP / یک‌ماهه', 'single duration appears in purchase and renewal');
        $send($keyboard['keyboard'][0][0]);
        check($session->state===$next && count($session->state_data['plans'])===2,'actual '.$state.' handler preserves the selected tariff');
        check($session->state_data['plans'][0]['plan_key']==='7_1m','selected plan key stays stable');
    }
    $session = Session::$current = new Session;
    $session->state = 'renew_select_group';
    $session->state_data = ['plans' => [$plans[2]]];
    $keyboard = $keyboardMethod->invoke($controller, $session);
    $send($keyboard['keyboard'][0][0]);
    check($session->state === 'renew_select_plan', 'single-duration renewal still prompts for price selection');
    $pricedKeyboard = Bot::$sent[array_key_last(Bot::$sent)][1]['keyboard'];
    check($pricedKeyboard === [['🧾 یک‌ماهه - 200 تومان'], ['⬅️ بازگشت']], 'renewal price is displayed before payment');
    $send($pricedKeyboard[0][0]);
    check($session->state === 'buy_select_payment_method' && $session->state_data['order_type'] === 'renew_account'
        && $session->state_data['amount'] === 200, 'explicit priced selection starts renewal payment');
    $volume=[['plan_key'=>'3_volume_gig','group_name'=>'حجم دلخواه - VIP','title'=>'حجمی','price'=>10,'description'=>'']];
    foreach(['buy_select_group'=>'buy_waiting_volume_gb','traffic_select_group'=>'traffic_waiting_gb'] as $state=>$next) {
        $session=Session::$current=new Session;
        $session->state=$state; $session->state_data=['plans'=>$volume];
        $keyboard=$keyboardMethod->invoke($controller,$session);
        $send($keyboard['keyboard'][0][0]);
        check($session->state===$next,'actual '.$state.' volume flow continues');
        check(!str_contains(Bot::$sent[array_key_last(Bot::$sent)][0],'[#'),'volume prompt does not leak the tariff ID');
    }
    $session=Session::$current=new Session;
    $session->state='buy_select_service';
    $session->state_data=['plans'=>[
        ['plan_key'=>'7_1m','group_name'=>'VIP','title'=>'یک‌ماهه','price'=>100,'description'=>'','service_type'=>'v2ray'],
        ['plan_key'=>'8_1m','group_name'=>'VIP','title'=>'یک‌ماهه','price'=>150000,'description'=>'','service_type'=>'wireguard'],
    ]];
    $send('🛡 WireGuard');
    $send(Bot::$sent[array_key_last(Bot::$sent)][1]['keyboard'][0][0]);
    check($session->state === 'buy_select_plan', 'single-duration purchase waits for the customer to select the priced option');
    $pricedKeyboard = Bot::$sent[array_key_last(Bot::$sent)][1]['keyboard'];
    check($pricedKeyboard === [['🧾 یک‌ماهه - 150,000 تومان'], ['⬅️ بازگشت']], 'single duration shows its price and the back button before payment');
    $send($pricedKeyboard[0][0]);
    check($session->state === 'buy_select_payment_method', 'selecting the priced option opens payment methods');
    check($session->state_data['selected_plan']['plan_key'] === '8_1m', 'the selected tariff belongs to WireGuard');
    check($session->state_data['amount'] === 150000 && $session->state_data['order_type'] === 'buy_account', 'single purchase retains payment state and price');
    check(str_contains(Bot::$sent[array_key_last(Bot::$sent)][0], 'روش پرداخت'), 'the regular payment prompt is sent');
    $legacyPlan=['plan_key'=>'7_volume_gig','group_name'=>'VIP [#7]','title'=>'حجمی'];
    $delivery=new \App\Services\UserBot\UserBotOrderDeliveryService;
    $titleMethod=new \ReflectionMethod($delivery,'planFullTitle');
    check($titleMethod->invoke($delivery,$legacyPlan)==='VIP','existing order delivery hides legacy group suffixes');
    $titleMethod=new \ReflectionMethod($controller,'planFullTitle');
    check($titleMethod->invoke($controller,$legacyPlan)==='VIP','existing payment summaries hide legacy group suffixes');
    echo "PASS: user bot clean/duplicate/legacy labels, purchase, renewal, traffic, service selection and order titles.\n";
}
