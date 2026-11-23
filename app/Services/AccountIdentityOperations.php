<?php

namespace App\Services;

use App\Jobs\SyncWireGuard;
use App\Models\UserBotSession;
use App\Models\WireGuardPeer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AccountIdentityOperations
{
    public const FIELDS = ['id','service_type','wireguard_instance_id', 'email', 'u', 'd', 'transfer_enable', 'expired_at', 'plan_id', 'group_id',
        'speed_limit', 'device_limit', 'banned', 'remarks', 'account_plan_name', 'account_group_type',
        'account_tariff_group_id', 'account_plan_key', 'reset_count', 'last_reset_at', 'next_reset_at', 'updated_at'];

    // Callers authorize the operation and hold the account lock in a transaction.
    public static function rotate(object $user, object $operator): object
    {
        $id = (int) $user->id;
        DB::table('v2_user')->where('id', $id)->update([
            'uuid' => (string) Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'updated_at' => time(),
        ]);
        if (($user->service_type ?? 'v2ray') === 'wireguard') {
            foreach (WireGuardPeer::where('user_id', $id)->orderBy('id')->lockForUpdate()->get() as $peer) {
                [$private, $public] = WireGuardService::keyPair();
                $peer->update([
                    'retired_keys' => [...($peer->retired_keys ?? []), $peer->public_key],
                    'private_key' => $private,
                    'public_key' => $public,
                    'preshared_key' => base64_encode(random_bytes(32)),
                    'last_handshake' => 0,
                ]);
            }
            SyncWireGuard::dispatch((int) $user->wireguard_instance_id)->afterCommit();
        }
        DB::table('speedbox_connection_leases')->where('user_id', $id)->delete();
        $after = DB::table('v2_user')->where('id', $id)->first();
        self::syncBotSessions($user, $after);
        self::audit($user, $operator, 'reset_secret');

        return $after;
    }

    public static function publicAccount(object $user): array
    {
        app(\App\Services\SubscriptionReservationPresenter::class)->attach([$user]);
        return array_intersect_key((array) $user, array_flip(array_merge(self::FIELDS, ['reservation', 'account_has_periodic_reset', 'account_ended'])));
    }

    public static function audit(object $user, ?object $operator, string $action, array $metadata = []): void
    {
        DB::table('speedbox_account_operations')->insert([
            'user_id' => $user->id, 'account_email' => $user->email,
            'operator_id' => $operator?->id, 'operator_email' => $operator?->email,
            'action' => $action, 'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public static function hasOpenBotOrders(object $user): bool
    {
        return Schema::hasTable('user_bot_orders') && DB::table('user_bot_orders')->where('account_email', $user->email)
            ->whereNotIn('status', ['done', 'rejected', 'failed', 'expired', 'cancelled', 'invalid'])->exists();
    }

    public static function syncBotSessions(object $before, ?object $after): void
    {
        $sessions = UserBotSession::where('account_email', $before->email)
            ->orWhere('account_snapshot->id', $before->id)->lockForUpdate()->get();
        foreach ($sessions as $session) {
            if ($after === null) {
                $session->fill(['is_logged_in' => false, 'account_email' => null, 'account_sub_link' => null,
                    'account_snapshot' => null, 'state' => null, 'state_data' => null]);
            } else {
                $snapshot = $session->account_snapshot ?: [];
                $snapshot = array_merge($snapshot, self::publicAccount($after));
                $session->account_email = $after->email;
                $session->account_snapshot = $snapshot;
                if ($before->token !== $after->token && $session->account_sub_link) {
                    $session->account_sub_link = preg_replace('/(?<![A-Za-z0-9_-])' . preg_quote($before->token, '/') . '(?![A-Za-z0-9_-])/', $after->token, $session->account_sub_link);
                }
            }
            $session->save();
        }
    }
}
