<?php

namespace App\Services;

use App\Models\PanelPaymentMethod;
use App\Models\PanelWalletReceipt;
use App\Models\UserBotOrder;
use App\Models\UserBotSession;
use App\Models\UserBotSetting;
use App\Services\UserBot\UserBotKeyboard;
use App\Services\UserBot\UserBotCardOrderApprovalService;
use App\Services\UserBot\UserBotTelegram;
use App\Services\UserBot\UserBotOrderDeliveryService;
use App\Support\JalaliDateFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReceiptAiAutoApproveRunner
{
    private const AI_MAX_RETRY_ATTEMPTS = 3;

    private const AI_RETRY_DELAYS_MINUTES = [
        1 => 5,
        2 => 15,
    ];

    public function tick(): void
    {
        $this->tickPanelWalletReceipts();
        $this->tickUserBotOrders();
    }

    private function tickPanelWalletReceipts(): void
    {
        $method = PanelPaymentMethod::where('method', 'card')->first();

        if (!$method || !(bool) $method->ai_auto_approve_enabled || trim((string) $method->gemini_api_key) === '') {
            return;
        }

        $adminId = (int) DB::table('panel_users')
            ->where('role', 1)
            ->value('id');

        if ($adminId <= 0) {
            return;
        }

        $receipts = PanelWalletReceipt::query()
            ->whereIn('status', ['uploaded', 'submitted', 'pending'])
            ->where('created_at', '<=', now()->subMinutes(30))
            ->latest('id')
            ->limit(10)
            ->get();

        foreach ($receipts as $receipt) {
            $meta = $this->arrayMeta($receipt->meta);

            if (!$this->shouldRunAiReceipt($meta)) {
                continue;
            }

            try {
                if (!$receipt->path || str_starts_with((string) $receipt->path, 'manual://') || str_starts_with((string) $receipt->path, 'adjust://')) {
                    continue;
                }

                $path = Storage::disk($receipt->disk ?: 'public')->path($receipt->path);

                $ai = app(GeminiReceiptAnalyzer::class)->analyzeLocalFile($path, (string) $method->gemini_api_key);

                $cardName = (string) DB::table('panel_card_number')
                    ->orderByDesc('id')
                    ->value('name');

                $expectedAmounts = [(int) $receipt->amount];
                $expectedReceiver = $cardName;

                if ((int) ($receipt->card_topup_id ?? 0) > 0) {
                    $cardTopup = \App\Models\PanelWalletCardTopup::query()->find((int) $receipt->card_topup_id);

                    if ($cardTopup) {
                        $expectedAmounts[] = (int) $cardTopup->amount_irt;
                        $expectedAmounts[] = (int) $cardTopup->payable_amount_irt;
                        $expectedAmounts = array_values(array_unique(array_filter($expectedAmounts, fn ($amount) => $amount > 0)));

                        if (trim((string) $cardTopup->card_holder) !== '') {
                            $expectedReceiver = (string) $cardTopup->card_holder;
                        }
                    }
                }

                $checks = $this->buildChecks(
                    $ai,
                    $expectedAmounts,
                    $expectedReceiver,
                    (string) $receipt->created_at,
                    'panel_wallet_receipts',
                    (int) $receipt->id
                );

                $meta['ai_receipt'] = [
                    'checked_at' => now()->toDateTimeString(),
                    'result' => $ai,
                    'checks' => $checks,
                ];

                $receipt->meta = $meta;
                $receipt->save();

                if (!$checks['ok']) {
                    if (!empty($checks['tracking_duplicate'])) {
                        $res = app(WalletReceiptService::class)->reject(
                            (int) $receipt->id,
                            $adminId,
                            'کد پیگیری تکراری است'
                        );

                        if (!empty($res['ok'])) {
                            try {
                                app(TelegramNotifier::class)->disableReceiptButtons((int) $receipt->id);
                                app(TelegramNotifier::class)->sendTextToAdmins(
                                    "❌ رسید #{$receipt->id} توسط هوش مصنوعی رد شد.\n\nدلیل: کد پیگیری تکراری است."
                                );
                            } catch (\Throwable $e) {}
                        }
                    }

                    continue;
                }

                $res = app(WalletReceiptService::class)->approve(
                    (int) $receipt->id,
                    $adminId,
                    (int) $receipt->amount,
                    'ai'
                );

                if (!empty($res['ok'])) {
                    try {
                        app(TelegramNotifier::class)->disableReceiptButtons((int) $receipt->id);
                        app(TelegramNotifier::class)->sendTextToAdmins(
                            "✅ رسید #{$receipt->id} پس از ۳۰ دقیقه انتظار، توسط هوش مصنوعی تایید شد."
                        );
                    } catch (\Throwable $e) {}
                }
            } catch (\Throwable $e) {
                $this->storeAiTemporaryErrorOnPanelReceipt($receipt, $meta, $e);
            }
        }
    }

    private function tickUserBotOrders(): void
    {
        $method = PanelPaymentMethod::where('method', 'card')->first();

        if (
            !$method ||
            !(bool) $method->ai_auto_approve_enabled ||
            trim((string) $method->gemini_api_key) === '' ||
            (int) ($method->ai_receipt_check_fee ?? 0) <= 0
        ) {
            return;
        }

        $orders = UserBotOrder::query()
            ->where('payment_method', 'card')
            ->where('status', 'pending')
            ->whereNotNull('receipt_file_id')
            ->where(function ($query) {
                $limit = now()->subMinutes(30);

                $query
                    ->where(
                        'receipt_submitted_at',
                        '<=',
                        $limit
                    )
                    ->orWhere(function ($fallback) use ($limit) {
                        /*
                        * سفارش‌های قدیمی که هنوز این فیلد را ندارند،
                        * با created_at بررسی می‌شوند.
                        */
                        $fallback
                            ->whereNull('receipt_submitted_at')
                            ->where(
                                'created_at',
                                '<=',
                                $limit
                            );
                    });
            })
            ->latest('id')
            ->limit(10)
            ->get();

        foreach ($orders as $order) {
            $payload = $this->arrayMeta($order->payload);

            if (!$this->shouldRunAiReceipt($payload)) {
                continue;
            }
            try {
                $setting = UserBotSetting::find($order->setting_id);

                if (!$setting || !(bool) $setting->card_ai_auto_approve_enabled || empty($setting->bot_token)) {
                    continue;
                }

                $owner = $this->resolveUserBotOwner($setting);
                $fee = (int) ($method->ai_receipt_check_fee ?? 0);

                if ($owner && (int) $owner->role === 2 && $fee > 0) {
                    $credit = (int) ($owner->credit ?? 0);

                    if ($credit < $fee) {
                        $payload['ai_receipt'] = [
                            'checked_at' => null,
                            'skipped_at' => now()->toDateTimeString(),
                            'skip_reason' => 'insufficient_partner_credit',
                            'required_fee' => $fee,
                            'current_credit' => $credit,
                        ];

                        $this->storeUserBotAiSkipIfStillPending(
                            $order,
                            $payload['ai_receipt']
                        );

                        continue;
                    }
                }

                $ai = app(GeminiReceiptAnalyzer::class)->analyzeTelegramFile(
                    (string) $setting->bot_token,
                    (string) $order->receipt_file_id,
                    (string) $method->gemini_api_key
                );

                $validAmounts = [
                    (int) $order->amount,
                ];

                if ((int) $order->card_sms_payable_amount > 0) {
                    $validAmounts[] =
                        (int) $order->card_sms_payable_amount;
                }

                $checks = $this->buildChecks(
                    $ai,
                    $validAmounts,
                    (string) $setting->card_holder,

                    (string) (
                        $order->receipt_submitted_at
                            ?: $order->created_at
                    ),

                    'user_bot_orders',
                    (int) $order->id
                );

                $storedAiResult =
                    $this->storeUserBotAiResultIfStillPending(
                        $order,
                        $ai,
                        $checks,
                        $owner,
                        $fee
                    );

                if (!$storedAiResult) {
                    continue;
                }

                $order = $storedAiResult['order'];
                $payload = $storedAiResult['payload'];

                if (!$checks['ok']) {
                    if (!empty($checks['tracking_duplicate'])) {
                        $fresh = UserBotOrder::where('id', $order->id)
                            ->where('status', 'pending')
                            ->first();

                        if ($fresh) {
                            $fresh->update([
                                'status' => 'rejected',
                                'rejected_at' => now(),
                                'card_sms_match_key' => null,
                            ]);

                            $fresh->refresh();

                            $this->disableUserBotOrderButtons($setting, $fresh);
                            $this->notifyUserBotRejectedByAi($setting, $fresh);
                        }
                    }

                    continue;
                }

                $fresh = UserBotOrder::where('id', $order->id)
                    ->where('status', 'pending')
                    ->first();

                if (!$fresh) {
                    continue;
                }

                $result = app(
                    UserBotCardOrderApprovalService::class
                )->approve(
                    $fresh,
                    'ai'
                );

                if (empty($result['ok'])) {
                    continue;
                }

                $approvedOrder =
                    $result['order']
                        instanceof UserBotOrder
                            ? $result['order']
                            : $fresh->fresh();

                app(
                    UserBotOrderDeliveryService::class
                )->notifyApproved(
                    $setting,
                    $approvedOrder,
                    $result,
                    'ai'
                );
            } catch (\Throwable $e) {
                $this->storeAiTemporaryErrorOnUserBotOrder(
                    $order,
                    $e
                );
            }
        }
    }

    private function buildChecks(array $ai, int|array $expectedToman, string $expectedReceiver, string $submittedAt, string $table, int $id): array
    {
        $expectedAmounts = is_array($expectedToman)
            ? $expectedToman
            : [$expectedToman];

        $expectedAmounts = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $expectedAmounts),
                    fn (int $amount) => $amount > 0
                )
            )
        );        
        $amountToman = $this->normalizeAmount($ai['amount_toman'] ?? 0);
        $amountRial = $this->normalizeAmount($ai['amount_rial'] ?? 0);

        if ($amountToman <= 0 && $amountRial > 0) {
            $amountToman = (int) floor($amountRial / 10);
        }

        if ($amountRial <= 0 && $amountToman > 0) {
            $amountRial = $amountToman * 10;
        }

        $trackingCode = trim((string) ($ai['tracking_code'] ?? ''));
        $trackingDuplicate = $trackingCode !== '' && $this->trackingCodeUsed($trackingCode, $table, $id);

        $checks = [
            'is_receipt' => (bool) ($ai['is_receipt'] ?? false),
            'success' => (bool) ($ai['is_successful'] ?? false),
            'amount' =>
                in_array(
                    $amountToman,
                    $expectedAmounts,
                    true
                )
                ||
                in_array(
                    $amountRial,
                    array_map(
                        fn (int $amount) => $amount * 10,
                        $expectedAmounts
                    ),
                    true
                ),
            'receiver' => $this->nameMatches((string) ($ai['receiver_name'] ?? ''), $expectedReceiver),
            'tracking_code' => $trackingCode !== '' && !$trackingDuplicate,
            'tracking_duplicate' => $trackingDuplicate,
            'time' => $this->timeIsValid(
                (string) ($ai['transaction_time'] ?? ''),
                $submittedAt,
                (string) ($ai['transaction_date_jalali'] ?? '')
            ),
        ];

        $checks['ok'] = $checks['is_receipt']
            && $checks['success']
            && $checks['amount']
            && $checks['receiver']
            && $checks['tracking_code']
            && $checks['time'];

        return $checks;
    }

    private function trackingCodeUsed(string $code, string $table, int $currentId): bool
    {
        $code = trim($code);

        if ($code === '') {
            return true;
        }

        $panelExists = PanelWalletReceipt::query()
            ->where('id', '!=', $table === 'panel_wallet_receipts' ? $currentId : 0)
            ->where('status', 'verified')
            ->get()
            ->contains(function ($row) use ($code) {
                $meta = $this->arrayMeta($row->meta);
                return (string) data_get($meta, 'ai_receipt.result.tracking_code') === $code;
            });

        if ($panelExists) {
            return true;
        }

        return UserBotOrder::query()
            ->where('id', '!=', $table === 'user_bot_orders' ? $currentId : 0)
            ->where('status', 'done')
            ->get()
            ->contains(function ($row) use ($code) {
                $payload = $this->arrayMeta($row->payload);
                return (string) data_get($payload, 'ai_receipt.result.tracking_code') === $code;
            });
    }

    private function nameMatches(string $actual, string $expected): bool
    {
        $actual = $this->normalizeName($actual);
        $expected = $this->normalizeName($expected);

        if ($actual === '' || $expected === '') {
            return false;
        }

        if (str_contains($actual, $expected) || str_contains($expected, $actual)) {
            return true;
        }

        $tokens = array_values(array_filter(explode(' ', $expected), fn ($x) => mb_strlen($x) >= 3));

        if (empty($tokens)) {
            return false;
        }

        $matched = 0;

        foreach ($tokens as $token) {
            if (str_contains($actual, $token)) {
                $matched++;
            }
        }

        return $matched >= max(1, count($tokens) - 1);
    }

    private function timeIsValid(string $time, string $submittedAt, string $jalaliDate = ''): bool
    {
        $time = $this->normalizeDigits(trim($time));

        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            return false;
        }

        $submitted = \Carbon\Carbon::parse($submittedAt, 'Asia/Tehran');

        $jalaliDate = trim($jalaliDate);

        if ($jalaliDate !== '') {
            $receiptDate = $this->parseJalaliDate($jalaliDate);

            if ($receiptDate) {
                [$gy, $gm, $gd] = $receiptDate;

                $receiptTime = \Carbon\Carbon::create(
                    $gy,
                    $gm,
                    $gd,
                    (int) $m[1],
                    (int) $m[2],
                    0,
                    'Asia/Tehran'
                );

                return $receiptTime->greaterThanOrEqualTo($submitted->copy()->subHours(24))
                    && $receiptTime->lessThanOrEqualTo($submitted->copy()->addMinutes(5));
            }
        }

        $receiptTime = $submitted->copy()->setTime((int) $m[1], (int) $m[2], 0);

        return $receiptTime->lessThanOrEqualTo($submitted->copy()->addMinutes(5));
    }

    private function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '：' => ':',
        ]);
    }

    private function normalizeAmount(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        $value = $this->normalizeDigits((string) $value);
        $value = preg_replace('/[^\d]/', '', $value);

        return $value === '' ? 0 : (int) $value;
    }

    private function parseJalaliDate(string $date): ?array
    {
        $date = $this->normalizeDigits($date);

        if (preg_match('/((?:13|14)\d{2})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})/u', $date, $m)) {
            return $this->jalaliToGregorian((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        $months = [
            'فروردین' => 1,
            'اردیبهشت' => 2,
            'خرداد' => 3,
            'تیر' => 4,
            'مرداد' => 5,
            'شهریور' => 6,
            'مهر' => 7,
            'آبان' => 8,
            'آذر' => 9,
            'دی' => 10,
            'بهمن' => 11,
            'اسفند' => 12,
        ];

        foreach ($months as $name => $month) {
            if (str_contains($date, $name) && preg_match('/(\d{1,2})\s*' . preg_quote($name, '/') . '\s*((13|14)\d{2})/u', $date, $m)) {
                return $this->jalaliToGregorian((int) $m[2], $month, (int) $m[1]);
            }
        }

        return null;
    }

    private function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;

        $days = -355668 + (365 * $jy) + intdiv($jy, 33) * 8 + intdiv((($jy % 33) + 3), 4) + $jd;

        if ($jm < 7) {
            $days += ($jm - 1) * 31;
        } else {
            $days += (($jm - 7) * 30) + 186;
        }

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;

            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;

        $salA = [0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        for ($gm = 1; $gm <= 12 && $gd > $salA[$gm]; $gm++) {
            $gd -= $salA[$gm];
        }

        return [$gy, $gm, $gd];
    }

    private function normalizeName(string $name): string
    {
        $name = strtr($name, [
            'ي' => 'ی',
            'ك' => 'ک',
            'ۀ' => 'ه',
            'ة' => 'ه',
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
        ]);

        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);

        return trim(mb_strtolower((string) $name));
    }

    private function arrayMeta(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return json_decode($value, true) ?: [];
        }

        return [];
    }

    private function resolveUserBotOwner(UserBotSetting $setting): ?object
    {
        if ((string) $setting->owner_type === 'admin') {
            return DB::table('panel_users')
                ->where('role', 1)
                ->orderBy('id')
                ->first();
        }

        if ((int) $setting->owner_id > 0) {
            return DB::table('panel_users')
                ->where('id', (int) $setting->owner_id)
                ->first();
        }

        return null;
    }

    private function storeUserBotAiSkipIfStillPending(
        UserBotOrder $order,
        array $aiReceipt
    ): void {
        DB::transaction(function () use (
            $order,
            $aiReceipt
        ) {
            $locked = UserBotOrder::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (
                !$locked
                || $locked->status !== 'pending'
            ) {
                return;
            }

            $freshPayload = $this->arrayMeta(
                $locked->payload
            );

            if (!$this->shouldRunAiReceipt($freshPayload)) {
                return;
            }

            $freshPayload['ai_receipt'] = $aiReceipt;

            $locked->update([
                'payload' => $freshPayload,
            ]);
        });
    }

    private function storeUserBotAiResultIfStillPending(
        UserBotOrder $order,
        array $ai,
        array $checks,
        ?object $owner,
        int $fee
    ): ?array {
        return DB::transaction(function () use (
            $order,
            $ai,
            $checks,
            $owner,
            $fee
        ) {
            $locked = UserBotOrder::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (
                !$locked
                || $locked->status !== 'pending'
            ) {
                return null;
            }

            $freshPayload = $this->arrayMeta(
                $locked->payload
            );

            if (!$this->shouldRunAiReceipt($freshPayload)) {
                return null;
            }

            $isPartner =
                $owner
                && (int) $owner->role === 2;

            $feeTxId = null;

            if ($isPartner && $fee > 0) {
                $feeTxId =
                    $this->chargePartnerAiReceiptFee(
                        (int) $owner->id,
                        $fee,
                        $locked,
                        $checks
                    );

                if (!$feeTxId) {
                    $freshPayload['ai_receipt'] = [
                        'checked_at' => null,
                        'skipped_at' => now()
                            ->toDateTimeString(),
                        'skip_reason' =>
                            'insufficient_partner_credit',
                        'required_fee' => $fee,
                    ];

                    $locked->update([
                        'payload' => $freshPayload,
                    ]);

                    return null;
                }
            }

            $freshPayload['ai_receipt'] = [
                'checked_at' => now()->toDateTimeString(),
                'result' => $ai,
                'checks' => $checks,
                'fee_amount' => $isPartner ? $fee : 0,
                'fee_transaction_id' => $feeTxId,
            ];

            $locked->update([
                'payload' => $freshPayload,
            ]);

            return [
                'order' => $locked->fresh(),
                'payload' => $freshPayload,
            ];
        });
    }

    private function chargePartnerAiReceiptFee(int $partnerId, int $fee, UserBotOrder $order, array $checks): ?int
    {
        if ($fee <= 0) {
            return null;
        }

        return DB::transaction(function () use ($partnerId, $fee, $order, $checks) {
            $idempotencyKey = "ai_receipt_check_fee:user_bot_order:{$order->id}";

            $exists = DB::table('panel_transactions')
                ->where('idempotency_key', $idempotencyKey)
                ->value('id');

            if ($exists) {
                return (int) $exists;
            }

            $partner = DB::table('panel_users')
                ->where('id', $partnerId)
                ->lockForUpdate()
                ->first();

            if (!$partner) {
                return null;
            }

            $before = (int) ($partner->credit ?? 0);

            if ($before < $fee) {
                return null;
            }

            $after = $before - $fee;

            DB::table('panel_users')
                ->where('id', $partnerId)
                ->update([
                    'credit' => $after,
                    'updated_at' => now(),
                ]);

            return (int) DB::table('panel_transactions')->insertGetId([
                'panel_user_id' => $partnerId,
                'type' => 'ai_receipt_check_fee',
                'direction' => 'debit',
                'amount' => $fee,
                'balance_before' => $before,
                'balance_after' => $after,
                'status' => 'success',
                'reference_type' => 'user_bot_orders',
                'reference_id' => (int) $order->id,
                'quantity' => 1,
                'currency' => 'IRT',
                'performed_by_id' => null,
                'performed_by_role' => 'system',
                'idempotency_key' => $idempotencyKey,
                'meta' => json_encode([
                    'title' => 'بررسی رسید توسط هوش مصنوعی',
                    'order_id' => (int) $order->id,
                    'order_code' => $order->order_code,
                    'result' => !empty($checks['ok']) ? 'approved' : 'not_approved',
                    'source' => 'user_bot_card_receipt_ai',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function disableUserBotOrderButtons(UserBotSetting $setting, UserBotOrder $order): void
    {
        if (empty($setting->bot_token)) {
            return;
        }

        $payload = $this->arrayMeta($order->payload);

        $chatId = $payload['manager_receipt_chat_id'] ?? $setting->owner_telegram_chat_id;
        $messageId = $payload['manager_receipt_message_id'] ?? null;

        if (!$chatId || !$messageId) {
            return;
        }

        try {
            $bot = new UserBotTelegram((string) $setting->bot_token);
            $bot->editMessageReplyMarkup($chatId, $messageId);
        } catch (\Throwable $e) {
            //
        }
    }

    private function notifyUserBotApprovedByAi(UserBotSetting $setting, UserBotOrder $order, array $result): void
    {
        if (empty($setting->bot_token)) {
            return;
        }

        $bot = new UserBotTelegram((string) $setting->bot_token);
        $orderNo = $order->order_code ?: $order->id;

        if ($setting->owner_telegram_chat_id) {
            $bot->sendMessage(
                $setting->owner_telegram_chat_id,
                "✅ سفارش شماره {$orderNo} پس از ۳۰ دقیقه انتظار، توسط هوش مصنوعی تایید شد.",
                UserBotKeyboard::manager()
            );
        }

        $this->deleteBuyerPaymentMessage($bot, $order);

        $buyerSession = UserBotSession::find($order->session_id);

        if (!$buyerSession) {
            return;
        }

        $isVolumePartner = $this->isVolumePartner($setting);
        $isBothPartner = $this->isBothPartner($setting);

        $buyerText = match ($order->order_type) {
            'buy_traffic' =>
                "✅ {$result['traffic_gb']} گیگ ترافیک با موفقیت به اکانت زیر اضافه شد:\n\n"
                . "👤 نام کاربری: <code>" . $this->formatUsername($result['email'] ?? null) . "</code>"
                . ($this->isVolumeOrder($order) ? "\n📅 تاریخ انقضای جدید: <b>" . $this->formatTimestamp($result['new_expired_at'] ?? null) . "</b>" : "")
                . ($this->isVolumeOrder($order) ? "\n📊 ترافیک باقی‌مانده: <b>" . $this->formatTrafficBytes($result['traffic_remaining_bytes'] ?? null) . "</b>" : ""),

            'renew_account' =>
                "✅ اکانت زیر با موفقیت تمدید شد:\n\n"
                . "👤 <code>" . $this->formatUsername($result['email'] ?? null) . "</code>\n\n"
                . "🧩 طرح: <b>" . e($this->planFullTitle($order->payload['selected_plan'] ?? ['title' => $result['plan_title'] ?? '-'])) . "</b>\n"
                . "📅 تاریخ انقضای جدید: <b>" . $this->formatTimestamp($result['new_expired_at'] ?? null) . "</b>",

            default =>
                "✅ اکانت شما با موفقیت ساخته شد\n\n"
                . "👤 نام کاربری:\n<code>" . $this->formatUsername($result['email'] ?? null) . "</code>\n\n"
                . "🔗 لینک سابسکرایب:\n<code>{$result['subscribe_url']}</code>",
        };

        $buyerKeyboard = $buyerSession->is_logged_in
            ? UserBotKeyboard::user($buyerSession->is_logged_in ? $this->isLoggedInVolumeAccount($buyerSession) : $isVolumePartner)
            : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner);

        if ($order->order_type === 'buy_account' && !empty($result['subscribe_url'])) {
            $bot->sendQrPhotoOrMessage(
                $buyerSession->telegram_chat_id,
                (string) $result['subscribe_url'],
                $buyerText,
                $buyerKeyboard
            );
        } else {
            $bot->sendMessage(
                $buyerSession->telegram_chat_id,
                $buyerText,
                $buyerKeyboard
            );
        }
    }

    private function notifyUserBotRejectedByAi(UserBotSetting $setting, UserBotOrder $order): void
    {
        if (empty($setting->bot_token)) {
            return;
        }

        $bot = new UserBotTelegram((string) $setting->bot_token);
        $orderNo = $order->order_code ?: $order->id;

        if ($setting->owner_telegram_chat_id) {
            $bot->sendMessage(
                $setting->owner_telegram_chat_id,
                "❌ سفارش شماره {$orderNo} توسط هوش مصنوعی رد شد.\n\nدلیل: کد پیگیری تکراری است.",
                UserBotKeyboard::manager()
            );
        }

        $this->deleteBuyerPaymentMessage($bot, $order);

        $buyerSession = UserBotSession::find($order->session_id);

        if (!$buyerSession) {
            return;
        }

        $isVolumePartner = $this->isVolumePartner($setting);
        $isBothPartner = $this->isBothPartner($setting);

        $bot->sendMessage(
            $buyerSession->telegram_chat_id,
            "❌ رسید شما رد شد\n\n🧾 شماره سفارش: <b>{$orderNo}</b>",
            $buyerSession->is_logged_in
                ? UserBotKeyboard::user($this->isLoggedInVolumeAccount($buyerSession))
                : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner)
        );
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
            //
        }
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
        $title = trim((string) ($plan['title'] ?? ''));
        $group = trim((string) ($plan['group_name'] ?? ''));
        $planKey = trim((string) ($plan['plan_key'] ?? ''));

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
        if ((string) ($snapshot['account_group_type'] ?? '') === 'volume') {
            return true;
        }

        $planId = (int) ($snapshot['plan_id'] ?? 0);

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

    private function shouldRunAiReceipt(array $container): bool
    {
        $ai = $container['ai_receipt'] ?? [];

        if (!is_array($ai) || empty($ai)) {
            return true;
        }

        if (!empty($ai['failed_final'])) {
            return false;
        }

        $hasFinalResult = !empty($ai['checked_at'])
            && (
                array_key_exists('result', $ai)
                || array_key_exists('checks', $ai)
            );

        if ($hasFinalResult) {
            return false;
        }

        $hasError = !empty($ai['error']) || !empty($ai['last_error']);

        if (!empty($ai['checked_at']) && !$hasError) {
            return false;
        }

        $attempts = (int) ($ai['attempts'] ?? 0);

        if ($hasError && $attempts >= self::AI_MAX_RETRY_ATTEMPTS) {
            return false;
        }

        $nextRetryAt = trim((string) ($ai['next_retry_at'] ?? ''));

        if ($nextRetryAt !== '') {
            try {
                if (\Carbon\Carbon::parse($nextRetryAt)->isFuture()) {
                    return false;
                }
            } catch (\Throwable $e) {
                //
            }
        }

        return true;
    }

    private function storeAiTemporaryErrorOnPanelReceipt(PanelWalletReceipt $receipt, array $meta, \Throwable $e): void
    {
        $meta['ai_receipt'] = $this->buildAiTemporaryErrorPayload($meta['ai_receipt'] ?? [], $e);

        $receipt->meta = $meta;
        $receipt->save();
    }

    private function storeAiTemporaryErrorOnUserBotOrder(
        UserBotOrder $order,
        \Throwable $e
    ): void {
        DB::transaction(function () use ($order, $e) {
            $locked = UserBotOrder::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (
                !$locked
                || $locked->status !== 'pending'
            ) {
                return;
            }

            $freshPayload = $this->arrayMeta(
                $locked->payload
            );

            $freshPayload['ai_receipt'] =
                $this->buildAiTemporaryErrorPayload(
                    $freshPayload['ai_receipt'] ?? [],
                    $e
                );

            $locked->update([
                'payload' => $freshPayload,
            ]);
        });
    }

    private function buildAiTemporaryErrorPayload(mixed $current, \Throwable $e): array
    {
        $current = is_array($current) ? $current : [];

        $attempts = (int) ($current['attempts'] ?? 0);
        $attempts++;

        $isFinal = $attempts >= self::AI_MAX_RETRY_ATTEMPTS;
        $delayMinutes = self::AI_RETRY_DELAYS_MINUTES[$attempts] ?? null;

        return [
            'checked_at' => $isFinal ? now()->toDateTimeString() : null,
            'attempts' => $attempts,
            'last_error_at' => now()->toDateTimeString(),
            'last_error' => $e->getMessage(),
            'error' => $e->getMessage(),
            'next_retry_at' => (!$isFinal && $delayMinutes) ? now()->addMinutes($delayMinutes)->toDateTimeString() : null,
            'failed_final' => $isFinal,
        ];
    }

}