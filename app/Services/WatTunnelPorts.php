<?php
namespace App\Services;

use App\Models\{ManagedServer,ServerTunnel};
use App\Support\NetworkTunnelRules;

final class WatTunnelPorts
{
    public static function listener(array $s): int
    {
        return (int)$s[!empty($s['reverse'])?'iran_server_id':'foreign_server_id'];
    }

    /** Conservative TCP/UDP reservations include loopback metrics and every engine. */
    public static function assertAvailable(array $s,int $iran,int $foreign,?int $except): void
    {
        $wanted=[$iran=>[],$foreign=>[]];
        foreach($s['mappings'] as $m){
            $wanted[$iran]=[...$wanted[$iran],...range($m['public_port'],$m['public_end'])];
            $target=range($m['target_port'],$m['target_end']);
            if(in_array($s['metrics_port']??null,$target,true)||($s['transport']!=='icmp'&&!$s['reverse']&&in_array($s['tunnel_port'],$target,true)))NetworkTunnelRules::fail('mappings','پورت مقصد با پورت داخلی موتور تداخل دارد.');
        }
        if($s['transport']!=='icmp')$wanted[self::listener($s)][]=$s['tunnel_port'];
        if(!empty($s['metrics_port']))foreach([$iran,$foreign] as $id)$wanted[$id][]=$s['metrics_port'];
        foreach($wanted as $id=>$ports){
            if(count($ports)!==count(array_unique($ports)))NetworkTunnelRules::fail('mappings','پورت‌های ورودی، ارتباط و پایش باید متفاوت باشند.');
            if(in_array((int)ManagedServer::findOrFail($id)->ssh_port,$ports,true))NetworkTunnelRules::fail('tunnel_port','پورت SSH سرور قابل استفاده نیست.');
        }
        foreach(ServerTunnel::where('status','!=','deleted')->when($except,fn($q)=>$q->whereKeyNot($except))->lockForUpdate()->get() as $t){
            $occupied=[];
            foreach($t->settings['mappings']??[] as $m)$occupied[(int)$t->iran_server_id]=[...($occupied[(int)$t->iran_server_id]??[]),...range((int)$m['public_port'],(int)($m['public_end']??$m['public_port']))];
            if($t->engine==='wattunnel'){
                if((int)$t->foreign_server_id===$foreign)foreach($s['mappings'] as $m)if((int)$t->subnet>=$m['target_port']&&(int)$t->subnet<=$m['target_end'])NetworkTunnelRules::fail('mappings','پورت مقصد برای پایش WatTunnel دیگری رزرو است.');
                foreach([(int)$t->iran_server_id,(int)$t->foreign_server_id] as $id)$occupied[$id][]=(int)$t->subnet;
                if($t->transport!=='icmp')$occupied[self::listener($t->settings)][]=(int)$t->settings['tunnel_port'];
            }elseif($t->engine==='gost'&&$t->transport==='grpc')$occupied[(int)$t->foreign_server_id][]=(int)$t->settings['tunnel_port'];
            elseif($t->engine==='backpack')$occupied[($t->kind==='reverse'||$t->orientation==='swapped')?(int)$t->iran_server_id:(int)$t->foreign_server_id][]=(int)$t->settings['tunnel_port'];
            foreach($wanted as $id=>$ports)if(array_intersect($ports,$occupied[$id]??[]))NetworkTunnelRules::fail('tunnel_port','پورت با اتصال «'.$t->name.'» روی یکی از سرورها تداخل دارد.');
        }
    }
}
