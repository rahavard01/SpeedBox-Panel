<?php

namespace App\Services\UserBot;

use App\Models\UserBotSession;
use App\Models\UserBotSetting;
use App\Support\JalaliDateFormatter;
use App\Services\RuntimeSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class UserBotExpiryWarningRunner
{
    private const WARNING_WINDOW_SECONDS = 86400;
    private const LOCK_NAME = 'panel:userbot-expiry-warning';

    public function __construct(
        private UserBotAccountResolver $accountResolver
    ) {}

    public function tick(): int
    {
        if (
            !RuntimeSetting::get('userbot_remind_expire_enable', true)
            || !Schema::hasTable('user_bot_sessions')
            || !Schema::hasTable('user_bot_settings')
            || !Schema::hasTable('v2_user')
            || !Schema::hasColumn('user_bot_sessions', 'expiry_warning_account_id')
        ) {
            return 0;
        }

        if (!$this->acquireLock()) {
            return 0;
        }

        try {
            $now = time();
            $sent = 0;
            $this->candidateSessions($now)->select('sessions.id as session_id')
                ->chunkById(200, function ($sessions) use ($now, &$sent) {
                    foreach ($sessions as $session) {
                        if ($this->sendWarning((int) $session->session_id, $now)) $sent++;
                    }
                }, 'sessions.id', 'session_id');

            return $sent;
        } finally {
            $this->releaseLock();
        }
    }

    private function candidateSessions(int $now): \Illuminate\Database\Query\Builder
    {
        return DB::table('user_bot_sessions as sessions')
            ->join(
                'user_bot_settings as settings',
                'settings.id',
                '=',
                'sessions.setting_id'
            )
            ->join(
                'v2_user as accounts',
                'accounts.email',
                '=',
                'sessions.account_email'
            )
            ->where('sessions.is_logged_in', 1)
            ->where('settings.is_enabled', 1)
            ->whereNotNull('settings.bot_token')
            ->where('settings.bot_token', '<>', '')
            ->whereNotNull('sessions.telegram_chat_id')
            ->whereNotNull('sessions.account_sub_link')
            ->whereNotNull('accounts.expired_at')
            ->where('accounts.banned', 0)
            ->where('accounts.expired_at', '>', $now)
            ->where(
                'accounts.expired_at',
                '<=',
                $now + self::WARNING_WINDOW_SECONDS
            )
            ->where(function ($query) {
                $query->whereNull('sessions.expiry_warning_for')
                    ->orWhereNull('sessions.expiry_warning_account_id')
                    ->orWhereColumn('sessions.expiry_warning_account_id', '<>', 'accounts.id')
                    ->orWhereColumn(
                        'sessions.expiry_warning_for',
                        '<>',
                        'accounts.expired_at'
                    );
            });
    }

    private function sendWarning(int $sessionId, int $now): bool
    {
        try {
            if (!RuntimeSetting::get('userbot_remind_expire_enable', true)) return false;
            $session = UserBotSession::query()->find($sessionId);

            if (
                !$session
                || !$session->is_logged_in
                || trim((string) $session->telegram_chat_id) === ''
                || trim((string) $session->account_sub_link) === ''
            ) {
                return false;
            }

            $setting = UserBotSetting::query()->find($session->setting_id);

            if (
                !$setting
                || !$setting->is_enabled
                || trim((string) $setting->bot_token) === ''
            ) {
                return false;
            }

            $account = $this->accountResolver->findCurrent(
                $setting,
                $session->account_sub_link
            );

            if (!$account) {
                return false;
            }

            $snapshot = (array) ($account['snapshot'] ?? []);
            $expiredAt = (int) ($snapshot['expired_at'] ?? 0);

            if (
                !empty($snapshot['banned'])
                || $expiredAt <= $now
                || $expiredAt > $now + self::WARNING_WINDOW_SECONDS
                || ((int) $session->expiry_warning_for === $expiredAt
                    && (int) $session->expiry_warning_account_id === (int) $snapshot['id'])
            ) {
                return false;
            }

            $isVolume = $this->accountResolver->isVolumeAccount($snapshot);

            (new UserBotTelegram((string) $setting->bot_token))
                ->sendMessage(
                    $session->telegram_chat_id,
                    $this->warningText($snapshot, $expiredAt, $isVolume),
                    UserBotKeyboard::user($isVolume)
                );

            $session->forceFill([
                'account_email' => $account['email']
                    ?? $session->account_email,
                'account_snapshot' => $snapshot,
                'expiry_warning_for' => $expiredAt,
                'expiry_warning_account_id' => (int) $snapshot['id'],
                'expiry_warning_sent_at' => now(),
            ])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('User bot expiry warning failed.', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function warningText(
        array $snapshot,
        int $expiredAt,
        bool $isVolume
    ): string {
        $username = e($this->formatUsername(
            $snapshot['email'] ?? null
        ));

        $date = JalaliDateFormatter::forTelegram(
            $expiredAt,
            'H:i d-m-Y',
            '-'
        );

        $action = $isVolume
            ? '➕ خرید ترافیک'
            : '🔄 تمدید اکانت';

        return "⏳ <b>یادآوری پایان اعتبار اکانت</b>\n\n"
            . "کاربر عزیز، کمتر از ۲۴ ساعت تا پایان اعتبار اکانت شما باقی مانده است.\n\n"
            . "👤 نام کاربری: <code>{$username}</code>\n"
            . "📅 تاریخ انقضا: <b>{$date}</b>\n\n"
            . "برای جلوگیری از قطع‌شدن سرویس، لطفاً از گزینه «{$action}» استفاده کنید.";
    }

    private function formatUsername(?string $email): string
    {
        if (!$email) {
            return '-';
        }

        $username = str_replace('@', '-', $email);
        $username = str_replace('.com', '', $username);

        return $username ?: '-';
    }

    private function acquireLock(): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return true;
        }

        $result = DB::selectOne(
            'SELECT GET_LOCK(?, 0) AS acquired',
            [self::LOCK_NAME]
        );

        return (int) ($result->acquired ?? 0) === 1;
    }

    private function releaseLock(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::selectOne(
            'SELECT RELEASE_LOCK(?) AS released',
            [self::LOCK_NAME]
        );
    }
}


