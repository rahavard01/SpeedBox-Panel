<?php
// Standalone regression checks with an isolated SQLite database; no live state.
namespace Illuminate\Support {
    class Collection implements \IteratorAggregate, \ArrayAccess {
        public function __construct(public array $items=[]) {}
        public function getIterator(): \Traversable { return new \ArrayIterator($this->items); }
        public function offsetExists(mixed $k): bool { return isset($this->items[$k]); }
        public function offsetGet(mixed $k): mixed { return $this->items[$k] ?? null; }
        public function offsetSet(mixed $k,mixed $v): void { $this->items[$k]=$v; }
        public function offsetUnset(mixed $k): void { unset($this->items[$k]); }
        public function all() { return $this->items; }
        public function isEmpty() { return !$this->items; }
        public function filter($fn=null) { return new self(array_filter($this->items,$fn??fn($x)=>(bool)$x)); }
        public function values() { return new self(array_values($this->items)); }
        public function unique() { return new self(array_unique($this->items)); }
        public function pluck($key) { return new self(array_map(fn($x)=>$x->$key??null,$this->items)); }
        public function keyBy($key) { $a=[];foreach($this->items as $x)$a[$x->$key]=$x;return new self($a); }
        public function groupBy($key) { $a=[];foreach($this->items as $x)$a[$x->$key][]=$x;return new self($a); }
        public function take($n) { return new self(array_slice($this->items,0,$n)); }
        public function sortBy($fn) { $a=$this->items;uasort($a,fn($x,$y)=>$fn($x)<=>$fn($y));return new self($a); }
    }
}
namespace Illuminate\Support\Facades {
    class DB { public static \PDO $pdo; public static function table($table) { return new \TariffQuery($table); } }
}
namespace App\Http\Controllers { class Controller {} }
namespace App\Models {
    class UserBotSetting { public int $owner_id=9; }
    class WireGuardInstance { public static $row; public static function find($id) { return self::$row; } }
}
namespace App\Services {
    function extension_loaded($name) { return $name==='sodium'||\extension_loaded($name); }
    class PartnerTariffAccessService { public static bool $allowed=true; public function canSeeTariffGroup($owner,$id) { return self::$allowed; } }
    class SubscriptionValidity { public static function hasExpiry($at) { return $at!==null && $at>0; } }
}
namespace {
    function collect($values=[]) { return $values instanceof \Illuminate\Support\Collection ? $values : new \Illuminate\Support\Collection(is_array($values)?$values:iterator_to_array($values)); }
    function app($class) { return new $class; }
    function e($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
    function now() { return new class { public function subMinutes($n) { return $this; } }; }
    function check($ok,$label) { if(!$ok)throw new \RuntimeException($label); }
    class TariffQuery {
        private array $where=[],$params=[],$joins=[],$columns=['*'];
        public function __construct(private string $table) {}
        public function where($column,$op,$value=null) { if(func_num_args()===2){$value=$op;$op='=';} $this->where[]="$column $op ?";$this->params[]=$value;return $this; }
        public function whereIn($column,$values) { $this->where[]=$values?"$column IN (".implode(',',array_fill(0,count($values),'?')).')':'0=1';array_push($this->params,...$values);return $this; }
        public function whereNotNull($column) { $this->where[]="$column IS NOT NULL";return $this; }
        public function join($table,$left,$op,$right) { $this->joins[]="JOIN $table ON $left $op $right";return $this; }
        public function select(...$columns) { $this->columns=$columns;return $this; }
        public function when($condition,$fn) { if($condition)$fn($this);return $this; }
        public function get($columns=null) {
            $cols=$columns??$this->columns;
            $sql='SELECT '.implode(',',$cols).' FROM '.$this->table.' '.implode(' ',$this->joins).($this->where?' WHERE '.implode(' AND ',$this->where):'');
            $q=\Illuminate\Support\Facades\DB::$pdo->prepare($sql);$q->execute($this->params);return collect($q->fetchAll(\PDO::FETCH_OBJ));
        }
        public function first() { return $this->get()->values()->items[0]??null; }
        public function exists() { return $this->first()!==null; }
        public function find($id) { return $this->where('id',$id)->first(); }
    }
    $root=dirname(__DIR__,2);
    require $root.'/app/Services/AccountTariffResolver.php';
    require $root.'/app/Services/SubscriptionServiceType.php';
    require $root.'/app/Services/UserBot/UserBotTrafficPricing.php';
    require $root.'/app/Services/UserBot/UserBotAccountResolver.php';
    require $root.'/app/Http/Controllers/Api/UserBotWebhookController.php';
    $db=new \PDO('sqlite::memory:');$db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);\Illuminate\Support\Facades\DB::$pdo=$db;
    $db->exec("CREATE TABLE panel_tariff_groups(id INTEGER PRIMARY KEY,name TEXT,group_type TEXT,service_type TEXT,enable INTEGER,is_default INTEGER);
      CREATE TABLE panel_plan(id INTEGER PRIMARY KEY,tariff_group_id INTEGER,plan_key TEXT,v2_plan_id INTEGER,enable INTEGER);
      CREATE TABLE v2_plan(id INTEGER PRIMARY KEY,service_type TEXT,wireguard_instance_id INTEGER);
      CREATE TABLE panel_users(id INTEGER PRIMARY KEY);
      INSERT INTO panel_users VALUES(9);
      INSERT INTO panel_tariff_groups VALUES(1,'120 GB ECO','time','v2ray',1,1),(2,'Other','time','v2ray',1,0),(3,'WG','time','wireguard',1,0);
      INSERT INTO v2_plan VALUES(10,'v2ray',NULL),(20,'wireguard',1);
      INSERT INTO panel_plan VALUES(1,1,'1m',10,1),(2,1,'gig',0,1),(3,2,'1m',10,1),(4,3,'1m',20,1),(5,3,'gig',NULL,1);");
    $resolver=new \App\Services\AccountTariffResolver;
    $account=(object)['id'=>1,'plan_id'=>10,'account_plan_key'=>'1m','account_tariff_group_id'=>1,'account_plan_name'=>'120 GB','account_group_type'=>'time','service_type'=>'v2ray'];
    check($resolver->name($account)==='120 GB ECO','current tariff name wins over historical snapshot');
    check($account->account_plan_name==='120 GB','display lookup does not overwrite stored snapshot');
    $presented=clone $account;$resolver->present([$presented]);
    check($presented->account_plan_name==='120 GB ECO'&&$presented->account_tariff_group_id===1,'batched management presentation');
    $legacy=clone $account;$legacy->account_tariff_group_id=null;
    check($resolver->group($legacy)===null,'ambiguous legacy plan is not attached to MIN group');
    $db->exec('DELETE FROM panel_plan WHERE id=3');
    check($resolver->group($legacy)->id===1,'unique legacy plan resolves despite changed name');
    $resolver->present([$legacy]);check($legacy->account_tariff_group_id===1&&$legacy->account_plan_name==='120 GB ECO','unique legacy presentation');
    $missing=clone $account;$missing->account_tariff_group_id=999;
    check($resolver->group($missing)===null && $resolver->name($missing)==='120 GB','deleted explicit tariff is not remapped');
    check(\App\Services\SubscriptionServiceType::botPlanReady('1_traffic_gig'),'extra traffic needs no technical plan');
    check(\App\Services\SubscriptionServiceType::botPlanReady('3_gig'),'nullable technical link on extra traffic');
    check(\App\Services\SubscriptionServiceType::botPlanReady('1_1m'),'normal purchase still requires technical link');
    $pricing=new \App\Services\UserBot\UserBotTrafficPricing;
    $options=collect([(object)['plan_key'=>'1_traffic_gig'],(object)['plan_key'=>'2_traffic_gig']]);
    check(count($pricing->forAccount($options,$account)->all())===1,'extra traffic remains scoped to account tariff');
    $method=new \ReflectionMethod(\App\Http\Controllers\Api\UserBotWebhookController::class,'ownerCanSeeUserBotPlan');
    $controller=new \App\Http\Controllers\Api\UserBotWebhookController;$setting=new \App\Models\UserBotSetting;
    check($method->invoke($controller,$setting,'1_traffic_gig'),'actual bot visibility accepts unlinked extra traffic');
    \App\Services\PartnerTariffAccessService::$allowed=false;
    check(!$method->invoke($controller,$setting,'1_traffic_gig'),'owner access remains enforced');
    \App\Services\PartnerTariffAccessService::$allowed=true;
    $wg=clone $account;$wg->plan_id=20;$wg->service_type='wireguard';$wg->account_tariff_group_id=3;
    \App\Models\WireGuardInstance::$row=(object)['enabled'=>false,'status'=>'disabled','last_synced_at'=>null];
    check(!$pricing->forAccount(collect([(object)['plan_key'=>'3_traffic_gig']]),$wg)->all(),'disabled WireGuard account service blocks traffic sale');
    \App\Models\WireGuardInstance::$row=(object)['enabled'=>true,'status'=>'ready','last_synced_at'=>new class {public function lt($x){return false;}}];
    check(count($pricing->forAccount(collect([(object)['plan_key'=>'3_traffic_gig']]),$wg)->all())===1,'ready WireGuard uses the account technical plan');
    $db->exec('UPDATE panel_tariff_groups SET enable=0 WHERE id=1');
    check(!\App\Services\SubscriptionServiceType::botPlanReady('1_gig'),'disabled tariff is blocked');
    check(!$pricing->forAccount($options,$account)->all(),'disabled account tariff stays blocked');
    $db->exec('UPDATE panel_tariff_groups SET enable=1 WHERE id=1; UPDATE panel_plan SET enable=0 WHERE id=2');
    check(!\App\Services\SubscriptionServiceType::botPlanReady('1_gig'),'disabled extra traffic row is blocked');
    $info=(new \App\Services\UserBot\UserBotAccountResolver)->accountInfoText((array)$account+['transfer_enable'=>1073741824,'u'=>0,'d'=>0,'expired_at'=>null],'https://example.invalid');
    check(str_contains($info,'120 GB ECO'),'bot account information uses renamed tariff');
    echo "PASS: renamed/current and historical names, legacy identity, traffic visibility/access, disabled tariffs and account service readiness.\n";
}
