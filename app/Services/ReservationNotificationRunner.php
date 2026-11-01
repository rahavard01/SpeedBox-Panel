<?php

namespace App\Services;

use App\Models\{UserBotSession, UserBotSetting};
use App\Services\UserBot\UserBotTelegram;
use Illuminate\Support\Facades\DB;

final class ReservationNotificationRunner
{
    public function tick(): void
    {
        DB::table('speedbox_reservation_notifications')->whereNull('sent_at')->where('retry_at', '<=', now())
            ->orderBy('id')->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        if (!DB::table('speedbox_reservation_notifications')->where('id', $row->id)->whereNull('sent_at')->where('retry_at', '<=', now())->update(['retry_at' => now()->addMinutes(5)])) continue;
                        $session = UserBotSession::find($row->session_id);
                        $setting = $session ? UserBotSetting::find($session->setting_id) : null;
                        if (!$setting || !$setting->is_enabled || !$setting->bot_token || !$session->telegram_chat_id) continue;
                        (new UserBotTelegram((string) $setting->bot_token))->sendMessage($session->telegram_chat_id, $row->message);
                        DB::table('speedbox_reservation_notifications')->where('id', $row->id)->update(['sent_at' => now(), 'last_error' => null]);
                    } catch (\Throwable $e) {
                        report($e);
                        DB::table('speedbox_reservation_notifications')->where('id', $row->id)->update([
                            'attempts' => $row->attempts + 1, 'retry_at' => now()->addMinutes(min(60, 2 ** min(6, $row->attempts))),
                            'last_error' => mb_substr($e->getMessage(), 0, 1000),
                        ]);
                    }
                }
            });
    }
}
