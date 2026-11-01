<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SubscriptionReservationHistory
{
    /** Add lifecycle lines to the original purchase, without creating financial rows. */
    public function attach(iterable $transactions): void
    {
        $transactions = collect($transactions);
        foreach ($transactions as $transaction) $transaction->reservation_history = [];
        if ($transactions->isEmpty() || !Schema::hasTable('speedbox_subscription_reservations')) return;

        $reservations = DB::table('speedbox_subscription_reservations')
            ->whereIn('transaction_id', $transactions->pluck('id')->all())->get()->keyBy('transaction_id');
        if ($reservations->isEmpty()) return;

        // Recover dates and actors for reservations recorded before lifecycle metadata existed.
        $operations = Schema::hasTable('speedbox_account_operations')
            ? DB::table('speedbox_account_operations as o')
                ->leftJoin('panel_users as p', 'p.id', '=', 'o.operator_id')
                ->whereIn('o.action', ['reservation_activate', 'reservation_cancel'])
                ->whereIn('o.metadata->reservation_id', $reservations->pluck('id')->all())
                ->orderBy('o.id')->get(['o.*', 'p.role as operator_role']) : collect();
        $events = [];
        foreach ($operations as $operation) {
            $metadata = json_decode($operation->metadata ?? '{}', true) ?: [];
            $events[(int) ($metadata['reservation_id'] ?? 0)][$operation->action] = [$operation, $metadata];
        }

        foreach ($transactions as $transaction) {
            $reservation = $reservations[$transaction->id] ?? null;
            if (!$reservation || $transaction->status !== 'success') continue;
            $meta = is_array($transaction->meta) ? $transaction->meta : (json_decode($transaction->meta ?? '{}', true) ?: []);
            [$activation, $activationMeta] = $events[$reservation->id]['reservation_activate'] ?? [null, []];
            [$cancellation, $cancellationMeta] = $events[$reservation->id]['reservation_cancel'] ?? [null, []];
            $wasDeferred = $reservation->state === 'reserved' || $reservation->state === 'cancelled'
                || !empty($meta['reservation_activated_at']) || !empty($activationMeta['automatic']);
            if (!$wasDeferred) continue; // Immediate renewals are not reservations in the UI.

            $transaction->reservation_history[] = [
                'state' => 'reserved', 'created_at' => $transaction->created_at,
                'performed_by_role' => TransactionOperator::displayRole($transaction->performed_by_role ?? null, $meta),
            ];
            if ($reservation->state === 'activated') {
                $transaction->reservation_history[] = [
                    'state' => 'activated', 'created_at' => $reservation->activated_at ?? $activation?->created_at,
                    'performed_by_role' => !empty($activationMeta['automatic']) || !empty($meta['reservation_activated_at'])
                        ? 'system' : TransactionOperator::displayRole($activationMeta['operator_role'] ?? $activation?->operator_role),
                ];
            } elseif ($reservation->state === 'cancelled') {
                $transaction->reservation_history[] = [
                    'state' => 'cancelled', 'created_at' => $meta['reservation_cancelled_at'] ?? $cancellation?->created_at ?? $reservation->updated_at,
                    'performed_by_role' => TransactionOperator::displayRole($meta['reservation_cancelled_by_role'] ?? $cancellationMeta['operator_role'] ?? $cancellation?->operator_role),
                ];
            }
        }
    }
}
