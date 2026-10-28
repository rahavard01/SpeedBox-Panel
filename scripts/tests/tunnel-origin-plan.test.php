<?php
/** Pure ORM fixture around the production policy; no server or database writes. */
namespace {
    final class Rows implements \IteratorAggregate {
        public function __construct(public array $rows){}
        public function map($fn){return new self(array_map($fn,$this->rows));}
        public function all(){return $this->rows;}
        public function getIterator(): \Traversable{return new \ArrayIterator($this->rows);}
    }
    final class Query {
        private array $tests=[];
        public function __construct(private array $rows){}
        public function where($field,$op=null,$value=null){
            if($field instanceof \Closure){$q=new self([]);$field($q);$this->tests[]=['and',fn($r)=>$q->matches($r)];return $this;}
            if(func_num_args()===2){$value=$op;$op='=';}
            $this->tests[]=['and',fn($r)=>$op==='!='?($r->$field??null)!=$value:($r->$field??null)==$value];return $this;
        }
        public function whereNull($field){$this->tests[]=['and',fn($r)=>($r->$field??null)===null];return $this;}
        public function orWhere($field,$value){$this->tests[]=['or',fn($r)=>($r->$field??null)==$value];return $this;}
        public function whereIn($field,$values){$values=$values instanceof Rows?$values->all():$values;$this->tests[]=['and',fn($r)=>in_array($r->$field??null,$values,true)];return $this;}
        private function matches($row){$out=true;foreach($this->tests as [$op,$fn])$out=$op==='or'?($out||$fn($row)):($out&&$fn($row));return $out;}
        public function get(){return new Rows(array_values(array_filter($this->rows,fn($r)=>$this->matches($r))));}
        public function pluck($field){return $this->get()->map(fn($r)=>$r->$field);}
    }
    function app($class){return new $class;}
    $checks=0;
    function check($value,$message){global $checks;if(!$value)throw new \RuntimeException($message);$checks++;}
}
namespace Illuminate\Support\Facades {final class Schema {public static function hasTable($t){return true;}}}
namespace App\Support {final class WatTunnelRules {const VERSION='wat-current-fixture';}}
namespace App\Models {
    class Model {
        public static array $rows=[];
        public function __construct(array $row){foreach($row as $k=>$v)$this->$k=$v;}
        public static function where(...$args){return (new \Query(static::$rows))->where(...$args);}
        public static function whereIn(...$args){return (new \Query(static::$rows))->whereIn(...$args);}
    }
    #[\AllowDynamicProperties] final class ManagedServer extends Model {
        public static array $rows=[];
        public static function find($id){foreach(self::$rows as $r)if($r->id===$id)return $r;return null;}
    }
    #[\AllowDynamicProperties] final class ProxyServer extends Model {public static array $rows=[];}
    #[\AllowDynamicProperties] final class ServerTunnel extends Model {public static array $rows=[];}
}
namespace App\Services {final class TunnelOriginRemote {public function allocate($t,$s,$e){return $s['source_identity_port']??25001;}}}
namespace {
    require dirname(__DIR__,2).'/app/Services/TrustedTunnelOrigin.php';
    use App\Models\{ManagedServer,ProxyServer,ServerTunnel};
    use App\Services\TrustedTunnelOrigin as Origin;
    ManagedServer::$rows=[new ManagedServer(['id'=>1,'machine_id'=>1,'host'=>'192.0.2.1']),new ManagedServer(['id'=>15,'machine_id'=>15,'host'=>'192.0.2.2'])];
    ProxyServer::$rows=[new ProxyServer(['id'=>8,'machine_id'=>15,'enabled'=>true,'parent_id'=>null,'server_port'=>7798]),new ProxyServer(['id'=>9,'machine_id'=>15,'enabled'=>true,'parent_id'=>8,'server_port'=>7799]),new ProxyServer(['id'=>10,'machine_id'=>15,'enabled'=>false,'parent_id'=>null,'server_port'=>7800])];
    $base=['enabled'=>true,'accept_udp'=>true,'inner_foreign'=>'10.226.0.2/30','mappings'=>[['public_port'=>7798,'target_port'=>7798],['public_port'=>8443,'target_port'=>443]]];
    foreach(['backpack','gost','gre','wattunnel'] as $engine){
        $t=new ServerTunnel(['id'=>3,'engine'=>$engine,'foreign_server_id'=>15,'iran_server_id'=>1,'remote_name'=>'sb-'.str_repeat('a',32),'secret'=>str_repeat('b',64),'subnet'=>33000,'status'=>'connected','settings'=>$base]);ServerTunnel::$rows=[$t];
        $settings=Origin::upgrade($t,$base);check($settings['source_identity']===true,"$engine enables identity");
        $node=ProxyServer::$rows[0];check(!Origin::required($node),"$engine uncommitted plan does not alter admission");
        if($engine==='wattunnel'){check(Origin::plan($t,$settings,'iran')===null,'Wat keeps its native broker wire format');check(Origin::ports($settings)===[443,7798],'Wat target coverage unchanged');}
        else {
            check(Origin::ports($settings)===[7798],"$engine ignores non-node targets");
            foreach(['iran','foreign'] as $side){$p=Origin::plan($t,[...$settings,'protocol'=>'both','inner_foreign'=>'2001:db8::2/126'],$side);check($p['side']===$side&&$p['tcp']&&$p['udp'],"$engine dual-protocol plan");check($p['entries']===[['public_port'=>7798,'target_port'=>7798]],"$engine whitelist");check($p['staged']===true,"$engine remains staged before policy reload");}
        }
        $t->settings=$settings;check(Origin::required($node),"$engine committed node requires origin");
        $unmapped=new ProxyServer(['machine_id'=>15,'server_port'=>2044]);check(!Origin::required($unmapped),"$engine leaves unrelated direct node unchanged");
        $t->settings=[...$settings,'enabled'=>false];check(!Origin::required($node),"$engine stopped mapping has no active alias policy");
        $t->settings=$settings;
        if($engine!=='wattunnel'){check(Origin::plan($t,$settings,'iran')['staged']===false,"$engine existing policy does not require disruptive reload");$paused=[...$settings,'enabled'=>false];$t->settings=$paused;check(Origin::plan($t,$settings,'iran')['staged']===true,"$engine start reloads previously disabled origin policy");}
    }
    $t->engine='backpack';$t->settings=$base;
    $settings=[...$base,'mappings'=>[['public_port'=>7797,'public_end'=>7800,'target_port'=>7797,'target_end'=>7800]]];
    check(Origin::entries($t,$settings)===[['public_port'=>7798,'target_port'=>7798],['public_port'=>7800,'target_port'=>7800]],'child aliases are excluded; disabled nodes keep protection for later re-enable');
    $t->engine='gost';$settings['mappings']=[['public_port'=>7798,'target_port'=>7798,'destinations'=>[['address'=>'192.0.2.2','port'=>7798],['address'=>'192.0.2.3','port'=>7798]]]];
    try{Origin::entries($t,$settings);throw new \RuntimeException('mixed managed targets were silently rewritten');}catch(\RuntimeException $e){check(str_contains($e->getMessage(),'چندمقصدی'),'mixed route fails before any deployment');}
    $settings['mappings'][0]=['public_port'=>7797,'public_end'=>7799,'target_port'=>7797,'target_end'=>7799,'destinations'=>[['address'=>'192.0.2.2','port'=>7797],['address'=>'192.0.2.9','port'=>8444]]];
    try{Origin::entries($t,$settings);throw new \RuntimeException('range offset escaped guard');}catch(\RuntimeException $e){check(str_contains($e->getMessage(),'چندمقصدی'),'mixed range offset is guarded');}
    $t->engine='backpack';$t->kind='reverse';$t->transport='udp';$t->orientation='normal';$t->settings=$base;
    $up=Origin::upgrade($t,$base);$p=Origin::plan($t,[...$up,'accept_udp'=>false],'iran');check(!$p['tcp']&&$p['udp'],'normal reverse UDP-only never requires TCP or an optional UDP flag');
    $t->orientation='swapped';check(Origin::plan($t,$up,'iran')['tcp'],'swapped UDP carrier still transports inner TCP');
    $settings=[...$base,'mappings'=>[['public_port'=>7798,'public_end'=>7801,'target_port'=>7798,'target_end'=>7801]]];$t->settings=Origin::upgrade($t,$settings);ServerTunnel::$rows=[$t];$before=$t->settings['source_identity_topology'];
    $new=new ProxyServer(['id'=>11,'machine_id'=>15,'enabled'=>true,'parent_id'=>null,'server_port'=>7801]);ProxyServer::$rows[]=$new;
    check(Origin::required($new),'new node in old range fails closed before paired enrollment');
    check(Origin::topology($t,$settings)!==$before,'new node changes enrollment signature');
    $new->enabled=false;$disabled=Origin::topology($t,$settings);$new->enabled=true;check(Origin::topology($t,$settings)!==$disabled,'enablement changes enrollment signature');
    $before=Origin::topology($t,$settings);$new->type='hysteria';check(Origin::topology($t,$settings)!==$before,'protocol change updates health enrollment');$p=Origin::plan($t,$t->settings,'foreign');check(!in_array(7801,$p['probe_targets'],true),'UDP-only node does not require TCP listener');check(in_array(7801,array_column($p['entries'],'target_port'),true),'UDP-only node retains origin protection');
    $t->engine='gost';$empty=[...$base,'mappings'=>[['public_port'=>8443,'target_port'=>443]]];$up=Origin::upgrade($t,$empty);$p=Origin::plan($t,$up,'foreign');check(!$up['source_identity']&&$p['health_only']&&$p['entries']===[],'unmanaged GOST gets authenticated health without node origin policy');
    echo "TUNNEL ORIGIN PLAN: $checks checks passed\n";
}
