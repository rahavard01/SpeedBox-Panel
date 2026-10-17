<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class JalaliDateFormatter
{
    public static function format(
        mixed $value,
        string $format = 'H:i d-m-Y',
        string $fallback = ''
    ): string {
        $date = self::normalize($value);

        if (!$date) {
            return $fallback;
        }

        [$year, $month, $day] = self::gregorianToJalali(
            (int) $date->format('Y'),
            (int) $date->format('n'),
            (int) $date->format('j')
        );

        $jalaliTokens = [
            'Y' => sprintf('%04d', $year),
            'y' => substr(sprintf('%04d', $year), -2),
            'm' => sprintf('%02d', $month),
            'n' => (string) $month,
            'd' => sprintf('%02d', $day),
            'j' => (string) $day,
        ];

        $output = '';
        $escaped = false;

        foreach (str_split($format) as $token) {
            if ($escaped) {
                $output .= $token;
                $escaped = false;
                continue;
            }

            if ($token === '\\') {
                $escaped = true;
                continue;
            }

            $output .= $jalaliTokens[$token]
                ?? $date->format($token);
        }

        return $output;
    }

    public static function forTelegram(
        mixed $value,
        string $format = 'H:i d-m-Y',
        string $fallback = ''
    ): string {
        $formatted = self::format(
            $value,
            $format,
            $fallback
        );

        return self::persianDigits(preg_replace_callback(
            '/(?<=\\d)([-\\/])(?=\\d)/u',
            static fn (array $matches): string =>
                $matches[1] . "\u{2060}",
            $formatted
        ) ?? $formatted);
    }

    /** Localize presentation only; format() retains its machine-readable contract. */
    public static function persianDigits(string $value): string
    {
        return strtr($value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴',
            '5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    private static function normalize(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timezone = 'Asia/Tehran';

        try {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value)
                    ->setTimezone($timezone);
            }

            if (
                is_int($value)
                || (is_string($value) && ctype_digit($value))
            ) {
                return CarbonImmutable::createFromTimestamp(
                    (int) $value,
                    $timezone
                );
            }

            return CarbonImmutable::parse(
                (string) $value,
                config('app.timezone', $timezone)
            )->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }
    }

    public static function gregorianToJalali(
        int $gregorianYear,
        int $gregorianMonth,
        int $gregorianDay
    ): array {
        $monthOffsets = [
            0, 31, 59, 90, 120, 151,
            181, 212, 243, 273, 304, 334,
        ];

        if ($gregorianYear > 1600) {
            $jalaliYear = 979;
            $gregorianYear -= 1600;
        } else {
            $jalaliYear = 0;
            $gregorianYear -= 621;
        }

        $leapAdjustedYear = $gregorianMonth > 2
            ? $gregorianYear + 1
            : $gregorianYear;

        $days = 365 * $gregorianYear
            + intdiv($leapAdjustedYear + 3, 4)
            - intdiv($leapAdjustedYear + 99, 100)
            + intdiv($leapAdjustedYear + 399, 400)
            - 80
            + $gregorianDay
            + $monthOffsets[$gregorianMonth - 1];

        $jalaliYear += 33 * intdiv($days, 12053);
        $days %= 12053;

        $jalaliYear += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jalaliYear += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jalaliMonth = 1 + intdiv($days, 31);
            $jalaliDay = 1 + ($days % 31);
        } else {
            $jalaliMonth = 7 + intdiv($days - 186, 30);
            $jalaliDay = 1 + (($days - 186) % 30);
        }

        return [$jalaliYear, $jalaliMonth, $jalaliDay];
    }
}
