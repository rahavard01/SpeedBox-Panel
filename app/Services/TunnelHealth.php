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

    /** Display projection only; a cached green result is never presented as a live check. */
    public static function display(ServerTunnel $tunnel): void
    {
        if ($tunnel->status==='connected' && !self::fresh($tunnel)) {
            $tunnel->status='needs_review';
            $tunnel->message='وضعیت اتصال نیازمند بررسی تازه است.';
        }
    }
}
