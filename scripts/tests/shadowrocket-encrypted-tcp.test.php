<?php
// Serializer-only regression: no framework boot, network, credentials or DB.
namespace App\Support { abstract class AbstractProtocol {} }
namespace App\Models {
    class ProxyServer {
        const TYPE_SHADOWSOCKS='shadowsocks', TYPE_VMESS='vmess', TYPE_VLESS='vless',
            TYPE_TROJAN='trojan', TYPE_HYSTERIA='hysteria', TYPE_TUIC='tuic',
            TYPE_ANYTLS='anytls', TYPE_SOCKS='socks';
    }
}
namespace App\Utils {
    class Helper {
        static function wrapIPv6($host) { return str_contains($host, ':') ? '['.$host.']' : $host; }
        static function getTlsFingerprint($value) { return $value ?: null; }
        static function toXrayEchConfigList($value) { return $value; }
    }
}
namespace Illuminate\Support {
    class Arr { static function random($values) { return reset($values); } }
}
namespace {
    function data_get($value, $key, $default=null) {
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return $default;
            $value=$value[$part];
        }
        return $value;
    }
    $root=dirname(__DIR__,2);
    require $root.'/app/Protocols/General.php';
    require $root.'/app/Protocols/Shadowrocket.php';
    $checks=0;
    $check=function($value,$message) use (&$checks) { ++$checks; if (!$value) throw new \RuntimeException($message); };
    $uuid='11111111-1111-4111-8111-111111111111';
    $encryption='mlkem768x25519plus.native.0rtt.'.rtrim(strtr(base64_encode(str_repeat('k',32)),'+/','-_'),'=');
    $base=['name'=>'HTTP تست & example','host'=>'198.51.100.10','port'=>2044,
        'protocol_settings'=>['network'=>'tcp','tls'=>0,'flow'=>'',
            'encryption'=>['enabled'=>true,'encryption'=>$encryption,'decryption'=>'PRIVATE-SERVER-KEY'],
            'network_settings'=>['header'=>['type'=>'http','request'=>['path'=>['/check'], 'headers'=>['Host'=>['example.com']]]]]]];
    foreach (['http','raw','ipv6','tls','reality','numeric-enabled'] as $case) {
        $server=$base;
        if ($case==='raw') $server['protocol_settings']['network_settings']['header']['type']='none';
        if ($case==='ipv6') $server['host']='2001:db8::10';
        if ($case==='numeric-enabled') $server['protocol_settings']['encryption']['enabled']=1;
        if ($case==='tls') {
            $server['protocol_settings']['tls']=1;
            $server['protocol_settings']['tls_settings']=['server_name'=>'example.com','allow_insecure'=>false];
        }
        if ($case==='reality') {
            $server['protocol_settings']['tls']=2;
            $server['protocol_settings']['flow']='xtls-rprx-vision';
            $server['protocol_settings']['reality_settings']=['server_name'=>'example.com','public_key'=>'PUBLIC','short_id'=>'abcd'];
        }
        $uri=trim(\App\Protocols\Shadowrocket::buildVless($uuid,$server));
        $parts=parse_url($uri); parse_str($parts['query'] ?? '',$query);
        $check(($parts['scheme'] ?? '')==='vless', $case.': scheme');
        $check(($parts['user'] ?? '')===$uuid, $case.': UUID must be standard URI userinfo');
        $check(($parts['port'] ?? 0)===2044, $case.': port');
        $check(($query['encryption'] ?? '')===$encryption, $case.': public encryption setting');
        $check(($query['type'] ?? '')==='tcp', $case.': transport');
        $check(($query['tfo'] ?? '')==='1', $case.': preserve TFO');
        $check(rawurldecode($parts['fragment'] ?? '')===$server['name'], $case.': name fragment');
        $check(!str_contains($uri,'PRIVATE-SERVER-KEY'), $case.': no server secret');
        if ($case!=='raw') {
            $check(($query['headerType'] ?? '')==='http', $case.': HTTP camouflage');
            $check(($query['host'] ?? '')==='example.com', $case.': HTTP host');
            $check(($query['path'] ?? '')==='/check', $case.': HTTP path');
        }
        $check(($query['security'] ?? '')===match($case){'tls'=>'tls','reality'=>'reality',default=>'none'},$case.': security');
    }
    $base['protocol_settings']['encryption']['enabled']=false;
    $uri=trim(\App\Protocols\Shadowrocket::buildVless($uuid,$base));
    $encoded=explode('?',substr($uri,8),2)[0];
    $check(base64_decode($encoded,true)==='auto:'.$uuid.'@198.51.100.10:2044','legacy unencrypted links unchanged');
    echo "SHADOWROCKET ENCRYPTED TCP: {$checks} assertions passed\n";
}
