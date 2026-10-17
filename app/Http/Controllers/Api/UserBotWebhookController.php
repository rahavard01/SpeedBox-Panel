<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserBotSession;
use App\Models\UserBotSetting;
use App\Services\UserBot\UserBotKeyboard;
use App\Services\UserBot\UserBotTelegram;
use Illuminate\Http\Request;
use App\Services\UserBot\UserBotAccountResolver;
use App\Models\UserBotPlan;
use App\Models\UserBotOrder;
use App\Services\UserBot\UserBotCardOrderApprovalService;
use App\Services\UserBot\UserBotOrderDeliveryService;
use App\Services\UserBot\UserBotCardPaymentIntentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\PartnerTariffAccessService;
use App\Services\CryptoRateService;
use App\Services\UserBotCryptoOrderVerifier;
use App\Models\CryptoPaymentOption;
use App\Support\JalaliDateFormatter;

class UserBotWebhookController extends Controller
{
    public function handle(Request $request, string $ownerType, int $ownerId, string $secret)
    {
        $setting = UserBotSetting::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('webhook_secret', $secret)
            ->first();

        if (!$setting || !$setting->is_enabled || !$setting->bot_token) {
            return response()->json(['ok' => true]);
        }

        $message = $request->input('message');
        $callbackQuery = $request->input('callback_query');

        if (!$message && !$callbackQuery) {
            return response()->json(['ok' => true]);
        }

        if ($callbackQuery) {
            $message = data_get($callbackQuery, 'message');
        }

        $chatId = data_get($message, 'chat.id');
        $telegramUserId = data_get($callbackQuery ?: $message, 'from.id');
        $telegramUsername = data_get($callbackQuery ?: $message, 'from.username');
        $text = trim((string) data_get($message, 'text', ''));

        if ($callbackQuery) {
            $text = trim((string) data_get($callbackQuery, 'data', ''));
        }
        $callbackQueryId = $callbackQuery ? data_get($callbackQuery, 'id') : null;
        $callbackMessageId = $callbackQuery ? data_get($message, 'message_id') : null;        
        $photo = data_get($message, 'photo');
        $document = data_get($message, 'document');

        if (!$chatId) {
            return response()->json(['ok' => true]);
        }

        $session = UserBotSession::updateOrCreate(
            [
                'setting_id' => $setting->id,
                'telegram_chat_id' => (string) $chatId,
            ],
            [
                'telegram_user_id' => $telegramUserId ? (string) $telegramUserId : null,
                'telegram_username' => $telegramUsername,
            ]
        );

        $bot = new UserBotTelegram($setting->bot_token);

        if (!in_array($text, ['', '/start', '⬅️ بازگشت'], true) && $session->state === 'buy_waiting_receipt') {
            $this->deletePendingPaymentMessage($bot, $session);
        }        

        $isVolumePartner = $this->isVolumePartner($setting);
        $isBothPartner = $this->isBothPartner($setting);

        $isVolumeAccount = $session->is_logged_in
            ? $this->isLoggedInVolumeAccount($session)
            : ($isVolumePartner && !$isBothPartner);

        if ($text === '/start' || $text === '⬅️ بازگشت') {
            if ($session->state === 'buy_waiting_receipt') {
                $this->expirePendingCardSmsOrder($session);
                $this->deletePendingPaymentMessage($bot, $session);
            }

            if (
                (string) $chatId === (string) $setting->owner_telegram_chat_id
                && $session->state === 'manager_broadcast_waiting'
            ) {
                $session->update([
                    'state' => 'manager',
                    'state_data' => null,
                ]);

                $bot->sendMessage(
                    $chatId,
                    "❌ ارسال پیام گروهی لغو شد",
                    UserBotKeyboard::manager()
                );

                return response()->json(['ok' => true]);
            }

            if (
                (string) $chatId === (string) $setting->owner_telegram_chat_id
                && $session->state === 'manager'
            ) {
                $session->update([
                    'state_data' => null,
                ]);

                $bot->sendMessage(
                    $chatId,
                    "👋 مدیر عزیز\n\nهر زمان رسید جدیدی ارسال شود، برای شما نمایش داده می‌شود",
                    UserBotKeyboard::manager()
                );

                return response()->json(['ok' => true]);
            }

            if ($this->shouldBlockForChannelJoin($setting, $session)) {
                $this->sendForceChannelJoinMessage($bot, $setting, $session);
                return response()->json(['ok' => true]);
            }

            $session->update([
                'state' => null,
                'state_data' => null,
            ]);

            $this->sendHome($bot, $setting, $session);
            return response()->json(['ok' => true]);
        }

        if (str_starts_with(strtoupper($text), 'SBMGR-')) {
            if (strtoupper($text) === strtoupper((string) $setting->owner_connect_code)) {
            $setting->update([
                'owner_telegram_chat_id' => (string) $chatId,
            ]);

            $session->update([
                'is_logged_in' => false,
                'account_email' => null,
                'account_sub_link' => null,
                'account_snapshot' => null,
                'state' => 'manager',
                'state_data' => null,
            ]);

            $bot->sendMessage(
                $chatId,
                "👋 مدیر عزیز خوش آمدید\n\nاین ربات جهت مدیریت رسیدهای واریزی خرید و تمدید کاربران می‌باشد",
                UserBotKeyboard::manager()
            );
                return response()->json(['ok' => true]);
            }

            $bot->sendMessage($chatId, "⛔ کد ورود مدیر نامعتبر است");
            return response()->json(['ok' => true]);
        }
   
        if ($this->shouldBlockForChannelJoin($setting, $session)) {
            if ($text === 'userbot_check_join') {
                if ($callbackQueryId) {
                    $bot->answerCallbackQuery($callbackQueryId, '❌ هنوز عضو کانال نیستید', true);
                }

                return response()->json(['ok' => true]);
            }

            $this->sendForceChannelJoinMessage($bot, $setting, $session);
            return response()->json(['ok' => true]);
        }

        if ($text === 'userbot_check_join') {
            if ($callbackQueryId) {
                $bot->answerCallbackQuery($callbackQueryId, 'عضویت شما تایید شد');
            }

            if ($callbackMessageId) {
                $bot->deleteMessage($chatId, $callbackMessageId);
            }

            $bot->sendMessage($chatId, "✅ عضویت شما تایید شد");
            $this->sendHome($bot, $setting, $session);
            return response()->json(['ok' => true]);
        }

        if ($text === '📣 ارسال پیام گروهی') {
            if (
                (string) $chatId !== (string) $setting->owner_telegram_chat_id
                || $session->state !== 'manager'
            ) {
                return response()->json(['ok' => true]);
            }

            $session->update([
                'state' => 'manager_broadcast_waiting',
                'state_data' => null,
            ]);

            $bot->sendMessage(
                $chatId,
                "📣 پیام گروهی را ارسال کنید.\n\n📌 این پیام برای کلیه کاربرانی که این ربات را استارت کرده‌ باشند ارسال خواهد شد.\n\nبرای لغو، «⬅️ بازگشت» را بزنید.",
                UserBotKeyboard::back()
            );

            return response()->json(['ok' => true]);
        }

        if ($session->state === 'manager_broadcast_waiting') {
            if ((string) $chatId !== (string) $setting->owner_telegram_chat_id) {
                return response()->json(['ok' => true]);
            }

            if (in_array($text, ['⬅️ بازگشت', 'لغو'], true)) {
                $session->update([
                    'state' => 'manager',
                    'state_data' => null,
                ]);

                $bot->sendMessage(
                    $chatId,
                    "❌ ارسال پیام گروهی لغو شد",
                    UserBotKeyboard::manager()
                );

                return response()->json(['ok' => true]);
            }

            $messageId = data_get($message, 'message_id');

            if (!$messageId) {
                $bot->sendMessage($chatId, "⚠️ پیام قابل ارسال نیست", UserBotKeyboard::back());
                return response()->json(['ok' => true]);
            }

            $chatIds = UserBotSession::where('setting_id', $setting->id)
                ->whereNotNull('telegram_chat_id')
                ->where('telegram_chat_id', '!=', (string) $chatId)
                ->pluck('telegram_chat_id')
                ->unique()
                ->values();

            $sent = 0;
            $failed = 0;

            foreach ($chatIds as $targetChatId) {
                try {
                    $res = Http::timeout(15)->post("https://api.telegram.org/bot{$setting->bot_token}/copyMessage", [
                        'chat_id' => (string) $targetChatId,
                        'from_chat_id' => (string) $chatId,
                        'message_id' => (int) $messageId,
                    ]);

                    if ((bool) data_get($res->json(), 'ok')) {
                        $sent++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                }
            }

            $session->update([
                'state' => 'manager',
                'state_data' => null,
            ]);

            $bot->sendMessage(
                $chatId,
                "✅ پیام گروهی با موفقیت برای {$sent} اکانت تلگرام ارسال شد",
                UserBotKeyboard::manager()
            );

            return response()->json(['ok' => true]);
        }

        if ($text === '❓ سوالات متداول') {
            $bot->sendMessage(
                $chatId,
                $this->frequentlyAskedQuestionsMenuText(),
                UserBotKeyboard::faqMenu()
            );

            return response()->json(['ok' => true]);
        }

        if ($text === 'userbot_faq:menu') {
            if ($callbackQueryId) {
                $bot->answerCallbackQuery($callbackQueryId);
            }

            if ($callbackMessageId) {
                $bot->editMessageText(
                    $chatId,
                    $callbackMessageId,
                    $this->frequentlyAskedQuestionsMenuText(),
                    UserBotKeyboard::faqMenu()
                );
            }

            return response()->json(['ok' => true]);
        }

        if (preg_match('/^userbot_faq:item_(\d+)$/', $text, $matches)) {
            $faqText = app(\App\Services\UserBot\GlobalUserBotFaq::class)->answer((int) $matches[1]);

            if ($faqText === null) {
                if ($callbackQueryId) {
                    $bot->answerCallbackQuery(
                        $callbackQueryId,
                        'این سوال پیدا نشد',
                        true
                    );
                }

                return response()->json(['ok' => true]);
            }

            if ($callbackQueryId) {
                $bot->answerCallbackQuery($callbackQueryId);
            }

            if ($callbackMessageId) {
                $bot->editMessageText(
                    $chatId,
                    $callbackMessageId,
                    $faqText,
                    UserBotKeyboard::faqBack()
                );
            }

            return response()->json(['ok' => true]);
        }

        if (str_starts_with($text, 'userbot_faq:')) {
            if ($callbackQueryId) $bot->answerCallbackQuery($callbackQueryId, 'این سؤال دیگر در فهرست نیست؛ سؤالات متداول را دوباره باز کنید.', true);
            return response()->json(['ok' => true]);
        }

        if ($text === '💬 پشتیبانی') {
            $supportUsername = trim((string) $setting->support_username);

            if ($supportUsername === '') {
                $bot->sendMessage(
                    $chatId,
                    "⚠️ پشتیبانی تنظیم نشده است",
                    $session->is_logged_in ? UserBotKeyboard::user($isVolumeAccount) : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner)
                );

                return response()->json(['ok' => true]);
            }

            $bot->sendMessage(
                $chatId,
                "برای ارتباط با پشتیبانی روی دکمه زیر بزنید:",
                UserBotKeyboard::support($supportUsername)
            );

            return response()->json(['ok' => true]);
        }
        
        if (preg_match('/^userbot_crypto_paid:(\d+)$/', $text, $m)) {
            $order = UserBotOrder::where('setting_id', $setting->id)
                ->where('id', (int) $m[1])
                ->where('session_id', $session->id)
                ->first();

            if (!$order) {
                if ($callbackQueryId) {
                    $bot->answerCallbackQuery($callbackQueryId, 'سفارش پیدا نشد', true);
                }
                return response()->json(['ok' => true]);
            }

            if ($order->status === 'crypto_checking') {
                if ($callbackMessageId) {
                    $bot->editMessageReplyMarkup($chatId, $callbackMessageId);
                }

                $bot->sendMessage($chatId, "⚠️ پرداخت شما در حال بررسی است.");

                return response()->json(['ok' => true]);
            }

            if ($finalMessage = $this->finalUserBotCryptoOrderMessage((string) $order->status)) {
                if ($callbackMessageId) {
                    $bot->editMessageReplyMarkup($chatId, $callbackMessageId);
                }

                $bot->sendMessage($chatId, $finalMessage);

                return response()->json(['ok' => true]);
            }

            if ($order->status !== 'crypto_pending') {
                if ($callbackMessageId) {
                    $bot->editMessageReplyMarkup($chatId, $callbackMessageId);
                }

                $bot->sendMessage($chatId, "⚠️ این سفارش در حال بررسی می‌باشد و قابل تغییر نیست.");

                return response()->json(['ok' => true]);
            }

            if ($order->crypto_expires_at && now()->greaterThan($order->crypto_expires_at)) {
                $order->update(['status' => 'expired']);

                if ($callbackMessageId) {
                    $bot->editMessageReplyMarkup($chatId, $callbackMessageId);
                }

                $bot->sendMessage($chatId, "⏰ مهلت پرداخت این سفارش به پایان رسیده است.");

                return response()->json(['ok' => true]);
            }

            $order->update(['status' => 'crypto_checking']);

            if ($callbackMessageId) {
                $bot->editMessageReplyMarkup($chatId, $callbackMessageId);
            }

            $bot->sendMessage(
                $chatId,
                "✅ پرداخت شما ثبت شد و سفارش در حال بررسی می‌باشد.\n\nپس از تأیید تراکنش، سفارش شما به‌صورت خودکار انجام خواهد شد.",
                $session->is_logged_in
                    ? UserBotKeyboard::user($isVolumeAccount)
                    : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner)
            );

            return response()->json(['ok' => true]);
        }

        if (preg_match('/^userbot_crypto_cancel:(\d+)$/', $text, $m)) {
            $order = UserBotOrder::where('setting_id', $setting->id)
                ->where('id', (int) $m[1])
                ->where('session_id', $session->id)
                ->first();

            if (!$order) {
                if ($callbackQueryId) {
                    $bot->answerCallbackQuery($callbackQueryId, 'سفارش پیدا نشد', true);
                }
                return response()->json(['ok' => true]);
            }

            if ($finalMessage = $this->finalUserBotCryptoOrderMessage((string) $order->status)) {
                if ($callbackMessageId) {
                    $bot->editMessageReplyMarkup($chatId, $callbackMessageId);
                }

                $bot->sendMessage($chatId, $finalMessage);

                return response()->json(['ok' => true]);
            }

            if ($order->status !== 'crypto_pending') {
                if ($callbackMessageId) {
                    $bot->editMessageReplyMarkup($chatId, $callbackMessageId);
                }

                $bot->sendMessage($chatId, "⚠️ این سفارش در حال بررسی می‌باشد و قابل لغو نیست.");

                return response()->json(['ok' => true]);
            }
            $order->update(['status' => 'cancelled']);

            if ($callbackMessageId) {
                $bot->editMessageReplyMarkup($chatId, $callbackMessageId);
            }

            $bot->sendMessage(
                $chatId,
                "✅ سفارش رمزارزی شما لغو شد.",
                $session->is_logged_in
                    ? UserBotKeyboard::user($isVolumeAccount)
                    : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner)
            );

            return response()->json(['ok' => true]);
        }

        if (preg_match('/^userbot_crypto_retry:(\d+)$/', $text, $m)) {
            if ((string) $chatId !== (string) $setting->owner_telegram_chat_id) {
                return response()->json(['ok' => true]);
            }

            $orderId = (int) $m[1];

            $order = UserBotOrder::where('setting_id', $setting->id)
                ->where('id', $orderId)
                ->where('payment_method', 'crypto')
                ->where('status', 'crypto_retry_pending')
                ->first();

            if (!$order) {
                if ($callbackQueryId) {
                    $bot->answerCallbackQuery($callbackQueryId, 'سفارش پیدا نشد یا قبلاً انجام شده است', true);
                }

                return response()->json(['ok' => true]);
            }

            $result = app(UserBotCryptoOrderVerifier::class)->retryApprove($order->id);

            if (empty($result['ok'])) {
                if ($callbackQueryId) {
                    $bot->answerCallbackQuery($callbackQueryId, 'انجام سفارش ناموفق بود', true);
                }

                $retryText =
                    "⚠️ سفارش انجام نشد.\n\n"
                    . "خطا: " . ($result['message'] ?? 'خطا در انجام سفارش') . "\n\n"
                    . "پس از رفع مشکل، دوباره دکمه تایید مجدد سفارش را بزنید.";

                if ($callbackMessageId) {
                    $bot->editMessageText(
                        $chatId,
                        $callbackMessageId,
                        $retryText,
                        UserBotKeyboard::cryptoRetryOrder($order->id)
                    );
                } else {
                    $bot->sendMessage(
                        $chatId,
                        $retryText,
                        UserBotKeyboard::cryptoRetryOrder($order->id)
                    );
                }

                return response()->json(['ok' => true]);
            }

            if ($callbackQueryId) {
                $bot->answerCallbackQuery($callbackQueryId, 'سفارش با موفقیت انجام شد');
            }

            if ($callbackMessageId) {
                $bot->editMessageText(
                    $chatId,
                    $callbackMessageId,
                    "✅ سفارش با موفقیت انجام و به کاربر تحویل داده شد."
                );
            }

            return response()->json(['ok' => true]);
        }

        if (preg_match('/^(?:✅ تایید سفارش |userbot_approve:)(\d+)$/u', $text, $m)) {
            if ((string) $chatId !== (string) $setting->owner_telegram_chat_id) {
                return response()->json(['ok' => true]);
            }

            $orderId = (int) $m[1];

            $order = UserBotOrder::where(
                    'setting_id',
                    $setting->id
                )
                ->where('id', $orderId)
                ->where(
                    'payment_method',
                    'card'
                )
                ->first();

            if (!$order) {
                $bot->sendMessage($chatId, "⚠️ این سفارش پیدا نشد یا قبلاً تایید/رد شده است", UserBotKeyboard::manager());
                return response()->json(['ok' => true]);
            }

            $result = app(
                UserBotCardOrderApprovalService::class
            )->approve(
                $order,
                'manual'
            );

            if (empty($result['ok'])) {
                $state = (string) (
                    $result['state']
                    ?? 'failed'
                );

                if ($callbackQueryId) {
                    $callbackText = match ($state) {
                        'already_done' =>
                            'سفارش قبلاً انجام شده است',

                        'already_processing' =>
                            'سفارش در حال انجام است',

                        default =>
                            'خطا در تأیید سفارش',
                    };

                    $bot->answerCallbackQuery(
                        $callbackQueryId,
                        $callbackText
                    );
                }

                if ($state === 'already_done') {
                    if ($callbackMessageId) {
                        $bot->editMessageReplyMarkup(
                            $chatId,
                            $callbackMessageId
                        );
                    }

                    return response()->json([
                        'ok' => true,
                    ]);
                }

                if ($state === 'already_processing') {
                    return response()->json([
                        'ok' => true,
                    ]);
                }

                $bot->sendMessage(
                    $chatId,
                    "⚠️ "
                    . (
                        $result['message']
                        ?? 'خطا در تأیید سفارش'
                    )
                    . "\n\nبعد از رفع مشکل، دوباره دکمه تأیید زیر همان رسید را بزنید"
                );

                return response()->json([
                    'ok' => true,
                ]);
            }

            $order = $result['order']
                instanceof UserBotOrder
                    ? $result['order']
                    : $order->fresh();

            app(
                UserBotOrderDeliveryService::class
            )->notifyApproved(
                $setting,
                $order,
                $result,
                'manual'
            );

            if ($callbackQueryId) {
                $bot->answerCallbackQuery(
                    $callbackQueryId,
                    'سفارش تایید شد'
                );
            }

            return response()->json(['ok' => true]);
        }

        if (
            preg_match(
                '/^userbot_sms_retry:(\d+)$/',
                $text,
                $m
            )
        ) {
            if (
                (string) $chatId
                !==
                (string) $setting->owner_telegram_chat_id
            ) {
                return response()->json([
                    'ok' => true,
                ]);
            }

            $orderId = (int) $m[1];

            $order = UserBotOrder::where(
                    'setting_id',
                    $setting->id
                )
                ->where('id', $orderId)
                ->where(
                    'payment_method',
                    'card'
                )
                ->first();

            if (!$order) {
                if ($callbackQueryId) {
                    $bot->answerCallbackQuery(
                        $callbackQueryId,
                        'سفارش پیدا نشد'
                    );
                }

                return response()->json([
                    'ok' => true,
                ]);
            }

            $result = app(
                UserBotCardOrderApprovalService::class
            )->retrySms(
                (int) $order->id
            );

            if (empty($result['ok'])) {
                $state = (string) (
                    $result['state']
                    ?? 'failed'
                );

                if ($callbackQueryId) {
                    $bot->answerCallbackQuery(
                        $callbackQueryId,
                        match ($state) {
                            'already_done' =>
                                'سفارش قبلاً انجام شده است',

                            'already_processing' =>
                                'سفارش در حال انجام است',

                            default =>
                                'انجام سفارش ناموفق بود',
                        }
                    );
                }

                if ($state === 'retry_pending') {
                    $retryOrder =
                        $result['order']
                            instanceof UserBotOrder
                                ? $result['order']
                                : $order->fresh();

                    app(
                        UserBotOrderDeliveryService::class
                    )->notifyRetryPending(
                        $setting,
                        $retryOrder,
                        (string) (
                            $result['message']
                            ?? 'خطا در انجام سفارش'
                        )
                    );
                }

                return response()->json([
                    'ok' => true,
                ]);
            }

            $approvedOrder =
                $result['order']
                    instanceof UserBotOrder
                        ? $result['order']
                        : $order->fresh();

            app(
                UserBotOrderDeliveryService::class
            )->notifyApproved(
                $setting,
                $approvedOrder,
                $result,
                'sms_retry'
            );

            if ($callbackQueryId) {
                $bot->answerCallbackQuery(
                    $callbackQueryId,
                    'سفارش با موفقیت انجام شد'
                );
            }

            if ($callbackMessageId) {
                $bot->editMessageReplyMarkup(
                    $chatId,
                    $callbackMessageId
                );
            }

            return response()->json([
                'ok' => true,
            ]);
        }

        if (preg_match('/^(?:❌ رد سفارش |userbot_reject:)(\d+)$/u', $text, $m)) {
            if ((string) $chatId !== (string) $setting->owner_telegram_chat_id) {
                return response()->json(['ok' => true]);
            }

            $orderId = (int) $m[1];

            $order = UserBotOrder::where('setting_id', $setting->id)
                ->where('id', $orderId)
                ->where('status', 'pending')
                ->first();

            if (!$order) {
                $bot->sendMessage($chatId, "⚠️ این سفارش پیدا نشد یا قبلاً تایید/رد شده است", UserBotKeyboard::manager());
                return response()->json(['ok' => true]);
            }

            $orderNo = $this->orderNumber($order);

            $order->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'card_sms_match_key' => null,
            ]);

            $bot->sendMessage($chatId, "❌ سفارش شماره {$orderNo} رد شد", UserBotKeyboard::manager());
            $this->deleteBuyerPaymentMessage($bot, $order);
            if ($callbackQueryId) {
                $bot->answerCallbackQuery($callbackQueryId, 'سفارش رد شد');
            }

            if ($callbackMessageId) {
                $bot->editMessageReplyMarkup($chatId, $callbackMessageId);
            }
            $buyerSession = UserBotSession::find($order->session_id);

            if ($buyerSession) {
                $bot->sendMessage(
                    $buyerSession->telegram_chat_id,
                    "❌ رسید شما رد شد\n\n🧾 شماره سفارش: <b>{$orderNo}</b>",
                    $buyerSession->is_logged_in ? UserBotKeyboard::user($this->isLoggedInVolumeAccount($buyerSession)) : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner)
                );
            }

            return response()->json(['ok' => true]);
        }

        if ($text === '🔐 ورود') {
            $session->update(['state' => 'waiting_login_link']);
            $bot->sendMessage($chatId, "🔐 لطفاً لینک اکانت خود را ارسال کنید", UserBotKeyboard::back());
            return response()->json(['ok' => true]);
        }

        if ($session->state === 'waiting_login_link') {
            $resolved = app(UserBotAccountResolver::class)->resolve($setting, $text);

            if (!$resolved) {
                $bot->sendMessage(
                    $chatId,
                    "⛔ دسترسی غیرمجاز\n\nلطفاً لینک صحیح اکانت خود را ارسال کنید",
                    UserBotKeyboard::back()
                );

                return response()->json(['ok' => true]);
            }

            $session->update([
                'is_logged_in' => true,
                'account_email' => $resolved['email'],
                'account_sub_link' => $resolved['sub_link'],
                'account_snapshot' => $resolved['snapshot'],
                'state' => null,
                'state_data' => null,
            ]);

            $username = $this->formatUsername($resolved['email'] ?? null);

            $bot->sendMessage(
                $chatId,
                "✅ ورود با موفقیت انجام شد\n\n👤 اکانت: <code>{$username}</code>",
                UserBotKeyboard::user($this->isSnapshotVolumeAccount($resolved['snapshot'] ?? []))
            );

            return response()->json(['ok' => true]);
        }

        if (
            in_array($text, [
                '🧾 رسیدهای بررسی‌ نشده',
                '🧾 رسیدهای بررسی ‌نشده',
                '🧾 رسیدهای بررسی نشده',
            ], true)
            && (string) $chatId === (string) $setting->owner_telegram_chat_id
            && $session->state === 'manager'
        ) {
            $this->resendPendingUserBotOrders($bot, $setting, $chatId);

            return response()->json(['ok' => true]);
        }

        if ($text === '🚪 خروج') {
            $session->update([
                'is_logged_in' => false,
                'account_email' => null,
                'account_sub_link' => null,
                'account_snapshot' => null,
                'state' => null,
                'state_data' => null,
            ]);

            $bot->sendMessage($chatId, "🚪 شما از حساب خارج شدید", UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner));
            return response()->json(['ok' => true]);
        }

        if ($text === '👤 مشاهده اطلاعات کاربری') {
            if (!$session->is_logged_in) {
                $session->update(['state' => 'waiting_login_link']);

                $bot->sendMessage(
                    $chatId,
                    "🔐 لطفاً ابتدا به اکانت خود وارد شوید\n\nلینک اکانت خود را ارسال کنید",
                    UserBotKeyboard::back()
                );

                return response()->json(['ok' => true]);
            }

            $resolver = app(UserBotAccountResolver::class);
            $resolved = $resolver->findCurrent($setting, $session->account_sub_link);

            if (!$resolved) {
                $session->update([
                    'is_logged_in' => false,
                    'account_email' => null,
                    'account_sub_link' => null,
                    'account_snapshot' => null,
                    'state' => 'waiting_login_link',
                ]);

                $bot->sendMessage(
                    $chatId,
                    "⛔ دسترسی غیرمجاز\n\nلطفاً دوباره لینک اکانت خود را ارسال کنید",
                    UserBotKeyboard::back()
                );

                return response()->json(['ok' => true]);
            }

            $session->update([
                'account_email' => $resolved['email'],
                'account_sub_link' => $resolved['sub_link'],
                'account_snapshot' => $resolved['snapshot'],
            ]);

            $bot->sendQrPhotoOrMessage(
                $chatId,
                (string) $resolved['sub_link'],
                $resolver->accountInfoText($resolved['snapshot'], $resolved['sub_link']),
                UserBotKeyboard::user($this->isSnapshotVolumeAccount($resolved['snapshot'] ?? []))
            );

            return response()->json(['ok' => true]);
        }

        if (in_array($text, ['🔄 تمدید اکانت', '➕ خرید ترافیک'], true)) {
            if (!$session->is_logged_in) {
                $session->update(['state' => 'waiting_login_link']);

                $bot->sendMessage(
                    $chatId,
                    "🔐 لطفاً ابتدا به اکانت خود وارد شوید\n\nلینک اکانت خود را ارسال کنید",
                    UserBotKeyboard::back()
                );

                return response()->json(['ok' => true]);
            }

            if ($text === '➕ خرید ترافیک') {
                if ($this->blockExpiredTimeAccountTrafficPurchase($bot, $setting, $session, $chatId)) {
                    return response()->json(['ok' => true]);
                }

                $session->refresh();
                $isVolumeAccount = $this->isLoggedInVolumeAccount($session);

                $trafficPlans = UserBotPlan::orderedForSetting((int) $setting->id)
                    ->filter(fn ($p) => $isVolumeAccount
                        ? $this->isVolumePlanKey((string) $p->plan_key)
                        : $this->isTrafficPlanKey((string) $p->plan_key)
                    )
                    ->filter(fn ($p) => $this->ownerCanSeeUserBotPlan($setting, (string) $p->plan_key))
                    ->values();

                if ($trafficPlans->isEmpty()) {
                    $bot->sendMessage($chatId, "⚠️ تعرفه ترافیک اضافه فعال نیست", UserBotKeyboard::user($isVolumeAccount));
                    return response()->json(['ok' => true]);
                }

                if ($isVolumeAccount && $trafficPlans->count() > 1) {
                    $groups = $trafficPlans->map(fn ($p) => $this->groupNameOf($p))->unique()->values()->all();

                    $session->update([
                        'state' => 'traffic_select_group',
                        'state_data' => [
                            'plans' => $trafficPlans->map(fn ($p) => [
                                'id' => $p->id,
                                'plan_key' => $p->plan_key,
                                'group_name' => $this->groupNameOf($p),
                                'title' => $p->title,
                                'price' => (int) $p->price,
                                'description' => $p->description,
                            ])->values()->all(),
                        ],
                    ]);

                    $bot->sendMessage(
                        $chatId,
                        "➕ لطفاً طرح حجمی مورد نظر برای خرید ترافیک را انتخاب کنید:",
                        UserBotKeyboard::planGroups($groups)
                    );

                    return response()->json(['ok' => true]);
                }

                $trafficPlan = $trafficPlans->first();

                $session->update([
                    'state' => 'traffic_waiting_gb',
                    'state_data' => [
                        'traffic_plan' => [
                            'plan_key' => $trafficPlan->plan_key,
                            'group_name' => $this->groupNameOf($trafficPlan),
                            'title' => $trafficPlan->title,
                            'price' => (int) $trafficPlan->price,
                            'description' => $trafficPlan->description,
                        ],
                    ],
                ]);

                $hasNoTrafficReset = !$isVolumeAccount
                    && !app(\App\Services\UserBot\AccountTrafficResetPolicy::class)->hasPeriodicReset($session->account_snapshot ?: []);

                $timeAccountWarning = $isVolumeAccount
                    ? ''
                    : "⚠️ <b>نکات مهم:</b>\n"
                        . ($hasNoTrafficReset ? '' : "• ترافیک ماهانه اکانت ها، در ابتدای هر ماه شمسی ریست می‌شود.\n")
                        . "• خرید ترافیک اضافه باعث تمدید اکانت نمی‌شود. برای تمدید، از گزینه «🔄 تمدید اکانت» استفاده کنید.\n\n";

                $bot->sendMessage(
                    $chatId,
                    "➕ <b>" . ($isVolumeAccount ? "خرید ترافیک" : "خرید ترافیک اضافه") . "</b>\n\n"
                    . "👤 نام کاربری: <code>" . $this->formatUsername($session->account_email) . "</code>\n"
                    . "💰 قیمت هر گیگ: <b>" . number_format((int) $trafficPlan->price) . " تومان</b>\n\n"
                    . $timeAccountWarning
                    . ($isVolumeAccount ? \App\Services\VolumeTrafficPolicy::label(\App\Services\VolumeTrafficPolicy::minimumForPlanKey((string) $trafficPlan['plan_key'])) . "\n\n" : '')
                    . "لطفاً مقدار ترافیک مورد نظر را به گیگ وارد کنید:",
                    UserBotKeyboard::back()
                );

                return response()->json(['ok' => true]);
            }

            if ($text === '🔄 تمدید اکانت') {

                if ($isVolumeAccount) {
                    $bot->sendMessage($chatId, "⚠️ برای پلن حجمی، گزینه تمدید فعال نیست", UserBotKeyboard::user($isVolumeAccount));
                    return response()->json(['ok' => true]);
                }

                $plans = UserBotPlan::orderedForSetting((int) $setting->id)
                    ->filter(fn ($p) => $this->ownerCanSeeUserBotPlan($setting, (string) $p->plan_key))
                    ->reject(fn ($p) =>
                        $this->isVolumePlanKey((string) $p->plan_key)
                        || $this->isTrafficPlanKey((string) $p->plan_key)
                        || $this->isTestPlan($p)
                    );

                if ($plans->isEmpty()) {
                    $bot->sendMessage($chatId, "⚠️ هیچ طرح تمدید فعالی وجود ندارد", UserBotKeyboard::user($isVolumeAccount));
                    return response()->json(['ok' => true]);
                }

                $groups = $plans->map(fn ($p) => $this->groupNameOf($p))->unique()->values()->all();

                $session->update([
                    'state' => 'renew_select_group',
                    'state_data' => [
                        'plans' => $plans->map(fn ($p) => [
                            'id' => $p->id,
                            'plan_key' => $p->plan_key,
                            'group_name' => $this->groupNameOf($p),
                            'title' => $p->title,
                            'price' => (int) $p->price,
                            'description' => $p->description,
                        ])->values()->all(),
                    ],
                ]);

                $bot->sendMessage(
                    $chatId,
                    "🔄 لطفاً طرح مورد نظر برای تمدید را انتخاب کنید:",
                    UserBotKeyboard::planGroups($groups)
                );

                return response()->json(['ok' => true]);
            }
        }

        if ($session->state === 'traffic_select_group' && str_starts_with($text, '📁 ')) {
            $groupName = trim(str_replace('📁 ', '', $text));
            $stateData = $session->state_data ?: [];

            $trafficPlan = collect($stateData['plans'] ?? [])
                ->firstWhere('group_name', $groupName);

            if (!$trafficPlan) {
                $bot->sendMessage($chatId, "⚠️ طرح حجمی انتخاب‌شده معتبر نیست", UserBotKeyboard::back());
                return response()->json(['ok' => true]);
            }

            $session->update([
                'state' => 'traffic_waiting_gb',
                'state_data' => [
                    'traffic_plan' => $trafficPlan,
                ],
            ]);

            $bot->sendMessage(
                $chatId,
                "➕ <b>خرید ترافیک</b>\n\n"
                . "👤 نام کاربری: <code>" . $this->formatUsername($session->account_email) . "</code>\n"
                . "💰 قیمت هر گیگ: <b>" . number_format((int) ($trafficPlan['price'] ?? 0)) . " تومان</b>\n\n"
                . ($isVolumeAccount ? \App\Services\VolumeTrafficPolicy::label(\App\Services\VolumeTrafficPolicy::minimumForPlanKey((string) $trafficPlan['plan_key'])) . "\n\n" : '')
                . "لطفاً مقدار ترافیک مورد نظر را به گیگ وارد کنید:",
                UserBotKeyboard::back()
            );

            return response()->json(['ok' => true]);
        }

        if (in_array($session->state, ['buy_select_group', 'renew_select_group'], true) && str_starts_with($text, '📁 ')) {
            $groupName = trim(str_replace('📁 ', '', $text));
            $stateData = $session->state_data ?: [];

            $plans = collect($stateData['plans'] ?? [])
                ->where('group_name', $groupName)
                ->values();

            if ($plans->isEmpty()) {
                $bot->sendMessage($chatId, "⚠️ طرح انتخاب‌شده معتبر نیست", UserBotKeyboard::back());
                return response()->json(['ok' => true]);
            }

            if ($session->state === 'buy_select_group' && $plans->count() === 1 && $this->isVolumePlanKey((string)($plans->first()['plan_key'] ?? ''))) {
                $selected = $plans->first();

                $session->update([
                    'state' => 'buy_waiting_volume_gb',
                    'state_data' => [
                        'order_type' => 'buy_account',
                        'selected_plan' => $selected,
                    ],
                ]);

                $bot->sendMessage(
                    $chatId,
                    "📦 <b>" . e($selected['group_name'] ?? $selected['title']) . "</b>\n\n"
                    . "📝 " . e((string)($selected['description'] ?? '')) . "\n\n"
                    . "💰 قیمت هر گیگ: <b>" . number_format((int)($selected['price'] ?? 0)) . " تومان</b>\n\n"
                    . \App\Services\VolumeTrafficPolicy::label(\App\Services\VolumeTrafficPolicy::minimumForPlanKey((string) $selected['plan_key'])) . "\n\n"
                    . "لطفاً مقدار حجم مورد نظر را به گیگ وارد کنید:",
                    UserBotKeyboard::back()
                );

                return response()->json(['ok' => true]);
            }

            $session->update([
                'state' => $session->state === 'buy_select_group' ? 'buy_select_plan' : 'renew_select_plan',
                'state_data' => [
                    'plans' => $plans->all(),
                ],
            ]);

            $bot->sendMessage(
                $chatId,
                "🧾 لطفاً طرح مورد نظر را انتخاب کنید:",
                UserBotKeyboard::plans($plans->all())
            );

            return response()->json(['ok' => true]);
        }

        if ($session->state === 'renew_select_plan' && str_starts_with($text, '🧾 ')) {
            $title = trim(str_replace('🧾 ', '', $text));
            $title = trim(preg_replace('/\s+-\s+[\d,]+\s+تومان$/u', '', $title));
            $title = trim(preg_replace('/\s+-\s+هر گیگ\s+[\d,]+\s+تومان$/u', '', $title));
            $stateData = $session->state_data ?: [];

            $selected = collect($stateData['plans'] ?? [])->firstWhere('title', $title);

            if (!$selected) {
                $bot->sendMessage($chatId, "⚠️ طرح انتخاب‌شده معتبر نیست", UserBotKeyboard::back());
                return response()->json(['ok' => true]);
            }

            $amount = (int) $selected['price'];

            $session->update([
                'state' => 'buy_select_payment_method',
                'state_data' => [
                    'order_type' => 'renew_account',
                    'selected_plan' => $selected,
                    'amount' => $amount,
                    'traffic_gb' => null,
                ],
            ]);

            $this->sendPaymentMethodSelection($bot, $setting, $session, $chatId);

            return response()->json(['ok' => true]);

        }

        if ($session->state === 'buy_select_plan' && str_starts_with($text, '🧾 ')) {
            $title = trim(str_replace('🧾 ', '', $text));
            $title = trim(preg_replace('/\s+-\s+[\d,]+\s+تومان$/u', '', $title));
            $title = trim(preg_replace('/\s+-\s+هر گیگ\s+[\d,]+\s+تومان$/u', '', $title));
            $stateData = $session->state_data ?: [];

            $selected = collect($stateData['plans'] ?? [])->firstWhere('title', $title);

            if (!$selected) {
                $bot->sendMessage($chatId, "⚠️ طرح انتخاب‌شده معتبر نیست", UserBotKeyboard::back());
                return response()->json(['ok' => true]);
            }

            $isVolume = $this->isVolumePlanKey((string) ($selected['plan_key'] ?? ''));

            if ($isVolume) {
                $session->update([
                    'state' => 'buy_waiting_volume_gb',
                    'state_data' => [
                        'order_type' => 'buy_account',
                        'selected_plan' => $selected,
                    ],
                ]);

                $bot->sendMessage(
                    $chatId,
                    "📦 <b>{$selected['title']}</b>\n\n"
                    . "📝 {$selected['description']}\n\n"
                    . "💰 قیمت هر گیگ: <b>" . number_format((int) $selected['price']) . " تومان</b>\n\n"
                    . \App\Services\VolumeTrafficPolicy::label(\App\Services\VolumeTrafficPolicy::minimumForPlanKey((string) $selected['plan_key'])) . "\n\n"
                    . "لطفاً مقدار حجم مورد نظر را به گیگ وارد کنید:",
                    UserBotKeyboard::back()
                );

                return response()->json(['ok' => true]);
            }

            $amount = (int) $selected['price'];

            $session->update([
                'state' => 'buy_select_payment_method',
                'state_data' => [
                    'order_type' => 'buy_account',
                    'selected_plan' => $selected,
                    'amount' => $amount,
                    'traffic_gb' => null,
                ],
            ]);

            $this->sendPaymentMethodSelection($bot, $setting, $session, $chatId);

            return response()->json(['ok' => true]);
        }

        if ($session->state === 'buy_waiting_volume_gb') {
            $gb = $this->normalizePositiveInt($text);

            if ($gb <= 0) {
                $bot->sendMessage($chatId, "⚠️ لطفاً مقدار حجم را به عدد وارد کنید. مثال: 10", UserBotKeyboard::back());
                return response()->json(['ok' => true]);
            }

            $stateData = $session->state_data ?: [];
            $selected = $stateData['selected_plan'] ?? null;

            if (!$selected) {
                $session->update(['state' => null, 'state_data' => null]);
                $bot->sendMessage($chatId, "⚠️ اطلاعات طرح پیدا نشد. دوباره خرید را شروع کنید", UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner));
                return response()->json(['ok' => true]);
            }

            $minimum = \App\Services\VolumeTrafficPolicy::minimumForPlanKey((string) $selected['plan_key']);
            if ($gb < $minimum) {
                $bot->sendMessage($chatId, \App\Services\VolumeTrafficPolicy::message($minimum), UserBotKeyboard::back());
                return response()->json(['ok' => true]);
            }

            $amount = $gb * (int) $selected['price'];

            $session->update([
                'state' => 'buy_select_payment_method',
                'state_data' => [
                    'order_type' => 'buy_account',
                    'selected_plan' => $selected,
                    'amount' => $amount,
                    'traffic_gb' => $gb,
                ],
            ]);

            $this->sendPaymentMethodSelection($bot, $setting, $session, $chatId);

            return response()->json(['ok' => true]);
        }

        if ($session->state === 'traffic_waiting_gb') {
            if ($this->blockExpiredTimeAccountTrafficPurchase($bot, $setting, $session, $chatId)) {
                return response()->json(['ok' => true]);
            }

            $session->refresh();
            $isVolumeAccount = $this->isLoggedInVolumeAccount($session);

            $gb = $this->normalizePositiveInt($text);

            if ($gb <= 0) {
                $bot->sendMessage($chatId, "⚠️ لطفاً مقدار ترافیک را به عدد وارد کنید. مثال: 10", UserBotKeyboard::back());
                return response()->json(['ok' => true]);
            }

            $stateData = $session->state_data ?: [];
            $trafficPlan = $stateData['traffic_plan'] ?? null;

            if (!$trafficPlan) {
                $session->update(['state' => null, 'state_data' => null]);
                $bot->sendMessage($chatId, "⚠️ تعرفه ترافیک پیدا نشد. دوباره تلاش کنید", UserBotKeyboard::user($isVolumeAccount));
                return response()->json(['ok' => true]);
            }

            $minimum = $this->isVolumePlanKey((string) ($trafficPlan['plan_key'] ?? ''))
                ? \App\Services\VolumeTrafficPolicy::minimumForPlanKey((string) $trafficPlan['plan_key']) : 1;
            if ($gb < $minimum) {
                $bot->sendMessage($chatId, \App\Services\VolumeTrafficPolicy::message($minimum), UserBotKeyboard::back());
                return response()->json(['ok' => true]);
            }

            $amount = $gb * (int) $trafficPlan['price'];

            $session->update([
                'state' => 'buy_select_payment_method',
                'state_data' => [
                    'order_type' => 'buy_traffic',
                    'selected_plan' => $trafficPlan,
                    'amount' => $amount,
                    'traffic_gb' => $gb,
                ],
            ]);

            $this->sendPaymentMethodSelection($bot, $setting, $session, $chatId);

            return response()->json(['ok' => true]);
        }
        
        if ($session->state === 'buy_select_payment_method') {
            $stateData = $session->state_data ?: [];

            if (
                ($stateData['order_type'] ?? null) === 'buy_traffic'
                && $this->blockExpiredTimeAccountTrafficPurchase($bot, $setting, $session, $chatId)
            ) {
                return response()->json(['ok' => true]);
            }

            $session->refresh();
            $isVolumeAccount = $session->is_logged_in
                ? $this->isLoggedInVolumeAccount($session)
                : ($isVolumePartner && !$isBothPartner);

            if ($text === '💳 کارت به کارت') {
                if (!(bool) $setting->card_payment_enabled) {
                    $bot->sendMessage(
                        $chatId,
                        "⛔ روش پرداخت کارت به کارت فعال نیست",
                        UserBotKeyboard::paymentMethods(
                            false,
                            (bool) $setting->crypto_payment_enabled
                        )
                    );

                    return response()->json(['ok' => true]);
                }

                /*
                * فقط وقتی قابلیت پیامکی روشن است،
                * سفارش موقت و مبلغ دقیق ساخته می‌شود.
                */
                if ((bool) $setting->card_sms_auto_approve_enabled) {
                    try {
                        $cardOrder = app(
                            UserBotCardPaymentIntentService::class
                        )->create(
                            $setting,
                            $session,
                            $stateData
                        );

                        $stateData['card_sms_order_id'] = $cardOrder->id;

                        $stateData['card_sms_payable_amount'] =
                            (int) $cardOrder->card_sms_payable_amount;

                        $stateData['card_sms_expires_at'] =
                            optional(
                                $cardOrder->card_sms_expires_at
                            )->toIso8601String();
                    } catch (\Throwable $e) {
                        report($e);

                        $bot->sendMessage(
                            $chatId,
                            "⚠️ در حال حاضر امکان ساخت مبلغ دقیق وجود ندارد. چند لحظه بعد دوباره تلاش کنید.",
                            UserBotKeyboard::back()
                        );

                        return response()->json(['ok' => true]);
                    }
                }

                $session->update([
                    'state' => 'buy_waiting_receipt',
                    'state_data' => $stateData,
                ]);

                $this->sendCardPaymentMessage(
                    $bot,
                    $setting,
                    $session->fresh()
                );

                return response()->json(['ok' => true]);
            }

            if ($text === '💲 ارز دیجیتال') {
                if (!(bool) $setting->crypto_payment_enabled || $this->activeUserBotCryptoOptions($setting)->isEmpty()) {
                    $bot->sendMessage($chatId, "⛔ روش پرداخت ارز دیجیتال فعال نیست", UserBotKeyboard::paymentMethods((bool) $setting->card_payment_enabled, false));
                    return response()->json(['ok' => true]);
                }

                $session->update([
                    'state' => 'buy_select_crypto_option',
                    'state_data' => $stateData,
                ]);

                $bot->sendMessage(
                    $chatId,
                    "💲 ارز و شبکه پرداخت را انتخاب کنید:",
                    UserBotKeyboard::cryptoOptions($this->activeUserBotCryptoOptions($setting)->toArray())
                );

                return response()->json(['ok' => true]);
            }

            $bot->sendMessage(
                $chatId,
                "💳 لطفاً روش پرداخت را انتخاب کنید:",
                UserBotKeyboard::paymentMethods((bool) $setting->card_payment_enabled, (bool) $setting->crypto_payment_enabled)
            );

            return response()->json(['ok' => true]);
        }

        if ($session->state === 'buy_select_crypto_option') {
            $option = $this->detectUserBotCryptoOptionFromText($setting, $text);

            if (!$option) {
                $bot->sendMessage(
                    $chatId,
                    "لطفاً یکی از ارزهای فعال جهت انتقال را انتخاب کنید:",
                    UserBotKeyboard::cryptoOptions($this->activeUserBotCryptoOptions($setting)->toArray())
                );
                return response()->json(['ok' => true]);
            }

            $stateData = $session->state_data ?: [];
            $stateData['crypto_asset'] = $option->asset;
            $stateData['crypto_network'] = $option->network;

            $session->update([
                'state' => 'buy_select_payment_method',
                'state_data' => $stateData,
            ]);

            $this->createUserBotCryptoOrder(
                $bot,
                $setting,
                $session->fresh(),
                $chatId,
                $isVolumePartner,
                $isBothPartner,
                $isVolumeAccount
            );

            return response()->json(['ok' => true]);
        }

        if ($session->state === 'buy_waiting_receipt') {
            $stateData = $session->state_data ?: [];
            $selected = $stateData['selected_plan'] ?? null;
            $amount = (int) ($stateData['amount'] ?? 0);
            $trafficGb = $stateData['traffic_gb'] ?? null;
            $orderType = $stateData['order_type'] ?? 'buy_account';

            if (
                $orderType === 'buy_traffic'
                && $this->blockExpiredTimeAccountTrafficPurchase($bot, $setting, $session, $chatId)
            ) {
                return response()->json(['ok' => true]);
            }

            $session->refresh();
            $isVolumeAccount = $session->is_logged_in
                ? $this->isLoggedInVolumeAccount($session)
                : ($isVolumePartner && !$isBothPartner);

            if (!$selected || $amount <= 0) {
                $session->update(['state' => null, 'state_data' => null]);
                $bot->sendMessage($chatId, "⚠️ اطلاعات سفارش ناقص است. لطفاً دوباره شروع کنید", UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner));
                return response()->json(['ok' => true]);
            }

            $fileId = null;
            $fileUniqueId = null;

            if (is_array($photo) && count($photo)) {
                $bestPhoto = end($photo);
                $fileId = $bestPhoto['file_id'] ?? null;
                $fileUniqueId = $bestPhoto['file_unique_id'] ?? null;
            } elseif (is_array($document)) {
                $fileId = $document['file_id'] ?? null;
                $fileUniqueId = $document['file_unique_id'] ?? null;
            }

            if (!$fileId) {
                $bot->sendMessage($chatId, "📎 لطفاً تصویر رسید پرداخت را ارسال کنید", UserBotKeyboard::back());
                return response()->json(['ok' => true]);
            }

            $cardSmsOrderId = (int) (
                $stateData['card_sms_order_id'] ?? 0
            );

            $orderPayload = [
                'selected_plan' => $selected,
                'telegram_chat_id' => $chatId,
                'telegram_username' => $session->telegram_username,
                'payment_message_id' =>
                    $stateData['payment_message_id'] ?? null,
            ];

            $receiptResult = DB::transaction(function () use (
                $cardSmsOrderId,
                $setting,
                $session,
                $fileId,
                $fileUniqueId,
                $orderPayload,
                $orderType,
                $selected,
                $trafficGb,
                $amount
            ) {
                if ($cardSmsOrderId > 0) {
                    $order = UserBotOrder::query()
                        ->where('id', $cardSmsOrderId)
                        ->where('setting_id', $setting->id)
                        ->where('session_id', $session->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$order) {
                        return [
                            'ok' => false,
                            'state' => 'order_not_found',
                            'order' => null,
                        ];
                    }

                    if (
                        $order->card_sms_paid_at
                        || in_array(
                            $order->status,
                            [
                                'done',
                                'card_processing',
                                'card_retry_pending',
                            ],
                            true
                        )
                    ) {
                        return [
                            'ok' => false,
                            'state' => 'payment_confirmed',
                            'order' => $order->fresh(),
                        ];
                    }

                    if ($order->status === 'pending') {
                        return [
                            'ok' => false,
                            'state' => 'already_submitted',
                            'order' => $order->fresh(),
                        ];
                    }

                    if (
                        $order->status
                            !== 'card_waiting_payment'
                    ) {
                        return [
                            'ok' => false,
                            'state' => 'not_approvable',
                            'order' => $order->fresh(),
                        ];
                    }

                    $order->update([
                        'status' => 'pending',
                        'receipt_file_id' => $fileId,
                        'receipt_file_unique_id' =>
                            $fileUniqueId,
                        'receipt_submitted_at' => now(),
                        'payload' => array_merge(
                            $order->payload ?: [],
                            $orderPayload
                        ),
                    ]);

                    return [
                        'ok' => true,
                        'state' => 'receipt_registered',
                        'order' => $order->fresh(),
                    ];
                }

                $order = UserBotOrder::create([
                    'setting_id' => $setting->id,
                    'session_id' => $session->id,
                    'order_type' => $orderType,
                    'order_code' =>
                        $this->generateOrderCode(),
                    'plan_key' =>
                        $selected['plan_key'] ?? null,
                    'account_email' =>
                        $session->account_email,
                    'traffic_gb' => $trafficGb
                        ? (int) $trafficGb
                        : null,
                    'amount' => $amount,
                    'payment_method' => 'card',
                    'status' => 'pending',
                    'receipt_file_id' => $fileId,
                    'receipt_file_unique_id' =>
                        $fileUniqueId,
                    'receipt_submitted_at' => now(),
                    'payload' => $orderPayload,
                ]);

                return [
                    'ok' => true,
                    'state' => 'receipt_registered',
                    'order' => $order,
                ];
            });

            if (empty($receiptResult['ok'])) {
                $resultState =
                    $receiptResult['state'] ?? 'failed';

                if ($resultState === 'payment_confirmed') {
                    $this->deletePendingPaymentMessage(
                        $bot,
                        $session
                    );
                }

                $resultMessage = match ($resultState) {
                    'payment_confirmed' =>
                        "✅ پرداخت شما قبلاً از طریق پیامک بانک تأیید شده است و نیازی به ارسال رسید نیست.",
                    'already_submitted' =>
                        "✅ رسید این سفارش قبلاً ثبت شده است و در حال بررسی است.",
                    default =>
                        "⚠️ این سفارش دیگر در وضعیت دریافت رسید نیست. لطفاً از منوی خرید دوباره شروع کنید.",
                };

                $bot->sendMessage(
                    $chatId,
                    $resultMessage,
                    $session->is_logged_in
                        ? UserBotKeyboard::user(
                            $isVolumeAccount
                        )
                        : UserBotKeyboard::guest(
                            (bool) $setting->active_users_only,
                            $isVolumePartner
                                && !$isBothPartner
                        )
                );

                return response()->json(['ok' => true]);
            }

            $order = $receiptResult['order'];

            $this->deletePendingPaymentMessage($bot, $session);

            $orderNo = $this->orderNumber($order);

            $session->update([
                'state' => null,
                'state_data' => null,
            ]);

            $bot->sendMessage(
                $chatId,
                "✅ رسید شما ثبت شد.\n\n🧾 شماره سفارش: <b>{$orderNo}</b>\n⏱ تایید رسید شما ممکن است تا ۳۰ دقیقه زمان ببرد.",
                $session->is_logged_in ? UserBotKeyboard::user($isVolumeAccount) : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner)
            );

            $orderTypeText = match ($orderType) {
                'buy_traffic' => $this->isVolumePlanKey((string)($selected['plan_key'] ?? '')) ? 'خرید ترافیک' : 'خرید ترافیک اضافه',
                'renew_account' => 'تمدید اکانت',
                default => 'خرید اکانت',
            };

            $caption = "🧾 <b>رسید پرداخت جدید</b>\n\n"
                . "🔢 شماره سفارش: <b>{$orderNo}</b>\n"
                . "👤 کاربر تلگرام: <b>" . ($session->telegram_username ? '@' . e($session->telegram_username) : '-') . "</b>\n"
                . "👤 نام کاربری اکانت: <code>" . e($this->formatUsername($session->account_email)) . "</code>\n"
                . "📦 نوع سفارش: <b>{$orderTypeText}</b>\n"
                . "🧩 طرح: <b>" . e(
                    ($orderType === 'buy_traffic' || $this->isVolumePlanKey((string)($selected['plan_key'] ?? '')))
                        ? (
                            !empty($selected['group_name']) && $selected['group_name'] !== 'طرح پیشفرض'
                                ? $selected['group_name']
                                : $this->planFullTitle($selected)
                        )
                        : $this->planFullTitle($selected)
                ) . "</b>\n"
                . ($trafficGb ? "📊 حجم: <b>{$trafficGb} گیگ</b>\n" : "")
                . "💰 مبلغ: <b>" . number_format($amount) . " تومان</b>\n"
                . "🕒 زمان: <b>" . JalaliDateFormatter::forTelegram(now(), 'H:i d-m-Y') . "</b>";

                if ($setting->owner_telegram_chat_id) {
                    $sent = $bot->sendPhoto(
                        $setting->owner_telegram_chat_id,
                        $fileId,
                        $caption,
                        UserBotKeyboard::approveReject($order->id)
                    );

                    $managerMessageId = data_get($sent, 'result.message_id');

                    if ($managerMessageId) {
                        $payload = $order->payload ?: [];
                        $payload['manager_receipt_chat_id'] = (string) $setting->owner_telegram_chat_id;
                        $payload['manager_receipt_message_id'] = (int) $managerMessageId;

                        $order->payload = $payload;
                        $order->save();
                    }
                }

            return response()->json(['ok' => true]);
        }

        if ($text === '🛒 خرید اکانت') {
            if ((bool) $setting->active_users_only && !$session->is_logged_in) {
                $session->update(['state' => 'waiting_login_link']);

                $bot->sendMessage(
                    $chatId,
                    "🔐 برای خرید اکانت، ابتدا باید وارد شوید.\n\nلینک اکانت خود را ارسال کنید",
                    UserBotKeyboard::back()
                );

                return response()->json(['ok' => true]);
            }

            $rawPlans = UserBotPlan::orderedForSetting((int) $setting->id)
                ->filter(fn ($p) => $this->ownerCanSeeUserBotPlan($setting, (string) $p->plan_key))
                ->reject(fn ($p) => $this->isTrafficPlanKey((string) $p->plan_key))
                ->values();

            $plans = $rawPlans
                ->reject(fn ($p) => $this->isVolumePlanKey((string) $p->plan_key))
                ->concat(
                    $rawPlans->filter(fn ($p) => $this->isVolumePlanKey((string) $p->plan_key))
                )
                ->values();

            if ($plans->isEmpty()) {
                $bot->sendMessage(
                    $chatId,
                    "⚠️ در حال حاضر هیچ طرح فعالی برای خرید وجود ندارد",
                    $session->is_logged_in ? UserBotKeyboard::user($isVolumeAccount) : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner)
                );
                return response()->json(['ok' => true]);
            }

            $groups = $plans->map(fn ($p) => $this->groupNameOf($p))->unique()->values()->all();

            $session->update([
                'state' => 'buy_select_group',
                'state_data' => [
                    'plans' => $plans->map(fn ($p) => [
                        'id' => $p->id,
                        'plan_key' => $p->plan_key,
                        'group_name' => $this->groupNameOf($p),
                        'title' => $p->title,
                        'price' => (int) $p->price,
                        'description' => $p->description,
                    ])->values()->all(),
                ],
            ]);

            $bot->sendMessage(
                $chatId,
                "🛒 لطفاً طرح مورد نظر را انتخاب کنید:",
                UserBotKeyboard::planGroups($groups)
            );

            return response()->json(['ok' => true]);
        }

        if ($text === '📘 راهنمای اتصال') {
            if ((bool) $setting->active_users_only && !$session->is_logged_in) {
                $session->update(['state' => 'waiting_login_link']);

                $bot->sendMessage(
                    $chatId,
                    "🔐 برای مشاهده راهنمای اتصال، ابتدا باید وارد شوید.\n\nلینک اکانت خود را ارسال کنید",
                    UserBotKeyboard::back()
                );

                return response()->json(['ok' => true]);
            }

            $session->update([
                'state' => 'help_select_platform',
                'state_data' => null,
            ]);

            $bot->sendMessage(
                $chatId,
                "📘 لطفاً سیستم‌عامل خود را انتخاب کنید:",
                UserBotKeyboard::helpPlatforms()
            );

            return response()->json(['ok' => true]);
        }

        if ($session->state === 'help_select_platform') {
            $helpText = match ($text) {
                '📱 iOS' => $setting->help_ios,
                '🤖 Android' => $setting->help_android,
                '🖥 Windows' => $setting->help_windows,
                default => null,
            };

            if (!$helpText) {
                $bot->sendMessage(
                    $chatId,
                    "⚠️ گزینه انتخاب‌شده معتبر نیست",
                    UserBotKeyboard::helpPlatforms()
                );

                return response()->json(['ok' => true]);
            }

            $session->update([
                'state' => null,
                'state_data' => null,
            ]);

            $this->sendLongHelpMessage(
                $bot,
                $chatId,
                (string) $helpText,
                $session->is_logged_in
                    ? UserBotKeyboard::user($isVolumeAccount)
                    : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner)
            );

            return response()->json(['ok' => true]);
        }

        if (
            (string) $chatId === (string) $setting->owner_telegram_chat_id
            && $session->state === 'manager'
        ) {
            $bot->sendMessage(
                $chatId,
                "👋 مدیر عزیز\n\n"
                . "هر زمان رسید جدیدی ارسال شود، برای شما نمایش داده می‌شود",
                UserBotKeyboard::manager()
            );

            return response()->json(['ok' => true]);
        }     

        $this->sendHome($bot, $setting, $session);

        return response()->json(['ok' => true]);
    }

    private function ownerCanSeeUserBotPlan(UserBotSetting $setting, string $planKey): bool
    {
        $groupId = $this->extractTariffGroupIdFromUserBotPlanKey($planKey);

        if (!$groupId) {
            return true;
        }

        $normalizedKey = $this->normalizeUserBotPlanKey($planKey);

        $planIsActive = DB::table("panel_plan as p")
            ->join("panel_tariff_groups as g", "g.id", "=", "p.tariff_group_id")
            ->where("p.tariff_group_id", (int) $groupId)
            ->where("p.plan_key", $normalizedKey)
            ->where("p.enable", 1)
            ->where("g.enable", 1)
            ->whereNotNull("p.v2_plan_id")
            ->exists();

        if (!$planIsActive) {
            return false;
        }

        $owner = DB::table("panel_users")
            ->where("id", (int) $setting->owner_id)
            ->first();

        if (!$owner) {
            return false;
        }

        return app(PartnerTariffAccessService::class)->canSeeTariffGroup($owner, (int) $groupId);
    }

    private function extractTariffGroupIdFromUserBotPlanKey(string $planKey): ?int
    {
        $key = strtolower(trim($planKey));

        if (preg_match('/^(\d+)_/', $key, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function normalizeUserBotPlanKey(string $planKey): string
    {
        $key = strtolower(trim($planKey));

        if (preg_match('/^\d+_(volume_gig|traffic_gig|test|1m|3m|6m|12m|gig)$/', $key, $m)) {
            return $m[1] === 'traffic_gig' ? 'gig' : $m[1];
        }

        return $key;
    }

    private function isTrafficPlanKey(string $key): bool
    {
        return $key === 'traffic_gig'
            || (bool) preg_match('/^\d+_(gig|traffic_gig)$/', $key);
    }

    private function isTestPlan($plan): bool
    {
        return str_contains((string) $plan->plan_key, '_test')
            || str_contains((string) $plan->plan_key, 'test')
            || str_contains((string) $plan->title, 'تست');
    }

    private function groupNameOf($plan): string
    {
        return trim((string) ($plan->group_name ?: 'طرح پیشفرض'));
    }

    private function formatUsername(?string $email): string
    {
        if (!$email) {
            return '';
        }

        $name = str_replace('@', '-', $email);
        $name = str_replace('.com', '', $name);

        return $name;
    }    

    private function planFullTitle(array $plan): string
    {
        $title = trim((string)($plan['title'] ?? ''));
        $group = trim((string)($plan['group_name'] ?? ''));
        $planKey = trim((string)($plan['plan_key'] ?? ''));

        if (
            str_contains($planKey, 'test') ||
            str_contains($title, 'تست')
        ) {
            return $title ?: 'اکانت تست';
        }

        if ($this->isVolumePlanKey($planKey)) {
            if ($group && $group !== 'طرح پیشفرض') {
                return $group;
            }

            return $title ?: 'اکانت حجمی یک ماهه';
        }

        if ($group && $group !== 'طرح پیشفرض') {
            return trim($title . ' ' . $group);
        }

        return $title ?: '-';
    }

    private function isVolumePlanKey(string $key): bool
    {
        return $key === 'volume_gig'
            || (bool) preg_match('/^\d+_volume_gig$/', $key);
    }

    private function isLoggedInVolumeAccount(UserBotSession $session): bool
    {
        return $this->isSnapshotVolumeAccount($session->account_snapshot ?: []);
    }

    private function isSnapshotVolumeAccount(array $snapshot): bool
    {
        if ((string)($snapshot['account_group_type'] ?? '') === 'volume') {
            return true;
        }

        $planId = (int)($snapshot['plan_id'] ?? 0);

        if ($planId <= 0) {
            return false;
        }

        return DB::table('panel_plan as p')
            ->join('panel_tariff_groups as g', 'g.id', '=', 'p.tariff_group_id')
            ->where('p.plan_key', 'volume_gig')
            ->where('p.v2_plan_id', $planId)
            ->where('p.enable', 1)
            ->where('g.group_type', 'volume')
            ->where('g.enable', 1)
            ->exists();
    }

    private function blockExpiredTimeAccountTrafficPurchase(
        UserBotTelegram $bot,
        UserBotSetting $setting,
        UserBotSession $session,
        string|int $chatId
    ): bool {
        if (!$session->is_logged_in) {
            return false;
        }

        $resolved = app(UserBotAccountResolver::class)->findCurrent(
            $setting,
            $session->account_sub_link
        );

        if (!$resolved) {
            $session->update([
                'is_logged_in' => false,
                'account_email' => null,
                'account_sub_link' => null,
                'account_snapshot' => null,
                'state' => 'waiting_login_link',
                'state_data' => null,
            ]);

            $bot->sendMessage(
                $chatId,
                "⛔ دسترسی غیرمجاز\n\nلطفاً دوباره لینک اکانت خود را ارسال کنید",
                UserBotKeyboard::back()
            );

            return true;
        }

        $snapshot = is_array($resolved['snapshot'] ?? null)
            ? $resolved['snapshot']
            : [];

        $session->update([
            'account_email' => $resolved['email'] ?? $session->account_email,
            'account_sub_link' => $resolved['sub_link'] ?? $session->account_sub_link,
            'account_snapshot' => $snapshot,
        ]);

        if ($this->isSnapshotVolumeAccount($snapshot)) {
            return false;
        }

        if (\App\Services\SubscriptionValidity::unexpired($snapshot['expired_at'] ?? null)) {
            return false;
        }

        $session->update([
            'state' => null,
            'state_data' => null,
        ]);

        $bot->sendMessage(
            $chatId,
            "⛔️ اکانت شما منقضی شده است.\n\n"
            . "امکان خرید ترافیک اضافه برای اکانت منقضی‌شده وجود ندارد.\n"
            . "برای فعال‌سازی مجدد اکانت، از گزینه «🔄 تمدید اکانت» استفاده کنید.",
            UserBotKeyboard::user(false)
        );

        return true;
    }

    private function isVolumePartner(UserBotSetting $setting): bool
    {
        return DB::table('panel_users')
            ->where('id', $setting->owner_id)
            ->value('partner_plan_type') === 'volume';
    }

    private function isBothPartner(UserBotSetting $setting): bool
    {
        return DB::table('panel_users')
            ->where('id', $setting->owner_id)
            ->value('partner_plan_type') === 'both';
    }

    private function isVolumeOrder(UserBotOrder $order): bool
    {
        $payload = $order->payload ?: [];
        $selected = $payload['selected_plan'] ?? [];

        return $this->isVolumePlanKey((string) $order->plan_key)
            || $this->isVolumePlanKey((string) ($selected['plan_key'] ?? ''));
    }

    private function formatTimestamp($timestamp): string
    {
        $timestamp = (int) $timestamp;

        if ($timestamp <= 0) {
            return '-';
        }

        return JalaliDateFormatter::forTelegram($timestamp, 'H:i d-m-Y', '-');
    }

    private function formatTrafficBytes($bytes): string
    {
        if ($bytes === null || $bytes === '') {
            return '-';
        }

        $gb = abs((int) $bytes) / 1024 / 1024 / 1024;

        return rtrim(rtrim(number_format($gb, 2), '0'), '.') . ' گیگ';
    }

    private function resendPendingUserBotOrders(UserBotTelegram $bot, UserBotSetting $setting, string|int $chatId): void
    {
        $orders = UserBotOrder::where('setting_id', $setting->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            $bot->sendMessage(
                $chatId,
                "✅ کلیه رسیدها بررسی شده است",
                UserBotKeyboard::manager()
            );
            return;
        }

        foreach ($orders as $order) {
            $session = UserBotSession::find($order->session_id);
            $payload = $order->payload ?: [];
            $selected = $payload['selected_plan'] ?? [];

            $orderNo = $this->orderNumber($order);

            $orderTypeText = match ($order->order_type) {
                'buy_traffic' => $this->isVolumePlanKey((string)($selected['plan_key'] ?? '')) ? 'خرید ترافیک' : 'خرید ترافیک اضافه',
                'renew_account' => 'تمدید اکانت',
                default => 'خرید اکانت',
            };

            $caption = "🧾 <b>رسید پرداخت بررسی‌ نشده</b>\n\n"
                . "🔢 شماره سفارش: <b>{$orderNo}</b>\n"
                . "👤 کاربر تلگرام: <b>" . (($session && $session->telegram_username) ? '@' . e($session->telegram_username) : '-') . "</b>\n"
                . "👤 نام کاربری اکانت: <code>" . e($this->formatUsername($order->account_email ?? ($session->account_email ?? null))) . "</code>\n"
                . "📦 نوع سفارش: <b>{$orderTypeText}</b>\n"
                . "🧩 طرح: <b>" . e(
                    ($order->order_type === 'buy_traffic' || $this->isVolumePlanKey((string)($selected['plan_key'] ?? '')))
                        ? (
                            !empty($selected['group_name']) && $selected['group_name'] !== 'طرح پیشفرض'
                                ? $selected['group_name']
                                : $this->planFullTitle(is_array($selected) ? $selected : [])
                        )
                        : $this->planFullTitle(is_array($selected) ? $selected : [])
                ) . "</b>\n"
                . ($order->traffic_gb ? "📊 حجم: <b>{$order->traffic_gb} گیگ</b>\n" : "")
                . "💰 مبلغ: <b>" . number_format((int)$order->amount) . " تومان</b>\n"
                . "🕒 زمان ثبت: <b>" . JalaliDateFormatter::forTelegram($order->created_at, 'H:i d-m-Y', '-') . "</b>";

            if ($order->receipt_file_id) {
                $bot->sendPhoto(
                    $chatId,
                    (string) $order->receipt_file_id,
                    $caption,
                    UserBotKeyboard::approveReject($order->id)
                );
            } else {
                $bot->sendMessage(
                    $chatId,
                    $caption,
                    UserBotKeyboard::approveReject($order->id)
                );
            }
        }
    }

    private function expirePendingCardSmsOrder(
        UserBotSession $session
    ): void {
        $stateData = $session->state_data ?: [];

        $orderId = (int) (
            $stateData['card_sms_order_id'] ?? 0
        );

        if ($orderId <= 0) {
            return;
        }

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

    private function deletePendingPaymentMessage(UserBotTelegram $bot, UserBotSession $session): void
    {
        $stateData = $session->state_data ?: [];
        $paymentMessageId = $stateData['payment_message_id'] ?? null;

        if (!$paymentMessageId) {
            return;
        }

        try {
            $bot->deleteMessage($session->telegram_chat_id, $paymentMessageId);
        } catch (\Throwable $e) {
            //
        }
    }

    private function deleteBuyerPaymentMessage(UserBotTelegram $bot, UserBotOrder $order): void
    {
        $payload = $order->payload ?: [];

        $buyerChatId = $payload['telegram_chat_id'] ?? null;
        $paymentMessageId = $payload['payment_message_id'] ?? null;

        if (!$buyerChatId || !$paymentMessageId) {
            return;
        }

        try {
            $bot->deleteMessage($buyerChatId, $paymentMessageId);
        } catch (\Throwable $e) {
            // اگر تلگرام اجازه حذف نداد یا پیام قبلاً حذف شده بود، روند تایید/رد نباید خراب شود.
        }
    }

    private function frequentlyAskedQuestionsMenuText(): string
    {
        return app(\App\Services\UserBot\GlobalUserBotFaq::class)->active()->isEmpty()
            ? "❓ در حال حاضر پرسش و پاسخی ثبت نشده است."
            : "❓ <b>سوالات متداول</b>\n\nسؤال موردنظر خود را انتخاب کنید:";
    }


    private function sendLongHelpMessage(UserBotTelegram $bot, string|int $chatId, string $text, array $keyboard): void
    {
        $text = trim($text);

        if ($text === '') {
            $bot->sendMessage($chatId, '⚠️ راهنمایی برای این سیستم‌عامل ثبت نشده است', $keyboard);
            return;
        }

        $chunks = $this->splitLongText($text, 3500);

        foreach ($chunks as $index => $chunk) {

            $message =
                ($index === 0
                    ? "📘 راهنمای اتصال\n\n"
                    : "📘 ادامه راهنمای اتصال\n\n")
                . $chunk;

            $bot->sendPlainMessage(
                $chatId,
                $message,
                $index === count($chunks) - 1 ? $keyboard : null
            );
        }
    }

    private function splitLongText(string $text, int $limit = 3500): array
    {
        $text = trim($text);

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return [$text];
        }

        $chunks = [];
        $lines = preg_split("/\r\n|\n|\r/u", $text);
        $current = '';

        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : $current . "\n" . $line;

            if (mb_strlen($candidate, 'UTF-8') <= $limit) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
                $current = '';
            }

            while (mb_strlen($line, 'UTF-8') > $limit) {
                $chunks[] = mb_substr($line, 0, $limit, 'UTF-8');
                $line = mb_substr($line, $limit, null, 'UTF-8');
            }

            $current = $line;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function shouldBlockForChannelJoin(UserBotSetting $setting, UserBotSession $session): bool
    {
        if (!$setting->force_channel_join_enabled) {
            return false;
        }

        if (blank($setting->force_channel_join_chat)) {
            return false;
        }

        if (
            (string) $session->telegram_chat_id === (string) $setting->owner_telegram_chat_id
            && $session->state === 'manager'
        ) {
            return false;
        }

        return !$this->isUserJoinedChannel($setting, $session);
    }

    private function isUserJoinedChannel(UserBotSetting $setting, UserBotSession $session): bool
    {
        try {
            $res = Http::timeout(15)->post("https://api.telegram.org/bot{$setting->bot_token}/getChatMember", [
                'chat_id' => $setting->force_channel_join_chat,
                'user_id' => $session->telegram_user_id ?: $session->telegram_chat_id,
            ])->json();

            $status = data_get($res, 'result.status');

            return in_array($status, ['creator', 'administrator', 'member'], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function sendForceChannelJoinMessage(UserBotTelegram $bot, UserBotSetting $setting, UserBotSession $session): void
    {
        $bot->sendMessage(
            $session->telegram_chat_id,
            "🔒 برای استفاده از ربات ابتدا باید عضو کانال شوید",
            UserBotKeyboard::forceChannelJoin((string) $setting->force_channel_join_chat)
        );
    }

    private function sendHome(UserBotTelegram $bot, UserBotSetting $setting, UserBotSession $session): void
    {
        $isVolumePartner = $this->isVolumePartner($setting);
        $isBothPartner = $this->isBothPartner($setting);

        $isVolumeAccount = $session->is_logged_in
            ? $this->isLoggedInVolumeAccount($session)
            : ($isVolumePartner && !$isBothPartner);

        if (
            (string) $session->telegram_chat_id === (string) $setting->owner_telegram_chat_id
            && $session->state === 'manager'
        ) {
            $bot->sendMessage(
                $session->telegram_chat_id,
                "👋 مدیر عزیز\n\nهر زمان رسید جدیدی ارسال شود، برای شما نمایش داده می‌شود",
                UserBotKeyboard::manager()
            );

            return;
        }

        $keyboard = $session->is_logged_in
            ? UserBotKeyboard::user($isVolumeAccount)
            : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner);

        $text = $session->is_logged_in
            ? "✅ برای استفاده از ربات یکی از گزینه‌های زیر را انتخاب کنید"
            : "👋 خوش آمدید\nبرای استفاده از ربات، یکی از گزینه‌های زیر را انتخاب کنید";

        $bot->sendMessage($session->telegram_chat_id, $text, $keyboard);
    }

    private function generateOrderCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (UserBotOrder::where('order_code', $code)->exists());

        return $code;
    }

    private function orderNumber(UserBotOrder $order): string
    {
        return $order->order_code ?: (string) $order->id;
    }  
    
    private function normalizePositiveInt(string $text): int
    {
        $text = trim($text);

        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $latin   = ['0','1','2','3','4','5','6','7','8','9'];

        $text = str_replace($persian, $latin, $text);
        $text = str_replace($arabic, $latin, $text);

        if (!preg_match('/^\d+$/', $text)) {
            return 0;
        }

        return max(0, (int) $text);
    }

    private function sendPaymentMethodSelection(UserBotTelegram $bot, UserBotSetting $setting, UserBotSession $session, string|int $chatId): void
    {
        $cardEnabled = (bool) $setting->card_payment_enabled;
        $cryptoEnabled = (bool) $setting->crypto_payment_enabled
            && $this->activeUserBotCryptoOptions($setting)->isNotEmpty();

        if (!$cardEnabled && !$cryptoEnabled) {
            $bot->sendMessage($chatId, "⛔ در حال حاضر هیچ روش پرداختی فعال نیست", UserBotKeyboard::back());
            return;
        }

        $bot->sendMessage(
            $chatId,
            "💳 لطفاً روش پرداخت را انتخاب کنید:",
            UserBotKeyboard::paymentMethods($cardEnabled, $cryptoEnabled)
        );
    }

    private function buildUserBotOrderSummaryText(UserBotSession $session, string $orderType, array $selected, ?int $trafficGb, int $amount, string $amountLabel): string
    {
        $isVolumeTraffic = $orderType === 'buy_traffic'
            && $this->isVolumePlanKey((string) ($selected['plan_key'] ?? ''));

        $title = match ($orderType) {
            'renew_account' => '🔄 <b>تمدید اکانت</b>',
            'buy_traffic' => $isVolumeTraffic ? '➕ <b>خرید ترافیک</b>' : '➕ <b>خرید ترافیک اضافه</b>',
            default => '🛒 <b>خرید اکانت</b>',
        };

        $text = $title . "\n\n";

        if ($orderType !== 'buy_account' && $session->account_email) {
            $text .= "👤 نام کاربری: <code>" . $this->formatUsername($session->account_email) . "</code>\n";
        }

        $text .= "🧩 طرح: <b>" . e($this->planFullTitle($selected)) . "</b>\n";

        if ($trafficGb) {
            $text .= "📊 حجم: <b>{$trafficGb} گیگ</b>\n";
        }

        if (!empty($selected['description'])) {
            $text .= "📝 {$selected['description']}\n";
        }

        if ($orderType === 'buy_traffic' && (
            $isVolumeTraffic
            || app(\App\Services\UserBot\AccountTrafficResetPolicy::class)->hasPeriodicReset($session->account_snapshot ?: [])
        )) {
            $text .= "\n" . (
                $isVolumeTraffic
                    ? "⚠️ با هر بار خرید ترافیک، تاریخ انقضای اکانت از زمان انجام عملیات یک ماه تنظیم می‌شود"
                    : "⚠️ ترافیک اضافی خریداری‌شده قابل انتقال به ماه بعد نیست"
            ) . "\n";
        }

        $text .= "\n{$amountLabel}: <b>" . number_format($amount) . " تومان</b>";

        return $text;
    }

    private function sendCardPaymentMessage(
        UserBotTelegram $bot,
        UserBotSetting $setting,
        UserBotSession $session
    ): void {
        $stateData = $session->state_data ?: [];
        $selected = $stateData['selected_plan'] ?? [];
        $amount = (int) (
            $stateData['amount'] ?? 0
        );

        $trafficGb =
            $stateData['traffic_gb']
            ?? null;

        $orderType =
            $stateData['order_type']
            ?? 'buy_account';

        $cardSmsPayableAmount = (int) (
            $stateData[
                'card_sms_payable_amount'
            ] ?? 0
        );

        /*
         * وقتی تأیید پیامکی فعال است، مبلغ بالای پیام
         * مبلغ اصلی سفارش است؛ مبلغ دقیق پرداخت جداگانه
         * و به ریال نمایش داده می‌شود.
         */
        $amountLabel =
            $cardSmsPayableAmount > 0
                ? '💰 مبلغ اصلی سفارش'
                : '💰 مبلغ قابل پرداخت';

        $text = $this
            ->buildUserBotOrderSummaryText(
                $session,
                (string) $orderType,
                is_array($selected)
                    ? $selected
                    : [],
                $trafficGb
                    ? (int) $trafficGb
                    : null,
                $amount,
                $amountLabel
            );

        if ($cardSmsPayableAmount > 0) {
            /*
             * مبلغ در دیتابیس به تومان است؛
             * برای کپی در برنامه بانکی به ریال
             * و بدون جداکننده نمایش داده می‌شود.
             */
            $cardSmsPayableRials =
                $cardSmsPayableAmount * 10;

            $text .=
                "\n\n━━━━━━━━━━━━━━━\n\n"
                . "⚡ <b>تأیید آنی پرداخت</b>\n\n"
                . "💵 مبلغ دقیق قابل پرداخت: <b><code>"
                . $cardSmsPayableRials
                . "</code> ریال</b>\n\n"
                . "📌 برای کپی مبلغ دقیق، فقط روی عدد بزنید.\n";           
        }

        $text .=
            "\n\n━━━━━━━━━━━━━━━\n\n"
            . "💳 شماره کارت:\n"
            . "<code>{$setting->card_number}</code>\n\n"
            . "👤 به نام: "
            . "<b>{$setting->card_holder}</b>";

        if ($cardSmsPayableAmount > 0) {
            $text .=
                "\n\n━━━━━━━━━━━━━━━\n\n"
                . "✅ با واریز مبلغ دقیق قابل پرداخت، نیازی به ارسال رسید نیست و پرداخت شما آنی تأیید خواهد شد.\n\n"
                . "⏳ اگر تا ۵ دقیقه پس از پرداخت، سفارش به‌صورت خودکار تأیید نشد، تصویر رسید را ارسال کنید.\n\n"    
                . "🧾 اگر مبلغ اصلی سفارش را واریز کردید، ارسال رسید الزامی است.\n\n"
                . "⏱ تأیید رسید شما ممکن است تا ۳۰ دقیقه زمان ببرد.";
        } else {
            $text .=
                "\n\n━━━━━━━━━━━━━━━\n\n"
                . "⏱ تأیید رسید شما ممکن است تا ۳۰ دقیقه زمان ببرد.\n\n"
                . "لطفاً تصویر رسید پرداخت را ارسال کنید.";
        }

        $paymentMessage = $bot->sendMessage(
            $session->telegram_chat_id,
            $text,
            UserBotKeyboard::back()
        );

        $paymentMessageId = data_get(
            $paymentMessage,
            'result.message_id'
        );

        $stateData['payment_message_id'] =
            $paymentMessageId;

        $session->update([
            'state_data' => $stateData,
        ]);

        $cardSmsOrderId = (int) (
            $stateData[
                'card_sms_order_id'
            ] ?? 0
        );

        if (
            $cardSmsOrderId > 0
            && $paymentMessageId
        ) {
            $order = UserBotOrder::query()
                ->where(
                    'id',
                    $cardSmsOrderId
                )
                ->where(
                    'setting_id',
                    $setting->id
                )
                ->where(
                    'session_id',
                    $session->id
                )
                ->where(
                    'status',
                    'card_waiting_payment'
                )
                ->first();

            if ($order) {
                $payload =
                    $order->payload
                    ?: [];

                $payload[
                    'payment_message_id'
                ] = (int) $paymentMessageId;

                $order->payload = $payload;
                $order->save();
            }
        }
    }

    private function finalUserBotCryptoOrderMessage(string $status): ?string
    {
        return match ($status) {
            'done' => "✅ این سفارش قبلاً با موفقیت پرداخت و تأیید شده است.",
            'expired' => "⏰ مهلت پرداخت این سفارش به پایان رسیده است.",
            'cancelled' => "❌ این سفارش قبلاً لغو شده است.",
            'failed', 'invalid' => "⚠️ این سفارش قابل پردازش نیست.",
            default => null,
        };
    }

    private function activeUserBotCryptoOptions(UserBotSetting $setting)
    {
        if (!(bool) $setting->crypto_payment_enabled) {
            return collect();
        }

        return CryptoPaymentOption::query()
            ->where('context', 'user_bot')
            ->where('owner_type', $setting->owner_type)
            ->where('owner_id', $setting->owner_id)
            ->where('enabled', true)
            ->whereNotNull('wallet_address')
            ->where('wallet_address', '!=', '')
            ->orderBy('sort_order')
            ->get();
    }

    private function detectUserBotCryptoOptionFromText(UserBotSetting $setting, string $text): ?CryptoPaymentOption
    {
        $clean = trim(str_replace('💲', '', $text));

        foreach ($this->activeUserBotCryptoOptions($setting) as $option) {
            $label = "{$option->asset} - {$option->network}";

            if ($clean === $label || $text === "💲 {$label}") {
                return $option;
            }
        }

        return null;
    }

    private function createUserBotCryptoOrder(
        UserBotTelegram $bot,
        UserBotSetting $setting,
        UserBotSession $session,
        string|int $chatId,
        bool $isVolumePartner,
        bool $isBothPartner,
        bool $isVolumeAccount
    ): void {
        $stateData = $session->state_data ?: [];
        $selected = $stateData['selected_plan'] ?? null;
        $amount = (int) ($stateData['amount'] ?? 0);
        $trafficGb = $stateData['traffic_gb'] ?? null;
        $orderType = $stateData['order_type'] ?? 'buy_account';

        if (
            $orderType === 'buy_traffic'
            && $this->blockExpiredTimeAccountTrafficPurchase($bot, $setting, $session, $chatId)
        ) {
            return;
        }

        $session->refresh();
        $isVolumeAccount = $session->is_logged_in
            ? $this->isLoggedInVolumeAccount($session)
            : ($isVolumePartner && !$isBothPartner);

        if (!$selected || $amount <= 0) {
            $session->update(['state' => null, 'state_data' => null]);
            $bot->sendMessage($chatId, "⚠️ اطلاعات سفارش ناقص است. لطفاً دوباره شروع کنید", UserBotKeyboard::back());
            return;
        }

        $asset = (string) ($stateData['crypto_asset'] ?? '');
        $network = (string) ($stateData['crypto_network'] ?? '');

        $wallet = CryptoPaymentOption::query()
            ->where('context', 'user_bot')
            ->where('owner_type', $setting->owner_type)
            ->where('owner_id', $setting->owner_id)
            ->where('asset', $asset)
            ->where('network', $network)
            ->where('enabled', true)
            ->first();

        if (!$wallet || trim((string) $wallet->wallet_address) === '') {
            $bot->sendMessage($chatId, "⛔ این ارز دیجیتال فعال نیست یا آدرس ولت آن تنظیم نشده است", UserBotKeyboard::back());
            return;
        }

        UserBotOrder::where('setting_id', $setting->id)
            ->where('session_id', $session->id)
            ->whereIn('status', ['crypto_pending', 'crypto_checking'])
            ->whereNotNull('crypto_expires_at')
            ->where('crypto_expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $activeOrder = UserBotOrder::where('setting_id', $setting->id)
            ->where('session_id', $session->id)
            ->whereIn('status', ['crypto_pending', 'crypto_checking'])
            ->where('crypto_expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($activeOrder) {
            $session->update([
                'state' => null,
                'state_data' => null,
            ]);

            $session = $session->fresh();

            $this->sendUserBotCryptoOrderMessage($bot, $setting, $session, $activeOrder, true, $isVolumePartner, $isBothPartner, $isVolumeAccount);
            return;
        }

        try {
            $rateToman = app(CryptoRateService::class)->tomanRate($wallet->asset);
        } catch (\Throwable $e) {
            $bot->sendMessage($chatId, "⛔ دریافت نرخ ارز ممکن نیست. چند دقیقه بعد دوباره تلاش کنید.", UserBotKeyboard::back());
            return;
        }

        if ($rateToman <= 0) {
            $bot->sendMessage($chatId, "⛔ نرخ ارز معتبر نیست", UserBotKeyboard::back());
            return;
        }

        $baseCryptoAmount = round($amount / $rateToman, 6);
        $uniqueExtra = random_int(11, 999) / 1000000;
        $cryptoAmount = round($baseCryptoAmount + $uniqueExtra, 6);

        $bscStartBlock = null;

        if (strtoupper((string) $wallet->network) === 'BEP20') {
            $bscStartBlock = $this->resolveUserBotBscStartBlock($setting);
        }

        $order = UserBotOrder::create([
            'setting_id' => $setting->id,
            'session_id' => $session->id,
            'order_type' => $orderType,
            'order_code' => $this->generateOrderCode(),
            'plan_key' => $selected['plan_key'] ?? null,
            'account_email' => $session->account_email,
            'traffic_gb' => $trafficGb ? (int) $trafficGb : null,
            'amount' => $amount,
            'payment_method' => 'crypto',
            'crypto_asset' => $wallet->asset,
            'crypto_network' => $wallet->network,
            'crypto_wallet_address' => $wallet->wallet_address,
            'crypto_amount' => $cryptoAmount,
            'crypto_base_amount' => $baseCryptoAmount,
            'crypto_unique_extra' => $uniqueExtra,
            'crypto_expires_at' => now()->addMinutes(30),
            'bsc_start_block' => $bscStartBlock,
            'bsc_last_checked_block' => null,
            'crypto_meta' => [
                'telegram_chat_id' => $chatId,
                'telegram_username' => $session->telegram_username,
                'rate_toman' => $rateToman,
                'rate_source' => 'nobitex',
            ],
            'status' => 'crypto_pending',
            'payload' => [
                'selected_plan' => $selected,
                'telegram_chat_id' => $chatId,
                'telegram_username' => $session->telegram_username,
            ],
        ]);

        $session->update([
            'state' => null,
            'state_data' => null,
        ]);

        $this->sendUserBotCryptoOrderMessage($bot, $setting, $session, $order, false, $isVolumePartner, $isBothPartner, $isVolumeAccount);
    }

    private function sendUserBotCryptoOrderMessage(
        UserBotTelegram $bot,
        UserBotSetting $setting,
        UserBotSession $session,
        UserBotOrder $order,
        bool $existing,
        bool $isVolumePartner,
        bool $isBothPartner,
        bool $isVolumeAccount
    ): void {
        $remainingText = 'نامشخص';

        if ($order->crypto_expires_at) {
            $seconds = now()->diffInSeconds($order->crypto_expires_at, false);

            if ($seconds <= 0) {
                $remainingText = 'منقضی شده';
            } else {
                $minutes = intdiv($seconds, 60);
                $remainSeconds = $seconds % 60;
                $remainingText = number_format($minutes, 0, '.', ',') . " دقیقه و " . number_format($remainSeconds, 0, '.', ',') . " ثانیه";

                $remainingText = str_replace(
                    ['0','1','2','3','4','5','6','7','8','9'],
                    ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
                    $remainingText
                );
            }
        }

        if ($order->status === 'crypto_checking') {
            $orderNo = $order->order_code ?: $order->id;

            $msg =
                "⏳ سفارش رمزارزی قبلی شما در حال بررسی است\n\n"
                . "تا دریافت نتیجه، امکان ثبت سفارش رمزارزی جدید وجود ندارد.\n\n"
                . "شماره سفارش: <b>{$orderNo}</b>\n\n"
                . "مبلغ سفارش: " . number_format((int) $order->amount) . " تومان\n"
                . "ارز: <code>{$order->crypto_asset}</code>\n"
                . "شبکه: <code>{$order->crypto_network}</code>\n"
                . "مبلغ دقیق پرداخت: <code>" . number_format((float) $order->crypto_amount, 6, '.', '') . "</code>\n"
                . "زمان باقی‌مانده تا انقضای سفارش: {$remainingText}";

            $bot->sendMessage(
                $session->telegram_chat_id,
                $msg,
                $session->is_logged_in
                    ? UserBotKeyboard::user($isVolumeAccount)
                    : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner)
            );

            return;
        }

        $orderNo = $order->order_code ?: $order->id;
        $payload = $order->payload ?: [];
        $selected = $payload['selected_plan'] ?? [];

        $msg =
            ($existing ? "⚠️ شما یک سفارش رمزارزی فعال دارید:\n\n" : "💲 سفارش پرداخت رمزارزی ایجاد شد:\n\n")
                        . $this->buildUserBotOrderSummaryText(
                $session,
                (string) $order->order_type,
                is_array($selected) ? $selected : [],
                $order->traffic_gb ? (int) $order->traffic_gb : null,
                (int) $order->amount,
                '💰 مبلغ سفارش'
            )
            . "\n\n"
            . "شماره سفارش: <b>{$orderNo}</b>\n"
            . "وضعیت: در انتظار پرداخت\n"
            . "\n\n━━━━━━━━━━━━━━━\n\n"
            . "ارز: <code>{$order->crypto_asset}</code>\n"
            . "شبکه: <code>{$order->crypto_network}</code>\n"
            . "مبلغ دقیق پرداخت: <code>" . number_format((float) $order->crypto_amount, 6, '.', '') . "</code>\n\n"
            . "آدرس ولت:\n<code>{$order->crypto_wallet_address}</code>\n\n"
            . "⏳ مهلت پرداخت: ۳۰ دقیقه\n"
            . "⏱ زمان باقی‌مانده تا انقضای سفارش: {$remainingText}\n"
            . "\n\n━━━━━━━━━━━━━━━\n\n"
            . "📌 حتماً نام ارز - شبکه انتقال - مبلغ دقیق - آدرس ولت را مطابق موارد بالا رعایت کنید.\n"
            . "بعد از پرداخت، سیستم به‌صورت خودکار تراکنش را بررسی می‌کند و سفارش شما انجام خواهد شد.";

        if ($order->status === 'crypto_pending') {
            $bot->sendMessage(
                $session->telegram_chat_id,
                $msg . "\n\n🔴 بعد از انجام انتقال، دکمه زیر را بزنید:",
                UserBotKeyboard::cryptoOrderActions($order->id)
            );

            return;
        }

        $bot->sendMessage(
            $session->telegram_chat_id,
            $msg,
            $session->is_logged_in
                ? UserBotKeyboard::user($isVolumeAccount)
                : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner)
        );
    }

    private function resolveUserBotBscStartBlock(UserBotSetting $setting): ?int
    {
        $apiKey = trim((string) ($setting->crypto_bscscan_api_key ?? ''));

        if ($apiKey === '') {
            return null;
        }

        try {
            $res = Http::timeout(15)->post('https://bsc-mainnet.nodereal.io/v1/' . $apiKey, [
                'jsonrpc' => '2.0',
                'method' => 'eth_blockNumber',
                'params' => [],
                'id' => 1,
            ]);

            $latestHex = $res->json('result');

            if (!is_string($latestHex) || !str_starts_with($latestHex, '0x')) {
                return null;
            }

            $latest = hexdec($latestHex);
            $latestSafe = max(0, $latest - 3);

            return max(0, $latestSafe - 300);
        } catch (\Throwable $e) {
            return null;
        }
    }

}
