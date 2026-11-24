<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
final class SubscriptionServiceType {
    public static function of(?object $row): string { return $row->service_type ?? 'v2ray'; }
    public const LABELS = ['v2ray' => 'V2Ray', 'wireguard' => 'WireGuard', 'anyconnect' => 'AnyConnect'];
    public const BOT_LABELS = ['v2ray' => '🌐 V2Ray', 'wireguard' => '🛡 WireGuard', 'anyconnect' => '🛡 AnyConnect'];

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? self::LABELS['v2ray'];
    }

    public static function sameServer(object $user, object $plan): bool
    {
        $type = self::of($user);
        if ($type !== self::of($plan)) return false;
        $column = match ($type) {
            'wireguard' => 'wireguard_instance_id',
            'anyconnect' => 'anyconnect_instance_id',
            default => null,
        };
        return $column === null || (int) ($user->{$column} ?? 0) === (int) ($plan->{$column} ?? 0);
    }

    public static function same(object $user, int $planId): void {
        $plan=DB::table('v2_plan')->find($planId);
        if (!$plan || self::of($user)!==self::of($plan)) throw new \DomainException('تمدید فقط با سرویس فعلی حساب مجاز است؛ برای سرویس دیگر خرید جدید انجام دهید.');
        if (self::of($plan)==='wireguard' && (int)$user->wireguard_instance_id!==(int)$plan->wireguard_instance_id) throw new \DomainException('تمدید وایرگارد باید روی همان سرور باشد؛ جابه‌جایی نیازمند انتقال حساب است.');
        if (self::of($plan) === 'anyconnect' && !self::sameServer($user, $plan)) {
            throw new \DomainException('تمدید AnyConnect باید روی همان سرور انجام شود.');
        }
    }
    public static function available(object $plan): bool
    {
        try { self::ready($plan); return true; }
        catch (\DomainException $e) { return false; }
    }

    public static function botPlanReady(string $raw): bool
    {
        $key = $raw; $groupId = null;
        if (preg_match('/^(\d+)_(.+)$/', $raw, $m)) { $groupId = (int) $m[1]; $key = $m[2]; }
        if ($key === 'traffic_gig') $key = 'gig';
        // Extra traffic is priced by its tariff, not by a separate technical plan.
        // The caller validates the existing account's own plan and service readiness.
        if ($key === 'gig') {
            $q = DB::table('panel_plan as pp')->join('panel_tariff_groups as g', 'g.id', '=', 'pp.tariff_group_id')
                ->where('pp.enable', 1)->where('g.enable', 1)->where('g.group_type', 'time')->where('pp.plan_key', 'gig');
            if ($groupId) $q->where('g.id', $groupId);
            else $q->where('g.is_default', 1);
            return $q->exists();
        }
        $q = DB::table('panel_plan as pp')->join('panel_tariff_groups as g', 'g.id', '=', 'pp.tariff_group_id')
            ->join('v2_plan as p', 'p.id', '=', 'pp.v2_plan_id')
            ->where('pp.enable', 1)->where('g.enable', 1)->where('pp.plan_key', $key)->select('p.*');
        if ($groupId) $q->where('g.id', $groupId);
        else $q->where('g.is_default', 1)->where('g.group_type', $key === 'volume_gig' ? 'volume' : 'time');
        $plan = $q->first();
        return $plan && self::available($plan);
    }

    public static function ready(object $plan): void {
        if (self::of($plan) === 'anyconnect') {
            $instance = \App\Models\AnyConnectInstance::find($plan->anyconnect_instance_id ?? null);
            if (!$instance?->isReady()) throw new \DomainException('سرویس AnyConnect هنوز آماده فروش نیست.');
            return;
        }
        if (self::of($plan)!=='wireguard') return;
        if(!extension_loaded('sodium'))throw new \DomainException('افزونه sodium در PHP پنل برای مدیریت کلیدهای وایرگارد لازم است.');
        $i=\App\Models\WireGuardInstance::find($plan->wireguard_instance_id);
        if (!$i || !$i->enabled || $i->status!=='ready' || !$i->last_synced_at || $i->last_synced_at->lt(now()->subMinutes(3))) throw new \DomainException('سرویس وایرگارد هنوز آماده فروش نیست یا همگام‌سازی آن متوقف شده است.');
    }
}
