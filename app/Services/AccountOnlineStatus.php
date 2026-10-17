<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class AccountOnlineStatus
{
    public function summary(?string $partnerCode = null): array
    {
        $accounts = DB::table('v2_user');
        if ($partnerCode !== null) PanelAccountOwnership::scope($accounts, $partnerCode);
        $pairs = DB::table('speedbox_connection_leases as leases')
            ->whereIn('leases.user_id', $accounts->select('id'))
            ->where('leases.expires_at', '>', time())
            ->select('leases.user_id', 'leases.source_ip')->distinct();
        $totals = DB::query()->fromSub($pairs, 'active_pairs')
            ->selectRaw('COUNT(DISTINCT user_id) AS users_online, COUNT(*) AS ips_online')->first();
        return ['users' => (int) $totals->users_online, 'ips' => (int) $totals->ips_online];
    }

    public function activeIpTotal(?string $partnerCode = null): int
    {
        return $this->summary($partnerCode)['ips'];
    }

    /** One query for a whole node list. Aliases pass the ID of their runtime node. */
    public function nodeUserCounts(array $nodeIds): array
    {
        $nodeIds = array_values(array_unique(array_filter(array_map('intval', $nodeIds), fn ($id) => $id > 0)));
        if (!$nodeIds) return [];
        return DB::table('speedbox_connection_leases')
            ->whereIn('node_id', $nodeIds)->where('expires_at', '>', time())
            ->whereIn('user_id', DB::table('v2_user')->select('id'))
            ->select('node_id')->selectRaw('COUNT(DISTINCT user_id) AS users_online')
            ->groupBy('node_id')->pluck('users_online', 'node_id')->map(fn ($count) => (int) $count)->all();
    }

    public function counts(array $ids): array
    {
        if (!$ids) return [];
        return DB::table('speedbox_connection_leases')
            ->whereIn('user_id', $ids)->where('expires_at', '>', time())
            ->select('user_id')->selectRaw('COUNT(DISTINCT source_ip) AS online_count')
            ->groupBy('user_id')->pluck('online_count', 'user_id')->map(fn ($count) => (int) $count)->all();
    }
}
