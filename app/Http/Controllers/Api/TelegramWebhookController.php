<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\PanelWalletReceipt;
use App\Models\PanelBotSession;
use App\Services\TransactionLogger;
use App\Services\PanelAccountActionService;
use Illuminate\Support\Facades\Storage;
use App\Models\PanelCardNumber;
use App\Services\TelegramNotifier;
use App\Services\TelegramConfig;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use App\Services\PartnerTariffAccessService;
use App\Models\PanelPaymentMethod;
use App\Models\PanelCryptoWalletSetting;
use App\Models\PanelCryptoTopup;
use App\Models\PanelWalletCardTopup;
use App\Services\PanelWallet\PanelWalletCardTopupIntentService;
use App\Services\CryptoRateService;
use App\Models\CryptoPaymentOption;
use App\Support\JalaliDateFormatter;

class TelegramWebhookController extends Controller
{
    // === Conversation States for in-bot login ===
    const STATE_IDLE            = 'idle';
    const STATE_AWAIT_USERNAME  = 'awaiting_username';
    const STATE_AWAIT_PASSWORD  = 'awaiting_password';
    const STATE_LOGGED_IN       = 'logged_in';

    const STATE_BUY_ACCOUNT      = 'buy_account';
    const STATE_RENEW_USERNAME   = 'renew_username';
    const STATE_TRAFFIC_USERNAME = 'traffic_username';
    const STATE_TRAFFIC_TARIFF_GROUP = 'traffic_tariff_group';
    const STATE_STATUS_USERNAME  = 'status_username';
    const STATE_WALLET_TOPUP     = 'wallet_topup';   
    const STATE_WALLET_TOPUP_METHOD = 'wallet_topup_method';
    const STATE_WALLET_TOPUP_CRYPTO_OPTION = 'wallet_topup_crypto_option';
    const STATE_BUY_VOLUME_GB = 'buy_volume_gb';
    const STATE_BUY_COUNT     = 'buy_count';    

    const STATE_RENEW_PLAN = 'renew_plan';
    const STATE_TRAFFIC_GB = 'traffic_gb';
    const STATE_BUY_TARIFF_GROUP   = 'buy_tariff_group';
    const STATE_RENEW_TARIFF_GROUP = 'renew_tariff_group';
    const STATE_WALLET_TOPUP_RECEIPT = 'wallet_topup_receipt';

    const STATE_PARTNERS_BROADCAST_WAITING = 'partners_broadcast_waiting';

    public function handle(Request $request)
    {
        $update = $request->all();

        // Secret header must match the one used when setting webhook
        $secret = \App\Services\TelegramConfig::webhookSecret();
        if ($secret) {
            $hdr = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if ($hdr !== $secret) {
                // Return 200 so Telegram doesn't retry forever
                return response('OK', 200);
            }
        }

        // --- Handle inline keyboard callbacks first (admin receipt approval + logout) ---
        if ($cb = data_get($update, 'callback_query')) {

            $cbId      = (string) data_get($cb, 'id');
            $fromId    = (string) data_get($cb, 'from.id');
            $data      = (string) data_get($cb, 'data');
            $chatId    = (string) data_get($cb, 'message.chat.id');
            $messageId = (int)    data_get($cb, 'message.message_id');

            // 0) Universal "logout" button (available to any logged-in user)
            if ($data === 'logout') {
                try { $this->answerCb($cbId, 'خروج انجام شد'); } catch (\Throwable $e) {}
                $fromIdInt = (int) $fromId;
                $s = PanelBotSession::firstOrCreate(['chat_id' => $fromIdInt], ['state' => self::STATE_IDLE]);
                if ($s->state === self::STATE_LOGGED_IN && $s->panel_user_id) {
                    if ($u = User::find($s->panel_user_id)) {
                        $u->telegram_user_id = null;
                        $u->save();
                    }
                }
                $s->state = self::STATE_IDLE;
                $s->panel_user_id = null;
                $s->temp_username = null;
                $s->last_activity = now();
                $s->save();

                $this->sendMsgKb($fromId, "شما با موفقیت از ربات خارج شدید. برای ورود دوباره، روی «🔐 ورود» بزنید", $this->buildMainMenu(false));
                return response()->noContent();
            }

            if (isset($update['callback_query'])) {
                $cb      = $update['callback_query'];
                $cbId    = (string) data_get($cb, 'id');
                $data    = (string) data_get($cb, 'data');
                $chatId  = (string) data_get($cb, 'message.chat.id');
                $msgId   = (int)    data_get($cb, 'message.message_id');

                // [ANN-TG] مشاهده اعلان توسط کاربر
                if (preg_match('/^ann:view:(\d+)$/', $data, $m)) {
                    $annId = (int) $m[1];

                    // کاربر لاگین‌شده
                    $user = \App\Models\User::where('telegram_user_id', $chatId)->first();
                    if (!$user) { try { $this->answerCb($cbId, 'لطفاً ابتدا در ربات لاگین کنید'); } catch (\Throwable $e) {} return response('OK', 200); }

                    $a = \App\Models\PanelAnnouncement::publishedNow()->find($annId);
                    if (!$a) { try { $this->answerCb($cbId, 'این اعلان در دسترس نیست'); } catch (\Throwable $e) {} return response('OK', 200); }

                    // ثبت ack/read + ذخیره chat/message برای حذف‌های بعدی
                    \App\Models\PanelAnnouncementRead::updateOrCreate(
                        ['announcement_id' => $a->id, 'user_id' => $user->id],
                        ['ack_at' => now(), 'read_at' => now(), 'tg_chat_id' => (string)$chatId, 'tg_message_id' => $msgId]
                    );

                    // حذف دکمه
                    try {
                        $token = \App\Services\TelegramConfig::token();
                        if ($token) {
                            $api = "https://api.telegram.org/bot{$token}";
                            \Illuminate\Support\Facades\Http::post("{$api}/editMessageReplyMarkup", [
                                'chat_id'      => $chatId,
                                'message_id'   => $msgId,
                                'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
                            ]);
                        }
                    } catch (\Throwable $e) {}

                    // ارسال عنوان + متن
                    $title = trim((string)$a->title);
                    $body  = trim((string)$a->body);
                    $txt   = "*{$title}*\n\n{$body}";
                    try {
                        $token = \App\Services\TelegramConfig::token();
                        if ($token) {
                            $api = "https://api.telegram.org/bot{$token}";
                            \Illuminate\Support\Facades\Http::post("{$api}/sendMessage", [
                                'chat_id'    => $chatId,
                                'text'       => $txt,
                                'parse_mode' => 'Markdown',
                            ]);
                        }
                    } catch (\Throwable $e) {}

                    try { $this->answerCb($cbId, 'متن اعلان ارسال شد'); } catch (\Throwable $e) {}
                    return response('OK', 200);
                }

            }

            if (preg_match('/^card_topup_change:(\d+)$/', $data, $m)) {
                $topupId = (int) $m[1];

                $user = User::where('telegram_user_id', $fromId)->first();
                $topup = PanelWalletCardTopup::find($topupId);

                if (!$user || !$topup || (int)$topup->user_id !== (int)$user->id) {
                    try { $this->answerCb($cbId, 'اجازه ندارید'); } catch (\Throwable $e) {}
                    return response('OK', 200);
                }

                try {
                    app(PanelWalletCardTopupIntentService::class)->cancel($topup, (int)$user->id);
                } catch (\RuntimeException $e) {
                    try { $this->answerCb($cbId, $e->getMessage()); } catch (\Throwable $x) {}
                    return response('OK', 200);
                }

                $topupMeta = is_array($topup->meta)
                    ? $topup->meta
                    : [];

                $paymentChatId = (string) (
                    $topupMeta['telegram_chat_id'] ?? $chatId
                );

                try {
                    app(TelegramNotifier::class)->deletePanelWalletCardTopupPaymentMessages(
                        $topup,
                        $paymentChatId
                    );
                } catch (\Throwable $e) {}

                $s = PanelBotSession::firstOrCreate(
                    ['chat_id' => (int)$fromId],
                    ['state' => self::STATE_LOGGED_IN]
                );

                $s->update([
                    'state' => self::STATE_WALLET_TOPUP_METHOD,
                    'panel_user_id' => $user->id,
                    'payload' => [
                        'action' => 'wallet_topup',
                        'method' => 'card',
                    ],
                    'temp_username' => null,
                ]);

                try { $this->answerCb($cbId, 'مبلغ جدید را انتخاب کنید'); } catch (\Throwable $e) {}

                $this->sendMsgKb(
                    $chatId,
                    "مبلغ شارژ کیف پول را انتخاب کنید:",
                    $this->buildWalletAmountKeyboard()
                );

                return response('OK', 200);
            }

            if (preg_match('/^card_topup_cancel:(\d+)$/', $data, $m)) {
                $topupId = (int) $m[1];

                $user = User::where('telegram_user_id', $fromId)->first();
                $topup = PanelWalletCardTopup::find($topupId);

                if (!$user || !$topup || (int)$topup->user_id !== (int)$user->id) {
                    try { $this->answerCb($cbId, 'اجازه ندارید'); } catch (\Throwable $e) {}
                    return response('OK', 200);
                }

                try {
                    app(PanelWalletCardTopupIntentService::class)->cancel($topup, (int)$user->id);
                } catch (\RuntimeException $e) {
                    try { $this->answerCb($cbId, $e->getMessage()); } catch (\Throwable $x) {}
                    return response('OK', 200);
                }

                try {
                    $this->answerCb($cbId, 'پرداخت لغو شد');
                } catch (\Throwable $e) {}

                $topupMeta = is_array($topup->meta)
                    ? $topup->meta
                    : [];

                $paymentMessageIds = $topupMeta['payment_message_ids'] ?? [];

                if (!is_array($paymentMessageIds)) {
                    $paymentMessageIds = [];
                }

                /*
                * سازگاری با آخرین message_id و پرداخت‌هایی که
                * قبل از اضافه‌شدن payment_message_ids ساخته شده‌اند.
                */
                $legacyPaymentMessageId = (int) (
                    $topupMeta['payment_message_id'] ?? 0
                );

                if ($legacyPaymentMessageId > 0) {
                    $paymentMessageIds[] = $legacyPaymentMessageId;
                }

                /*
                * خود پیامی که Callback از روی آن زده شده هم
                * حتماً داخل لیست حذف قرار بگیرد.
                */
                if ((int)$messageId > 0) {
                    $paymentMessageIds[] = (int)$messageId;
                }

                $paymentMessageIds = array_values(array_unique(array_filter(
                    array_map('intval', $paymentMessageIds),
                    fn ($id) => $id > 0
                )));

                $paymentChatId = (string) (
                    $topupMeta['telegram_chat_id'] ?? $chatId
                );

                foreach ($paymentMessageIds as $paymentMessageId) {
                    try {
                        $this->deleteMsg(
                            $paymentChatId,
                            $paymentMessageId
                        );
                    } catch (\Throwable $e) {
                        //
                    }
                }

                $s = PanelBotSession::firstOrCreate(
                    ['chat_id' => (int)$fromId],
                    ['state' => self::STATE_LOGGED_IN]
                );

                $s->update([
                    'state' => self::STATE_LOGGED_IN,
                    'panel_user_id' => $user->id,
                    'payload' => null,
                    'temp_username' => null,
                ]);

                $this->sendMsgKb(
                    $chatId,
                    "✅ پرداخت کارت‌به‌کارت شما لغو شد.",
                    $this->buildMainMenu(true, $user)
                );

                return response('OK', 200);
            }

            if (preg_match('/^crypto_paid:(\d+)$/', $data, $m)) {
                $topupId = (int) $m[1];

                $user = User::where('telegram_user_id', $fromId)->first();
                $topup = PanelCryptoTopup::find($topupId);

                if (!$user || !$topup || (int)$topup->user_id !== (int)$user->id) {
                    try { $this->answerCb($cbId, 'اجازه ندارید'); } catch (\Throwable $e) {}
                    return response('OK', 200);
                }

                try {
                    $this->editKb($chatId, $messageId, ['inline_keyboard' => []]);
                } catch (\Throwable $e) {}

                if ($finalMessage = $this->finalPartnerCryptoTopupMessage((string) $topup->status)) {
                    $this->sendMsgKb(
                        $chatId,
                        $finalMessage,
                        $this->buildMainMenu(true, $user)
                    );

                    return response('OK', 200);
                }

                if ($topup->expires_at && $topup->expires_at->isPast()) {
                    $topup->update(['status' => 'expired']);

                    $this->sendMsgKb(
                        $chatId,
                        "⏰ مهلت پرداخت این سفارش به پایان رسیده است.",
                        $this->buildMainMenu(true, $user)
                    );

                    return response('OK', 200);
                }

                if ($topup->status === 'pending') {
                    $topup->update([
                        'status' => 'checking',
                        'meta' => array_merge($topup->meta ?: [], [
                            'paid_clicked_at' => now()->toIso8601String(),
                            'paid_clicked_source' => 'telegram_bot',
                        ]),
                    ]);

                    $this->sendMsgKb(
                        $chatId,
                        "✅ پرداخت شما ثبت شد و سفارش در حال بررسی می باشد.",
                        $this->buildMainMenu(true, $user)
                    );

                    return response('OK', 200);
                }

                if ($topup->status === 'checking') {
                    $this->sendMsgKb(
                        $chatId,
                        "⚠️ پرداخت شما در حال بررسی است.",
                        $this->buildMainMenu(true, $user)
                    );

                    return response('OK', 200);
                }

                $this->sendMsgKb(
                    $chatId,
                    "⚠️ این سفارش قابل پردازش نیست.",
                    $this->buildMainMenu(true, $user)
                );

                return response('OK', 200);
            }

            if (preg_match('/^crypto_cancel:(\d+)$/', $data, $m)) {
                $topupId = (int) $m[1];

                $user = User::where('telegram_user_id', $fromId)->first();
                $topup = PanelCryptoTopup::find($topupId);

                if (!$user || !$topup || (int)$topup->user_id !== (int)$user->id) {
                    try { $this->answerCb($cbId, 'اجازه ندارید'); } catch (\Throwable $e) {}
                    return response('OK', 200);
                }

                try {
                    $this->editKb($chatId, $messageId, ['inline_keyboard' => []]);
                } catch (\Throwable $e) {}

                if ($finalMessage = $this->finalPartnerCryptoTopupMessage((string) $topup->status)) {
                    $this->sendMsgKb(
                        $chatId,
                        $finalMessage,
                        $this->buildMainMenu(true, $user)
                    );

                    return response('OK', 200);
                }

                if ($topup->status !== 'pending') {
                    $this->sendMsgKb(
                        $chatId,
                        "⚠️ این سفارش در حال بررسی می‌باشد و قابل لغو نیست.",
                        $this->buildMainMenu(true, $user)
                    );

                    return response('OK', 200);
                }

                $topup->update(['status' => 'cancelled']);

                $this->sendMsgKb(
                    $chatId,
                    "✅ سفارش رمزارزی شما لغو شد.\n",
                    $this->buildMainMenu(true, $user)
                );

                return response('OK', 200);
            }

            // 1) Admin-only callbacks (approve/reject receipts)
            $admin = User::where('role', 1)->where('telegram_user_id', $fromId)->first();
            if (!$admin) {
                // not admin → deny
                try { $this->answerCb($cbId, 'اجازه ندارید'); } catch (\Throwable $e) {}
                return response('OK', 200);
            }

            if (preg_match('/^wr:(ok|reject):(\d+)$/', $data, $m)) {
                $act = $m[1]; // ok | reject
                $rid = (int) $m[2];

                if ($act === 'ok') {
                    $amount = \DB::table('panel_wallet_receipts')->where('id', $rid)->value('amount') ?: 0;
                    $res = app(\App\Services\WalletReceiptService::class)->approve($rid, (int)$admin->id, (int)$amount);
                    $txt = $res['ok']
                        ? "✅ رسید #{$rid} تایید شد"
                        : "⛔ عملیات نامعتبر بود";
                } else {
                    $res = app(\App\Services\WalletReceiptService::class)->reject($rid, (int)$admin->id, null);
                    $txt = $res['ok']
                        ? "❌ رسید #{$rid} رد شد"
                        : "⛔ عملیات نامعتبر بود";
                }

                // Ack
                try { $this->answerCb($cbId, 'ثبت شد'); } catch (\Throwable $e) {}

                // خاموش‌کردن دکمه‌های همه پیام‌های مرتبط با این رسید (برای سایر ادمین‌ها هم)
                try { app(\App\Services\TelegramNotifier::class)->disableReceiptButtons($rid); } catch (\Throwable $e) {}

                try { $this->deleteWalletPaymentMessage($rid); } catch (\Throwable $e) {}

                // حذف کیبورد همین پیام فعلی هم (الگوی قبلی‌ات اگر خواستی حفظ شود)
                try { $this->editKb($chatId, $messageId, ['inline_keyboard' => []]); } catch (\Throwable $e) {}

                // پیام نتیجه برای همین ادمین
                try { $this->sendMsg($chatId, $txt); } catch (\Throwable $e) {}

                return response()->noContent();
            }
        }

        // --- Handle plain messages (/start, username, password) ---
        if ($msg = data_get($update, 'message')) {
            $this->handleLoginMessage($msg);
            return response()->noContent();
        }

        // Other update types are ignored
        return response()->noContent();
    }

    // === LOGIN CONVERSATION ===
    protected function handleLoginMessage(array $message): void
    {
        if (!isset($message['chat']['id'])) return;

        $chatIdStr = (string) ($message['chat']['id'] ?? '');
        if ($chatIdStr === '') return;
        $chatId = (int) $chatIdStr;
        $text = trim((string) ($message['text'] ?? ''));

        $s = $this->getOrCreateSession($chatId);
        $s->last_activity = now();
        $s->save();

        if (!in_array($text, ['', '↩️ بازگشت به منو', 'لغو'], true) && $s->state === self::STATE_WALLET_TOPUP_RECEIPT) {
            $this->deletePendingWalletPaymentMessage($s);
        }

        if ($s->state === self::STATE_WALLET_TOPUP_RECEIPT && $this->messageHasReceiptFile($message)) {
            $this->handleWalletReceiptUpload($chatIdStr, $message, $s);
            return;
        }

        if ($s->state === self::STATE_PARTNERS_BROADCAST_WAITING) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user || (int)($user->role ?? 2) !== 1) {
                $this->sendMsgKb($chatIdStr, "⛔️ دسترسی ندارید", $this->buildMainMenu((bool)$user, $user));
                return;
            }

            if (in_array($text, ['↩️ بازگشت به منو', 'لغو'], true)) {
                $s->update([
                    'state' => self::STATE_LOGGED_IN,
                    'payload' => null,
                    'temp_username' => null,
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    "❌ ارسال پیام گروهی لغو شد",
                    $this->buildMainMenu(true, $user)
                );

                return;
            }

            $messageId = (int) ($message['message_id'] ?? 0);

            if (!$messageId) {
                $this->sendMsgKb($chatIdStr, "⚠️ پیام قابل ارسال نیست", $this->buildBackKeyboard());
                return;
            }

            $token = TelegramConfig::token();

            if (!$token) {
                $this->sendMsgKb($chatIdStr, "⛔️ توکن ربات نمایندگان تنظیم نشده است", $this->buildMainMenu(true, $user));
                return;
            }

            $chatIds = PanelBotSession::query()
                ->join('panel_users as u', 'u.id', '=', 'panel_bot_sessions.panel_user_id')
                ->whereNotNull('panel_bot_sessions.chat_id')
                ->where('panel_bot_sessions.chat_id', '!=', (int) $chatId)
                ->where('u.role', '<>', 1)
                ->whereNotNull('u.telegram_user_id')
                ->pluck('panel_bot_sessions.chat_id')
                ->unique()
                ->values();

            $sent = 0;
            $failed = 0;

            foreach ($chatIds as $targetChatId) {
                try {
                    $res = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/copyMessage", [
                        'chat_id' => (string) $targetChatId,
                        'from_chat_id' => (string) $chatId,
                        'message_id' => $messageId,
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

            $s->update([
                'state' => self::STATE_LOGGED_IN,
                'payload' => null,
                'temp_username' => null,
            ]);

            $this->sendMsgKb(
                $chatIdStr,
                "✅ پیام گروهی با موفقیت برای {$sent} اکانت تلگرام ارسال شد",
                $this->buildMainMenu(true, $user)
            );

            return;
        }
        
        if ($text === '') {
            $this->sendMsgKb(
                $chatIdStr,
                "سلام! برای شروع روی «🔐 ورود» بزنید یا /start ارسال کنید",
                $this->buildMainMenu(false)
            );
            return;
        }

        // 1) خروج با منو/کامند — باید قبل از هر چیز هندل شود
        if ($text === '🚪 خروج' || $text === '/logout') {
            if ($s->state === self::STATE_LOGGED_IN && $s->panel_user_id) {
                if ($u = \App\Models\User::find($s->panel_user_id)) {
                    $u->telegram_user_id = null;
                    $u->save();
                }
            }
            $this->resetSession($s);
            $this->sendMsgKb($chatIdStr, "از اکانت خود خارج شدید، برای ورود مجدد، روی «🔐 ورود» بزنید", $this->buildMainMenu(false));
            return;
        }

        if ($text === '📣 ارسال پیام گروهی') {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user || (int)($user->role ?? 2) !== 1) {
                $this->sendMsgKb($chatIdStr, "⛔️ دسترسی ندارید", $this->buildMainMenu((bool)$user, $user));
                return;
            }

            $s->update([
                'state' => self::STATE_PARTNERS_BROADCAST_WAITING,
                'payload' => null,
                'temp_username' => null,
            ]);

            $this->sendMsgKb(
                $chatIdStr,
                "📣 پیام گروهی را ارسال کنید.\n\n📌 این پیام برای کلیه نمایندگانی که داخل ربات لاگین کرده باشند ارسال خواهد شد.\n\nبرای لغو، «↩️ بازگشت به منو» را بزنید.",
                $this->buildBackKeyboard()
            );

            return;
        }

        if ($text === '🧾 رسیدهای بررسی‌ نشده') {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user || (int)($user->role ?? 2) !== 1) {
                $this->sendMsgKb($chatIdStr, "⛔️ دسترسی ندارید", $this->buildMainMenu((bool)$user, $user));
                return;
            }

            $this->resendPendingWalletReceiptsToAdmin($chatIdStr, $user);
            return;
        }

        if ($text === '↩️ بازگشت به منو' || $text === 'لغو') {
            if ($s->state === self::STATE_WALLET_TOPUP_RECEIPT) {
                $this->deletePendingWalletPaymentMessage($s);
            }

            $s->update([
                'state' => self::STATE_LOGGED_IN,
                'payload' => null,
                'temp_username' => null,
            ]);

            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            $this->sendMsgKb(
                $chatIdStr,
                "برای شروع یکی از گزینه های زیر را انتخاب کنید:",
                $this->buildMainMenu(true, $user)
            );
            return;
        }

        // 2) /start
            if ($text !== '' && preg_match('/^\/start(\s|$)/', $text)) {
            if ($s->state === self::STATE_LOGGED_IN) {
                $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

                $this->sendMsgKb(
                    $chatIdStr,
                    "یکی از گزینه‌های زیر را انتخاب کنید:",
                    $this->buildMainMenu(true, $user)
                );
                return;
            }
         
            $s->update([
                'state'         => self::STATE_AWAIT_USERNAME,
                'temp_username' => null,
            ]);
            $this->sendMsgKb($chatIdStr, "💬 لطفاً <b>ایمیل</b> خود را وارد کنید", $this->buildMainMenu(false));
            return;
        }

        // 3) شروع ورود با منو/کامند
        if ($text === '🔐 ورود' || $text === '/login') {
            $s->update([
                'state'         => self::STATE_AWAIT_USERNAME,
                'temp_username' => null,
            ]);
            $this->sendMsgKb($chatIdStr, "💬 لطفاً <b>ایمیل</b> خود را وارد کنید ", $this->buildMainMenu(false));
            return;
        }

        // 4) هندل دکمه‌های منوی اصلی وقتی کاربر لاگین است
        if ($s->state === self::STATE_LOGGED_IN) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $role = (int)($user->role ?? 2);
            $isAdmin = $role === 1;
            $partnerPlanType = $user->partner_plan_type ?? 'time';
            $isVolumePartner = !$isAdmin && $partnerPlanType === 'volume';
            $isBothPartner = !$isAdmin && $partnerPlanType === 'both';

            if ($text === '🛒 خرید اکانت جدید') {
                $s->update([
                    'state' => self::STATE_BUY_TARIFF_GROUP,
                    'payload' => [
                        'action' => 'buy_account',
                    ],
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    $isAdmin
                        ? "نوع طرح موردنظر برای ساخت اکانت را انتخاب کنید:"
                        : "طرح موردنظر برای ساخت اکانت را انتخاب کنید:",
                    $this->buildTariffGroupKeyboard($user, 'buy')
                );
                return;
            }

            if ($text === '🔁 تمدید اکانت') {
                if ($isVolumePartner) {
                    $this->sendMsgKb(
                        $chatIdStr,
                        "برای نماینده حجمی، تمدید اکانت فعال نیست",
                        $this->buildMainMenu(true, $user)
                    );
                    return;
                }

                $s->update([
                    'state' => self::STATE_RENEW_USERNAME,
                    'payload' => [
                        'action' => 'renew_account',
                    ],
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    "نام کاربری اکانت را وارد کنید:",
                    $this->buildMainMenu(true, $user)
                );
                return;
            }

            if ($text === '➕ خرید ترافیک' || $text === '➕ خرید ترافیک اضافه') {
                $s->update([
                    'state' => self::STATE_TRAFFIC_USERNAME,
                    'payload' => [
                        'action' => 'buy_traffic',
                    ],
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    "نام کاربری اکانت را وارد کنید:",
                    $this->buildMainMenu(true, $user)
                );
                return;
            }

            if ($text === '👤 اطلاعات کاربر') {
                $s->update([
                    'state' => self::STATE_STATUS_USERNAME,
                    'payload' => [
                        'action' => 'user_status',
                    ],
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    "نام کاربری اکانت را وارد کنید:",
                    $this->buildMainMenu(true, $user)
                );
                return;
            }

            if ($text === '💰 اعتبار کیف پول') {
                if ($isAdmin) {
                    $this->sendMsgKb(
                        $chatIdStr,
                        "این گزینه برای ادمین فعال نیست",
                        $this->buildMainMenu(true, $user)
                    );
                    return;
                }

                $balance = (int)($user->credit ?? 0);

                $this->sendMsgKb(
                    $chatIdStr,
                    "💰 اعتبار کیف پول شما:\n\n" . number_format($balance) . " تومان",
                    $this->buildMainMenu(true, $user)
                );
                return;
            }

            if ($text === '💳 شارژ کیف پول') {
                if ($isAdmin) {
                    $this->sendMsgKb(
                        $chatIdStr,
                        "شارژ کیف پول برای ادمین نیاز نیست",
                        $this->buildMainMenu(true, $user)
                    );
                    return;
                }

                $s->update([
                    'state' => self::STATE_WALLET_TOPUP,
                    'payload' => [
                        'action' => 'wallet_topup',
                    ],
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    "روش پرداخت را انتخاب کنید:",
                    $this->buildWalletPaymentMethodKeyboard($user)
                );
                return;
            }

            $this->sendMsgKb(
                $chatIdStr,
                "یکی از گزینه‌های زیر را انتخاب کنید:",
                $this->buildMainMenu(true, $user)
            );
            return;
        }

        if ($s->state === self::STATE_BUY_TARIFF_GROUP) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $selected = $this->detectTariffGroupFromText($text, $user, 'buy');

            if (!$selected) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "لطفاً یکی از طرح‌های نمایش‌داده‌شده را انتخاب کنید:",
                    $this->buildTariffGroupKeyboard($user, 'buy')
                );
                return;
            }

            $payload = $s->payload ?: [];
            $payload['tariff_group_id'] = (int)$selected['id'];
            $payload['tariff_group_name'] = (string)$selected['name'];

            if (($selected['type'] ?? '') === 'volume') {
                $payload['plan_key'] = 'volume_gig';

                $s->update([
                    'state' => self::STATE_BUY_VOLUME_GB,
                    'payload' => $payload,
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    \App\Services\VolumeTrafficPolicy::label(\App\Services\VolumeTrafficPolicy::minimum((int) ($payload['tariff_group_id'] ?? 0))) . "\n\nمقدار حجم اکانت را به گیگ وارد کنید:",
                    $this->buildBackKeyboard()
                );
                return;
            }

            $s->update([
                'state' => self::STATE_BUY_ACCOUNT,
                'payload' => $payload,
            ]);

            $this->sendMsgKb(
                $chatIdStr,
                "مدت اکانت را برای طرح «{$selected['name']}» انتخاب کنید:",
                $this->buildBuyPlanKeyboard($user, (int)$selected['id'])
            );
            return;
        }

        // 5) انتخاب پلن برای خرید اکانت
        if ($s->state === self::STATE_BUY_ACCOUNT) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $payload = $s->payload ?: [];
            $tariffGroupId = (int)($payload['tariff_group_id'] ?? 0);

            $planKey = $this->detectBuyPlanKeyFromText($text);

            if (!$planKey) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "لطفاً طرح را انتخاب کنید:",
                    $this->buildBuyPlanKeyboard($user, $tariffGroupId)
                );
                return;
            }

            $payload['plan_key'] = $planKey;

            if ($planKey === 'volume_gig') {
                $s->update([
                    'state' => self::STATE_BUY_VOLUME_GB,
                    'payload' => $payload,
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    \App\Services\VolumeTrafficPolicy::label(\App\Services\VolumeTrafficPolicy::minimum((int) ($payload['tariff_group_id'] ?? 0))) . "\n\nمقدار حجم اکانت را به گیگ وارد کنید:",
                    $this->buildBackKeyboard()
                );
                return;
            }

            $s->update([
                'state' => self::STATE_BUY_COUNT,
                'payload' => $payload,
            ]);

            $this->sendMsgKb(
                $chatIdStr,
                "تعداد اکانت را وارد کنید:\n\nبرای ساخت تکی عدد 1 را بفرستید",
                $this->buildBackKeyboard()
            );
            return;
        }   

        // 6) دریافت حجم برای خرید اکانت حجمی
        if ($s->state === self::STATE_BUY_VOLUME_GB) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $gb = $this->normalizePositiveInt($text);

            if ($gb < 1) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "مقدار حجم نامعتبر است. لطفاً فقط عدد گیگ را وارد کنید، مثلاً 30",
                    $this->buildBackKeyboard()
                );
                return;
            }

            $minimum = \App\Services\VolumeTrafficPolicy::minimum((int) (($s->payload ?: [])['tariff_group_id'] ?? 0));
            if ($gb < $minimum) {
                $this->sendMsgKb($chatIdStr, \App\Services\VolumeTrafficPolicy::message($minimum), $this->buildBackKeyboard());
                return;
            }

            $payload = $s->payload ?: [];
            $payload['volume_gb'] = $gb;

            $s->update([
                'state' => self::STATE_BUY_COUNT,
                'payload' => $payload,
            ]);

            $this->sendMsgKb(
                $chatIdStr,
                "تعداد اکانت را وارد کنید:\n\nبرای ساخت تکی عدد 1 را بفرستید",
                $this->buildBackKeyboard()
            );
            return;
        }

        // 7) دریافت تعداد و ساخت اکانت
        if ($s->state === self::STATE_BUY_COUNT) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $count = $this->normalizePositiveInt($text);

            if ($count < 1) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "تعداد نامعتبر است. لطفاً فقط عدد وارد کنید، مثلاً 1",
                    $this->buildBackKeyboard()
                );
                return;
            }

            $payload = $s->payload ?: [];
            $planKey = (string)($payload['plan_key'] ?? '');
            $tariffGroupId = (int)($payload['tariff_group_id'] ?? 0) ?: null;

            if ($planKey === '') {
                $s->update([
                    'state' => self::STATE_BUY_ACCOUNT,
                    'payload' => ['action' => 'buy_account'],
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    "طرح مشخص نیست. لطفاً دوباره طرح را انتخاب کنید:",
                    $this->buildBuyPlanKeyboard($user)
                );
                return;
            }

            $volumeGb = null;
            if ($planKey === 'volume_gig') {
                $volumeGb = (int)($payload['volume_gb'] ?? 0);

                if ($volumeGb < 1) {
                    $s->update([
                        'state' => self::STATE_BUY_VOLUME_GB,
                        'payload' => $payload,
                    ]);

                    $this->sendMsgKb(
                        $chatIdStr,
                        "مقدار حجم اکانت مشخص نیست. لطفاً حجم را به گیگ وارد کنید:",
                        $this->buildBackKeyboard()
                    );
                    return;
                }
            }

            $this->sendMsg($chatIdStr, "⏳ در حال ساخت اکانت، لطفاً چند لحظه صبر کنید...");

            $result = app(PanelAccountActionService::class)->createAccountForPanelUser(
                panelUser: $user,
                planKey: $planKey,
                count: $count,
                tariffGroupId: $tariffGroupId,
                volumeGb: $volumeGb,
                downloadExcel: false,
                operatorSource: 'telegram_bot'
            );

            $s->update([
                'state' => self::STATE_LOGGED_IN,
                'payload' => null,
                'temp_username' => null,
            ]);

            if (empty($result['ok'])) {
                $msgText = (string)($result['message'] ?? 'ساخت اکانت انجام نشد');

                if (!empty($result['insufficient_credit'])) {
                    $msgText = "اعتبار شما کافی نیست\nپس از افزایش اعتبار مجدداً تلاش کنید";
                }

                $this->sendMsgKb(
                    $chatIdStr,
                    "⛔️ {$msgText}",
                    $this->buildMainMenu(true, $user)
                );
                return;
            }

            $created = $result['created'] ?? [];

            $createdText = $this->formatCreatedAccountsMessage(
                $created,
                $planKey,
                $volumeGb,
                (string)($payload['tariff_group_name'] ?? '')
            );

            $mainMenu = $this->buildMainMenu(true, $user);

            if (count($created) === 1 && !empty($created[0]['token'])) {
                $this->sendQrPhotoKb(
                    $chatIdStr,
                    $this->buildSubscribeUrl((string) $created[0]['token']),
                    $createdText,
                    $mainMenu
                );
            } else {
                $this->sendMsgKb(
                    $chatIdStr,
                    $createdText,
                    $mainMenu
                );
            }
            return;
        }

        // 8) دریافت نام کاربری برای تمدید
        if ($s->state === self::STATE_RENEW_USERNAME) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $v2User = $this->findV2UserByUsernameInput($text);

            if ($v2User) {
                $email = strtolower((string)$v2User->email);
            } else {
                $email = strtolower(trim($text));
            }

            if (!$v2User) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "⛔️ کاربر با این نام کاربری پیدا نشد\n\nنام کاربری را دوباره وارد کنید:",
                    $this->buildBackKeyboard()
                );
                return;
            }

            if ((int)($user->role ?? 2) !== 1) {
                $panelCode = strtolower((string)($user->code ?? ''));
                if ($panelCode !== '' && !str_contains(strtolower($email), $panelCode)) {
                    $this->sendMsgKb(
                        $chatIdStr,
                        "⛔️ کاربری یافت نشد",
                        $this->buildMainMenu(true, $user)
                    );
                    $s->update(['state' => self::STATE_LOGGED_IN, 'payload' => null]);
                    return;
                }
            }

            $payload = $s->payload ?: [];
            $payload['target_email'] = $email;

            if ($this->isV2UserVolumePlan($v2User)) {
                $s->update([
                    'state' => self::STATE_LOGGED_IN,
                    'payload' => null,
                    'temp_username' => null,
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    $this->formatV2UserSummary($v2User, true) .
                    "\n\n⛔️ این اکانت از نوع حجمی است و امکان تمدید ندارد\n\nبرای تمدید این اکانت، از گزینه «➕ خرید ترافیک» استفاده کنید",
                    $this->buildMainMenu(true, $user)
                );
                return;
            }

            $s->update([
                'state' => self::STATE_RENEW_TARIFF_GROUP,
                'payload' => $payload,
            ]);

            $this->sendMsgKb(
                $chatIdStr,
                $this->formatV2UserSummary($v2User) . "\n\nلطفاً طرح تمدید را انتخاب کنید:",
                $this->buildTariffGroupKeyboard($user, 'renew')
            );
            return;
        }

        if ($s->state === self::STATE_RENEW_TARIFF_GROUP) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $selected = $this->detectTariffGroupFromText($text, $user, 'renew');

            if (!$selected || ($selected['type'] ?? '') === 'volume') {
                $this->sendMsgKb(
                    $chatIdStr,
                    "لطفاً یکی از طرح‌های زمانی نمایش‌داده‌شده را انتخاب کنید:",
                    $this->buildTariffGroupKeyboard($user, 'renew')
                );
                return;
            }

            $payload = $s->payload ?: [];
            $tariffGroupId = (int)($payload['tariff_group_id'] ?? 0) ?: null;
            $payload['tariff_group_id'] = (int)$selected['id'];
            $payload['tariff_group_name'] = (string)$selected['name'];

            $s->update([
                'state' => self::STATE_RENEW_PLAN,
                'payload' => $payload,
            ]);

            $this->sendMsgKb(
                $chatIdStr,
                "مدت تمدید را برای طرح «{$selected['name']}» انتخاب کنید:",
                $this->buildRenewPlanKeyboard((int)$selected['id'])
            );
            return;
        }

        // 9) انتخاب پلن و انجام تمدید
        if ($s->state === self::STATE_RENEW_PLAN) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $payload = $s->payload ?: [];
            $tariffGroupId = (int)($payload['tariff_group_id'] ?? 0) ?: null;
            $email = strtolower(trim((string)($payload['target_email'] ?? '')));

            $period = $this->detectRenewPlanKeyFromText($text);

            if (!$period) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "لطفاً طرح تمدید را انتخاب کنید:",
                    $this->buildRenewPlanKeyboard((int)($tariffGroupId ?? 0))
                );
                return;
            }

            if ($email === '') {
                $s->update(['state' => self::STATE_RENEW_USERNAME, 'payload' => ['action' => 'renew_account']]);
                $this->sendMsgKb($chatIdStr, "نام کاربری مشخص نیست. لطفاً دوباره نام کاربری را وارد کنید:", $this->buildBackKeyboard());
                return;
            }

            $this->sendMsg($chatIdStr, "⏳ در حال تمدید اکانت، لطفاً چند لحظه صبر کنید...");

            $result = app(PanelAccountActionService::class)->renewAccountForPanelUser(
                panelUser: $user,
                email: $email,
                period: $period,
                tariffGroupId: $tariffGroupId,
                operatorSource: 'telegram_bot'
            );

            $s->update([
                'state' => self::STATE_LOGGED_IN,
                'payload' => null,
                'temp_username' => null,
            ]);

            if (empty($result['ok'])) {
                $msgText = (string)($result['message'] ?? 'تمدید انجام نشد');

                if (!empty($result['insufficient_credit'])) {
                    $msgText = "اعتبار شما کافی نیست\nپس از افزایش اعتبار مجدداً تلاش کنید";
                }

                $this->sendMsgKb(
                    $chatIdStr,
                    "⛔️ {$msgText}",
                    $this->buildMainMenu(true, $user)
                );
                return;
            }

            $v2User = $this->findV2UserByEmailSafe($email);

            $renewText = "✅ اکانت با موفقیت تمدید شد\n\n" . ($v2User ? $this->formatV2UserSummary($v2User, true) : "نام کاربری: <code>{$email}</code>");
            $mainMenu = $this->buildMainMenu(true, $user);

            if ($v2User && !empty($v2User->token)) {
                $this->sendQrPhotoKb(
                    $chatIdStr,
                    $this->buildSubscribeUrl((string) $v2User->token),
                    $renewText,
                    $mainMenu
                );
            } else {
                $this->sendMsgKb($chatIdStr, $renewText, $mainMenu);
            }
            return;
        }

        // 10) دریافت نام کاربری برای خرید ترافیک
        if ($s->state === self::STATE_TRAFFIC_USERNAME) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $v2User = $this->findV2UserByUsernameInput($text);

            if ($v2User) {
                $email = strtolower((string)$v2User->email);
            } else {
                $email = strtolower(trim($text));
            }

            if (!$v2User) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "⛔️ کاربر با این نام کاربری پیدا نشد\n\nنام کاربری را دوباره وارد کنید:",
                    $this->buildBackKeyboard()
                );
                return;
            }

            if ((int)($user->role ?? 2) !== 1) {
                $panelCode = strtolower((string)($user->code ?? ''));
                if ($panelCode !== '' && !str_contains(strtolower($email), $panelCode)) {
                    $s->update(['state' => self::STATE_LOGGED_IN, 'payload' => null]);

                    $this->sendMsgKb(
                        $chatIdStr,
                        "⛔️ کاربری یافت نشد",
                        $this->buildMainMenu(true, $user)
                    );
                    return;
                }
            }

            $payload = $s->payload ?: [];
            $payload['target_email'] = $email;

            $isAdmin = ((int)($user->role ?? 2) === 1);

            $partnerPlanType = $user->partner_plan_type ?? 'time';

            $resolver=app(\App\Services\AccountTariffResolver::class);
            $isVolumeMode=$resolver->isVolume($v2User);
            if (!$isVolumeMode) $payload['tariff_group_id']=(int)($resolver->group($v2User)->id??0);

            $payload['is_volume_mode'] = $isVolumeMode ? 1 : 0;
            $payload['traffic_group_type'] = $isVolumeMode ? 'volume' : 'time';

            $hasNoTrafficReset = !$isVolumeMode
                && !app(\App\Services\UserBot\AccountTrafficResetPolicy::class)->hasPeriodicReset($v2User);

            $trafficWarning = $isVolumeMode
                ? "⚠️ با هر بار خرید ترافیک، تاریخ انقضای اکانت از زمان انجام عملیات یک ماه تنظیم می‌شود"
                : "⚠️ <b>نکات مهم:</b>\n"
                    . ($hasNoTrafficReset ? '' : "• ترافیک ماهانه اکانت در ابتدای هر ماه شمسی ریست می‌شود.\n")
                    . "• خرید ترافیک اضافه باعث تمدید تاریخ انقضای اکانت نمی‌شود.\n"
                    . ($hasNoTrafficReset ? '' : "• ترافیک اضافی خریداری‌شده قابل انتقال به ماه بعد نیست.");

            if ($isVolumeMode) {
                $s->update([
                    'state' => self::STATE_TRAFFIC_TARIFF_GROUP,
                    'payload' => $payload,
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    $this->formatV2UserSummary($v2User) . "\n\n" .
                    $trafficWarning . "\n\n" .
                    "طرح حجمی موردنظر برای خرید ترافیک را انتخاب کنید:",
                    $this->buildTariffGroupKeyboard($user, 'traffic', 'volume')
                );
                return;
            }

            $s->update([
                'state' => self::STATE_TRAFFIC_GB,
                'payload' => $payload,
            ]);

            $this->sendMsgKb(
                $chatIdStr,
                $this->formatV2UserSummary($v2User) . "\n\n" .
                $trafficWarning . "\n\n" .
                "مقدار ترافیک را به گیگ وارد کنید:",
                $this->buildBackKeyboard()
            );
            return;
        }

        // 10.5) انتخاب طرح حجمی برای خرید ترافیک
        if ($s->state === self::STATE_TRAFFIC_TARIFF_GROUP) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $payload = $s->payload ?: [];
            $targetGroupType = !empty($payload['is_volume_mode']) ? 'volume' : 'time';

            $selected = $this->detectTariffGroupFromText($text, $user, 'traffic', $targetGroupType);

            if (!$selected || ($selected['type'] ?? '') !== $targetGroupType) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "لطفاً یکی از طرح‌های حجمی نمایش‌داده‌شده را انتخاب کنید:",
                    $this->buildTariffGroupKeyboard($user, 'traffic', $targetGroupType)
                );
                return;
            }

            $payload = $s->payload ?: [];
            $payload['tariff_group_id'] = (int) $selected['id'];
            $payload['tariff_group_name'] = (string) $selected['name'];
            $payload['is_volume_mode'] = 1;

            $s->update([
                'state' => self::STATE_TRAFFIC_GB,
                'payload' => $payload,
            ]);

            $this->sendMsgKb(
                $chatIdStr,
                \App\Services\VolumeTrafficPolicy::label(\App\Services\VolumeTrafficPolicy::minimum((int) $selected['id'])) . "\n\nمقدار ترافیک را به گیگ وارد کنید:",
                $this->buildBackKeyboard()
            );
            return;
        }

        // 11) دریافت مقدار گیگ و خرید ترافیک
        if ($s->state === self::STATE_TRAFFIC_GB) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $gb = $this->normalizePositiveInt($text);

            if ($gb < 1) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "مقدار ترافیک نامعتبر است. لطفاً فقط عدد گیگ را وارد کنید، مثلاً 20",
                    $this->buildBackKeyboard()
                );
                return;
            }

            $minimum = !empty(($s->payload ?: [])['is_volume_mode'])
                ? \App\Services\VolumeTrafficPolicy::minimum((int) (($s->payload ?: [])['tariff_group_id'] ?? 0)) : 1;
            if ($gb < $minimum) {
                $this->sendMsgKb($chatIdStr, \App\Services\VolumeTrafficPolicy::message($minimum), $this->buildBackKeyboard());
                return;
            }

            $payload = $s->payload ?: [];
            $email = strtolower(trim((string)($payload['target_email'] ?? '')));

            if ($email === '') {
                $s->update(['state' => self::STATE_TRAFFIC_USERNAME, 'payload' => ['action' => 'buy_traffic']]);

                $this->sendMsgKb(
                    $chatIdStr,
                    "نام کاربری مشخص نیست. لطفاً دوباره نام کاربری را وارد کنید:",
                    $this->buildBackKeyboard()
                );
                return;
            }

            $this->sendMsg($chatIdStr, "⏳ در حال افزودن ترافیک، لطفاً چند لحظه صبر کنید...");

            $tariffGroupId = (int) ($payload['tariff_group_id'] ?? 0) ?: null;

            $result = app(PanelAccountActionService::class)->topupTrafficForPanelUser(
                panelUser: $user,
                email: $email,
                gb: $gb,
                tariffGroupId: $tariffGroupId,
                operatorSource: 'telegram_bot'
            );

            $s->update([
                'state' => self::STATE_LOGGED_IN,
                'payload' => null,
                'temp_username' => null,
            ]);

            if (empty($result['ok'])) {
                $msgText = (string)($result['message'] ?? 'خرید ترافیک انجام نشد');

                if (!empty($result['insufficient_credit'])) {
                    $msgText = "اعتبار شما کافی نیست\nپس از افزایش اعتبار مجدداً تلاش کنید";
                }

                $this->sendMsgKb(
                    $chatIdStr,
                    "⛔️ {$msgText}",
                    $this->buildMainMenu(true, $user)
                );
                return;
            }

            $v2User = $this->findV2UserByEmailSafe($email);

            $isVolumeMode = !empty($payload['is_volume_mode']);

            $hasNoTrafficReset = !$isVolumeMode && $v2User
                && !app(\App\Services\UserBot\AccountTrafficResetPolicy::class)->hasPeriodicReset($v2User);

            $trafficDoneNote = $isVolumeMode
                ? "📌 تاریخ انقضای اکانت از همین لحظه یک ماه تنظیم شد\n\n"
                : ($hasNoTrafficReset ? '' : "📌 ترافیک اضافه قابل انتقال به ماه بعد نیست\n\n");

            $trafficGroupName = trim((string) ($payload['tariff_group_name'] ?? ''));

            $trafficText = "✅ ترافیک با موفقیت اضافه شد\n\n" .
                ($isVolumeMode && $trafficGroupName !== '' ? "طرح: <b>" . e($trafficGroupName) . "</b>\n" : "") .
                "ترافیک افزوده‌شده: {$gb} گیگ\n\n" .
                $trafficDoneNote .
                ($v2User ? $this->formatV2UserSummary($v2User, true) : "نام کاربری: <code>{$email}</code>");

            $mainMenu = $this->buildMainMenu(true, $user);

            if ($v2User && !empty($v2User->token)) {
                $this->sendQrPhotoKb(
                    $chatIdStr,
                    $this->buildSubscribeUrl((string) $v2User->token),
                    $trafficText,
                    $mainMenu
                );
            } else {
                $this->sendMsgKb($chatIdStr, $trafficText, $mainMenu);
            }
            return;
        }

        // 12) نمایش وضعیت کاربر
        if ($s->state === self::STATE_STATUS_USERNAME) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $v2User = $this->findV2UserByUsernameInput($text);

            if ($v2User) {
                $email = strtolower((string)$v2User->email);
            } else {
                $email = strtolower(trim($text));
            }

            if (!$v2User) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "⛔️ کاربر با این نام کاربری پیدا نشد\n\nنام کاربری را دوباره وارد کنید:",
                    $this->buildBackKeyboard()
                );
                return;
            }

            if ((int)($user->role ?? 2) !== 1) {
                $panelCode = strtolower((string)($user->code ?? ''));
                if ($panelCode !== '' && !str_contains(strtolower($email), $panelCode)) {
                    $s->update(['state' => self::STATE_LOGGED_IN, 'payload' => null]);

                    $this->sendMsgKb(
                        $chatIdStr,
                        "⛔️ کاربری یافت نشد",
                        $this->buildMainMenu(true, $user)
                    );
                    return;
                }
            }

            $s->update([
                'state' => self::STATE_LOGGED_IN,
                'payload' => null,
                'temp_username' => null,
            ]);

            $statusText = $this->formatV2UserSummary($v2User, true);
            $mainMenu = $this->buildMainMenu(true, $user);

            if (!empty($v2User->token)) {
                $this->sendQrPhotoKb(
                    $chatIdStr,
                    $this->buildSubscribeUrl((string) $v2User->token),
                    $statusText,
                    $mainMenu
                );
            } else {
                $this->sendMsgKb($chatIdStr, $statusText, $mainMenu);
            }
            return;
        }

        // 13) انتخاب روش پرداخت کیف پول
        if ($s->state === self::STATE_WALLET_TOPUP) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            if ((int)($user->role ?? 2) === 1) {
                $s->update(['state' => self::STATE_LOGGED_IN, 'payload' => null]);
                $this->sendMsgKb($chatIdStr, "شارژ کیف پول برای ادمین نیاز نیست", $this->buildMainMenu(true, $user));
                return;
            }

            if (!$this->canUsePaymentMethod('card', $user) && !$this->canUsePaymentMethod('crypto', $user)) {
                $s->update(['state' => self::STATE_LOGGED_IN, 'payload' => null]);

                $this->sendMsgKb(
                    $chatIdStr,
                    "⛔️ در حال حاضر هیچ روش پرداختی برای شما فعال نیست.",
                    $this->buildMainMenu(true, $user)
                );
                return;
            }

            if ($text === '💳 کارت به کارت') {
                if (!$this->canUsePaymentMethod('card', $user)) {
                    $this->sendMsgKb($chatIdStr, "⛔️ روش پرداخت کارت به کارت فعال نیست", $this->buildWalletPaymentMethodKeyboard($user));
                    return;
                }

                $activeCardTopup = app(PanelWalletCardTopupIntentService::class)->activeForUser((int)$user->id);

                if ($activeCardTopup) {
                    $this->sendTelegramCardTopupMessage($chatIdStr, $s, $user, $activeCardTopup, true);
                    return;
                }

                $s->update([
                    'state' => self::STATE_WALLET_TOPUP_METHOD,
                    'payload' => [
                        'action' => 'wallet_topup',
                        'method' => 'card',
                    ],
                ]);

                $this->sendMsgKb($chatIdStr, "مبلغ شارژ کیف پول را انتخاب کنید:", $this->buildWalletAmountKeyboard());
                return;
            }

            if ($text === '💲 ارز دیجیتال') {
                if (!$this->canUsePaymentMethod('crypto', $user)) {
                    $this->sendMsgKb($chatIdStr, "⛔️ روش پرداخت ارز دیجیتال فعال نیست", $this->buildWalletPaymentMethodKeyboard($user));
                    return;
                }

                PanelCryptoTopup::where('user_id', $user->id)
                    ->whereIn('status', ['pending', 'checking'])
                    ->where('expires_at', '<=', now())
                    ->update(['status' => 'expired']);

                $activeTopup = PanelCryptoTopup::where('user_id', $user->id)
                    ->whereIn('status', ['pending', 'checking'])
                    ->where('expires_at', '>', now())
                    ->latest('id')
                    ->first();

                if ($activeTopup) {
                    $this->sendTelegramCryptoTopupMessage($chatIdStr, $s, $user, $activeTopup, true);
                    return;
                }

                $s->update([
                    'state' => self::STATE_WALLET_TOPUP_METHOD,
                    'payload' => [
                        'action' => 'wallet_topup',
                        'method' => 'crypto',
                    ],
                ]);

                $this->sendMsgKb($chatIdStr, "مبلغ شارژ کیف پول را انتخاب کنید:", $this->buildWalletAmountKeyboard());
                return;
            }

            $this->sendMsgKb(
                $chatIdStr,
                "لطفاً روش پرداخت را انتخاب کنید:",
                $this->buildWalletPaymentMethodKeyboard($user)
            );
            return;
        }

        if ($s->state === self::STATE_WALLET_TOPUP_METHOD) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $payload = $s->payload ?: [];
            $method = (string)($payload['method'] ?? '');

            $amount = $this->detectWalletAmountFromText($text);

            if ($amount < 1000) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "لطفاً یکی از مبلغ‌های نمایش‌داده‌شده را انتخاب کنید:",
                    $this->buildWalletAmountKeyboard()
                );
                return;
            }

            if (!in_array($method, ['card', 'crypto'], true)) {
                $s->update(['state' => self::STATE_WALLET_TOPUP, 'payload' => ['action' => 'wallet_topup']]);
                $this->sendMsgKb($chatIdStr, "روش پرداخت مشخص نیست. لطفاً دوباره روش پرداخت را انتخاب کنید:", $this->buildWalletPaymentMethodKeyboard($user));
                return;
            }

            if ($method === 'card') {
                if (!$this->canUsePaymentMethod('card', $user)) {
                    $this->sendMsgKb(
                        $chatIdStr,
                        "⛔️ روش پرداخت کارت به کارت فعال نیست",
                        $this->buildWalletPaymentMethodKeyboard($user)
                    );
                    return;
                }

                if ($this->partnerWalletCardSmsEnabled($user)) {
                    try {
                        $topup = app(PanelWalletCardTopupIntentService::class)->create($user, $amount, 'partner_bot');
                        $this->sendTelegramCardTopupMessage($chatIdStr, $s, $user, $topup);
                    } catch (\RuntimeException $e) {
                        $this->sendMsgKb(
                            $chatIdStr,
                            "⛔️ " . e($e->getMessage()),
                            $this->buildWalletAmountKeyboard()
                        );
                    }

                    return;
                }

                $card = PanelCardNumber::orderByDesc('id')->first();

                if (!$card) {
                    $s->update(['state' => self::STATE_LOGGED_IN, 'payload' => null]);
                    $this->sendMsgKb($chatIdStr, "⛔️ شماره کارت ثبت نشده است", $this->buildMainMenu(true, $user));
                    return;
                }

                $digits = preg_replace('/\D/', '', (string)($card->card ?? ''));
                $digits = substr($digits, 0, 16);
                $formattedCard = $digits ? implode('-', str_split($digits, 4)) : '';

                $s->update([
                    'state' => self::STATE_WALLET_TOPUP_RECEIPT,
                    'payload' => [
                        'action' => 'wallet_topup',
                        'amount' => $amount,
                    ],
                ]);

                $paymentMsg = $this->sendMsgKbResult(
                    $chatIdStr,
                    "📌 اطلاعات واریز:\n\n" .
                    "مبلغ: <code>" . number_format($amount) . " تومان</code>\n" .
                    "شماره کارت: <code>&#8206;{$formattedCard}&#8206;</code>\n" .
                    "نام صاحب کارت: " . e((string)($card->name ?? '')) . "\n\n" .
                    "بعد از واریز، عکس یا فایل رسید را ارسال کنید.\n" .
                    "بررسی رسید ممکن است تا ۳۰ دقیقه زمان ببرد.",
                    $this->buildBackKeyboard()
                );

                $payload = $s->payload ?: [];
                $payload['payment_message_id'] = data_get($paymentMsg, 'result.message_id');
                $s->update(['payload' => $payload]);
                return;
            }

            if ($method === 'crypto') {
                PanelCryptoTopup::where('user_id', $user->id)
                    ->whereIn('status', ['pending', 'checking'])
                    ->where('expires_at', '<=', now())
                    ->update(['status' => 'expired']);

                $activeTopup = PanelCryptoTopup::where('user_id', $user->id)
                    ->whereIn('status', ['pending', 'checking'])
                    ->where('expires_at', '>', now())
                    ->latest('id')
                    ->first();

                if ($activeTopup) {
                    $this->sendTelegramCryptoTopupMessage($chatIdStr, $s, $user, $activeTopup, true);
                    return;
                }

                $s->update([
                    'state' => self::STATE_WALLET_TOPUP_CRYPTO_OPTION,
                    'payload' => [
                        'action' => 'wallet_topup',
                        'method' => 'crypto',
                        'amount' => $amount,
                    ],
                ]);

                $this->sendMsgKb(
                    $chatIdStr,
                    "ارز و شبکه پرداخت را انتخاب کنید:",
                    $this->buildWalletCryptoOptionKeyboard($user)
                );
                return;
            }

            $this->sendMsgKb(
                $chatIdStr,
                "لطفاً یکی از مبلغ‌های نمایش‌داده‌شده را انتخاب کنید:",
                $this->buildWalletAmountKeyboard()
            );
            return;
        }

        if ($s->state === self::STATE_WALLET_TOPUP_CRYPTO_OPTION) {
            $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

            if (!$user) {
                $this->resetSession($s);
                $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
                return;
            }

            $payload = $s->payload ?: [];
            $amount = (int)($payload['amount'] ?? 0);

            if ($amount < 1000) {
                $s->update(['state' => self::STATE_WALLET_TOPUP_METHOD, 'payload' => ['action' => 'wallet_topup', 'method' => 'crypto']]);
                $this->sendMsgKb($chatIdStr, "مبلغ شارژ مشخص نیست. لطفاً دوباره مبلغ را انتخاب کنید:", $this->buildWalletAmountKeyboard());
                return;
            }

            $option = $this->detectWalletCryptoOptionFromText($text, $user);

            if (!$option) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "لطفاً یکی از ارزهای فعال جهت انتقال را انتخاب کنید:",
                    $this->buildWalletCryptoOptionKeyboard($user)
                );
                return;
            }

            $this->createTelegramCryptoTopup($chatIdStr, $s, $user, $amount, $option->asset, $option->network);
            return;
        }

        if ($s->state === self::STATE_WALLET_TOPUP_RECEIPT) {
            $this->sendMsgKb(
                $chatIdStr,
                "لطفاً عکس یا فایل رسید را ارسال کنید",
                $this->buildBackKeyboard()
            );
            return;
        }

        // 5) اگر هنوز /start نزده
        if ($s->state === self::STATE_IDLE) {
            $this->sendMsgKb(
                $chatIdStr,
                "سلام! برای شروع روی «🔐 ورود» بزنید یا /start ارسال کنید",
                $this->buildMainMenu(false)
            );
            return;
        }

        // 6) دریافت ایمیل
        if ($s->state === self::STATE_AWAIT_USERNAME) {
            if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $this->sendMsg($chatIdStr, "ایمیل معتبر وارد کنید (مثلاً example@gmail.com)");
                return;
            }
            $s->update([
                'state'         => self::STATE_AWAIT_PASSWORD,
                'temp_username' => $text, // اینجا ایمیل را نگه می‌داریم
            ]);
            $this->sendMsg($chatIdStr, "💬 <b>رمز عبور</b> خود را وارد کنید ");
            return;
        }

        // 7) دریافت پسورد و لاگین
        if ($s->state === self::STATE_AWAIT_PASSWORD) {
            $email    = $s->temp_username;
            $password = $text;

            if (!$email) {
                $s->update(['state' => self::STATE_AWAIT_USERNAME, 'temp_username' => null]);
                $this->sendMsg($chatIdStr, "💬 ابتدا <b>ایمیل</b> خود را وارد کنید ");
                return;
            }

            $user = $this->findPanelUserByUsername($email);

            if (!$user || !$this->checkPasswordFlexible($password, $user->password ?? '')) {
                $s->update(['state' => self::STATE_AWAIT_USERNAME, 'temp_username' => null]);
                $this->sendMsg($chatIdStr, "⛔️ ایمیل یا رمز عبور اشتباه است،  مجدد <b>ایمیل</b> تلاش کنید");
                return;
            }

            // اکانت انتخاب‌شده قبلاً به یک تلگرام دیگر لینک شده؟
            if ($user->telegram_user_id && (string)$user->telegram_user_id !== $chatIdStr) {
                $this->sendMsg(
                    $chatIdStr,
                    "⚠️ این حساب کاربری قبلاً به یک تلگرام دیگر متصل شده است، ابتدا از آنجا خارج شوید"
                );

                $s->update(['state' => self::STATE_AWAIT_USERNAME, 'temp_username' => null]);
                return;
            }

            // همین تلگرام قبلاً به یک پنل نماینده دیگر لینک شده؟
            $alreadyLinkedUser = User::where('telegram_user_id', $chatIdStr)
                ->where('id', '<>', $user->id)
                ->first();

            if ($alreadyLinkedUser) {
                $this->sendMsg(
                    $chatIdStr,
                    "⚠️ این تلگرام قبلاً به یک پنل نماینده دیگر متصل شده است، ابتدا در همین ربات گزینه «🚪 خروج» را بزنید و سپس ورود را انجام دهید"
                );

                $s->update(['state' => self::STATE_AWAIT_USERNAME, 'temp_username' => null]);
                return;
            }

            // موفق
            $user->telegram_user_id = $chatIdStr;
            $user->save();

            $s->update([
                'state'         => self::STATE_LOGGED_IN,
                'panel_user_id' => $user->id,
                'temp_username' => null,
            ]);

            $name = $user->name ?: ($user->username ?? $user->email);
            $this->sendMsgKb(
                $chatIdStr,
                "{$name} عزیز خوش آمدید ✨\n\nتمامی امکانات ربات برای شما فعال گردید",
                $this->buildMainMenu(true, $user)
            );
            return;
        }
    }

    // === Receipt approval ===
    protected function applyDecision(string $act, int $receiptId, int $adminId): bool
    {
        return \DB::transaction(function () use ($act, $receiptId, $adminId) {
            /** @var \App\Models\PanelWalletReceipt|null $wr */
            $wr = \App\Models\PanelWalletReceipt::lockForUpdate()->find($receiptId);
            if (!$wr) return false;

            // فقط این دو عمل مجازند
            if (!in_array($act, ['ok', 'reject'], true)) return false;

            // وضعیت‌های معتبر طبق جریان فعلی پروژه: uploaded → submitted → (verified/rejected)
            $status = (string) ($wr->status ?? '');
            $allowed = ['uploaded', 'submitted']; // اگر حالت rechecking هم داری، به لیست اضافه کن

            if (!in_array($status, $allowed, true)) {
                // مثلاً اگر قبلاً verified یا rejected شده باشد، عملیات نامعتبر است
                return false;
            }

            if ($act === 'ok') {
                // ✅ شارژ خودِ کاربر + ست‌کردن status رسید به verified (متد موجود)
                app(\App\Services\TransactionLogger::class)->walletTopupCardVerified(
                    (int) $wr->user_id,
                    (int) $wr->amount,
                    (int) $wr->id,
                    (int) $adminId
                );

                // ✅ همان منطق مرحله ۳: پرداخت کمیسیون به معرّف
                $payer = \Illuminate\Support\Facades\DB::table('panel_users')
                    ->select('id','email','name','code','referred_by_id','ref_commission_rate')
                    ->where('id', $wr->user_id)
                    ->first();

                if ($payer) {
                    $referrerId = (int)($payer->referred_by_id ?? 0);
                    $rate       = (float)($payer->ref_commission_rate ?? 0);

                    if ($referrerId > 0 && $referrerId !== (int)$payer->id && $rate > 0 && !($wr->commission_paid ?? false)) {
                        $commission = (int) floor(((int)$wr->amount) * $rate / 100);

                        if ($commission > 0) {
                            $meta = [
                                'source_user_id'    => (int)$payer->id,
                                'source_user_email' => (string)($payer->email ?? ''),
                                'source_user_code'  => (string)($payer->code  ?? ''),
                                'source_user_name'  => (string)($payer->name  ?? ''),
                                'source_amount'     => (int)$wr->amount,
                                'commission_rate'   => (float)$rate,
                                'source_receipt_id' => (int)$wr->id,
                                'user_ids'          => [(int)$payer->id],
                            ];

                            $commissionTxId = app(\App\Services\TransactionLogger::class)
                                ->walletReferrerCommissionAwarded($referrerId, $commission, $meta, $adminId);

                            $wr->commission_paid  = true;
                            $wr->commission_tx_id = $commissionTxId;
                            $wr->save();
                        }
                    }
                }

                return true;
            }

            // ❌ رد: فقط وضعیت رسید را عوض کن (ستون‌های اضافه مثل approved_by در جدول شما نیستند)
            $wr->status       = 'rejected';
            $wr->notified_at  = null;
            $wr->save();

            return true;
        });
    }

    protected function deletePendingWalletPaymentMessage(PanelBotSession $s): void
    {
        $payload = $s->payload ?: [];
        $messageId = $payload['payment_message_id'] ?? null;

        if (!$messageId || !$s->chat_id) {
            return;
        }

        try {
            $this->deleteMsg((string) $s->chat_id, (int) $messageId);
        } catch (\Throwable $e) {
            //
        }
    }

    protected function deleteWalletPaymentMessage(int $receiptId): void
    {
        try {
            $wr = PanelWalletReceipt::find($receiptId);
            if (!$wr) {
                Log::warning('DELETE_PAYMENT_MSG_NO_RECEIPT', ['receipt_id' => $receiptId]);
                return;
            }

            $meta = $wr->meta;

            if (is_string($meta)) {
                $meta = json_decode($meta, true) ?: [];
            }

            if (!is_array($meta)) {
                $meta = [];
            }

            $targets = [];

            if (!empty($meta['telegram_chat_id']) && !empty($meta['payment_message_id'])) {
                $targets[] = [
                    'chat_id' => $meta['telegram_chat_id'],
                    'message_id' => $meta['payment_message_id'],
                ];
            }

            foreach ($targets as $target) {
                try {
                    $this->deleteMsg((string)$target['chat_id'], (int)$target['message_id']);

                } catch (\Throwable $e) {
                    Log::error('DELETE_FAILED', [
                        'receipt_id' => $receiptId,
                        'chat_id' => $target['chat_id'],
                        'message_id' => $target['message_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {

        }
    }

    // === Telegram helpers from your original controller ===
    protected function sendMsg(string $chatId, string $text): void
    {
        $api = 'https://api.telegram.org/bot' . \App\Services\TelegramConfig::token() . '/sendMessage';
        Http::asForm()->post($api, [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ])->throw();
    }

    protected function sendQrPhotoKb(string $chatId, string $qrText, string $caption, array $kb): void
    {
        try {
            if (trim($qrText) === '') {
                $this->sendMsgKb($chatId, $caption, $kb);
                return;
            }

            $result = Builder::create()
                ->writer(new PngWriter())
                ->data($qrText)
                ->size(800)
                ->margin(20)
                ->build();

            $tmp = tempnam(sys_get_temp_dir(), 'sb_qr_') . '.png';
            $result->saveToFile($tmp);

            $api = 'https://api.telegram.org/bot' . \App\Services\TelegramConfig::token() . '/sendPhoto';

            Http::timeout(20)
                ->attach('photo', fopen($tmp, 'rb'), 'qrcode.png')
                ->post($api, [
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode($kb, JSON_UNESCAPED_UNICODE),
                ])
                ->throw();

            @unlink($tmp);
        } catch (\Throwable $e) {
            $this->sendMsgKb($chatId, $caption, $kb);
        }
    }

    protected function editKb(string $chatId, int $messageId, array $kb): void
    {
        $api = 'https://api.telegram.org/bot' . \App\Services\TelegramConfig::token() . '/editMessageReplyMarkup';
        Http::asForm()->post($api, [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => json_encode($kb, JSON_UNESCAPED_UNICODE),
        ])->throw();
    }

    protected function answerCb(string $cbId, string $text): void
    {
        $api = 'https://api.telegram.org/bot' . \App\Services\TelegramConfig::token() . '/answerCallbackQuery';
        Http::asForm()->post($api, [
            'callback_query_id' => $cbId,
            'text'              => $text,
            'show_alert'        => false,
        ])->throw();
    }

    // === Helpers for login conversation ===
    protected function buildLogoutKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [ ['text' => '🚪 خروج', 'callback_data' => 'logout'] ]
            ]
        ];
    }

    protected function getOrCreateSession(int $chatId): PanelBotSession
    {
        return PanelBotSession::firstOrCreate(
            ['chat_id' => $chatId],
            ['state' => self::STATE_IDLE]
        );
    }

    protected function resetSession(PanelBotSession $s): void
    {
        $s->update([
            'state'          => self::STATE_IDLE,
            'panel_user_id'  => null,
            'temp_username'  => null,
            'payload'        => null,
            'last_activity'  => now(),
        ]);
    }

    protected function findPanelUserByUsername(string $u): ?User
    {
        return User::query()
            ->where('email', $u)
            ->first();
    }

    protected function checkPasswordFlexible(string $plain, string $stored): bool
    {
        if (Hash::check($plain, $stored)) return true;
        return hash_equals($stored, $plain);
    }

    private function finalPartnerCryptoTopupMessage(string $status): ?string
    {
        return match ($status) {
            'paid' => "✅ این سفارش قبلاً با موفقیت پرداخت و تأیید شده است.",
            'expired' => "⏰ مهلت پرداخت این سفارش به پایان رسیده است.",
            'cancelled' => "❌ این سفارش قبلاً لغو شده است.",
            'rejected', 'failed', 'invalid' => "⚠️ این سفارش قابل پردازش نیست.",
            default => null,
        };
    }

    protected function sendMsgKb(string $chatId, string $text, array $kb): void
    {
        $api = 'https://api.telegram.org/bot' . \App\Services\TelegramConfig::token() . '/sendMessage';
        Http::asForm()->post($api, [
            'chat_id'      => $chatId,
            'text'         => $text,              // متن خالی نباشد
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($kb, JSON_UNESCAPED_UNICODE),
        ])->throw();
    }

    protected function sendMsgKbResult(string $chatId, string $text, array $kb): array
    {
        $api = 'https://api.telegram.org/bot' . \App\Services\TelegramConfig::token() . '/sendMessage';

        return Http::asForm()->post($api, [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($kb, JSON_UNESCAPED_UNICODE),
        ])->throw()->json();
    }

    protected function deleteMsg(string $chatId, int $messageId): void
    {
        $api = 'https://api.telegram.org/bot' . \App\Services\TelegramConfig::token() . '/deleteMessage';

        Http::asForm()->post($api, [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ])->throw();
    }

    protected function normalizePositiveInt(string $text): int
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

    protected function formatCreatedAccountsMessage(array $created, string $planKey, ?int $volumeGb = null, string $tariffGroupName = ''): string
    {
        if (empty($created)) {
            return "✅ عملیات انجام شد، اما اطلاعات اکانت برای نمایش دریافت نشد";
        }

        $tariffGroupName = trim($tariffGroupName);

        if ($planKey === 'volume_gig') {
            $planTitle = $tariffGroupName !== ''
                ? $tariffGroupName
                : 'حجمی';
        } else {
            $planTitle = $this->planTitle($planKey);

            if ($tariffGroupName !== '') {
                $planTitle .= ' - ' . $tariffGroupName;
            }
        }

        $lines = [];
        $lines[] = "✅ اکانت با موفقیت ساخته شد";
        $lines[] = "";
        $lines[] = "🧾 طرح: {$planTitle}";

        if ($planKey === 'volume_gig' && $volumeGb) {
            $lines[] = "📊 حجم: {$volumeGb} گیگ";
        }

        $lines[] = "";

        foreach ($created as $i => $item) {
            $n = $i + 1;
            $email = (string)($item['email'] ?? '');
            $token = (string)($item['token'] ?? '');

            $lines[] = "📌 اکانت {$n}:";
            $lines[] = "👤 نام کاربری: <code>" . $this->formatPanelUsername($email) . "</code>";

            if ($token !== '') {
                $lines[] = "🔗 لینک سابسکرایب:";
                $lines[] = "<code>" . $this->buildSubscribeUrl($token) . "</code>";
            }

            $lines[] = "";
        }

        return trim(implode("\n", $lines));
    }

    protected function planTitle(string $planKey): string
    {
        return match ($planKey) {
            'test' => 'تست',
            '1m' => 'یک‌ماهه',
            '3m' => 'سه‌ماهه',
            '6m' => 'شش‌ماهه',
            '12m' => 'یک‌ساله',
            'volume_gig' => 'حجمی',
            default => $planKey,
        };
    }

    protected function buildSubscribeUrl(string $token): string
    {
        if ($token === '') return '';

        return \App\Utils\Helper::getSubscribeUrl($token);
    }

    protected function buildRenewPlanKeyboard(?int $tariffGroupId = null): array
    {
        $keys = $this->enabledPlanKeysForGroup((int)$tariffGroupId, ['1m', '3m', '6m', '12m']);

        $rows = [];

        foreach ($keys as $key) {
            $rows[] = [
                ['text' => $this->renewPlanButtonText($key)]
            ];
        }

        $rows[] = [['text' => '↩️ بازگشت به منو']];

        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'is_persistent' => true,
        ];
    }

    protected function renewPlanButtonText(string $key): string
    {
        return match ($key) {
            '1m' => '📅 تمدید یک‌ماهه',
            '3m' => '📅 تمدید سه‌ماهه',
            '6m' => '📅 تمدید شش‌ماهه',
            '12m' => '📅 تمدید یک‌ساله',
            default => $key,
        };
    }

    protected function detectRenewPlanKeyFromText(string $text): ?string
    {
        return match ($text) {
            '📅 تمدید یک‌ماهه' => '1m',
            '📅 تمدید سه‌ماهه' => '3m',
            '📅 تمدید شش‌ماهه' => '6m',
            '📅 تمدید یک‌ساله' => '12m',
            default => null,
        };
    }

    protected function formatPanelUsername(?string $email): string
    {
        if (!$email) return '-';

        $name = strtolower(trim($email));
        $name = preg_replace('/\.com$/i', '', $name);
        $name = str_replace('@', '-', $name);

        return $name ?: '-';
    }

    protected function findV2UserByUsernameInput(string $input): ?object
    {
        $raw = strtolower(trim($input));

        if ($raw === '') {
            return null;
        }

        $candidates = [];
        $candidates[] = $raw;

        if (str_contains($raw, '@')) {
            $candidates[] = $raw;
        } else {
            $pos = strrpos($raw, '-');

            if ($pos !== false) {
                $candidates[] = substr($raw, 0, $pos) . '@' . substr($raw, $pos + 1) . '.com';
            }

            $candidates[] = $raw . '.com';
        }

        $candidates = array_values(array_unique(array_filter($candidates)));

        foreach ($candidates as $candidate) {
            $found = DB::table('v2_user')
                ->whereRaw('LOWER(`email`) = ?', [$candidate])
                ->first();

            if ($found) {
                return $found;
            }
        }

        return null;
    }

    protected function findV2UserByEmailSafe(string $email): ?object
    {
        return DB::table('v2_user')
            ->whereRaw('LOWER(`email`) = ?', [strtolower(trim($email))])
            ->first();
    }

    protected function formatV2UserSummary(object $v2User, bool $withSubLink = false): string
    {
        $email = (string)($v2User->email ?? '');
        $token = (string)($v2User->token ?? '');

        $transfer = (int)($v2User->transfer_enable ?? 0);
        $used = (int)($v2User->u ?? 0) + (int)($v2User->d ?? 0);
        $remain = max(0, $transfer - $used);

        $expiredAt = $v2User->expired_at === null ? null : (int) $v2User->expired_at;
        $expireText = \App\Services\SubscriptionValidity::hasExpiry($expiredAt)
            ? JalaliDateFormatter::forTelegram($expiredAt, 'H:i d-m-Y', '-')
            : 'بدون تاریخ';

        $daysText = $this->formatRemainingTime($expiredAt);

        $lines = [];
        $lines[] = "🔰 اطلاعات کاربر";
        $lines[] = "";
        $lines[] = "👤 نام کاربری: <code>" . $this->formatPanelUsername($email) . "</code>";
        $lines[] = "🧾 طرح فعال: " . e($this->v2UserPlanTitle($v2User));
        $lines[] = "📊 ترافیک باقی‌مانده: " . $this->formatBytesGb($remain);
        $lines[] = "📅 تاریخ انقضا: {$expireText}";
        $lines[] = "⏳ زمان باقی‌مانده: {$daysText}";

        if ($withSubLink && $token !== '') {
            $lines[] = "";
            $lines[] = "🔗 لینک سابسکرایب:";
            $lines[] = "<code>" . $this->buildSubscribeUrl($token) . "</code>";
        }

        return implode("\n", $lines);
    }

    protected function formatRemainingTime(?int $expiredAt): string
    {
        if (!\App\Services\SubscriptionValidity::hasExpiry($expiredAt)) {
            return 'نامحدود';
        }

        $seconds = $expiredAt - time();

        if ($seconds <= 0) {
            return 'منقضی شده';
        }

        if ($seconds >= 86400) {
            return JalaliDateFormatter::persianDigits((string) ceil($seconds / 86400)) . ' روز';
        }

        if ($seconds >= 3600) {
            return JalaliDateFormatter::persianDigits((string) ceil($seconds / 3600)) . ' ساعت';
        }

        return JalaliDateFormatter::persianDigits((string) ceil($seconds / 60)) . ' دقیقه';
    }

    protected function formatBytesGb(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024 / 1024, 2) . ' گیگ';
    }

    protected function buildWalletPaymentMethodKeyboard(?User $user = null): array
    {
        $keyboard = [];

        if ($this->canUsePaymentMethod('card', $user)) {
            $keyboard[] = [ ['text' => '💳 کارت به کارت'] ];
        }

        if ($this->canUsePaymentMethod('crypto', $user)) {
            $keyboard[] = [ ['text' => '💲 ارز دیجیتال'] ];
        }

        $keyboard[] = [ ['text' => '↩️ بازگشت به منو'] ];

        return [
            'keyboard' => $keyboard,
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
            'is_persistent'     => true,
        ];
    }

    protected function partnerWalletCardSmsEnabled(?User $user = null): bool
    {
        if (!$this->canUsePaymentMethod('card', $user)) {
            return false;
        }

        $method = PanelPaymentMethod::where('method', 'card')->first();

        return (bool) ($method->sms_auto_approve_enabled ?? false);
    }

    protected function canUsePaymentMethod(string $method, ?User $user = null, bool $checkCryptoOptions = true): bool
    {
        $paymentMethod = PanelPaymentMethod::where('method', $method)->first();

        if (!$paymentMethod || !$paymentMethod->enabled) {
            return false;
        }

        if (!$user) {
            return true;
        }

        if (($paymentMethod->access_mode ?? 'all') === 'selected') {
            $ids = array_map('intval', $paymentMethod->partner_ids ?: []);

            if (!in_array((int)$user->id, $ids, true)) {
                return false;
            }
        }

        if ($method === 'crypto' && $checkCryptoOptions) {
            return CryptoPaymentOption::query()
                ->where('context', 'panel_wallet')
                ->where('owner_type', 'admin')
                ->whereNull('owner_id')
                ->where('enabled', true)
                ->whereNotNull('wallet_address')
                ->where('wallet_address', '!=', '')
                ->exists();
        }

        return true;

        if ($method === 'crypto' && $checkCryptoOptions) {
            return CryptoPaymentOption::query()
                ->where('context', 'panel_wallet')
                ->where('owner_type', 'admin')
                ->whereNull('owner_id')
                ->where('enabled', true)
                ->whereNotNull('wallet_address')
                ->where('wallet_address', '!=', '')
                ->exists();
        }

        return true;
    }

    protected function buildWalletAmountKeyboard(): array
    {
        return [
            'keyboard' => [
                [ ['text' => '💰 1,000,000 تومان'] ],
                [ ['text' => '💰 3,000,000 تومان'], ['text' => '💰 5,000,000 تومان'] ],
                [ ['text' => '💰 10,000,000 تومان'], ['text' => '💰 20,000,000 تومان'] ],
                [ ['text' => '↩️ بازگشت به منو'] ],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
            'is_persistent'     => true,
        ];
    }

    protected function detectWalletAmountFromText(string $text): int
    {
        $text = trim($text);

        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $latin   = ['0','1','2','3','4','5','6','7','8','9'];

        $text = str_replace($persian, $latin, $text);
        $text = str_replace($arabic, $latin, $text);

        $digits = preg_replace('/\D+/', '', $text);

        return $digits !== '' ? max(0, (int) $digits) : 0;
    }

    protected function messageHasReceiptFile(array $message): bool
    {
        return !empty($message['photo']) || !empty($message['document']);
    }

    protected function handleWalletReceiptUpload(string $chatIdStr, array $message, PanelBotSession $s): void
    {
        $user = $s->panel_user_id ? User::find($s->panel_user_id) : null;

        if (!$user) {
            $this->resetSession($s);
            $this->sendMsgKb($chatIdStr, "نشست شما منقضی شده است. لطفاً دوباره وارد شوید", $this->buildMainMenu(false));
            return;
        }

        $payload = $s->payload ?: [];
        $cardTopupId = (int)($payload['card_topup_id'] ?? 0);

        if ($cardTopupId > 0) {
            $this->handleInstantWalletReceiptUpload($chatIdStr, $message, $s, $user, $cardTopupId);
            return;
        }

        $amount = (int)($payload['amount'] ?? 0);

        if ($amount < 1000) {
            $s->update(['state' => self::STATE_WALLET_TOPUP, 'payload' => ['action' => 'wallet_topup']]);
            $this->sendMsgKb($chatIdStr, "مبلغ شارژ مشخص نیست. لطفاً دوباره مبلغ را انتخاب کنید:", $this->buildWalletAmountKeyboard());
            return;
        }

        $fileInfo = $this->extractTelegramReceiptFile($message);

        if (!$fileInfo) {
            $this->sendMsgKb($chatIdStr, "⛔️ لطفاً عکس یا فایل رسید را ارسال کنید", $this->buildBackKeyboard());
            return;
        }

        try {
            $wr = PanelWalletReceipt::create([
                'user_id'       => (int)$user->id,
                'amount'        => $amount,
                'method'        => 'card',
                'disk'          => 'public',
                'path'          => '',
                'original_name' => $fileInfo['original_name'],
                'mime'          => $fileInfo['mime'],
                'size'          => $fileInfo['size'],
                'status'        => 'uploaded',
                'meta'          => [
                    'source' => 'partner_bot',
                    'telegram_chat_id' => $chatIdStr,
                    'payment_message_id' => $payload['payment_message_id'] ?? null,
                ],
            ]);

            $content = $this->downloadTelegramFileContent($fileInfo['file_id']);

            if ($content === null) {
                $wr->delete();
                $this->sendMsgKb($chatIdStr, "⛔️ دریافت فایل رسید از تلگرام انجام نشد. دوباره ارسال کنید", $this->buildBackKeyboard());
                return;
            }

            $ext = $fileInfo['ext'] ?: 'bin';
            $path = "receipts/{$wr->id}.{$ext}";

            Storage::disk('public')->put($path, $content);

            $wr->path = $path;
            $wr->status = 'submitted';
            $wr->save();

            $this->deletePendingWalletPaymentMessage($s);

            $before = (int) DB::table('panel_users')->where('id', (int)$user->id)->value('credit');

            app(TransactionLogger::class)->start([
                'panel_user_id'   => (int)$user->id,
                'type'            => 'wallet_topup_card',
                'direction'       => 'credit',
                'amount'          => $amount,
                'balance_before'  => (string)$before,
                'balance_after'   => (string)$before,
                'reference_type'  => 'panel_wallet_receipt',
                'reference_id'    => (int)$wr->id,
                'idempotency_key' => "pending_receipt:{$wr->id}",
                'meta'            => ['method' => 'card', 'source' => 'partner_bot'],
            ]);

            try {
                if (TelegramConfig::enabled() && TelegramConfig::notifyOnCardSubmit()) {
                    $email = (string)($user->email ?? '');
                    $codeRaw = (string)($user->code ?? '');
                    $userCode = $codeRaw !== ''
                        ? preg_replace(['/@/', '/\.com$/i'], ['', ''], $codeRaw)
                        : $email;

                    app(TelegramNotifier::class)->sendReceiptToAdmins([
                        'id'         => $wr->id,
                        'user_email' => $email,
                        'user_code'  => $userCode,
                        'amount'     => (int)$wr->amount,
                        'created_at' => JalaliDateFormatter::forTelegram($wr->created_at, 'H:i d-m-Y', '-'),
                        'photo_url'  => Storage::disk($wr->disk)->url($wr->path),
                        'local_path' => Storage::disk($wr->disk)->path($wr->path),
                        'mime'       => (string)($wr->mime ?? ''),
                    ]);
                    $wr->refresh();

                    $meta = $wr->meta;

                    if (is_string($meta)) {
                        $meta = json_decode($meta, true) ?: [];
                    }

                    if (!is_array($meta)) {
                        $meta = [];
                    }

                    $meta['telegram_chat_id'] = $chatIdStr;
                    $meta['payment_message_id'] = $payload['payment_message_id'] ?? null;

                    $wr->meta = $meta;
                    $wr->save();                    
                }
            } catch (\Throwable $e) {}

            $s->update([
                'state' => self::STATE_LOGGED_IN,
                'payload' => null,
                'temp_username' => null,
            ]);

            $this->sendMsgKb(
                $chatIdStr,
                "✅ رسید شما ثبت و برای بررسی ارسال شد\n\nشماره رسید: <code>{$wr->id}</code>\nمبلغ: " . number_format($amount) . " تومان",
                $this->buildMainMenu(true, $user)
            );
        } catch (\Throwable $e) {
            Log::error('partner_bot_wallet_receipt_failed', ['error' => $e->getMessage()]);

            $this->sendMsgKb(
                $chatIdStr,
                "⛔️ ثبت رسید انجام نشد. لطفاً دوباره تلاش کنید",
                $this->buildBackKeyboard()
            );
        }
    }

    protected function handleInstantWalletReceiptUpload(string $chatIdStr, array $message, PanelBotSession $s, User $user, int $cardTopupId): void
    {
        $payload = $s->payload ?: [];
        $topup = PanelWalletCardTopup::query()->whereKey($cardTopupId)->where('user_id', (int)$user->id)->first();

        if (!$topup) {
            $s->update(['state' => self::STATE_WALLET_TOPUP, 'payload' => ['action' => 'wallet_topup']]);
            $this->sendMsgKb($chatIdStr, "⛔️ پرداخت کارت‌به‌کارت پیدا نشد. لطفاً دوباره وارد بخش شارژ کیف پول شوید", $this->buildWalletPaymentMethodKeyboard($user));
            return;
        }

        if ($topup->status === 'paid') {
            $this->deletePendingWalletPaymentMessage($s);
            $s->update(['state' => self::STATE_LOGGED_IN, 'payload' => null, 'temp_username' => null]);
            $this->sendMsgKb($chatIdStr, "✅ این پرداخت قبلاً تأیید شده و کیف پول شما شارژ شده است", $this->buildMainMenu(true, $user));
            return;
        }

        $fileInfo = $this->extractTelegramReceiptFile($message);

        if (!$fileInfo) {
            $this->sendMsgKb($chatIdStr, "⛔️ لطفاً عکس یا فایل رسید را ارسال کنید", $this->buildBackKeyboard());
            return;
        }

        $content = $this->downloadTelegramFileContent($fileInfo['file_id']);

        if ($content === null) {
            $this->sendMsgKb($chatIdStr, "⛔️ دریافت فایل رسید از تلگرام انجام نشد. دوباره ارسال کنید", $this->buildBackKeyboard());
            return;
        }

        $wr = null;
        $path = null;

        try {
            $wr = PanelWalletReceipt::create([
                'user_id' => (int)$user->id,
                'card_topup_id' => (int)$topup->id,
                'amount' => (int)$topup->amount_irt,
                'method' => 'card',
                'disk' => 'public',
                'path' => '',
                'original_name' => $fileInfo['original_name'],
                'mime' => $fileInfo['mime'],
                'size' => $fileInfo['size'],
                'status' => 'uploaded',
                'meta' => [
                    'source' => 'partner_bot',
                    'telegram_chat_id' => $chatIdStr,
                    'payment_message_id' => $payload['payment_message_id'] ?? null,
                    'card_topup_id' => (int)$topup->id,
                    'payable_amount_irt' => (int)$topup->payable_amount_irt,
                ],
            ]);

            $ext = $fileInfo['ext'] ?: 'bin';
            $path = "receipts/{$wr->id}.{$ext}";
            Storage::disk('public')->put($path, $content);

            $result = DB::transaction(function () use ($wr, $user, $cardTopupId, $path) {
                $lockedTopup = PanelWalletCardTopup::query()->whereKey($cardTopupId)->where('user_id', (int)$user->id)->lockForUpdate()->first();
                $lockedReceipt = PanelWalletReceipt::query()->whereKey($wr->id)->lockForUpdate()->first();

                if (!$lockedTopup || !$lockedReceipt) {
                    return ['ok' => false, 'code' => 'NOT_FOUND'];
                }

                $lockedReceipt->path = $path;
                $lockedReceipt->amount = (int)$lockedTopup->amount_irt;

                if ($lockedTopup->status === 'paid') {
                    $lockedReceipt->status = 'verified';
                    $lockedReceipt->save();

                    return ['ok' => true, 'code' => 'ALREADY_PAID', 'receipt' => $lockedReceipt];
                }

                if ($lockedTopup->status !== 'pending') {
                    return ['ok' => false, 'code' => 'INVALID_TOPUP'];
                }

                if ($lockedTopup->expires_at && $lockedTopup->expires_at->isPast()) {
                    return ['ok' => false, 'code' => 'EXPIRED'];
                }

                $existingReceipt = PanelWalletReceipt::query()
                    ->where('card_topup_id', $lockedTopup->id)
                    ->where('id', '!=', $lockedReceipt->id)
                    ->whereIn('status', ['uploaded', 'submitted', 'pending', 'verified'])
                    ->lockForUpdate()
                    ->first();

                if ($existingReceipt) {
                    return ['ok' => false, 'code' => 'RECEIPT_EXISTS'];
                }

                $lockedReceipt->status = 'submitted';
                $lockedReceipt->save();

                $before = (int) DB::table('panel_users')->where('id', (int)$user->id)->value('credit');

                app(TransactionLogger::class)->start([
                    'panel_user_id' => (int)$user->id,
                    'type' => 'wallet_topup_card',
                    'direction' => 'credit',
                    'amount' => (int)$lockedTopup->amount_irt,
                    'balance_before' => (string)$before,
                    'balance_after' => (string)$before,
                    'reference_type' => 'panel_wallet_receipt',
                    'reference_id' => (int)$lockedReceipt->id,
                    'idempotency_key' => "pending_receipt:{$lockedReceipt->id}",
                    'meta' => [
                        'method' => 'card',
                        'source' => 'partner_bot',
                        'card_topup_id' => (int)$lockedTopup->id,
                        'payable_amount_irt' => (int)$lockedTopup->payable_amount_irt,
                    ],
                ]);

                return ['ok' => true, 'code' => 'SUBMITTED', 'receipt' => $lockedReceipt];
            });

            if (empty($result['ok'])) {
                if ($path) Storage::disk('public')->delete($path);
                if ($wr) $wr->delete();

                if (($result['code'] ?? '') === 'EXPIRED') {
                    $this->sendMsgKb($chatIdStr, "⛔️ مهلت ۳۰ دقیقه‌ای این پرداخت تمام شده است. اگر مبلغ را واریز کرده‌اید منتظر بررسی خودکار بمانید", $this->buildBackKeyboard());
                    return;
                }

                if (($result['code'] ?? '') === 'RECEIPT_EXISTS') {
                    $this->sendMsgKb($chatIdStr, "رسید این پرداخت قبلاً ثبت شده است و در حال بررسی است", $this->buildBackKeyboard());
                    return;
                }

                $this->sendMsgKb($chatIdStr, "⛔️ این پرداخت دیگر امکان ثبت رسید ندارد", $this->buildBackKeyboard());
                return;
            }

            $wr = $result['receipt'];
            $this->deletePendingWalletPaymentMessage($s);

            if (($result['code'] ?? '') === 'SUBMITTED') {
                try {
                    if (TelegramConfig::enabled() && TelegramConfig::notifyOnCardSubmit()) {
                        $email = (string)($user->email ?? '');
                        $codeRaw = (string)($user->code ?? '');
                        $userCode = $codeRaw !== '' ? preg_replace(['/@/', '/\.com$/i'], ['', ''], $codeRaw) : $email;

                        app(TelegramNotifier::class)->sendReceiptToAdmins([
                            'id' => $wr->id,
                            'user_email' => $email,
                            'user_code' => $userCode,
                            'amount' => (int)$wr->amount,
                            'created_at' => JalaliDateFormatter::forTelegram($wr->created_at, 'H:i d-m-Y', '-'),
                            'photo_url' => Storage::disk($wr->disk)->url($wr->path),
                            'local_path' => Storage::disk($wr->disk)->path($wr->path),
                            'mime' => (string)($wr->mime ?? ''),
                        ]);

                        $wr->refresh();

                        if ($wr->status === 'verified') {
                            app(TelegramNotifier::class)->disableReceiptButtons((int)$wr->id);
                        }

                        $meta = $wr->meta;

                        if (is_string($meta)) {
                            $meta = json_decode($meta, true) ?: [];
                        }

                        if (!is_array($meta)) {
                            $meta = [];
                        }

                        $meta['telegram_chat_id'] = $chatIdStr;
                        $meta['payment_message_id'] = $payload['payment_message_id'] ?? null;

                        $wr->meta = $meta;
                        $wr->save();
                    }
                } catch (\Throwable $e) {}
            }

            $s->update(['state' => self::STATE_LOGGED_IN, 'payload' => null, 'temp_username' => null]);

            $topup->refresh();
            $wr->refresh();

            if ($topup->status === 'paid' || $wr->status === 'verified') {
                $this->sendMsgKb(
                    $chatIdStr,
                    "✅ پرداخت شما تأیید شده و کیف پول شما شارژ شده است\n\nشماره رسید: <code>{$wr->id}</code>\nمبلغ شارژ: " . number_format((int)$topup->amount_irt) . " تومان",
                    $this->buildMainMenu(true, $user)
                );
                return;
            }

            $this->sendMsgKb(
                $chatIdStr,
                "✅ رسید شما ثبت و برای بررسی ارسال شد\n\nشماره رسید: <code>{$wr->id}</code>\nمبلغ: " . number_format((int)$topup->amount_irt) . " تومان",
                $this->buildMainMenu(true, $user)
            );
        } catch (\Throwable $e) {
            if ($path) {
                try { Storage::disk('public')->delete($path); } catch (\Throwable $x) {}
            }

            if ($wr && $wr->exists && $wr->status === 'uploaded') {
                try { $wr->delete(); } catch (\Throwable $x) {}
            }

            Log::error('partner_bot_wallet_instant_receipt_failed', [
                'error' => $e->getMessage(),
                'card_topup_id' => $cardTopupId,
            ]);

            $this->sendMsgKb($chatIdStr, "⛔️ ثبت رسید انجام نشد. لطفاً دوباره تلاش کنید", $this->buildBackKeyboard());
        }
    }

    protected function activeWalletCryptoOptions(?User $user = null)
    {
        if (!$this->canUsePaymentMethod('crypto', $user, false)) {
            return collect();
        }

        return CryptoPaymentOption::query()
            ->where('context', 'panel_wallet')
            ->where('owner_type', 'admin')
            ->whereNull('owner_id')
            ->where('enabled', true)
            ->whereNotNull('wallet_address')
            ->where('wallet_address', '!=', '')
            ->orderBy('sort_order')
            ->get();
    }

    protected function buildWalletCryptoOptionKeyboard(?User $user = null): array
    {
        $keyboard = [];

        foreach ($this->activeWalletCryptoOptions($user) as $option) {
            $keyboard[] = [
                [
                    'text' => "💲 {$option->asset} - {$option->network}",
                ],
            ];
        }

        $keyboard[] = [ ['text' => '↩️ بازگشت به منو'] ];

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'is_persistent' => true,
        ];
    }

    protected function detectWalletCryptoOptionFromText(string $text, ?User $user = null): ?CryptoPaymentOption
    {
        $clean = trim(str_replace('💲', '', $text));

        foreach ($this->activeWalletCryptoOptions($user) as $option) {
            $label = "{$option->asset} - {$option->network}";

            if ($clean === $label || $text === "💲 {$label}") {
                return $option;
            }
        }

        return null;
    }

    protected function createTelegramCryptoTopup(string $chatIdStr, PanelBotSession $s, User $user, int $amount, string $asset, string $network): void
    {
        $method = PanelPaymentMethod::where('method', 'crypto')->first();

        if (!$method || !$method->enabled) {
            $this->sendMsgKb($chatIdStr, "⛔️ روش پرداخت ارز دیجیتال فعال نیست", $this->buildWalletPaymentMethodKeyboard($user));
            return;
        }

        if (($method->access_mode ?? 'all') === 'selected') {
            $ids = array_map('intval', $method->partner_ids ?: []);

            if (!in_array((int)$user->id, $ids, true)) {
                $this->sendMsgKb($chatIdStr, "⛔️ روش پرداخت ارز دیجیتال فعال نیست", $this->buildWalletPaymentMethodKeyboard($user));
                return;
            }
        }

        $wallet = CryptoPaymentOption::query()
            ->where('context', 'panel_wallet')
            ->where('owner_type', 'admin')
            ->whereNull('owner_id')
            ->where('asset', $asset)
            ->where('network', $network)
            ->where('enabled', true)
            ->first();

        if (!$wallet || trim((string)$wallet->wallet_address) === '') {
            $this->sendMsgKb($chatIdStr, "⛔️ این ارز دیجیتال فعال نیست یا آدرس ولت آن تنظیم نشده است", $this->buildMainMenu(true, $user));
            return;
        }

        PanelCryptoTopup::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'checking'])
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $activeTopup = PanelCryptoTopup::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'checking'])
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($activeTopup) {
            $this->sendTelegramCryptoTopupMessage($chatIdStr, $s, $user, $activeTopup, true);
            return;
        }

        try {
            $rateToman = app(CryptoRateService::class)->tomanRate($wallet->asset);
        } catch (\Throwable $e) {
            $this->sendMsgKb($chatIdStr, "⛔️ دریافت نرخ ارز ممکن نیست. چند دقیقه بعد دوباره تلاش کنید.", $this->buildMainMenu(true, $user));
            return;
        }

        if ($rateToman <= 0) {
            $this->sendMsgKb($chatIdStr, "⛔️ نرخ ارز معتبر نیست", $this->buildMainMenu(true, $user));
            return;
        }

        $baseCryptoAmount = round($amount / $rateToman, 6);
        $uniqueExtra = random_int(11, 999) / 1000000;
        $cryptoAmount = round($baseCryptoAmount + $uniqueExtra, 6);

        $bscStartBlock = null;

        if (strtoupper((string) ($wallet->network ?: 'TRC20')) === 'BEP20') {
            $bscStartBlock = $this->resolvePartnerBotBscStartBlock();
        }

        $topup = PanelCryptoTopup::create([
            'user_id' => (int)$user->id,
            'amount_irt' => $amount,
            'asset' => $wallet->asset ?: 'USDT',
            'network' => $wallet->network ?: 'TRC20',
            'wallet_address' => $wallet->wallet_address,
            'crypto_amount' => $cryptoAmount,
            'base_crypto_amount' => $baseCryptoAmount,
            'unique_extra' => $uniqueExtra,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(30),
            'bsc_start_block' => $bscStartBlock,
            'bsc_last_checked_block' => null,
            'meta' => [
                'source' => 'partner_bot',
                'telegram_chat_id' => $chatIdStr,
                'rate_toman' => $rateToman,
                'rate_source' => 'nobitex',
            ],
        ]);

        $this->sendTelegramCryptoTopupMessage($chatIdStr, $s, $user, $topup, false);
    }

    protected function sendTelegramCardTopupMessage(
        string $chatIdStr,
        PanelBotSession $s,
        User $user,
        PanelWalletCardTopup $topup,
        bool $existing = false
    ): void {
        $receipt = PanelWalletReceipt::query()
            ->where('card_topup_id', (int)$topup->id)
            ->whereIn('status', ['submitted', 'pending'])
            ->latest('id')
            ->first();

        $remainingText = 'نامشخص';

        if ($topup->expires_at) {
            $seconds = now()->diffInSeconds($topup->expires_at, false);

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

        $digits = substr(
            preg_replace('/\D/', '', (string)$topup->card_number),
            0,
            16
        );

        $statusText = $receipt
            ? '🟠 رسید ثبت شده؛ در انتظار بررسی'
            : '🟡 در انتظار پرداخت';

        $payableAmountRials = (int)$topup->payable_amount_irt * 10;

        $msg =
            ($existing
                ? "⚠️ شما یک پرداخت کارت‌به‌کارت فعال دارید:\n\n"
                : "⚡ تأیید آنی پرداخت کارت‌به‌کارت\n\n")
            . "وضعیت: {$statusText}\n\n"
            . "مبلغ شارژ کیف پول:\n"
            . "<b>" . number_format((int)$topup->amount_irt) . " تومان</b>\n\n"
            . "━━━━━━━━━━━━━━━\n\n"
            . "⚡ <b>تأیید آنی پرداخت</b>\n\n"
            . "💵 مبلغ دقیق قابل پرداخت: <b><code>"
            . $payableAmountRials
            . "</code> ریال</b>\n\n"
            . "📌 برای کپی مبلغ دقیق، فقط روی عدد بزنید.\n"
            . "\n━━━━━━━━━━━━━━━\n\n"
            . "💳 شماره کارت:\n"
            . "<code>{$digits}</code>\n\n"
            . "👤 به نام: <b>" . e((string)$topup->card_holder) . "</b>\n\n"
            . "⏳ مهلت پرداخت: ۳۰ دقیقه\n"
            . "⏱ زمان باقی‌مانده تا انقضای سفارش: {$remainingText}\n\n"
            . "━━━━━━━━━━━━━━━\n\n";

        if ($receipt) {
            $msg .=
                "🧾 رسید شما قبلاً ثبت شده و در انتظار بررسی است.\n"
                . "تا مشخص شدن نتیجه، امکان تغییر مبلغ یا لغو این پرداخت وجود ندارد.";
        } else {
            $msg .=
                "✅ با واریز مبلغ دقیق قابل پرداخت، نیازی به ارسال رسید نیست و پرداخت شما آنی تأیید خواهد شد.\n\n"
                . "⏳ اگر تا ۵ دقیقه پس از واریز، پرداخت به‌صورت خودکار تأیید نشد، تصویر رسید را ارسال کنید.\n\n"
                . "🧾 اگر مبلغ اصلی شارژ کیف پول را واریز کردید، ارسال رسید الزامی است.\n\n"
                . "⏱ تأیید رسید شما ممکن است تا ۳۰ دقیقه زمان ببرد.";
        }

        $keyboard = ['inline_keyboard' => []];

        if (
            !$receipt
            && $topup->status === 'pending'
            && (!$topup->expires_at || $topup->expires_at->isFuture())
        ) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '🔄 تغییر مبلغ',
                            'callback_data' => 'card_topup_change:' . $topup->id,
                        ],
                    ],
                    [
                        [
                            'text' => '❌ لغو پرداخت',
                            'callback_data' => 'card_topup_cancel:' . $topup->id,
                        ],
                    ],
                ],
            ];
        }

        $paymentMsg = $this->sendMsgKbResult(
            $chatIdStr,
            $msg,
            $keyboard
        );

        $paymentMessageId = data_get($paymentMsg, 'result.message_id');

        $topupMeta = is_array($topup->meta)
            ? $topup->meta
            : [];

        $legacyPaymentMessageId = (int)($topupMeta['payment_message_id'] ?? 0);
        $paymentMessageIds = $topupMeta['payment_message_ids'] ?? [];

        if (!is_array($paymentMessageIds)) {
            $paymentMessageIds = [];
        }

        if ($legacyPaymentMessageId > 0) {
            $paymentMessageIds[] = $legacyPaymentMessageId;
        }

        if ((int)$paymentMessageId > 0) {
            $paymentMessageIds[] = (int)$paymentMessageId;
        }

        $topupMeta['telegram_chat_id'] = $chatIdStr;
        $topupMeta['payment_message_id'] = $paymentMessageId;
        $topupMeta['payment_message_ids'] = array_values(array_unique(array_filter(
            array_map('intval', $paymentMessageIds),
            fn ($id) => $id > 0
        )));

        $topup->meta = $topupMeta;
        $topup->save();

        if ($receipt) {
            $s->update([
                'state' => self::STATE_LOGGED_IN,
                'panel_user_id' => $user->id,
                'payload' => null,
                'temp_username' => null,
            ]);

            return;
        }

        $s->update([
            'state' => self::STATE_WALLET_TOPUP_RECEIPT,
            'panel_user_id' => $user->id,
            'payload' => [
                'action' => 'wallet_topup',
                'method' => 'card',
                'amount' => (int)$topup->amount_irt,
                'card_topup_id' => (int)$topup->id,
                'payment_message_id' => $paymentMessageId,
            ],
            'temp_username' => null,
        ]);
    }

    protected function sendTelegramCryptoTopupMessage(string $chatIdStr, PanelBotSession $s, User $user, PanelCryptoTopup $topup, bool $existing = false): void
    {
        $s->update([
            'state' => self::STATE_LOGGED_IN,
            'payload' => null,
            'temp_username' => null,
        ]);

        $remainingText = 'نامشخص';

        if ($topup->expires_at) {
            $seconds = now()->diffInSeconds($topup->expires_at, false);

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

        if ($topup->status === 'checking') {
            $msg =
                "⏳ سفارش رمزارزی قبلی شما در حال بررسی است\n\n".
                "تا دریافت نتیجه، امکان ثبت سفارش رمزارزی جدید وجود ندارد.\n\n".
                "پس از تأیید تراکنش، کیف پول شما شارژ خواهد شد.\n\n" .
                "مبلغ شارژ پنل: " . number_format((int)$topup->amount_irt) . " تومان\n" .
                "ارز: <code>{$topup->asset}</code>\n" .
                "شبکه: <code>{$topup->network}</code>\n" .
                "مبلغ دقیق پرداخت: <code>" . number_format((float)$topup->crypto_amount, 6, '.', '') . "</code>\n" .
                "زمان باقی‌مانده تا انقضای سفارش: {$remainingText}";

            $this->sendMsgKb($chatIdStr, $msg, $this->buildMainMenu(true, $user));
            return;
        }

        $msg =
            ($existing ? "⚠️ شما یک سفارش رمزارزی فعال دارید:\n\n" : "💲 سفارش پرداخت رمزارزی ایجاد شد:\n\n") .
            "وضعیت: در انتظار پرداخت\n" .
            "مبلغ شارژ پنل: " . number_format((int)$topup->amount_irt) . " تومان\n" .
            "\n\n━━━━━━━━━━━━━━━\n\n" . 
            "ارز: <code>{$topup->asset}</code>\n" .
            "شبکه: <code>{$topup->network}</code>\n" .
            "مبلغ دقیق پرداخت: <code>" . number_format((float)$topup->crypto_amount, 6, '.', '') . "</code>\n\n" .
            "آدرس ولت:\n<code>{$topup->wallet_address}</code>\n\n" .
            "⏳ مهلت پرداخت: ۳۰ دقیقه\n" .
            "⏱ زمان باقی‌مانده تا انقضای سفارش: {$remainingText}\n" .
            "\n\n━━━━━━━━━━━━━━━\n\n" . 
            "📌 حتماً نام ارز - شبکه انتقال - مبلغ دقیق - آدرس ولت را مطابق موارد بالا رعایت کنید.\n" .
            "بعد از پرداخت، سیستم به‌صورت خودکار تراکنش را بررسی می‌کند و کیف پول شما شارژ خواهد شد.";

        if ($topup->status === 'pending') {
            $this->sendMsgKb($chatIdStr, $msg . "\n\n🔴 بعد از انجام انتقال، دکمه زیر را بزنید:", [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ پرداخت کردم',
                            'callback_data' => 'crypto_paid:' . $topup->id,
                        ],
                    ],
                    [
                        [
                            'text' => '❌ لغو سفارش',
                            'callback_data' => 'crypto_cancel:' . $topup->id,
                        ],
                    ],
                ],
            ]);
            return;
        }

        $this->sendMsgKb($chatIdStr, $msg, $this->buildMainMenu(true, $user));
    }

    protected function extractTelegramReceiptFile(array $message): ?array
    {
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $photos = $message['photo'];
            $photo = end($photos);

            return [
                'file_id' => (string)($photo['file_id'] ?? ''),
                'original_name' => 'telegram_receipt.jpg',
                'mime' => 'image/jpeg',
                'size' => (int)($photo['file_size'] ?? 0),
                'ext' => 'jpg',
            ];
        }

        if (!empty($message['document']) && is_array($message['document'])) {
            $doc = $message['document'];
            $name = (string)($doc['file_name'] ?? 'telegram_receipt.bin');
            $mime = (string)($doc['mime_type'] ?? 'application/octet-stream');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');

            return [
                'file_id' => (string)($doc['file_id'] ?? ''),
                'original_name' => $name,
                'mime' => $mime,
                'size' => (int)($doc['file_size'] ?? 0),
                'ext' => $ext,
            ];
        }

        return null;
    }

    protected function downloadTelegramFileContent(string $fileId): ?string
    {
        if ($fileId === '') return null;

        $token = TelegramConfig::token();
        if (!$token) return null;

        $res = Http::asForm()->post("https://api.telegram.org/bot{$token}/getFile", [
            'file_id' => $fileId,
        ]);

        if (!$res->ok()) return null;

        $filePath = (string)data_get($res->json(), 'result.file_path', '');
        if ($filePath === '') return null;

        $download = Http::get("https://api.telegram.org/file/bot{$token}/{$filePath}");

        if (!$download->ok()) return null;

        return $download->body();
    }

    protected function buildTariffGroupKeyboard(User $user, string $action, ?string $groupTypeFilter = null): array
    {
        $groups = $this->availableTariffGroupsForBot($user, $action, $groupTypeFilter);

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [['text' => '📁 ' . $group['name']]];
        }

        $rows[] = [['text' => '↩️ بازگشت به منو']];

        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'is_persistent' => true,
        ];
    }

    protected function availableTariffGroupsForBot(User $user, string $action, ?string $groupTypeFilter = null): array
    {
        $role = (int)($user->role ?? 2);
        $isAdmin = $role === 1;
        $partnerPlanType = $user->partner_plan_type ?? 'time';
        $isVolumePartner = !$isAdmin && $partnerPlanType === 'volume';
        $isBothPartner = !$isAdmin && $partnerPlanType === 'both';

        $timeKeys = $action === 'renew'
            ? ['1m', '3m', '6m', '12m']
            : ['test', '1m', '3m', '6m', '12m'];

        $groups = [];

        $access = app(PartnerTariffAccessService::class);

        if (!$isVolumePartner && $groupTypeFilter !== 'volume') {
            $timeGroupsQuery = DB::table('panel_tariff_groups as g')
                ->where('g.enable', 1)
                ->where('g.group_type', 'time')
                ->whereExists(function ($q) use ($timeKeys) {
                    $q->select(DB::raw(1))
                        ->from('panel_plan as p')
                        ->whereColumn('p.tariff_group_id', 'g.id')
                        ->whereIn('p.plan_key', $timeKeys)
                        ->where('p.enable', 1)
                        ->whereNotNull('p.v2_plan_id');
                });

            $access->applyVisibleGroups($timeGroupsQuery, $user, 'g');

            $timeGroups = $timeGroupsQuery
                ->orderBy('g.sort_order')
                ->orderBy('g.id')
                ->get();

            foreach ($timeGroups as $g) {
                $groups[] = [
                    'id' => (int)$g->id,
                    'name' => (string)$g->name,
                    'type' => 'time',
                ];
            }
        }

        if (in_array($action, ['buy', 'traffic'], true) && ($isAdmin || $isVolumePartner || $isBothPartner) && $groupTypeFilter !== 'time') {
            $volumeGroupsQuery = DB::table('panel_plan as p')
                ->join('panel_tariff_groups as g', 'g.id', '=', 'p.tariff_group_id')
                ->where('p.plan_key', 'volume_gig')
                ->where('p.enable', 1)
                ->where('g.enable', 1)
                ->where('g.group_type', 'volume')
                ->whereNotNull('p.v2_plan_id')
                ->select('g.id', 'g.name');

            $access->applyVisibleGroups($volumeGroupsQuery, $user, 'g');

            $volumeGroups = $volumeGroupsQuery
                ->orderBy('g.sort_order')
                ->orderBy('g.id')
                ->get();

            foreach ($volumeGroups as $volume) {
                $groups[] = [
                    'id' => (int) $volume->id,
                    'name' => (string) $volume->name,
                    'type' => 'volume',
                ];
            }
        }

        return $groups;
    }

    protected function detectTariffGroupFromText(string $text, User $user, string $action, ?string $groupTypeFilter = null): ?array
    {
        $name = trim(str_replace('📁 ', '', $text));

        if ($name === '') {
            return null;
        }

        foreach ($this->availableTariffGroupsForBot($user, $action, $groupTypeFilter) as $group) {
            if ($group['name'] === $name) {
                return $group;
            }
        }

        return null;
    }

    protected function enabledPlanKeysForGroup(int $tariffGroupId, array $allowedKeys): array
    {
        if ($tariffGroupId <= 0) {
            return [];
        }

        return DB::table('panel_plan')
            ->where('tariff_group_id', $tariffGroupId)
            ->whereIn('plan_key', $allowedKeys)
            ->where('enable', 1)
            ->whereNotNull('v2_plan_id')
            ->orderByRaw("FIELD(plan_key, 'test', '1m', '3m', '6m', '12m')")
            ->pluck('plan_key')
            ->values()
            ->all();
    }

    protected function isV2UserVolumePlan(object $v2User): bool
    {
        return app(\App\Services\AccountTariffResolver::class)->isVolume($v2User);
    }

    protected function v2UserPlanTitle(object $v2User): string
    {
        $snapshotName = trim((string)($v2User->account_plan_name ?? ''));

        if ($snapshotName !== '') {
            return $snapshotName;
        }

        $planId = (int)($v2User->plan_id ?? 0);

        if ($planId <= 0) {
            return '-';
        }

        $row = DB::table('panel_plan as p')
            ->leftJoin('panel_tariff_groups as g', 'g.id', '=', 'p.tariff_group_id')
            ->where('p.v2_plan_id', $planId)
            ->where('p.enable', 1)
            ->select('p.plan_key', 'p.name as plan_name', 'g.name as group_name')
            ->orderByRaw("FIELD(p.plan_key, 'volume_gig', 'test', '1m', '3m', '6m', '12m')")
            ->first();

        if (!$row) {
            $v2Name = DB::table('v2_plan')->where('id', $planId)->value('name');
            return $v2Name ? (string)$v2Name : '-';
        }

        if ((string)$row->plan_key === 'volume_gig') {
            return trim((string)($row->group_name ?? '')) ?: 'حجمی';
        }

        return trim((string)($row->group_name ?? '')) ?: ((string)($row->plan_name ?? '-'));
    }

    protected function resendPendingWalletReceiptsToAdmin(string $chatId, User $admin): void
    {
        if ((int)($admin->role ?? 2) !== 1) {
            $this->sendMsgKb($chatId, "⛔️ دسترسی ندارید", $this->buildMainMenu(true, $admin));
            return;
        }

        $receipts = PanelWalletReceipt::query()
            ->where('status', 'submitted')
            ->orderBy('id')
            ->get();

        if ($receipts->isEmpty()) {
            $this->sendMsgKb(
                $chatId,
                "✅ کلیه رسیدها بررسی شده است",
                $this->buildMainMenu(true, $admin)
            );
            return;
        }

        foreach ($receipts as $receipt) {
            $user = User::find($receipt->user_id);

            $codeRaw = (string)($user->code ?? '');
            $userCode = $codeRaw !== ''
                ? preg_replace(['/@/', '/\.com$/i'], ['', ''], $codeRaw)
                : (string)($user->email ?? '-');

            $caption = "🧾 <b>رسید کیف پول بررسی‌ نشده</b>\n\n"
                . "شماره رسید: <b>{$receipt->id}</b>\n"
                . "کاربر: <code>" . e($userCode) . "</code>\n"
                . "مبلغ: <b>" . number_format((int)$receipt->amount) . " تومان</b>\n"
                . "زمان ثبت: <b>" . JalaliDateFormatter::forTelegram($receipt->created_at, 'H:i d-m-Y', '-') . "</b>";

            $keyboard = [
                'inline_keyboard' => [[
                    ['text' => '✅ تأیید', 'callback_data' => 'wr:ok:' . $receipt->id],
                    ['text' => '❌ رد', 'callback_data' => 'wr:reject:' . $receipt->id],
                ]],
            ];

            $path = $receipt->path
                ? Storage::disk($receipt->disk ?: 'public')->path($receipt->path)
                : null;

            try {
                if ($path && is_file($path)) {
                    $method = str_starts_with((string)$receipt->mime, 'image/') ? 'sendPhoto' : 'sendDocument';
                    $field = $method === 'sendPhoto' ? 'photo' : 'document';

                    Http::timeout(20)
                        ->attach($field, fopen($path, 'rb'), basename($path))
                        ->post(
                            'https://api.telegram.org/bot' . TelegramConfig::token() . '/' . $method,
                            [
                                'chat_id' => $chatId,
                                'caption' => $caption,
                                'parse_mode' => 'HTML',
                                'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
                            ]
                        )
                        ->throw();
                } else {
                    $this->sendMsgKb($chatId, $caption, $keyboard);
                }
            } catch (\Throwable $e) {
                $this->sendMsgKb($chatId, $caption, $keyboard);
            }
        }
    }

    protected function buildBackKeyboard(): array
    {
        return [
            'keyboard' => [
                [ ['text' => '↩️ بازگشت به منو'] ],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
            'is_persistent'     => true,
        ];
    }

    protected function buildBuyPlanKeyboard(User $user, ?int $tariffGroupId = null): array
    {
        $keys = $this->enabledPlanKeysForGroup((int)$tariffGroupId, ['test', '1m', '3m', '6m', '12m']);

        $rows = [];

        foreach ($keys as $key) {
            $rows[] = [
                ['text' => $this->buyPlanButtonText($key)]
            ];
        }
        $rows[] = [['text' => '↩️ بازگشت به منو']];

        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'is_persistent' => true,
        ];
    }

    protected function buyPlanButtonText(string $key): string
    {
        return match ($key) {
            'test' => '⌛️ اکانت تست',
            '1m' => '📅 اکانت یک‌ماهه',
            '3m' => '📅 اکانت سه‌ماهه',
            '6m' => '📅 اکانت شش‌ماهه',
            '12m' => '📅 اکانت یک‌ساله',
            default => $key,
        };
    }

    protected function detectBuyPlanKeyFromText(string $text): ?string
    {
        return match ($text) {
            '⌛️ اکانت تست' => 'test',
            '📅 اکانت یک‌ماهه' => '1m',
            '📅 اکانت سه‌ماهه' => '3m',
            '📅 اکانت شش‌ماهه' => '6m',
            '📅 اکانت یک‌ساله' => '12m',
            '📦 اکانت حجمی' => 'volume_gig',
            default => null,
        };
    }

    // منوی اصلی چسبیده (Reply Keyboard) — وقتی لاگین است فقط «خروج»، وقتی نیست فقط «ورود»
    protected function buildMainMenu(bool $loggedIn, ?User $user = null): array
    {
        if (!$loggedIn) {
            return [
                'keyboard' => [
                    [ ['text' => '🔐 ورود'] ],
                ],
                'resize_keyboard'   => true,
                'one_time_keyboard' => false,
                'is_persistent'     => true,
            ];
        }

        $role = (int)($user->role ?? 2);
        $isAdmin = $role === 1;
        $partnerPlanType = $user->partner_plan_type ?? 'time';
        $isVolumePartner = !$isAdmin && $partnerPlanType === 'volume';
        $isBothPartner = !$isAdmin && $partnerPlanType === 'both';

        if ($isAdmin) {
            $keyboard = [
                [ ['text' => '🛒 خرید اکانت جدید'] ],
                [ ['text' => '🔁 تمدید اکانت'], ['text' => '➕ خرید ترافیک'] ],
                [ ['text' => '👤 اطلاعات کاربر'] ],
                [ ['text' => '🧾 رسیدهای بررسی‌ نشده'] ],
                [ ['text' => '📣 ارسال پیام گروهی'] ],
                [ ['text' => '🚪 خروج'] ],
            ];
        } elseif ($isVolumePartner) {
            $keyboard = [
                [ ['text' => '🛒 خرید اکانت جدید'] ],
                [ ['text' => '➕ خرید ترافیک'] ],
                [ ['text' => '👤 اطلاعات کاربر'], ['text' => '💰 اعتبار کیف پول'] ],
                [ ['text' => '💳 شارژ کیف پول'] ],
                [ ['text' => '🚪 خروج'] ],
            ];
        } else {
            $keyboard = [
                [ ['text' => '🛒 خرید اکانت جدید'] ],
                [ ['text' => '🔁 تمدید اکانت'], ['text' => '➕ خرید ترافیک اضافه'] ],
                [ ['text' => '👤 اطلاعات کاربر'], ['text' => '💰 اعتبار کیف پول'] ],
                [ ['text' => '💳 شارژ کیف پول'] ],
                [ ['text' => '🚪 خروج'] ],
            ];
        }

        return [
            'keyboard' => $keyboard,
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
            'is_persistent'     => true,
        ];
    }

    private function resolvePartnerBotBscStartBlock(): ?int
    {
        $setting = PanelCryptoWalletSetting::query()->first();
        $apiKey = trim((string) ($setting->bscscan_api_key ?? ''));

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
