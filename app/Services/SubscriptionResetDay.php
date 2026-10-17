<?php

namespace App\Services;

use Carbon\Carbon;

/** Read-only display of the same policy used by the traffic reset scheduler. */
final class SubscriptionResetDay
{
    public function forUser(object $user): ?int
    {
        // Check the current policy before trusting a previously stored date:
        // switching to volume/no-reset must not advertise a future reset.
        $next = $this->next($user);
        if ($next === null) return null;
        $timestamp = (int) ($user->next_reset_at ?? $next->timestamp);
        return max(0, (int) ceil(($timestamp - time()) / 86400));
    }

    public function next(object $user): ?Carbon
    {
        if (($user->banned ?? false) || !SubscriptionValidity::unexpired($user->expired_at ?? null)) return null;
        $timestamp = app(SubscriptionTrafficReset::class)->next($user);
        return $timestamp === null ? null : Carbon::createFromTimestamp($timestamp, 'Asia/Tehran');
    }
}
