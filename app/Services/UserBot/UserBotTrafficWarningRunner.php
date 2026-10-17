<?php

namespace App\Services\UserBot;

use App\Models\UserBotSession;
use App\Models\UserBotSetting;
use App\Services\RuntimeSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class UserBotTrafficWarningRunner
{
    private const LOCK_NAME = 'panel:userbot-traffic-warning';

    public function __construct(private UserBotAccountResolver $accountResolver) {}

    public function tick(): int
    {
        if (!RuntimeSetting::get('userbot_remind_traffic_enable', true)
            || !Schema::hasTable('user_bot_settings')
            || !Schema::hasColumn('user_bot_sessions', 'traffic_warning_for')
            || !Schema::hasColumn('v2_user', 'traffic_warning_version')) {
            return 0;
        }
        $mysql = DB::connection()->getDriverName() === 'mysql';
        if ($mysql && (int) (DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [self::LOCK_NAME])->acquired ?? 0) !== 1) {
            return 0;
        }
        $sent = 0;
        try {
            DB::table('user_bot_sessions as sessions')
                ->join('user_bot_settings as settings', 'settings.id', '=', 'sessions.setting_id')
                ->join('v2_user as accounts', 'accounts.email', '=', 'sessions.account_email')
                ->where('sessions.is_logged_in', 1)->where('settings.is_enabled', 1)
                ->whereNotNull('settings.bot_token')->where('settings.bot_token', '<>', '')
                ->whereNotNull('sessions.telegram_chat_id')->whereNotNull('sessions.account_sub_link')
                ->where('accounts.banned', 0)->where('accounts.transfer_enable', '>', 0)
                ->tap(fn ($query) => \App\Services\SubscriptionValidity::whereUnexpired($query, 'accounts.expired_at'))
                // Purchased traffic is represented by negative d; avoid unsigned MySQL arithmetic.
                ->whereRaw('(CAST(accounts.u AS DECIMAL(30,0)) + CAST(accounts.d AS DECIMAL(30,0))) >= accounts.transfer_enable * 0.8')
                ->select('sessions.id as session_id')
                ->chunkById(200, function ($sessions) use (&$sent) {
                    foreach ($sessions as $session) {
                        if ($this->sendWarning((int) $session->session_id)) $sent++;
                    }
                }, 'sessions.id', 'session_id');
            return $sent;
        } finally {
            if ($mysql) DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        }
    }

    private function sendWarning(int $sessionId): bool
    {
        try {
            if (!RuntimeSetting::get('userbot_remind_traffic_enable', true)) return false;
            $session = UserBotSession::query()->find($sessionId);
            if (!$session || !$session->is_logged_in || trim((string) $session->telegram_chat_id) === '' || !$session->account_sub_link) return false;
            $setting = UserBotSetting::query()->find($session->setting_id);
            if (!$setting || !$setting->is_enabled || trim((string) $setting->bot_token) === '') return false;
            // Resolve the current account by token and verify that it belongs to this bot's owner.
            $account = $this->accountResolver->findCurrent($setting, $session->account_sub_link);
            if (!$account) return false;
            $snapshot = $account['snapshot'];
            $total = (int) ($snapshot['transfer_enable'] ?? 0);
            $used = max(0, (int) ($snapshot['u'] ?? 0) + (int) ($snapshot['d'] ?? 0));
            $expiry = (int) ($snapshot['expired_at'] ?? 0);
            if (!empty($snapshot['banned']) || !\App\Services\SubscriptionValidity::unexpired($snapshot['expired_at'] ?? null) || $total <= 0 || $used < $total * 0.8) return false;
            // Usage is deliberately excluded: receiving more traffic must not repeat the same warning.
            $fingerprint = hash('sha256', implode('|', [
                $snapshot['id'], $snapshot['traffic_warning_version'] ?? 0,
                $total, $expiry, $snapshot['last_reset_at'] ?? 0, $snapshot['reset_count'] ?? 0,
            ]));
            if ($session->traffic_warning_for === $fingerprint) return false;
            $percent = number_format($used / $total * 100, 1);
            $text = "⚠️ <b>هشدار مصرف ترافیک</b>\n\n"
                . "مصرف ترافیک اکانت شما به <b>{$percent}%</b> رسیده است.\n\n"
                . $this->accountResolver->trafficText($snapshot)
                . "\n\nبرای ادامه استفاده، از گزینه «➕ خرید ترافیک» استفاده کنید.";
            (new UserBotTelegram((string) $setting->bot_token))->sendMessage(
                $session->telegram_chat_id, $text,
                UserBotKeyboard::user($this->accountResolver->isVolumeAccount($snapshot))
            );
            $session->forceFill([
                'account_email' => $account['email'], 'account_snapshot' => $snapshot,
                'traffic_warning_for' => $fingerprint, 'traffic_warning_sent_at' => now(),
            ])->save();
            return true;
        } catch (Throwable $e) {
            Log::warning('User bot traffic warning failed.', ['session_id' => $sessionId, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
