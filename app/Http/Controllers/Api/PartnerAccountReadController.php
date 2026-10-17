<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountOnlineStatus;
use App\Services\PanelAccountOwnership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PartnerAccountReadController extends Controller
{
    private function accounts(Request $request)
    {
        $owner = $request->user();
        abort_unless($owner, 401);
        $query = DB::table('v2_user');
        if ((int) $owner->role !== 1) {
            abort_if(trim((string) $owner->code) === '', 403, 'کد پنل شما تنظیم نشده است');
            PanelAccountOwnership::scope($query, (string) $owner->code);
        }
        return $query;
    }

    public function onlineSummary(Request $request, AccountOnlineStatus $online)
    {
        $this->accounts($request); // Apply the same authentication/blank-code rules as account reads.
        $owner = $request->user();
        $counts = $online->summary((int) $owner->role === 1 ? null : (string) $owner->code);
        return response()->json(['data' => ['usersOnline' => $counts['users'], 'onlineIps' => $counts['ips']]])
            ->header('Cache-Control', 'no-store');
    }

    public function online(Request $request, AccountOnlineStatus $online)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $accounts = $this->accounts($request)->whereIn('id', $data['ids'])->get(['id', 'device_limit']);
        abort_unless($accounts->count() === count($data['ids']), 404);
        $counts = $online->counts($accounts->pluck('id')->all());
        return response()->json(['data' => $accounts->map(fn ($account) => [
            'id' => (int) $account->id, 'online_count' => $counts[$account->id] ?? 0,
            'device_limit' => $account->device_limit === null ? null : (int) $account->device_limit,
        ])])->header('Cache-Control', 'no-store');
    }

    public function details(Request $request, int $id)
    {
        $account = $this->accounts($request)->where('id', $id)->first([
            'id', 'email', 'token', 'u', 'd', 't', 'transfer_enable', 'expired_at', 'plan_id', 'group_id',
            'speed_limit', 'device_limit', 'banned', 'remarks', 'account_plan_name', 'account_group_type',
            'account_tariff_group_id', 'account_plan_key', 'reset_count', 'last_reset_at', 'next_reset_at',
            'remind_expire', 'remind_traffic', 'created_at', 'updated_at',
        ]);
        abort_unless($account, 404);
        // Imported accounts may predate the stored tariff snapshot. Use the same
        // fallback as the list until a purchase/renewal saves that snapshot.
        if ($account->plan_id && ($account->account_plan_name === null || $account->account_group_type === null || $account->account_tariff_group_id === null)) {
            $fallback = DB::table('panel_plan as pp')->join('panel_tariff_groups as tg', 'tg.id', '=', 'pp.tariff_group_id')
                ->where('pp.v2_plan_id', $account->plan_id)
                ->selectRaw("MIN(tg.name) as name, MIN(pp.tariff_group_id) as group_id, MAX(CASE WHEN tg.group_type = 'volume' OR pp.plan_key = 'volume_gig' THEN 1 ELSE 0 END) as is_volume")
                ->first();
            $account->account_plan_name ??= $fallback?->name;
            $account->account_tariff_group_id ??= $fallback?->group_id;
            $account->account_group_type ??= $fallback?->is_volume ? 'volume' : 'time';
        }
        $account->account_is_volume = $account->account_group_type === 'volume' || $account->account_plan_key === 'volume_gig' ? 1 : 0;
        $token = (string) ($account->token ?? '');
        $account->subscribe_url = $token === '' ? '' : \App\Utils\Helper::getSubscribeUrl($token);
        return response()->json(['data' => $account])->header('Cache-Control', 'no-store');
    }

    public function resetHistory(Request $request, int $id)
    {
        $account = $this->accounts($request)->where('id', $id)->first(['id', 'email', 'reset_count', 'last_reset_at', 'next_reset_at']);
        abort_unless($account, 404);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        // Deliberately omit operator identities, internal metadata and credentials.
        $logs = DB::table('v2_traffic_reset_logs')->where('user_id', $id)->orderByDesc('id')
            ->paginate(10, ['id', 'reset_time', 'reset_type', 'trigger_source', 'old_upload', 'old_download', 'old_total', 'new_upload', 'new_download', 'new_total', 'metadata']);
        $items = collect($logs->items())->map(function ($log) {
            $metadata = json_decode($log->metadata ?? '{}', true);
            $log->reason = is_array($metadata) && is_string($metadata['reason'] ?? null) ? $metadata['reason'] : '';
            unset($log->metadata);
            return $log;
        });
        return response()->json([
            'data' => $items,
            'account' => ['id' => (int) $account->id, 'email' => $account->email, 'reset_count' => (int) $account->reset_count,
                'last_reset_at' => $account->last_reset_at === null ? null : (int) $account->last_reset_at,
                'next_reset_at' => $account->next_reset_at === null ? null : (int) $account->next_reset_at],
            'pagination' => ['page' => $logs->currentPage(), 'per_page' => 10, 'total' => $logs->total(), 'last_page' => $logs->lastPage()],
        ])->header('Cache-Control', 'no-store');
    }
}
