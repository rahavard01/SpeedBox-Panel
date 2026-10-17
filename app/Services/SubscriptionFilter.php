<?php

namespace App\Services;

use App\Models\ProxyServer;

final class SubscriptionFilter
{
    public static function types(?string $input): array
    {
        if (blank($input) || $input === 'all') return ProxyServer::TYPES;
        $types = array_filter(array_map('trim', preg_split('/[|,｜]+/', $input)));
        return array_values(array_intersect($types, ProxyServer::TYPES));
    }

    public static function keywords(?string $input): ?array
    {
        if (blank($input) || mb_strlen($input) > 20) return null;
        return array_values(array_filter(array_map('trim', preg_split('/[|,｜]+/', $input))));
    }

    public static function matches(array $server, array $types, ?array $keywords): bool
    {
        if ($types && !in_array($server['type'], $types, true)) return false;
        if (!$keywords) return true;
        foreach ($keywords as $keyword) {
            if (stripos($server['name'] ?? '', $keyword) !== false
                || in_array($keyword, $server['tags'] ?? [])) return true;
        }
        return false;
    }
}
