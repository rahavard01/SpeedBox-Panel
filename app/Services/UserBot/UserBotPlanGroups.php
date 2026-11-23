<?php

namespace App\Services\UserBot;

use App\Services\PurchasePlanDuration;
use App\Support\JalaliDateFormatter;

/** Visible group names stay separate from the stable tariff identity. */
final class UserBotPlanGroups
{
    public static function displayName(string $name): string
    {
        return trim(preg_replace('/ \[#[0-9]+\]$/u', '', $name));
    }

    private static function identity(array $plan): string
    {
        if (preg_match('/^(\d+)_/', (string) ($plan['plan_key'] ?? ''), $match)) {
            return 'tariff:' . (int) $match[1];
        }
        return 'name:' . self::displayName((string) ($plan['group_name'] ?? 'طرح پیشفرض'));
    }

    public static function options(array $plans, bool $showSingleDuration = false): array
    {
        $groups = [];
        $keysByGroup = [];
        foreach ($plans as $plan) {
            $identity = self::identity($plan);
            $groups[$identity] ??= $plan;
            $keysByGroup[$identity][] = $plan['plan_key'] ?? '';
        }
        $names = [];
        foreach ($groups as $key => $plan) $names[$key] = self::displayName((string) ($plan['group_name'] ?? 'طرح پیشفرض'));
        $counts = array_count_values($names);
        $labels = [];
        foreach ($groups as $key => $plan) {
            $label = $names[$key];
            if ($counts[$label] > 1) {
                $service = match ($plan['service_type'] ?? '') { 'v2ray' => 'V2Ray، ', 'wireguard' => 'WireGuard، ', default => '' };
                $volume = str_ends_with((string) ($plan['plan_key'] ?? ''), 'volume_gig');
                $label .= ' — ' . $service . ($volume ? 'حجمی' : 'زمانی');
            }
            $duration = $showSingleDuration ? PurchasePlanDuration::label($keysByGroup[$key]) : null;
            $labels[$key] = $duration === null ? $label : $label . ' / ' . $duration;
        }
        $counts = array_count_values($labels);
        $ordinals = [];
        $options = [];
        foreach ($labels as $key => $base) {
            $label = $base;
            if ($counts[$base] > 1) {
                $ordinals[$base] = ($ordinals[$base] ?? 0) + 1;
                $label .= ' (گزینه ' . JalaliDateFormatter::persianDigits((string) $ordinals[$base]) . ')';
            }
            while (isset($options[$label]) || ($label !== $base && isset($counts[$label]))) {
                $ordinals[$base] = ($ordinals[$base] ?? 0) + 1;
                $label = $base . ' (گزینه ' . JalaliDateFormatter::persianDigits((string) $ordinals[$base]) . ')';
            }
            $options[$label] = $key;
        }
        return $options;
    }

    public static function select(array $plans, ?array $options, string $text): array
    {
        $label = trim(str_starts_with($text, '📁 ') ? substr($text, strlen('📁 ')) : $text);
        if ($label === '') return [];
        if ($options !== null) {
            $identity = $options[$label] ?? null;
            if ($identity === null) return [];
        } else {
            // Old sessions may still contain the generated [#id] suffix.
            $identities = [];
            foreach ($plans as $plan) {
                $name = (string) ($plan['group_name'] ?? 'طرح پیشفرض');
                if ($label === $name || $label === self::displayName($name)) $identities[self::identity($plan)] = true;
            }
            if (count($identities) !== 1) return [];
            $identity = array_key_first($identities);
        }
        return array_values(array_filter($plans, fn ($plan) => self::identity($plan) === $identity));
    }
}
