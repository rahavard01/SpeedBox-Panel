<?php

namespace App\Services;

final class PurchasePlanDuration
{
    public static function label(array $keys): ?string
    {
        if (count($keys) !== 1) {
            return null;
        }

        $key = preg_replace('/^\d+_/', '', (string) reset($keys));

        return match ($key) {
            '1m' => 'یک‌ماهه',
            '3m' => 'سه‌ماهه',
            '6m' => 'شش‌ماهه',
            '12m' => 'یک‌ساله',
            default => null,
        };
    }
}
