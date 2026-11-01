<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

final class SubscriptionReservationStatistics
{
    /** Exclude the original cancelled purchase; its created_at still determines its reporting day. */
    public static function excludeCancelled(Builder $query, string $transactionId = 'panel_transactions.id'): void
    {
        if (!Schema::hasTable('speedbox_subscription_reservations')) return;
        $query->whereNotExists(function (Builder $cancelled) use ($transactionId) {
            $cancelled->selectRaw('1')->from('speedbox_subscription_reservations as cancelled_reservation')
                ->whereColumn('cancelled_reservation.transaction_id', $transactionId)
                ->where('cancelled_reservation.state', 'cancelled');
        });
    }
}
