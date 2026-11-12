<?php
namespace App\Http\Controllers { class Controller {} }
namespace Illuminate\Http { class Request {} }
namespace App\Models {
    class SubscriptionAccount {
        public int $id=42, $u=100, $d=200, $transfer_enable=1073741824, $device_limit=2;
        public string $email='account@example.com', $token='private-token';
        public bool $banned=false;
        public $expired_at=null;
    }
}
namespace App\Utils { class Helper { static function getSubscribeUrl($token){ return 'https://example.test/sub/'.$token; } } }
namespace App\Services {
    class SubscriptionValidity { static function hasExpiry($value){ return false; } static function unexpired($value){ return true; } }
    class SubscriptionResetDay { function forUser($user){ return 0; } }
    class AccountOnlineStatus { function counts($ids){ return [$ids[0]=>1]; } }
    class SubscriptionReservationService {
        public static array $rows=[];
        public static array $requested=[];
        function current(int $id): ?array { self::$requested[]=$id;return self::$rows[$id]??null; }
    }
}
namespace BaconQrCode { class Writer { function __construct(...$args){} function writeString($value){return '<svg>QR</svg>'; } } }
namespace BaconQrCode\Renderer { class ImageRenderer { function __construct(...$args){} } }
namespace BaconQrCode\Renderer\RendererStyle { class RendererStyle { function __construct(...$args){} } }
namespace BaconQrCode\Renderer\Image { class SvgImageBackEnd {} }
namespace {
    use App\Services\SubscriptionReservationService as Reservations;
    function app($class){return new $class;}
    class PortalResponse {
        public array $data=[], $headers=[];
        function view($name,$data){ if($name!=='client.subscribe')throw new \RuntimeException('Wrong view'); $this->data=$data;return $this; }
        function header($name,$value){$this->headers[$name]=$value;return $this;}
    }
    function response(){return new PortalResponse;}
    require dirname(__DIR__,2).'/app/Http/Controllers/Api/SubscriptionController.php';
    $method=new \ReflectionMethod(\App\Http\Controllers\Api\SubscriptionController::class,'portal');
    $controller=new \App\Http\Controllers\Api\SubscriptionController;
    $user=new \App\Models\SubscriptionAccount;
    $render=fn()=>$method->invoke($controller,new \Illuminate\Http\Request,$user);
    $checks=0;
    $check=function($condition,$message)use(&$checks){if(!$condition)throw new \RuntimeException($message);$checks++;};
    $baseline=$render();$check($baseline->data['reservation']===null,'No reservation produces no notice');
    Reservations::$rows[99]=['state'=>'reserved','title'=>'Other account'];
    $check($render()->data['reservation']===null,'Other accounts cannot leak into this subscription');
    Reservations::$rows[42]=['state'=>'reserved','title'=>'Next plan','traffic_label'=>'60 GB','duration_days'=>30,'activation_label'=>'On expiry','id'=>999,'can_refund'=>true];
    $reserved=$render();
    $check(count($reserved->data['reservation'])===4,'Only public display fields are passed to the view');
    $check($reserved->data['reservation']['title']==='Next plan','Real reservation title reaches the portal');
    foreach(['data_limit','data_used','data_remaining','remaining_days','expired_date','status','subscription_url','qr_code'] as $field)$check($baseline->data[$field]===$reserved->data[$field],'Current plan is unchanged: '.$field);
    $check(str_contains($reserved->headers['Cache-Control'],'no-store'),'Reservation display cannot be cached for another request');
    foreach(['cancelled','activated'] as $state){Reservations::$rows[42]['state']=$state;$check($render()->data['reservation']===null,'Retired reservation is hidden: '.$state);}
    $check(count(array_unique(Reservations::$requested))===1&&Reservations::$requested[0]===42,'Lookup always uses the authenticated subscription account');
    echo "SUBSCRIPTION RESERVATION PORTAL: {$checks} checks passed\n";
}
