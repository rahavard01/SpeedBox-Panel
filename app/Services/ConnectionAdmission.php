<?php

namespace App\Services;

use App\Models\ProxyServer;
use Illuminate\Support\Facades\DB;

final class ConnectionAdmission
{
    public const TTL = 60;

    public function handle(ProxyServer $node, array $input): array
    {
        $ip = inet_ntop(inet_pton($input['source_ip']));
        if (str_starts_with($ip, '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = substr($ip, 7);
        return DB::transaction(function () use ($node, $input, $ip) {
            // One account lock serializes admission across every node and both kernels.
            $user = DB::table('v2_user')->where('id', $input['user_id'])->lockForUpdate()
                ->first(['id', 'device_limit', 'group_id', 'banned', 'expired_at', 'u', 'd', 'transfer_enable']);
            if (!$user) return ['allowed' => false];
            if (DB::connection()->getDriverName() === 'sqlite') {
                // SQLite has no row locks: acquire its write lock before counting.
                // A concurrent stale snapshot is retried by the transaction below.
                DB::table('v2_user')->where('id', $user->id)->update(['device_limit' => $user->device_limit]);
            }
            $leases = DB::table('speedbox_connection_leases')->where('user_id', $user->id);
            $own = (clone $leases)->where('node_id', $node->id)->where('source_ip', $ip);
            if ($input['operation'] === 'release') {
                (clone $own)->where('lease_id', $input['lease_id'])->delete();
                return ['allowed' => true];
            }
            $now = time();
            (clone $leases)->where('expires_at', '<=', $now)->delete();
            // Bound the lifetime even if a retry renews a no-longer-owned lease.
            $current = (clone $own)->first();
            if ($input['operation'] === 'renew' && (!$current || !hash_equals($current->lease_id, $input['lease_id']))) return ['allowed' => false];
            $groupId = (int) $user->group_id;
            $hasGroup = in_array($groupId, array_map('intval', $node->group_ids ?: []), true);
            // A child can only add access; avoid loading every child and its
            // protocol/certificate configuration when the parent already grants it.
            if (!$hasGroup) {
                foreach (ProxyServer::where('parent_id', $node->id)->where('enabled', true)
                    ->get(['group_ids', 'transfer_enable', 'u', 'd']) as $child) {
                    if ((!$child->transfer_enable || $child->u + $child->d < $child->transfer_enable)
                        && in_array($groupId, array_map('intval', $child->group_ids ?: []), true)) {
                        $hasGroup = true;
                        break;
                    }
                }
            }
            if (($node->transfer_enable && $node->u + $node->d >= $node->transfer_enable)
                || !$hasGroup
                || $user->banned || !SubscriptionValidity::unexpired($user->expired_at, $now)
                || (int) $user->u + (int) $user->d >= (int) $user->transfer_enable) return ['allowed' => false];
            $limit = (int) ($user->device_limit ?? 0);
            // Keep IP aggregation for every account, including unlimited ones.
            $ips = (clone $leases)->select('source_ip')->selectRaw('MIN(id) AS first_id')
                ->groupBy('source_ip')->orderBy('first_id')->pluck('source_ip')->all();
            $index = array_search($ip, $ips, true);
            // Existing addresses count once across nodes. On a reduced limit,
            // renew only the oldest addresses until the other links drain.
            if ($limit > 0 && (($index === false && count($ips) >= $limit) || ($index !== false && $index >= $limit))) return ['allowed' => false];
            // The existing unique owner key makes this one round trip, preserving
            // the row ID used to order addresses when a device limit is reduced.
            DB::table('speedbox_connection_leases')->upsert([[
                'user_id' => $user->id, 'node_id' => $node->id, 'source_ip' => $ip,
                'lease_id' => $input['lease_id'], 'expires_at' => $now + self::TTL,
            ]], ['user_id', 'node_id', 'source_ip'], ['lease_id', 'expires_at']);
            return ['allowed' => true, 'ttl' => self::TTL];
        }, 3);
    }
}

