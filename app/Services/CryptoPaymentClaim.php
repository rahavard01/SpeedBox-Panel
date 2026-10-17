<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** One permanent owner per chain transaction, shared by both payment paths. */
final class CryptoPaymentClaim
{
    public function claim(string $network, string $hash, string $ownerType, int $ownerId): bool
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Payment claims require an enclosing transaction.');
        $network = strtoupper(trim($network));
        $hash = strtolower(trim($hash));
        if (str_starts_with($hash, '0x')) $hash = substr($hash, 2);
        if ($hash === '' || strlen($hash) > 255 || !in_array($ownerType, ['user_bot_order', 'panel_crypto_topup'], true) || $ownerId < 1) {
            throw new \InvalidArgumentException('Invalid payment identity.');
        }
        $key = hash('sha256', $network.':'.$hash);
        DB::table('speedbox_crypto_payment_claims')->insertOrIgnore([
            'id' => $key, 'network' => $network, 'tx_hash' => $hash,
            'owner_type' => null, 'owner_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $claim = DB::table('speedbox_crypto_payment_claims')->where('id', $key)->lockForUpdate()->first();
        if ($claim->owner_type !== null) return $claim->owner_type === $ownerType && (int) $claim->owner_id === $ownerId;

        // Protect payments recorded before this table existed, including paid
        // orders whose fulfillment failed. Duplicate-rejected records are not owners.
        $hashes = [$hash, '0x'.$hash];
        $legacy = [];
        foreach (DB::table('panel_crypto_topups')->whereRaw('UPPER(network) = ?', [$network])
            ->whereIn(DB::raw('LOWER(TRIM(tx_hash))'), $hashes)
            ->where(fn ($q) => $q->where('status', 'paid')->orWhereNotNull('paid_at'))->pluck('id') as $id) {
            $legacy[] = ['panel_crypto_topup', (int) $id];
        }
        foreach (DB::table('user_bot_orders')->where('payment_method', 'crypto')->whereRaw('UPPER(crypto_network) = ?', [$network])
            ->whereIn(DB::raw('LOWER(TRIM(crypto_tx_hash))'), $hashes)
            ->where(fn ($q) => $q->whereIn('status', ['done', 'crypto_paid', 'crypto_checking', 'crypto_retry_pending'])->orWhereNotNull('crypto_paid_at'))->pluck('id') as $id) {
            $legacy[] = ['user_bot_order', (int) $id];
        }
        // When historical records conflict, preserve another recorded owner;
        // do not turn a pre-existing duplicate into permission to fulfill again.
        $owner = [$ownerType, $ownerId];
        foreach ($legacy as $candidate) if ($candidate !== $owner) { $owner = $candidate; break; }
        DB::table('speedbox_crypto_payment_claims')->where('id', $key)->update([
            'owner_type' => $owner[0], 'owner_id' => $owner[1], 'updated_at' => now(),
        ]);
        return $owner === [$ownerType, $ownerId];
    }
}
