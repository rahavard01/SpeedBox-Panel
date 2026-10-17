<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\PanelAccountOwnership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PartnerAccountStatisticsController extends Controller
{
    public function __invoke(Request $request)
    {
        $input = $request->validate(['panel_code' => ['required', 'string', 'max:50']]);
        $code = strtolower(trim($input['panel_code']));
        abort_if($code === '', 422);
        $partner = DB::table('panel_users')->whereRaw('LOWER(TRIM(code)) = ?', [$code])->first(['code']);
        abort_unless($partner, 404);

        $now = time();
        $query = PanelAccountOwnership::scope(DB::table('v2_user as accounts'), (string) $partner->code, 'accounts.email');
        $query->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(accounts.banned, 0) = 0 AND COALESCE(accounts.u, 0) + COALESCE(accounts.d, 0) < COALESCE(accounts.transfer_enable, 0) AND (accounts.expired_at IS NULL OR accounts.expired_at = 0 OR accounts.expired_at > ?) THEN 1 ELSE 0 END), 0) AS active', [$now])
            ->selectRaw('COALESCE(SUM(CASE WHEN accounts.expired_at IS NOT NULL AND accounts.expired_at <> 0 AND accounts.expired_at <= ? THEN 1 ELSE 0 END), 0) AS expired', [$now]);

        if (Schema::hasTable('speedbox_connection_leases')) {
            // EXISTS counts each account once, even across several IPs/nodes.
            $query->selectRaw('COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM speedbox_connection_leases AS leases WHERE leases.user_id = accounts.id AND leases.expires_at > ?) THEN 1 ELSE 0 END), 0) AS online', [$now]);
        } else {
            $query->selectRaw('0 AS online');
        }
        $row = $query->first();
        return response()->json(['data' => [
            'total' => (int) $row->total,
            'active' => (int) $row->active,
            'online' => (int) $row->online,
            'expired' => (int) $row->expired,
        ]])->header('Cache-Control', 'no-store');
    }
}
