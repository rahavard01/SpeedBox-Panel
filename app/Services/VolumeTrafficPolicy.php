<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class VolumeTrafficPolicy
{
    public static function minimum(?int $groupId): int
    {
        $query = DB::table('panel_tariff_groups')->where('group_type', 'volume');
        if ($groupId) {
            $query->where('id', $groupId);
        } else {
            $query->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id');
        }

        return max(1, (int) ($query->value('min_traffic_gb') ?? 10));
    }

    public static function minimumForPlanKey(string $planKey): int
    {
        preg_match('/^(\d+)_/', $planKey, $match);
        return self::minimum(isset($match[1]) ? (int) $match[1] : null);
    }

    public static function label(int $minimum): string
    {
        return 'حداقل خرید ترافیک: ' . self::digits($minimum) . ' گیگ';
    }

    public static function message(int $minimum): string
    {
        $number = self::digits($minimum);
        return "حداقل خرید ترافیک {$number} گیگ است، لطفاً عدد {$number} یا بیشتر وارد کنید";
    }

    private static function digits(int $number): string
    {
        return strtr((string) $number, ['0'=>'۰', '1'=>'۱', '2'=>'۲', '3'=>'۳', '4'=>'۴', '5'=>'۵', '6'=>'۶', '7'=>'۷', '8'=>'۸', '9'=>'۹']);
    }
}
