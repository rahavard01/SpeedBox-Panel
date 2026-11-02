<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Scheduler-only delivery: no Telegram request runs inside a purchase or its transaction. */
final class PanelWalletLowBalanceRunner
{
    public const THRESHOLD = 500000;
    private const TABLE = 'panel_wallet_low_balance_warnings';

    public function tick(): int
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasTable('panel_users')
            || !Schema::hasTable('panel_bot_settings') || !TelegramConfig::enabled()) return 0;

        $sent = 0;
        DB::table('panel_users')->select('id')->where('role', 2)->where(function ($query) {
            $query->where(function ($connected) {
                $connected->whereNotNull('telegram_user_id')->where('telegram_user_id', '<>', '')
                    ->where('banned', 0)->where('credit', '<=', self::THRESHOLD);
            })->orWhereExists(function ($state) {
                $state->selectRaw('1')->from(self::TABLE)->whereColumn(self::TABLE.'.panel_user_id', 'panel_users.id');
            });
        })->orderBy('id')->chunkById(100, function ($users) use (&$sent) {
            foreach ($users as $user) {
                try {
                    $claim = $this->claim((int) $user->id);
                    if ($claim && $this->deliver($claim)) $sent++;
                } catch (\Throwable $error) {
                    // Do not log Telegram URLs/tokens or make another representative's warning fail.
                    Log::warning('Panel wallet warning could not be processed.', ['panel_user_id' => $user->id, 'exception' => get_class($error)]);
                }
            }
        });
        return $sent;
    }

    private function eligible(?object $user): bool
    {
        return $user && (int) $user->role === 2 && !(bool) ($user->banned ?? false)
            && (int) ($user->telegram_user_id ?? 0) !== 0;
    }

    private function claim(int $userId): ?object
    {
        return DB::transaction(function () use ($userId) {
            // Same lock as wallet writes; overlapping scheduler processes share a durable claim.
            $user = DB::table('panel_users')->where('id', $userId)->lockForUpdate()->first();
            if (!$user) return null;
            if ((int) $user->credit > self::THRESHOLD) {
                DB::table(self::TABLE)->where('panel_user_id', $userId)->delete();
                return null;
            }
            if (!$this->eligible($user)) return null;

            $state = DB::table(self::TABLE)->where('panel_user_id', $userId)->lockForUpdate()->first();
            $transactions = DB::table('panel_transactions')->where('panel_user_id', $userId);
            // A recharge and another debit may both occur between two scheduler ticks.
            // Successful above-threshold ledger entries rearm the warning in that case too.
            // The watermark includes pending entries, so a late finalization of an OLD
            // operation cannot create a new warning cycle.
            $rearmed = $state && (clone $transactions)->where('id', '>', $state->transaction_id)
                ->where('status', 'success')->whereRaw('CAST(balance_after AS DECIMAL(20,0)) > ?', [self::THRESHOLD])->exists();
            // A topup can have an older pending ledger row but be approved only AFTER
            // the warning. Distinguish that real recharge from a late debit finalization.
            $pendingCredits = $state ? json_decode($state->pending_credit_ids ?? '[]', true) : [];
            if (!$rearmed && $pendingCredits) {
                $rearmed = (clone $transactions)->whereIn('id', $pendingCredits)->where('direction', 'credit')
                    ->where('status', 'success')->whereRaw('CAST(balance_after AS DECIMAL(20,0)) > ?', [self::THRESHOLD])->exists();
            }
            if ($state && !$rearmed && ($state->sent_at || ($state->retry_at && now()->lt($state->retry_at)))) return null;

            $token = bin2hex(random_bytes(16));
            DB::table(self::TABLE)->updateOrInsert(['panel_user_id' => $userId], [
                'transaction_id' => (int) ((clone $transactions)->max('id') ?? 0),
                'pending_credit_ids' => json_encode((clone $transactions)->where('direction', 'credit')->where('status', 'pending')->pluck('id')->all()),
                'claim_token' => $token, 'sent_at' => null, 'retry_at' => now()->addMinutes(5),
                'attempts' => $state && !$rearmed ? (int) $state->attempts + 1 : 1,
                'created_at' => $state->created_at ?? now(), 'updated_at' => now(),
            ]);
            return (object) ['user_id' => $userId, 'chat_id' => (string) $user->telegram_user_id, 'token' => $token];
        }, 3);
    }

    private function deliver(object $claim): bool
    {
        try {
            $user = DB::table('panel_users')->where('id', $claim->user_id)->first();
            if (!TelegramConfig::enabled() || !$this->eligible($user) || (int) $user->credit > self::THRESHOLD
                || (string) $user->telegram_user_id !== $claim->chat_id) {
                DB::table(self::TABLE)->where('panel_user_id', $claim->user_id)->where('claim_token', $claim->token)->delete();
                return false;
            }
            $response = Http::connectTimeout(3)->timeout(10)->asForm()->post(
                'https://api.telegram.org/bot'.TelegramConfig::token().'/sendMessage', [
                    'chat_id' => $claim->chat_id,
                    'text' => $this->message((int) $user->credit),
                    'disable_web_page_preview' => true,
                ]
            );
            if (!$response->successful() || $response->json('ok') !== true) {
                // Telegram may return HTTP 200 with ok=false. Never mark that as delivered.
                return false;
            }
            return (bool) DB::table(self::TABLE)->where('panel_user_id', $claim->user_id)->where('claim_token', $claim->token)
                ->update(['sent_at' => now(), 'claim_token' => null, 'retry_at' => null, 'updated_at' => now()]);
        } catch (\Throwable $error) {
            Log::warning('Panel wallet Telegram warning failed.', ['panel_user_id' => $claim->user_id, 'exception' => get_class($error)]);
            return false;
        }
    }

    public function message(int $balance): string
    {
        $digits = strtr(number_format($balance), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
        return "\u{200F}⚠️ اعتبار کیف پول شما رو به پایان است\n\n"
            ."💰 موجودی فعلی: {$digits} تومان\n\n"
            .'لطفاً برای جلوگیری از اختلال در خرید و تمدید اکانت‌ها، کیف پول خود را شارژ کنید.';
    }
}
