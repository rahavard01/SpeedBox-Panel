<?php

namespace App\Services;

final class StreisandSmartSettings
{
    public const KEY = 'streisand_smart';
    public const DEFAULTS = ['enabled' => true, 'all' => true, 'countries' => true];

    public static function get(): array
    {
        $stored = RuntimeSetting::get(self::KEY, []);

        return array_replace(self::DEFAULTS, array_intersect_key((array) $stored, self::DEFAULTS));
    }
}
