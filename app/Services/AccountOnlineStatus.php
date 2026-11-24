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
        $wg = $this->wireguardCounts($partnerCode) + $this->anyConnectCounts($partnerCode);
        return ['users' => (int) $totals->users_online+count($wg), 'ips' => (int) $totals->ips_online+array_sum($wg)];
    }

    private function wireguardCounts(?string $partnerCode=null): array {
        $q=DB::table('speedbox_wireguard_peers as p')->join('v2_user','v2_user.id','=','p.user_id')
            ->join('speedbox_wireguard_instances as i','i.id','=','p.instance_id')
            ->where('p.enabled',1)->where('i.enabled',1)->where('i.status','ready')->where('i.last_synced_at','>',now()->subMinutes(3))
            ->where('p.last_handshake','>',time()-180)->where('v2_user.banned',0)->whereRaw('(v2_user.u+v2_user.d)<v2_user.transfer_enable');
        SubscriptionValidity::whereUnexpired($q,'v2_user.expired_at');
        if($partnerCode!==null)PanelAccountOwnership::scope($q,$partnerCode);
        return $q->select('p.user_id')->selectRaw('COUNT(*) AS total')->groupBy('p.user_id')->pluck('total','p.user_id')->map(fn($n)=>(int)$n)->all();
    }

    private function anyConnectCounts(?string $partnerCode = null): array
    {
        if (!DB::connection()->getSchemaBuilder()->hasTable('speedbox_anyconnect_accounts')) return [];
        $query = DB::table('speedbox_anyconnect_accounts as ac')->join('v2_user', 'v2_user.id', '=', 'ac.user_id')
            ->join('speedbox_anyconnect_instances as i', 'i.id', '=', 'ac.instance_id')
            ->where('i.enabled', true)->where('i.status', 'ready')->where('i.last_synced_at', '>', now()->subMinutes(3))
            ->where('ac.online', '>', 0)->where('v2_user.banned', 0)->whereRaw('(v2_user.u+v2_user.d)<v2_user.transfer_enable');
        SubscriptionValidity::whereUnexpired($query, 'v2_user.expired_at');
        if ($partnerCode !== null) PanelAccountOwnership::scope($query, $partnerCode);
        return $query->pluck('ac.online', 'ac.user_id')->map(fn ($count) => (int) $count)->all();
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
        $counts=DB::table('speedbox_connection_leases')
            ->whereIn('user_id', $ids)->where('expires_at', '>', time())
            ->select('user_id')->selectRaw('COUNT(DISTINCT source_ip) AS online_count')
            ->groupBy('user_id')->pluck('online_count', 'user_id')->map(fn ($count) => (int) $count)->all();
        return array_replace($counts,array_intersect_key($this->wireguardCounts() + $this->anyConnectCounts(),array_flip($ids)));
    }
}
