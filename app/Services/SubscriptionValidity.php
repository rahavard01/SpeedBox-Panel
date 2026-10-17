<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

/** Zero and NULL have no current expiry, independently of account role or ID. */
final class SubscriptionValidity
{
    public static function hasExpiry(mixed $expiry): bool
    {
        return $expiry !== null && $expiry !== 0 && $expiry !== '0';
    }

    public static function unexpired(mixed $expiry, ?int $now = null): bool
    {
        return !self::hasExpiry($expiry) || (int) $expiry > ($now ?? time());
    }

    public static function whereUnexpired(Builder $query, string $column = 'expired_at', ?int $now = null): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, 0)->orWhere($column, '>', $now ?? time()));
    }
}
