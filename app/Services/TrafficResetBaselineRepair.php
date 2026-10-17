<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Repair only the extra creation count proved by our purchase ledger and logs. */
final class TrafficResetBaselineRepair
{
    public function run(bool $apply = false): array
    {
        $result = ['mode' => $apply ? 'apply' : 'preview', 'accounts' => []];
        DB::table('panel_transactions')->where('type', 'account_purchase')->where('status', 'success')
            ->where('reference_type', 'v2_user')->orderBy('id')->chunkById(200, function ($transactions) use ($apply, &$result) {
                foreach ($transactions as $transaction) {
                    $meta = json_decode($transaction->meta ?? '{}', true);
                    if (!is_array($meta) || !is_array($meta['user_ids'] ?? null)) continue;
                    foreach (array_unique(array_map('intval', $meta['user_ids'])) as $id) {
                        if ($id <= 0 || isset($result['accounts'][$id])) continue;
                        $change = DB::transaction(function () use ($id, $transaction, $apply) {
                            $user = DB::table('v2_user')->where('id', $id)->lockForUpdate()->first();
                            if (!$user || (int) $user->reset_count < 1 || !(int) $user->created_at) return null;
                            // Imported accounts without a matching original purchase are untouched.
                            $purchaseAt = CarbonImmutable::parse($transaction->created_at, config('app.timezone'))->timestamp;
                            if (abs($purchaseAt - (int) $user->created_at) > 60) return null;
                            $logs = DB::table('v2_traffic_reset_logs')->where('user_id', $id)->orderBy('id')->get();
                            if ((int) $user->reset_count !== $logs->count() + 1) return null;
                            $last = null;
                            foreach ($logs as $log) {
                                if (!in_array($log->reset_type, ['manual', 'periodic', 'renewal'], true)) return null;
                                $at = CarbonImmutable::parse($log->reset_time, config('app.timezone'))->timestamp;
                                if ($at < (int) $user->created_at) return null;
                                $last = max($last ?? 0, $at);
                            }
                            // Incomplete or inconsistent history must never be guessed away.
                            if ((int) $user->last_reset_at !== ($last ?? (int) $user->created_at)) return null;
                            $change = ['id' => $id, 'before_count' => (int) $user->reset_count,
                                'after_count' => $logs->count(), 'before_last' => $user->last_reset_at,
                                'after_last' => $last];
                            if ($apply) DB::table('v2_user')->where('id', $id)->update([
                                'reset_count' => $logs->count(), 'last_reset_at' => $last,
                            ]);
                            return $change;
                        }, 3);
                        if ($change !== null) $result['accounts'][$id] = $change;
                    }
                }
            });
        $result['accounts'] = array_values($result['accounts']);
        $result['count'] = count($result['accounts']);
        return $result;
    }
}
