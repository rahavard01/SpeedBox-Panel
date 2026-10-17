<?php

namespace App\Services;

use App\Support\JalaliDateFormatter;
use Illuminate\Support\Facades\Http;

class TelegramNotifier
{
    protected string $api;
    protected array $admins;

    public function __construct()
    {
        $token        = \App\Services\TelegramConfig::token();
        $this->api    = "https://api.telegram.org/bot{$token}";
        $this->admins = \App\Services\TelegramConfig::adminChatIds();
    }

    public function isEnabled(): bool
    {
        return \App\Services\TelegramConfig::enabled() && !empty($this->admins);
    }

    /** ارسال متن ساده (تست/فالبک) */
    public function sendTextToAdmins(string $text): bool
    {
        if (!$this->isEnabled()) return false;

        foreach ($this->admins as $chatId) {
            Http::asForm()->post("{$this->api}/sendMessage", [
                'chat_id'                  => $chatId,
                'text'                     => $this->rtl($text),
                'disable_web_page_preview' => true,
            ])->throw();
        }
        return true;
    }

    public function notifyPanelWalletSmsApproved(int $topupId, ?int $receiptId = null): void
    {
        $topup = \App\Models\PanelWalletCardTopup::query()->with('user')->find($topupId);

        if (!$topup) {
            return;
        }

        $this->disablePanelWalletCardTopupPaymentButtons($topup);

        if ($receiptId) {
            $this->disableReceiptButtons($receiptId);
        }

        $meta = is_array($topup->meta) ? $topup->meta : [];

        if (!empty($meta['sms_admin_notified_at'])) {
            return;
        }

        if ($receiptId) {
            $sent = $this->sendTextToAdmins(
                "✅ پرداخت پیامکی مربوط به رسید #{$receiptId} تأیید و کیف پول کاربر با مبلغ "
                . number_format((int)$topup->amount_irt)
                . " تومان شارژ شد."
            );
        } else {
            $user = $topup->user;
            $email = (string)($user?->email ?? '');
            $codeRaw = (string)($user?->code ?? '');
            $userCode = $codeRaw !== '' ? preg_replace(['/@/', '/\.com$/i'], ['', ''], $codeRaw) : $email;

            $text = "🧾 کارت‌به‌کارت\n"
                . "شماره پرداخت: " . $this->wrapLtr((string)$topup->id) . "\n"
                . "کاربر: " . $this->wrapLtr($this->esc($userCode)) . "\n"
                . "مبلغ: " . $this->wrapLtr(number_format((int)$topup->payable_amount_irt)) . " تومان\n"
                . "زمان: " . $this->wrapLtr(JalaliDateFormatter::forTelegram($topup->created_at, 'Y-m-d H:i')) . "\n\n"
                . "✅ پرداخت پیامکی شماره {$topup->id} تأیید و کیف پول کاربر با موفقیت شارژ شد.";

            $sent = $this->sendTextToAdmins($text);
        }

        if (!$sent) {
            return;
        }

        $meta['sms_admin_notified_at'] = now()->toDateTimeString();
        $meta['sms_admin_receipt_id'] = $receiptId;

        $topup->meta = $meta;
        $topup->save();
    }

    protected function disablePanelWalletCardTopupPaymentButtons(\App\Models\PanelWalletCardTopup $topup): void
    {
        try {
            $meta = is_array($topup->meta) ? $topup->meta : [];
            $chatId = $meta['telegram_chat_id'] ?? null;
            $messageId = $meta['payment_message_id'] ?? null;

            if (!$chatId || !$messageId) {
                return;
            }

            Http::asForm()->post("{$this->api}/editMessageReplyMarkup", [
                'chat_id' => $chatId,
                'message_id' => (int)$messageId,
                'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
            ])->throw();
        } catch (\Throwable $e) {
            // ممکن است پیام قبلاً حذف شده باشد یا دکمه‌ای نداشته باشد.
        }
    }

    /** ارسال رسید با مدیا (بدون کپشن) + متن جداگانه با دکمه‌ها */
    public function sendReceiptToAdmins(array $payload): bool
    {
        if (!$this->isEnabled()) return false;

        $receiptId = (int)($payload['id'] ?? 0);

        $tpl = \App\Services\TelegramConfig::template() ?:
            "🧾 رسید کارت‌به‌کارت\n".
            "کاربر: {user_code}\n".
            "مبلغ: {amount} تومان\n".
            "زمان: {created_at}\n".
            "شناسه رسید: {id}\n";

        $tpl = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $tpl);    
        $text = strtr($tpl, [
            '{id}'         => $this->rtl($this->esc((string)($payload['id'] ?? ''))),
            '{email}'      => $this->wrapLtr($this->esc((string)($payload['user_email'] ?? ''))),
            '{user_code}'  => $this->wrapLtr($this->esc((string)($payload['user_code']  ?? ''))),
            '{amount}'     => $this->wrapLtr(number_format((int)($payload['amount'] ?? 0))),
            '{created_at}' => $this->wrapLtr($this->esc((string)($payload['created_at'] ?? ''))),
        ]);

        $kb = null;
        if (\App\Services\TelegramConfig::allowApproveViaTelegram()) {
            $kb = [
                'inline_keyboard' => [[
                    ['text' => '✅ تأیید', 'callback_data' => 'wr:ok:'     . $receiptId],
                    ['text' => '❌ رد',   'callback_data' => 'wr:reject:' . $receiptId],
                ]]
            ];
        }
        $kbJson = $kb ? json_encode($kb, JSON_UNESCAPED_UNICODE) : null;

        $usePhoto  = \App\Services\TelegramConfig::usePhoto();
        $photoUrl  = $payload['photo_url']  ?? null;
        $localPath = $payload['local_path'] ?? null;
        $mime      = strtolower((string)($payload['mime'] ?? ''));
        $isImage   = str_starts_with($mime, 'image/');

        $saved = []; // برای ذخیره chat_id/message_id ها

        foreach ($this->admins as $chatId) {
            try {
                // 1) مدیا (بدون کپشن)
                if ($localPath && @is_file($localPath)) {
                    if ($usePhoto && $isImage) {
                        $resp = \Illuminate\Support\Facades\Http::asMultipart()
                            ->attach('photo', fopen($localPath, 'rb'), basename($localPath))
                            ->post("{$this->api}/sendPhoto", ['chat_id' => $chatId]);
                    } else {
                        $resp = \Illuminate\Support\Facades\Http::asMultipart()
                            ->attach('document', fopen($localPath, 'rb'), basename($localPath))
                            ->post("{$this->api}/sendDocument", ['chat_id' => $chatId]);
                    }
                    $resp->throw();
                } elseif ($photoUrl && preg_match('/^https?:\/\/(?!localhost|127\.0\.0\.1)/i', $photoUrl)) {
                    if ($usePhoto && $isImage) {
                        $resp = \Illuminate\Support\Facades\Http::asForm()->post("{$this->api}/sendPhoto", [
                            'chat_id' => $chatId,
                            'photo'   => $photoUrl,
                        ]);
                    } else {
                        $resp = \Illuminate\Support\Facades\Http::asForm()->post("{$this->api}/sendDocument", [
                            'chat_id'  => $chatId,
                            'document' => $photoUrl,
                        ]);
                    }
                    $resp->throw();
                }

                // 2) متن + دکمه (reply_markup)
                $resp2 = \Illuminate\Support\Facades\Http::asForm()->post("{$this->api}/sendMessage", [
                    'chat_id'      => $chatId,
                    'text'         => $this->rtl($text),
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $kbJson,
                    'disable_web_page_preview' => true,
                ])->throw();

                $json = $resp2->json();
                $mid  = (int) data_get($json, 'result.message_id');
                if ($mid > 0) {
                    $saved[] = ['chat_id' => (string)$chatId, 'message_id' => $mid];
                }
            } catch (\Throwable $e) {
                // خطای ارسال را نادیده بگیر تا روند اصلی نخوابد
            }
        }

        // ذخیره message_idها داخل meta رسید
        if ($receiptId && !empty($saved)) {
            try {
                $wr = \App\Models\PanelWalletReceipt::find($receiptId);
                if ($wr) {
                    $meta = [];
                    if ($wr->meta) {
                        if (is_array($wr->meta)) {
                            $meta = $wr->meta;
                        } elseif (is_string($wr->meta)) {
                            try { $meta = json_decode($wr->meta, true) ?: []; } catch (\Throwable $e) {}
                        }
                    }

                    $meta['telegram_messages'] = array_values(array_unique(array_merge($meta['telegram_messages'] ?? [], $saved), SORT_REGULAR));
                    $wr->meta = $meta;
                    $wr->save();
                }
            } catch (\Throwable $e) {}
        }

        return true;
    }

    /** ——— کمکی‌ها ——— */

    protected function sendKbMessage(string|int $chatId, string $text, ?string $kbJson): void
    {
        Http::asForm()->post("{$this->api}/sendMessage", [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $kbJson,
        ])->throw();
    }

    /** Escape برای HTML */
    protected function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** اعداد انگلیسی → فارسی (کمک به RTL پایدار) */
//    protected function faDigits(string $s): string
//    {
//        $en = ['0','1','2','3','4','5','6','7','8','9', ','];
//        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹', '،'];
//        return str_replace($en, $fa, $s);
//    }

    /** ایزوله‌ی LTR برای مقادیر لاتین/رقمی (user_code, id, amount, date) */
    protected function wrapLtr(string $s): string
    {
        $LRI = "\u{2066}";
        $PDI = "\u{2069}";
        return $LRI . $s . $PDI;
    }

    /** بستن کل متن به RTL (پایدارتر برای iOS) */
    protected function rtl(string $s): string
    {
        $RLM = "\u{200F}";
        $RLE = "\u{202B}";
        $PDF = "\u{202C}";
        return $RLM . $RLE . $s . $PDF;
    }
    // تبدیل زمان به شمسی با اعداد لاتین
    protected function toJalali(string $dt): string
    {
        return JalaliDateFormatter::forTelegram($dt, 'Y/m/d H:i');
    }

    public function sendTextTo(string $chatId, string $text): bool
    {
        // متن را RTL می‌کنیم تا روی همه دستگاه‌ها درست نمایش داده شود
        $text = $this->rtl($text);

        \Illuminate\Support\Facades\Http::asForm()->post("{$this->api}/sendMessage", [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ])->throw();

        return true;
    }

    public function finalizePanelWalletCardTopupUser(int $topupId, int $amount, int $balanceAfter): void
    {
        $topup = \App\Models\PanelWalletCardTopup::query()
            ->with('user')
            ->find($topupId);

        if (!$topup) {
            return;
        }

        $meta = is_array($topup->meta) ? $topup->meta : [];
        $user = $topup->user;

        $chatId = (string)($meta['telegram_chat_id'] ?? ($user?->telegram_user_id ?? ''));

        if ($chatId === '') {
            return;
        }

        $this->deletePanelWalletCardTopupPaymentMessages($topup, $chatId);

        $session = \App\Models\PanelBotSession::query()
            ->where('chat_id', (int)$chatId)
            ->first();

        $sessionPayload = is_array($session?->payload)
            ? $session->payload
            : [];

        $ownsCurrentCardFlow = $session
            && (string)$session->state === 'wallet_topup_receipt'
            && (int)($sessionPayload['card_topup_id'] ?? 0) === (int)$topup->id;

        if ($ownsCurrentCardFlow) {
            $session->update([
                'state' => 'logged_in',
                'panel_user_id' => (int)$topup->user_id,
                'payload' => null,
                'temp_username' => null,
            ]);
        }

        $message =
            "✅ کیف پول شما با مبلغ " . number_format($amount) . " تومان شارژ شد.\n"
            . "💰 موجودی فعلی: " . number_format($balanceAfter) . " تومان\n";

        if ($ownsCurrentCardFlow && $user) {
            $this->sendTextToWithKeyboard(
                $chatId,
                $message,
                $this->buildPartnerBotMainMenu($user)
            );

            return;
        }

        $this->sendTextTo($chatId, $message);
    }

    public function finalizePanelWalletCardTopupCancellation(int $topupId): void
    {
        $topup = \App\Models\PanelWalletCardTopup::query()
            ->with('user')
            ->find($topupId);

        if (!$topup) {
            return;
        }

        $meta = is_array($topup->meta) ? $topup->meta : [];
        $user = $topup->user;

        $chatId = (string)($meta['telegram_chat_id'] ?? ($user?->telegram_user_id ?? ''));

        if ($chatId === '') {
            return;
        }

        $this->deletePanelWalletCardTopupPaymentMessages($topup, $chatId);

        $session = \App\Models\PanelBotSession::query()
            ->where('chat_id', (int)$chatId)
            ->first();

        $sessionPayload = is_array($session?->payload)
            ? $session->payload
            : [];

        $ownsCurrentCardFlow = $session
            && (string)$session->state === 'wallet_topup_receipt'
            && (int)($sessionPayload['card_topup_id'] ?? 0) === (int)$topup->id;

        if (!$ownsCurrentCardFlow) {
            return;
        }

        $session->update([
            'state' => 'logged_in',
            'panel_user_id' => (int)$topup->user_id,
            'payload' => null,
            'temp_username' => null,
        ]);

        if ($user) {
            $this->sendTextToWithKeyboard(
                $chatId,
                "✅ پرداخت کارت‌به‌کارت قبلی لغو شد.",
                $this->buildPartnerBotMainMenu($user)
            );
        }
    }

    public function deletePanelWalletCardTopupPaymentMessages(
        \App\Models\PanelWalletCardTopup $topup,
        string $chatId
    ): void {
        $meta = is_array($topup->meta) ? $topup->meta : [];
        $messageIds = $meta['payment_message_ids'] ?? [];

        if (!is_array($messageIds)) {
            $messageIds = [];
        }

        $legacyMessageId = (int)($meta['payment_message_id'] ?? 0);

        if ($legacyMessageId > 0) {
            $messageIds[] = $legacyMessageId;
        }

        $messageIds = array_values(array_unique(array_filter(
            array_map('intval', $messageIds),
            fn ($id) => $id > 0
        )));

        foreach ($messageIds as $messageId) {
            try {
                Http::asForm()->post("{$this->api}/deleteMessage", [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ])->throw();
            } catch (\Throwable $e) {
                // پیام ممکن است قبلاً حذف شده باشد.
            }
        }
    }

    protected function sendTextToWithKeyboard(string $chatId, string $text, array $keyboard): bool
    {
        $text = $this->rtl($text);

        Http::asForm()->post("{$this->api}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ])->throw();

        return true;
    }

    protected function buildPartnerBotMainMenu(\App\Models\User $user): array
    {
        $role = (int)($user->role ?? 2);
        $isAdmin = $role === 1;
        $partnerPlanType = $user->partner_plan_type ?? 'time';
        $isVolumePartner = !$isAdmin && $partnerPlanType === 'volume';

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
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'is_persistent' => true,
        ];
    }

    public function disableReceiptButtons(int $receiptId): void
    {
        try {
            $wr = \App\Models\PanelWalletReceipt::find($receiptId);
            if (!$wr) return;
            $meta = [];
            if ($wr->meta) {
                if (is_array($wr->meta)) {
                    $meta = $wr->meta;
                } elseif (is_string($wr->meta)) {
                    try { $meta = json_decode($wr->meta, true) ?: []; } catch (\Throwable $e) {}
                }
            }
            $list = $meta['telegram_messages'] ?? [];
            foreach ($list as $row) {
                $chatId = $row['chat_id'] ?? null;
                $msgId  = $row['message_id'] ?? null;
                if ($chatId && $msgId) {
                    try {
                        \Illuminate\Support\Facades\Http::asForm()->post("{$this->api}/editMessageReplyMarkup", [
                            'chat_id'    => $chatId,
                            'message_id' => $msgId,
                            'reply_markup' => json_encode(['inline_keyboard' => []]),
                        ])->throw();
                    } catch (\Throwable $e) {}
                }
            }
        } catch (\Throwable $e) {}
    }

    public function broadcastReceiptDecisionText(int $receiptId, string $action, ?string $reason = null): void
    {
        $action = ($action === 'ok' ? 'ok' : 'reject');
        $txt = $action === 'ok'
            ? "✅ رسید #{$receiptId} تایید شد"
            : "❌ رسید #{$receiptId} رد شد" . ($reason ? " — دلیل: {$reason}" : "");

        $this->sendTextToAdmins($txt);
    }

    // [ANN-TG] ارسال اعلان به یک کاربر (و ذخیره chat_id/message_id برای حذف فوری دکمه)
    public function sendAnnouncementToUser(int $userId, \App\Models\PanelAnnouncement $a): bool
    {
        try {
            $row = \DB::table('panel_users')->where('id', $userId)->first();
            if (!$row || (int) ($row->role ?? 2) === 1 || !$row->telegram_user_id) return true;
            $chatId = (string) $row->telegram_user_id;
            $previous = \App\Models\PanelAnnouncementRead::where('announcement_id', $a->id)->where('user_id', $userId)->first();
            if ($previous && (string) $previous->tg_chat_id === $chatId && (int) $previous->tg_message_id > 0) return true;
            $token = \App\Services\TelegramConfig::token();
            if (!$token) return false;
            $pubAt = JalaliDateFormatter::forTelegram($a->publish_at ?: now(), 'H:i d-m-Y', '-');
            $resp = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => "📣 شما یک اعلان جدید دارید\n📅 تاریخ انتشار: {$pubAt}\n💬 ".trim((string) $a->title),
                'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '👁 مشاهده', 'callback_data' => "ann:view:{$a->id}"]]]], JSON_UNESCAPED_UNICODE),
            ])->throw()->json();
            $messageId = (int) data_get($resp, 'result.message_id');
            if (!data_get($resp, 'ok') || $messageId < 1) return false;
            \App\Models\PanelAnnouncementRead::updateOrCreate(
                ['announcement_id' => $a->id, 'user_id' => $userId],
                ['tg_chat_id' => $chatId, 'tg_message_id' => $messageId],
            );
            return true;
        } catch (\Throwable $error) {
            \Log::warning('Announcement delivery failed.', ['announcement_id' => $a->id, 'user_id' => $userId]);
            return false;
        }
    }

    public function broadcastAnnouncement(int $announcementId): int
    {
        // Also serializes immediate publication against the scheduled sender.
        $mysql = \DB::connection()->getDriverName() === 'mysql';
        $lock = 'speedbox:announcement:'.$announcementId;
        if ($mysql && (int) (\DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock])->acquired ?? 0) !== 1) return 0;
        try {
            $a = \App\Models\PanelAnnouncement::find($announcementId);
            if (!$a || !$a->is_published || ($a->publish_at && $a->publish_at->isFuture()) || ($a->expires_at && !$a->expires_at->isFuture())) return 0;
            $complete = true;
            $sent = 0;
            foreach (\DB::table('panel_users')->whereNotNull('telegram_user_id')->where('role', '!=', 1)->pluck('id') as $id) {
                if ($this->sendAnnouncementToUser((int) $id, $a)) $sent++;
                else $complete = false;
            }
            \DB::table('panel_announcements')->where('id', $announcementId)->update(['tg_broadcasted_at' => $complete ? now() : null]);
            return $sent;
        } finally {
            if ($mysql) \DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
    }

    // --- [TUTORIALS] Helpers & senders ---

    /**
     * برچسب و آیکون دسته‌بندی برای پیام تلگرام
     */
    protected function tutorialCategoryChip(?string $cat): array
    {
        $cat = strtolower((string)$cat);
        switch ($cat) {
            case 'ios':     return ['label' => 'اپل',    'icon' => "📱"];      // یا \uF8FF
            case 'android': return ['label' => 'اندروید','icon' => "🤖"];
            case 'system':  return ['label' => 'سیستم',  'icon' => "🖥️"];
            default:        return ['label' => '—',      'icon' => "•"];
        }
    }

    /**
     * متن پیام «منتشر شد»
     */
    protected function buildTutorialCreatedText(\App\Models\PanelTutorial $t): string
    {
        $chip = $this->tutorialCategoryChip($t->category ?? '');
        $app  = trim((string)$t->app_name);
        $date = JalaliDateFormatter::forTelegram($t->published_at ?? now(), 'H:i d-m-Y', '-');
        return "🔰 آموزش برنامه «{$app}» منتشر شد\n"
            ."📅 تاریخ انتشار: {$date}\n"
            ."{$chip['icon']} دسته‌بندی: {$chip['label']}\n\n"
            ."جهت مشاهده به پنل کاربری خود در قسمت «آموزش‌ها» مراجعه کنید.";
    }

    /**
     * متن پیام «به‌روزرسانی شد»
     */
    protected function buildTutorialUpdatedText(\App\Models\PanelTutorial $t): string
    {
        $chip = $this->tutorialCategoryChip($t->category ?? '');
        $app  = trim((string)$t->app_name);
        return "🔰 آموزش برنامه «{$app}» به‌روزرسانی شد\n"
            ."{$chip['icon']} دسته‌بندی: {$chip['label']}\n\n"
            ." جهت مشاهده به پنل کاربری خود در قسمت «آموزش‌ها» مراجعه کنید.";
    }

    /**
     * ارسال پیام «منتشر شد» به یک کاربر
     */
    public function sendTutorialCreatedToUser(int $chatId, \App\Models\PanelTutorial $t): void
    {
        if (!$this->isEnabled()) return;
        $text = $this->buildTutorialCreatedText($t);
        // اگر دکمه هم خواستی اضافه کنی، اینجا می‌تونی inline keyboard بدی
        $this->sendTextTo($chatId, $text);
    }

    /**
     * ارسال پیام «آپدیت‌شد» به یک کاربر
     */
    public function sendTutorialUpdatedToUser(int $chatId, \App\Models\PanelTutorial $t): void
    {
        if (!$this->isEnabled()) return;
        $text = $this->buildTutorialUpdatedText($t);
        $this->sendTextTo($chatId, $text);
    }

    /**
     * Broadcast: فقط برای «کاربران» با تلگرام وصل‌شده
     */
    public function broadcastTutorialCreated(\App\Models\PanelTutorial $t): void
    {
        if (!$this->isEnabled()) return;

        // کاربران با chat_id (فیلد مرسوم در پروژه: users.telegram_user_id)
        $adminIds = \App\Services\TelegramConfig::adminChatIds(); // احتیاطی برای حذف ادمین‌ها

        $chatIds = \DB::table('panel_users')
            ->whereNotNull('telegram_user_id')
            ->where('role', '!=', 1)     // فقط کاربرها (ادمین: role=1)
            ->where('banned', 0)         // اگر ستون banned دارید، نرفتن برای بن‌شده‌ها
            ->pluck('telegram_user_id')
            ->filter()
            ->map(fn($v) => (int)$v)
            ->unique()
            ->reject(fn($id) => in_array((string)$id, $adminIds, true)) // حذف قطعی ادمین‌ها
            ->values();

        foreach ($chatIds as $chatId) {
            try { $this->sendTutorialCreatedToUser($chatId, $t); }
            catch (\Throwable $e) { \Log::warning('TG send tutorial created failed', ['chat'=>$chatId,'err'=>$e->getMessage()]); }
        }
    }

    public function broadcastTutorialUpdated(\App\Models\PanelTutorial $t): void
    {
        if (!$this->isEnabled()) return;

        $adminIds = \App\Services\TelegramConfig::adminChatIds(); // احتیاطی برای حذف ادمین‌ها

        $chatIds = \DB::table('panel_users')
            ->whereNotNull('telegram_user_id')
            ->where('role', '!=', 1)     // فقط کاربرها (ادمین: role=1)
            ->where('banned', 0)         // اگر ستون banned دارید، نرفتن برای بن‌شده‌ها
            ->pluck('telegram_user_id')
            ->filter()
            ->map(fn($v) => (int)$v)
            ->unique()
            ->reject(fn($id) => in_array((string)$id, $adminIds, true)) // حذف قطعی ادمین‌ها
            ->values();

        foreach ($chatIds as $chatId) {
            try { $this->sendTutorialUpdatedToUser($chatId, $t); }
            catch (\Throwable $e) { \Log::warning('TG send tutorial updated failed', ['chat'=>$chatId,'err'=>$e->getMessage()]); }
        }
    }

}
