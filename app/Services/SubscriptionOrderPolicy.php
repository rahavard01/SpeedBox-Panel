<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SubscriptionOrderPolicy
{
    public function prepare(object $user, array $update): array
    {
        // Every time-account extension follows the same rule, including a different selected plan.
        $expired = !SubscriptionValidity::unexpired($user->expired_at ?? null);
        $reset = $expired || (int) RuntimeSetting::get('renew_order_event_id', 1) === 1;
        $update['u'] = $reset ? 0 : (int) $user->u;
        $update['d'] = $reset ? 0 : (int) $user->d;
        $update['traffic_warning_version'] = (int) ($user->traffic_warning_version ?? 0) + 1;
        $nextUser = (object) array_merge((array) $user, $update);
        $update['next_reset_at'] = app(SubscriptionTrafficReset::class)->next($nextUser);
        if ($reset) {
            $update['last_reset_at'] = time();
            $update['reset_count'] = (int) ($user->reset_count ?? 0) + 1;
        }
        return $update;
    }

    /** Track paid value in the Panel ledger's currency, without changing XBoard's account or order columns. */
    public function recordPurchase(object $transaction, array $meta): void
    {
        if ($transaction->status !== 'success' || !in_array($transaction->type, ['account_purchase', 'account_extend'], true)) return;
        $ids = array_values(array_unique(array_filter(array_map('intval', $meta['user_ids'] ?? [$transaction->reference_id]))));
        $divisor = max(1, (int) ($meta['account_count'] ?? $meta['requested_qty'] ?? $transaction->quantity ?? count($ids)));
        if (count($ids) === 1 && $transaction->type === 'account_extend') $divisor = 1;
        $paid = bcdiv((string) $transaction->amount, (string) $divisor, 0);
        DB::transaction(function () use ($ids, $transaction, $paid) {
            foreach ($ids as $id) {
                $user = DB::table('v2_user')->where('id', $id)->lockForUpdate()->first();
                if (!$user) continue;
                $cycle = DB::table('speedbox_subscription_cycles')->where('user_id', $id)->lockForUpdate()->first();
                if ($cycle && (int) $cycle->last_transaction_id >= (int) $transaction->id) continue;
                $freeRenewal = $cycle && $transaction->type === 'account_extend' && bccomp($paid, '0', 0) === 0 && (int) $cycle->plan_id === (int) $user->plan_id;
                $sameCycle = $cycle && (int) $cycle->plan_id === (int) $user->plan_id && ($freeRenewal || ((int) $cycle->panel_user_id === (int) $transaction->panel_user_id && (!$cycle->expired_at || (int) $cycle->expired_at > time())));
                $data = [
                    'panel_user_id' => $sameCycle ? (int) $cycle->panel_user_id : (int) $transaction->panel_user_id,
                    'plan_id' => $user->plan_id,
                    'paid_amount' => $sameCycle ? bcadd((string) $cycle->paid_amount, $paid, 0) : $paid,
                    'started_at' => $sameCycle ? $cycle->started_at : time(),
                    'expired_at' => $freeRenewal ? $cycle->expired_at : $user->expired_at,
                    'last_transaction_id' => $transaction->id,
                    'updated_at' => now(),
                ];
                if ($cycle) DB::table('speedbox_subscription_cycles')->where('user_id', $id)->update($data);
                else DB::table('speedbox_subscription_cycles')->insert(['user_id' => $id, 'created_at' => now()] + $data);
            }
        });
    }
}
