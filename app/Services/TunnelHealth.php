<?php
namespace App\Services;

use App\Models\ServerTunnel;

class TunnelHealth
{
    public const MAX_AGE=180;

    public static function fresh(ServerTunnel $tunnel): bool
    {
        $r=$tunnel->remote_status??[];
        $stamp=(int)($r['_checked_at']??min((int)($r['iran']['checked_at']??0),(int)($r['foreign']['checked_at']??0)));
        $now=now()->timestamp;
        return $stamp>0 && $stamp<=$now+5 && $now-$stamp<=self::MAX_AGE;
    }

    public static function unreachable(ServerTunnel $tunnel): bool
    {
        foreach (['iran','foreign'] as $side) if(isset($tunnel->remote_status[$side]['error'])) return true;
        return false;
    }

    /** One decision for cards and alerts; unknown evidence never becomes green. */
    public static function reason(ServerTunnel $tunnel): ?string
    {
        $settings=$tunnel->settings??[];
        if(!($settings['enabled']??true))return 'stopped';
        if(self::unreachable($tunnel))return 'access';
        $unknown=false;
        foreach(['iran','foreign'] as $side){
            $s=$tunnel->remote_status[$side]??[];
            if(($settings['quota_mode']??'')==='enforce'&&!empty($s['quota_exhausted']))return 'quota';
            $native=!($tunnel->engine==='gost'&&$side==='foreign'&&$tunnel->transport!=='grpc');
            if($native&&($s['active']??null)===false)return 'carrier';
            if($native&&($s['active']??null)!==true)$unknown=true;
            $endpoint=(!empty($settings['source_identity_port']))||($tunnel->engine==='wattunnel'&&$side==='foreign'&&!empty($settings['source_identity']));
            $ready=$s['origin_endpoint']??$s['source_identity']??null;
            if($ready===false)return 'endpoint';
            if($endpoint&&$ready!==true)$unknown=true;
            if($tunnel->engine==='gost'&&$side==='iran'&&($settings['source_identity_runtime_version']??0)>=3){
                if(($s['path_verified']??null)===false)return 'carrier';
                if(($s['path_verified']??null)!==true)$unknown=true;
            }
            if(in_array(false,$s['source_identity_targets']??[],true))return 'target';
            if(in_array(false,$s['targets']??[],true))return 'target';
            if(($s['target_available']??null)===false)return 'target';
            if(($settings['connection_mode']??'direct')==='cdn'&&isset($s['cdn']['cdn_verified'])&&!$s['cdn']['cdn_verified'])return 'cdn';
            if($tunnel->engine==='gost'&&$side==='foreign')continue;
            $connected=$tunnel->engine==='backpack'?($s['metrics']['connected']??null):($s['connected']??null);
            if($connected===false)return 'carrier';
            if($connected!==true)$unknown=true;
        }
        return $unknown?'unknown':null;
    }

    public static function connection(ServerTunnel $tunnel): ?bool
    {
        $reason=self::reason($tunnel);
        return $reason===null?true:(in_array($reason,['stopped','quota','access','unknown'],true)?null:false);
    }

    /** Display projection only; a cached green result is never presented as a live check. */
    public static function display(ServerTunnel $tunnel): void
    {
        if ($tunnel->status==='connected' && !self::fresh($tunnel)) {
            $tunnel->status='needs_review';
            $tunnel->message='وضعیت اتصال نیازمند بررسی تازه است.';
        }
    }
}
