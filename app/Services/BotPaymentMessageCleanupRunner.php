<?php

namespace App\Services;

use App\Models\PanelBotSession;
use App\Models\UserBotSession;
use App\Models\UserBotOrder;
use App\Models\UserBotSetting;
use App\Services\UserBot\UserBotTelegram;

class BotPaymentMessageCleanupRunner
{
    private const SMS_MATCH_KEY_RELEASE_GRACE_MINUTES = 12;

    public function tick(): void
    {
        $this->cleanupExpiredUserBotSmsMatchKeys();
        $this->cleanupUserBots();
        $this->cleanupPanelBot();
    }

    private function cleanupExpiredUserBotSmsMatchKeys(): void
    {
        $releaseBefore = now()->subMinutes(
            self::SMS_MATCH_KEY_RELEASE_GRACE_MINUTES
        );

        /*
         * سفارش بدون رسید:
         * پس از پایان مهلت و زمان انتظار امن، منقضی شود.
         */
        UserBotOrder::query()
            ->where('payment_method', 'card')
            ->whereNotNull('card_sms_match_key')
            ->whereNotNull('card_sms_expires_at')
            ->where(
                'card_sms_expires_at',
                '<=',
                $releaseBefore
            )
            ->where(
                'status',
                'card_waiting_payment'
            )
            ->update([
                'status' => 'expired',
                'card_sms_match_key' => null,
            ]);

        /*
         * سفارش دارای رسید:
         * pending باقی بماند تا رسید قابل بررسی باشد،
         * ولی مبلغ یکتای SMS آزاد شود.
         */
        UserBotOrder::query()
            ->where('payment_method', 'card')
            ->whereNotNull('card_sms_match_key')
            ->whereNotNull('card_sms_expires_at')
            ->where(
                'card_sms_expires_at',
                '<=',
                $releaseBefore
            )
            ->where(
                'status',
                'pending'
            )
            ->update([
                'card_sms_match_key' => null,
            ]);

        /*
         * پاکسازی محافظتی سفارش‌های نهایی که به هر دلیل
         * کلید SMS آن‌ها باقی مانده است.
         */
        UserBotOrder::query()
            ->where('payment_method', 'card')
            ->whereNotNull('card_sms_match_key')
            ->whereNotIn('status', [
                'card_waiting_payment',
                'pending',
                'card_processing',
            ])
            ->update([
                'card_sms_match_key' => null,
            ]);
    }

    private function cleanupUserBots(): void
    {
        UserBotSession::query()
            ->where('state', 'buy_waiting_receipt')
            ->where('updated_at', '<=', now()->subMinutes(30))
            ->chunkById(50, function ($sessions) {
                foreach ($sessions as $session) {
                    $stateData = $session->state_data ?: [];
                    $messageId = $stateData['payment_message_id'] ?? null;

                    if ($messageId) {
                        $setting = UserBotSetting::find($session->setting_id);

                        if ($setting && $setting->bot_token) {
                            try {
                                (new UserBotTelegram($setting->bot_token))
                                    ->deleteMessage($session->telegram_chat_id, $messageId);
                            } catch (\Throwable $e) {
                                //
                            }
                        }
                    }

                    $orderId = (int) (
                        $stateData['card_sms_order_id'] ?? 0
                    );

                    if ($orderId > 0) {
                        UserBotOrder::query()
                            ->where('id', $orderId)
                            ->where('setting_id', $session->setting_id)
                            ->where('session_id', $session->id)
                            ->where('status', 'card_waiting_payment')
                            ->update([
                                'status' => 'expired',
                                'card_sms_match_key' => null,
                            ]);
                    }

                    $session->update([
                        'state' => null,
                        'state_data' => null,
                    ]);
                }
            });
    }

    private function cleanupPanelBot(): void
    {
        PanelBotSession::query()
            ->where('state', 'wallet_topup_receipt')
            ->where('last_activity', '<=', now()->subMinutes(30))
            ->chunkById(50, function ($sessions) {
                foreach ($sessions as $session) {
                    $payload = $session->payload ?: [];

                    /*
                    * در پرداخت آنی کارت‌به‌کارت، تمام پیام‌هایی که
                    * برای همین Card Topup ساخته شده‌اند حذف شوند.
                    */
                    $messageIds = [];

                    $cardTopupId = (int) (
                        $payload['card_topup_id'] ?? 0
                    );

                    if ($cardTopupId > 0) {
                        $topup = \App\Models\PanelWalletCardTopup::find(
                            $cardTopupId
                        );

                        if ($topup) {
                            $meta = is_array($topup->meta)
                                ? $topup->meta
                                : [];

                            $storedMessageIds =
                                $meta['payment_message_ids'] ?? [];

                            if (is_array($storedMessageIds)) {
                                foreach ($storedMessageIds as $messageId) {
                                    $messageId = (int) $messageId;

                                    if ($messageId > 0) {
                                        $messageIds[] = $messageId;
                                    }
                                }
                            }

                            /*
                            * سازگاری با پیام قدیمی/آخرین پیام.
                            */
                            $legacyMessageId = (int) (
                                $meta['payment_message_id'] ?? 0
                            );

                            if ($legacyMessageId > 0) {
                                $messageIds[] = $legacyMessageId;
                            }
                        }
                    }

                    /*
                    * message_id داخل Session هم حتماً لحاظ شود.
                    * این قسمت باعث می‌شود فلو قدیمی کارت‌به‌کارت
                    * هم مثل قبل کار کند.
                    */
                    $sessionMessageId = (int) (
                        $payload['payment_message_id'] ?? 0
                    );

                    if ($sessionMessageId > 0) {
                        $messageIds[] = $sessionMessageId;
                    }

                    $messageIds = array_values(
                        array_unique(
                            array_filter(
                                array_map('intval', $messageIds),
                                fn ($id) => $id > 0
                            )
                        )
                    );

                    if ($session->chat_id && $messageIds !== []) {
                        try {
                            $token = \App\Services\TelegramConfig::token();

                            if ($token) {
                                foreach ($messageIds as $messageId) {
                                    try {
                                        \Illuminate\Support\Facades\Http::asForm()->post(
                                            "https://api.telegram.org/bot{$token}/deleteMessage",
                                            [
                                                'chat_id' => $session->chat_id,
                                                'message_id' => $messageId,
                                            ]
                                        );
                                    } catch (\Throwable $e) {
                                        //
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            //
                        }
                    }

                    $session->update([
                        'state' => 'logged_in',
                        'payload' => null,
                    ]);
                }
            });
    }
}