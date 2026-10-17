<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class JalaliCalendar
{
    public static function parts(CarbonImmutable $date): array
    {
        $date = $date->setTimezone('Asia/Tehran');
        return JalaliDateFormatter::gregorianToJalali($date->year, $date->month, $date->day);
    }

    public static function date(int $year, int $month, int $day = 1): CarbonImmutable
    {
        // Inverse of the formatter's 33-year arithmetic calendar; timestamps stay Unix.
        $jy = $year + 1595;
        $days = -355668 + 365 * $jy + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4)
            + $day + ($month < 7 ? ($month - 1) * 31 : ($month - 7) * 30 + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $lengths = [31, (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 1;
        foreach ($lengths as $length) {
            if ($gd <= $length) break;
            $gd -= $length;
            $gm++;
        }
        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, 'Asia/Tehran');
    }

    public static function monthLength(int $year, int $month): int
    {
        if ($month <= 6) return 31;
        if ($month <= 11) return 30;
        return (int) self::date($year, 12)->diffInDays(self::date($year + 1, 1));
    }
}
