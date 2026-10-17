<?php

namespace App\Services\UserBot;

use App\Models\UserBotOrder;
use App\Models\UserBotSession;
use App\Models\UserBotSetting;
use App\Support\JalaliDateFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class UserBotOrderDeliveryService
{
    public function notifyApproved(
        UserBotSetting $setting,
        UserBotOrder $order,
        array $result,
        string $source = 'sms'
    ): array {
        $claim = $this->claimDeliveryAttempt(
            $setting,
            $order,
            $result,
            $source
        );

        if (!$claim) {
            return [
                'ok' => true,
                'state' => 'already_sent_or_sending',
            ];
        }

        $order = $claim['order'];
        $token = $claim['token'];
        $state = $claim['state'];

        if (empty($setting->bot_token)) {
            $this->finishDeliveryAttempt(
                $order,
                $token,
                'توکن ربات تنظیم نشده است'
            );

            return [
                'ok' => false,
                'state' => 'pending',
            ];
        }

        $bot = new UserBotTelegram(
            (string) $setting->bot_token
        );

        $this->disableManagerButtons(
            $bot,
            $setting,
            $order
        );

        $this->deleteBuyerPaymentMessage(
            $bot,
            $order
        );

        if (
            ($state['owner_status'] ?? 'pending')
            === 'pending'
        ) {
            if (!$setting->owner_telegram_chat_id) {
                $this->markDeliveryPart(
                    $order,
                    $token,
                    'owner',
                    'skipped'
                );
            } else {
                try {
                    $response = $bot->sendMessage(
                        $setting->owner_telegram_chat_id,
                        $this->ownerApprovedText(
                            $order,
                            $source
                        ),
                        UserBotKeyboard::manager()
                    );

                    $this->markDeliveryPart(
                        $order,
                        $token,
                        'owner',
                        'sent',
                        (int) data_get(
                            $response,
                            'result.message_id',
                            0
                        )
                    );
                } catch (Throwable $e) {
                    $this->markDeliveryPart(
                        $order,
                        $token,
                        'owner',
                        'pending',
                        null,
                        $e->getMessage()
                    );
                }
            }
        }

        if (
            ($state['buyer_status'] ?? 'pending')
            === 'pending'
        ) {
            try {
                $buyerResult = $this->sendBuyerResult(
                    $bot,
                    $setting,
                    $order,
                    $claim['result']
                );

                $this->markDeliveryPart(
                    $order,
                    $token,
                    'buyer',
                    (string) $buyerResult['status'],
                    $buyerResult['message_id'] ?? null
                );
            } catch (Throwable $e) {
                $this->markDeliveryPart(
                    $order,
                    $token,
                    'buyer',
                    'pending',
                    null,
                    $e->getMessage()
                );
            }
        }

        return $this->finishDeliveryAttempt(
            $order,
            $token
        );
    }

    public function retryApproved(
        UserBotSetting $setting,
        UserBotOrder $order
    ): array {
        $payload = $order->payload ?: [];
        $delivery =
            $payload['telegram_delivery'] ?? [];

        $result = $delivery['result'] ?? [];

        $source = (string) (
            $delivery['source'] ?? 'sms'
        );

        if (!$result) {
            return [
                'ok' => false,
                'state' => 'missing_delivery_result',
            ];
        }

        return $this->notifyApproved(
            $setting,
            $order,
            $result,
            $source
        );
    }

    public function notifyRetryPending(
        UserBotSetting $setting,
        UserBotOrder $order,
        string $message
    ): void {
        if (empty($setting->bot_token)) {
            return;
        }

        $order = $order->fresh() ?: $order;
        $bot = new UserBotTelegram((string) $setting->bot_token);
        $orderNo = $order->order_code ?: $order->id;

        $this->disableManagerButtons(
            $bot,
            $setting,
            $order
        );

        $this->deleteBuyerPaymentMessage(
            $bot,
            $order
        );

        if (!$setting->owner_telegram_chat_id) {
            return;
        }

        $bot->sendMessage(
            $setting->owner_telegram_chat_id,
            "⚠️ پرداخت پیامکی سفارش شماره {$orderNo} تأیید شد، اما انجام سفارش با خطا روبه‌رو شد.\n\n"
            . "دلیل: " . e($message) . "\n\n"
            . "پس از رفع مشکل، دوباره دکمه تایید مجدد سفارش را بزنید.",
            UserBotKeyboard::smsRetryOrder(
                (int) $order->id
            )
        );
    }

    private function sendBuyerResult(
        UserBotTelegram $bot,
        UserBotSetting $setting,
        UserBotOrder $order,
        array $result
    ): array {
        $buyerSession = UserBotSession::find(
            $order->session_id
        );

        if (!$buyerSession) {
            return [
                'status' => 'skipped',
                'message_id' => null,
            ];
        }

        $isVolumePartner =
            $this->isVolumePartner($setting);

        $isBothPartner =
            $this->isBothPartner($setting);

        $buyerText = match ($order->order_type) {
            'buy_traffic' =>
                "✅ {$result['traffic_gb']} گیگ ترافیک با موفقیت به اکانت زیر اضافه شد:\n\n"
                . "👤 نام کاربری: <code>"
                . $this->formatUsername(
                    $result['email'] ?? null
                )
                . "</code>"
                . (
                    $this->isVolumeOrder($order)
                        ? "\n📅 تاریخ انقضای جدید: <b>"
                            . $this->formatTimestamp(
                                $result['new_expired_at']
                                ?? null
                            )
                            . "</b>"
                        : ""
                )
                . (
                    $this->isVolumeOrder($order)
                        ? "\n📊 ترافیک باقی‌مانده: <b>"
                            . $this->formatTrafficBytes(
                                $result['traffic_remaining_bytes']
                                ?? null
                            )
                            . "</b>"
                        : ""
                ),

            'renew_account' =>
                "✅ اکانت زیر با موفقیت تمدید شد:\n\n"
                . "👤 <code>"
                . $this->formatUsername(
                    $result['email'] ?? null
                )
                . "</code>\n\n"
                . "🧩 طرح: <b>"
                . e(
                    $this->planFullTitle(
                        $order->payload['selected_plan']
                        ?? [
                            'title' =>
                                $result['plan_title']
                                ?? '-',
                        ]
                    )
                )
                . "</b>\n"
                . "📅 تاریخ انقضای جدید: <b>"
                . $this->formatTimestamp(
                    $result['new_expired_at']
                    ?? null
                )
                . "</b>",

            default =>
                "✅ اکانت شما با موفقیت ساخته شد\n\n"
                . "👤 نام کاربری:\n<code>"
                . $this->formatUsername(
                    $result['email'] ?? null
                )
                . "</code>\n\n"
                . "🔗 لینک سابسکرایب:\n<code>"
                . e(
                    (string) (
                        $result['subscribe_url']
                        ?? ''
                    )
                )
                . "</code>",
        };

        $buyerKeyboard = $buyerSession->is_logged_in
            ? UserBotKeyboard::user(
                $this->isLoggedInVolumeAccount(
                    $buyerSession
                )
            )
            : UserBotKeyboard::guest(
                (bool) $setting->active_users_only,
                $isVolumePartner && !$isBothPartner
            );

        if (
            $order->order_type === 'buy_account'
            && !empty($result['subscribe_url'])
        ) {
            $response = $bot->sendQrPhotoOrMessage(
                $buyerSession->telegram_chat_id,
                (string) $result['subscribe_url'],
                $buyerText,
                $buyerKeyboard
            );
        } else {
            $response = $bot->sendMessage(
                $buyerSession->telegram_chat_id,
                $buyerText,
                $buyerKeyboard
            );
        }

        return [
            'status' => 'sent',
            'message_id' => (int) data_get(
                $response,
                'result.message_id',
                0
            ),
        ];

        $bot->sendMessage(
            $buyerSession->telegram_chat_id,
            $buyerText,
            $buyerKeyboard
        );
    }

    private function disableManagerButtons(
        UserBotTelegram $bot,
        UserBotSetting $setting,
        UserBotOrder $order
    ): void {
        $payload = $order->payload ?: [];

        $chatId =
            $payload['manager_receipt_chat_id']
            ?? $setting->owner_telegram_chat_id;

        $messageId =
            $payload['manager_receipt_message_id']
            ?? null;

        if (!$chatId || !$messageId) {
            return;
        }

        try {
            $bot->editMessageReplyMarkup(
                $chatId,
                $messageId
            );
        } catch (\Throwable $e) {
            // خطای تلگرام نباید نتیجه سفارش را خراب کند.
        }
    }

    private function deleteBuyerPaymentMessage(
        UserBotTelegram $bot,
        UserBotOrder $order
    ): void {
        $payload = $order->payload ?: [];

        $buyerChatId =
            $payload['telegram_chat_id']
            ?? null;

        $paymentMessageId =
            $payload['payment_message_id']
            ?? null;

        if (!$buyerChatId || !$paymentMessageId) {
            return;
        }

        try {
            $bot->deleteMessage(
                $buyerChatId,
                $paymentMessageId
            );
        } catch (\Throwable $e) {
            // خطای حذف پیام نباید تحویل سفارش را خراب کند.
        }
    }

    private function claimDeliveryAttempt(
        UserBotSetting $setting,
        UserBotOrder $order,
        array $result,
        string $source
    ): ?array {
        return DB::transaction(function () use (
            $setting,
            $order,
            $result,
            $source
        ) {
            $locked = UserBotOrder::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (
                !$locked
                || $locked->status !== 'done'
            ) {
                return null;
            }

            $payload = $locked->payload ?: [];

            $state =
                $payload['telegram_delivery'] ?? [];

            if (
                ($state['status'] ?? null)
                === 'sent'
            ) {
                return null;
            }

            if (
                ($state['status'] ?? null)
                === 'sending'
                && !empty($state['started_at'])
                && \Illuminate\Support\Carbon::parse($state['started_at'])->gt(now()->subMinutes(5))
            ) {
                return null;
            }

            $token = Str::uuid()->toString();

            $normalizedResult =
                $this->normalizeDeliveryResult(
                    $locked,
                    $result
                );

            $state = array_merge(
                [
                    'status' => 'sending',
                    'source' => $source,
                    'result' => $normalizedResult,

                    'owner_status' =>
                        $setting->owner_telegram_chat_id
                            ? 'pending'
                            : 'skipped',

                    'buyer_status' => 'pending',
                    'attempts' => 0,
                ],
                $state
            );

            $state['status'] = 'sending';
            $state['source'] = $source;
            $state['result'] = $normalizedResult;

            $state['attempts'] =
                (int) ($state['attempts'] ?? 0) + 1;

            $state['token'] = $token;

            $state['started_at'] =
                now()->toDateTimeString();

            $state['last_attempt_at'] =
                now()->toDateTimeString();

            $state['next_retry_at'] = null;

            $payload['telegram_delivery'] = $state;

            $locked->update([
                'payload' => $payload,
            ]);

            return [
                'order' => $locked->fresh(),
                'token' => $token,
                'state' => $state,
                'result' => $normalizedResult,
            ];
        });
    }

    private function markDeliveryPart(
        UserBotOrder $order,
        string $token,
        string $part,
        string $status,
        ?int $messageId = null,
        ?string $error = null
    ): void {
        DB::transaction(function () use (
            $order,
            $token,
            $part,
            $status,
            $messageId,
            $error
        ) {
            $locked = UserBotOrder::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return;
            }

            $payload = $locked->payload ?: [];

            $state =
                $payload['telegram_delivery'] ?? [];

            if (
                (string) ($state['token'] ?? '')
                !== $token
            ) {
                return;
            }

            $state[$part . '_status'] = $status;

            $state[$part . '_message_id'] =
                $messageId;

            $state[$part . '_error'] =
                $error;

            $state[$part . '_updated_at'] =
                now()->toDateTimeString();

            $payload['telegram_delivery'] = $state;

            $locked->update([
                'payload' => $payload,
            ]);
        });
    }

    private function finishDeliveryAttempt(
        UserBotOrder $order,
        string $token,
        ?string $generalError = null
    ): array {
        return DB::transaction(function () use (
            $order,
            $token,
            $generalError
        ) {
            $locked = UserBotOrder::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return [
                    'ok' => false,
                    'state' => 'not_found',
                ];
            }

            $payload = $locked->payload ?: [];

            $state =
                $payload['telegram_delivery'] ?? [];

            if (
                (string) ($state['token'] ?? '')
                !== $token
            ) {
                return [
                    'ok' => false,
                    'state' => 'token_mismatch',
                ];
            }

            if ($generalError) {
                $state['general_error'] =
                    $generalError;
            }

            $ownerDone = in_array(
                $state['owner_status'] ?? null,
                ['sent', 'skipped'],
                true
            );

            $buyerDone = in_array(
                $state['buyer_status'] ?? null,
                ['sent', 'skipped'],
                true
            );

            $completed = $ownerDone && $buyerDone;

            $state['status'] = $completed
                ? 'sent'
                : 'pending';

            $state['completed_at'] = $completed
                ? now()->toDateTimeString()
                : null;

            $state['next_retry_at'] = $completed
                ? null
                : now()
                    ->addMinutes(2)
                    ->toDateTimeString();

            $state['token'] = null;
            $state['started_at'] = null;

            $payload['telegram_delivery'] = $state;

            $locked->update([
                'payload' => $payload,
            ]);

            return [
                'ok' => $completed,
                'state' => $state['status'],
                'delivery' => $state,
            ];
        });
    }

    private function normalizeDeliveryResult(
        UserBotOrder $order,
        array $result
    ): array {
        $payload = $order->payload ?: [];

        return match ($order->order_type) {
            'buy_traffic' => [
                'traffic_gb' => (int) (
                    $result['traffic_gb']
                    ?? $order->traffic_gb
                    ?? 0
                ),

                'email' => (string) (
                    $result['email']
                    ?? $order->account_email
                    ?? ''
                ),

                'new_expired_at' =>
                    $result['new_expired_at']
                    ?? $payload['new_expired_at']
                    ?? null,

                'traffic_remaining_bytes' =>
                    $result['traffic_remaining_bytes']
                    ?? null,
            ],

            'renew_account' => [
                'email' => (string) (
                    $result['email']
                    ?? $order->account_email
                    ?? ''
                ),

                'plan_title' => (string) (
                    $result['plan_title']
                    ?? data_get(
                        $payload,
                        'selected_plan.title',
                        '-'
                    )
                ),

                'new_expired_at' =>
                    $result['new_expired_at']
                    ?? $payload['new_expired_at']
                    ?? null,
            ],

            default => [
                'email' => (string) (
                    $result['email']
                    ?? data_get(
                        $payload,
                        'created_user.email',
                        ''
                    )
                ),

                'subscribe_url' => (string) (
                    $result['subscribe_url']
                    ?? $payload['subscribe_url']
                    ?? ''
                ),
            ],
        };
    }

    private function ownerApprovedText(
        UserBotOrder $order,
        string $source
    ): string {
        $orderNo = $order->order_code
            ?: $order->id;

        $successText = match ($source) {
            'manual' =>
                "✅ سفارش شماره {$orderNo} با تأیید مدیر با موفقیت انجام شد.",

            'ai' =>
                "✅ سفارش شماره {$orderNo} توسط هوش مصنوعی تأیید و با موفقیت انجام شد.",

            'sms_retry' =>
                "✅ سفارش شماره {$orderNo} پس از تأیید مجدد با موفقیت انجام شد.",

            default =>
                "✅ پرداخت پیامکی سفارش شماره {$orderNo} تأیید و سفارش با موفقیت انجام شد.",
        };

        if (
            $this->managerReceiptWasSent($order)
            || !in_array(
                $source,
                [
                    'sms',
                    'sms_retry',
                ],
                true
            )
        ) {
            return $successText;
        }

        return $this->managerReceiptCaption($order)
            . "\n\n"
            . $successText;
    }

    private function managerReceiptWasSent(
        UserBotOrder $order
    ): bool {
        return (int) data_get(
            $order->payload,
            'manager_receipt_message_id',
            0
        ) > 0;
    }

    private function managerReceiptCaption(
        UserBotOrder $order
    ): string {
        $payload = $order->payload ?: [];

        $selected = data_get(
            $payload,
            'selected_plan',
            []
        );

        if (!is_array($selected)) {
            $selected = [];
        }

        $session = UserBotSession::find(
            $order->session_id
        );

        $orderNo = $order->order_code
            ?: $order->id;

        $telegramUsername = $session
            ? $session->telegram_username
            : data_get(
                $payload,
                'telegram_username'
            );

        $accountEmail = (
            $session
            && $session->account_email
        )
            ? $session->account_email
            : $order->account_email;

        $orderTypeText = match ($order->order_type) {
            'buy_traffic' =>
                $this->isVolumePlanKey(
                    (string) data_get(
                        $selected,
                        'plan_key',
                        ''
                    )
                )
                    ? 'خرید ترافیک'
                    : 'خرید ترافیک اضافه',

            'renew_account' =>
                'تمدید اکانت',

            default =>
                'خرید اکانت',
        };

        $selectedPlanKey = (string) data_get(
            $selected,
            'plan_key',
            ''
        );

        $planTitle = (
            $order->order_type === 'buy_traffic'
            || $this->isVolumePlanKey(
                $selectedPlanKey
            )
        )
            ? (
                !empty($selected['group_name'])
                && $selected['group_name']
                    !== 'طرح پیشفرض'
                    ? (string) $selected['group_name']
                    : $this->planFullTitle(
                        $selected
                    )
            )
            : $this->planFullTitle(
                $selected
            );

        $amount = (int) (
            $order->card_sms_payable_amount
            ?: $order->amount
        );

        $displayTime =
            $order->receipt_submitted_at
            ?: $order->card_sms_paid_at
            ?: $order->approved_at
            ?: $order->created_at;

        $formattedTime = JalaliDateFormatter::forTelegram(
            $displayTime ?: now(),
            'H:i d-m-Y',
            '-'
        );

        return "🧾 <b>رسید پرداخت جدید</b>\n\n"
            . "🔢 شماره سفارش: <b>{$orderNo}</b>\n"
            . "👤 کاربر تلگرام: <b>"
            . (
                $telegramUsername
                    ? '@' . e(
                        $telegramUsername
                    )
                    : '-'
            )
            . "</b>\n"
            . "👤 نام کاربری اکانت: <code>"
            . e(
                $this->formatUsername(
                    $accountEmail
                )
            )
            . "</code>\n"
            . "📦 نوع سفارش: <b>{$orderTypeText}</b>\n"
            . "🧩 طرح: <b>"
            . e($planTitle)
            . "</b>\n"
            . (
                $order->traffic_gb
                    ? "📊 حجم: <b>"
                        . $order->traffic_gb
                        . " گیگ</b>\n"
                    : ""
            )
            . "💰 مبلغ: <b>"
            . number_format($amount)
            . " تومان</b>\n"
            . "🕒 زمان: <b>"
            . $formattedTime
            . "</b>";
    }

    private function formatUsername(
        ?string $email
    ): string {
        if (!$email) {
            return '';
        }

        $name = str_replace('@', '-', $email);

        return str_replace(
            '.com',
            '',
            $name
        );
    }

    private function planFullTitle(
        array $plan
    ): string {
        $title = trim(
            (string) ($plan['title'] ?? '')
        );

        $group = trim(
            (string) ($plan['group_name'] ?? '')
        );

        $planKey = trim(
            (string) ($plan['plan_key'] ?? '')
        );

        if (
            str_contains($planKey, 'test')
            || str_contains($title, 'تست')
        ) {
            return $title ?: 'اکانت تست';
        }

        if ($this->isVolumePlanKey($planKey)) {
            if (
                $group
                && $group !== 'طرح پیشفرض'
            ) {
                return $group;
            }

            return $title
                ?: 'اکانت حجمی یک ماهه';
        }

        if (
            $group
            && $group !== 'طرح پیشفرض'
        ) {
            return trim(
                $title . ' ' . $group
            );
        }

        return $title ?: '-';
    }

    private function isVolumePlanKey(
        string $key
    ): bool {
        return $key === 'volume_gig'
            || (bool) preg_match(
                '/^\d+_volume_gig$/',
                $key
            );
    }

    private function isLoggedInVolumeAccount(
        UserBotSession $session
    ): bool {
        $snapshot =
            $session->account_snapshot
            ?: [];

        if (
            (string) (
                $snapshot['account_group_type']
                ?? ''
            ) === 'volume'
        ) {
            return true;
        }

        $planId = (int) (
            $snapshot['plan_id']
            ?? 0
        );

        if ($planId <= 0) {
            return false;
        }

        return DB::table('panel_plan as p')
            ->join(
                'panel_tariff_groups as g',
                'g.id',
                '=',
                'p.tariff_group_id'
            )
            ->where(
                'p.plan_key',
                'volume_gig'
            )
            ->where(
                'p.v2_plan_id',
                $planId
            )
            ->where('p.enable', 1)
            ->where(
                'g.group_type',
                'volume'
            )
            ->where('g.enable', 1)
            ->exists();
    }

    private function isVolumePartner(
        UserBotSetting $setting
    ): bool {
        return DB::table('panel_users')
            ->where(
                'id',
                $setting->owner_id
            )
            ->value('partner_plan_type')
            === 'volume';
    }

    private function isBothPartner(
        UserBotSetting $setting
    ): bool {
        return DB::table('panel_users')
            ->where(
                'id',
                $setting->owner_id
            )
            ->value('partner_plan_type')
            === 'both';
    }

    private function isVolumeOrder(
        UserBotOrder $order
    ): bool {
        $payload = $order->payload ?: [];
        $selected =
            $payload['selected_plan']
            ?? [];

        return $this->isVolumePlanKey(
            (string) $order->plan_key
        ) || $this->isVolumePlanKey(
            (string) (
                $selected['plan_key']
                ?? ''
            )
        );
    }

    private function formatTimestamp(
        $timestamp
    ): string {
        $timestamp = (int) $timestamp;

        if ($timestamp <= 0) {
            return '-';
        }

        return JalaliDateFormatter::forTelegram(
            $timestamp,
            'H:i d-m-Y',
            '-'
        );
    }

    private function formatTrafficBytes(
        $bytes
    ): string {
        if (
            $bytes === null
            || $bytes === ''
        ) {
            return '-';
        }

        $gb =
            abs((int) $bytes)
            / 1024
            / 1024
            / 1024;

        return rtrim(
            rtrim(
                number_format($gb, 2),
                '0'
            ),
            '.'
        ) . ' گیگ';
    }
}