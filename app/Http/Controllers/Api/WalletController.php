<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\PanelCardNumber;
use App\Models\PanelWalletReceipt;
use App\Models\PanelWalletCardTopup;
use App\Services\TelegramNotifier;
use App\Services\TelegramConfig;
use App\Models\PanelPaymentMethod;
use App\Models\PanelCryptoWalletSetting;
use App\Models\PanelCryptoTopup;
use App\Services\CryptoRateService;
use App\Models\CryptoPaymentOption;
use App\Services\PanelWallet\PanelWalletCardTopupIntentService;
use App\Support\JalaliDateFormatter;

class WalletController extends Controller
{
    public function paymentMethods(Request $request)
    {
        $user = $request->user();
        $userId = (int) optional($user)->id;

        $card = PanelPaymentMethod::firstOrCreate(
            ['method' => 'card'],
            ['enabled' => true, 'access_mode' => 'all', 'partner_ids' => null]
        );

        $crypto = PanelPaymentMethod::firstOrCreate(
            ['method' => 'crypto'],
            ['enabled' => false, 'access_mode' => 'all', 'partner_ids' => null]
        );

        $cryptoWallet = PanelCryptoWalletSetting::query()->first();

        $cryptoOptions = CryptoPaymentOption::query()
            ->where('context', 'panel_wallet')
            ->where('owner_type', 'admin')
            ->whereNull('owner_id')
            ->where('enabled', true)
            ->whereNotNull('wallet_address')
            ->where('wallet_address', '!=', '')
            ->orderBy('sort_order')
            ->get();

        $canUse = function ($method) use ($userId) {
            if (!$method || !$method->enabled) {
                return false;
            }

            if (($method->access_mode ?? 'all') === 'all') {
                return true;
            }

            $ids = $method->partner_ids ?: [];

            return in_array($userId, array_map('intval', $ids), true);
        };

        $cardRow = PanelCardNumber::orderByDesc('id')->first();
        $cardDigits = $cardRow ? preg_replace('/\D/', '', (string)($cardRow->card ?? '')) : '';

        $cardEnabled = $canUse($card) && strlen(substr($cardDigits, 0, 16)) === 16;
        $cryptoEnabled = $canUse($crypto) && $cryptoOptions->isNotEmpty();

        return response()->json([
            'ok' => true,
            'methods' => [
                'card' => [
                    'enabled' => $cardEnabled,
                    'sms_auto_approve_enabled' => $cardEnabled && (bool) ($card->sms_auto_approve_enabled ?? false),
                ],
                'crypto' => [
                    'enabled' => $cryptoEnabled,
                ],
            ],
            'crypto_wallet' => [
                'asset' => $cryptoWallet?->asset ?: 'USDT',
                'network' => $cryptoWallet?->network ?: 'TRC20',
                'wallet_address' => $cryptoWallet?->wallet_address ?: '',
            ],

            'crypto_options' => $cryptoOptions->map(fn ($item) => [
                'asset' => $item->asset,
                'network' => $item->network,
                'title' => $item->title ?: "{$item->asset} - {$item->network}",
            ])->values(),

        ]);
    }

    public function cardInfo(Request $request)
    {
        // آخرین کارت ثبت‌شده
        $row = PanelCardNumber::orderByDesc('id')->first();

        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'no_card'], 404);
        }

        $digits = preg_replace('/\D/', '', (string)($row->card ?? ''));
        $digits = substr($digits, 0, 16);
        if ($digits === '') {
            return response()->json(['ok' => false, 'message' => 'empty_card'], 404);
        }

        $formatted = implode('-', str_split($digits, 4));

        return response()->json([
            'ok'             => true,
            'card'           => $digits,
            'card_formatted' => $formatted,
            'name'           => (string)($row->name ?? ''),
        ]);
    }

    public function uploadReceipt(Request $request)
    {
        $validated = $request->validate([
            'receipt' => 'required|file|mimes:jpeg,jpg,png,webp,gif,pdf|max:10240',
            'card_topup_id' => 'nullable|integer|exists:panel_wallet_card_topups,id',
        ]);

        $cardTopupId = (int) ($validated['card_topup_id'] ?? 0);

        if ($cardTopupId > 0) {
            $cardTopup = PanelWalletCardTopup::query()
                ->whereKey($cardTopupId)
                ->where('user_id', optional($request->user())->id)
                ->first();

            if (!$cardTopup || $cardTopup->status !== 'pending') {
                return response()->json([
                    'ok' => false,
                    'message' => 'invalid_card_topup',
                ], 422);
            }

            if (
                $cardTopup->expires_at
                && $cardTopup->expires_at->isPast()
            ) {
                return response()->json([
                    'ok' => false,
                    'message' => 'مهلت ۳۰ دقیقه‌ای این پرداخت تمام شده است',
                ], 422);
            }
        }

        $file = $request->file('receipt');

        // 1) اول ردیف رسید را بسازیم تا id بگیریم
        $wr = PanelWalletReceipt::create([
            'user_id'       => optional($request->user())->id,
            'card_topup_id' => $cardTopupId > 0 ? $cardTopupId : null,
            'method'        => 'card',
            'disk'          => 'public',
            'path'          => '', // بعد از ذخیرهٔ فایل ست می‌کنیم
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'status'        => 'uploaded',
            'meta'          => ['ip' => $request->ip()],
        ]);

        // 2) نام فایل = شماره رسید + پسوند
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        if ($ext === 'jpeg') $ext = 'jpg';
        $filename = $wr->id . '.' . $ext;              // مثلا: 12345.jpg

        // 3) ذخیره با نام دلخواه در دیسک public
        $path = $file->storeAs('receipts', $filename, 'public');  // receipts/12345.jpg

        if (!$path) {
            // اگر ذخیره فایل شکست خورد، ردیف DB را پاک می‌کنیم تا orphan نماند
            $wr->delete();
            return response()->json(['ok' => false, 'message' => 'store_failed'], 500);
        }

        // 4) به‌روزرسانی مسیر فایل و خروجی
        $wr->path = $path;
        $wr->save();

        return response()->json([
            'ok'  => true,
            'id'  => $wr->id,
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }

    public function submitCardDeposit(Request $request)
    {
        try {
            $validated = $request->validate([
                'receipt_id' => 'required|integer|exists:panel_wallet_receipts,id',
                'amount'     => 'required|integer|min:1000',
            ]);

            // همه چیز اتمیک انجام شود
            $receiptTopupId = (int) (PanelWalletReceipt::query()->whereKey($validated['receipt_id'])->value('card_topup_id') ?? 0);

            $result = DB::transaction(function () use ($request, $validated, $receiptTopupId) {
                $cardTopup = null;

                if ($receiptTopupId > 0) {
                    $cardTopup = PanelWalletCardTopup::query()
                        ->whereKey($receiptTopupId)
                        ->where('user_id', optional($request->user())->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$cardTopup || $cardTopup->status !== 'pending') {
                        return [
                            'ok' => false,
                            'code' => 'INVALID_CARD_TOPUP',
                        ];
                    }

                    if (
                        $cardTopup->expires_at
                        && $cardTopup->expires_at->isPast()
                    ) {
                        return [
                            'ok' => false,
                            'code' => 'EXPIRED_CARD_TOPUP',
                        ];
                    }
                }

                /** @var \App\Models\PanelWalletReceipt $wr */
                $wr = PanelWalletReceipt::where('id', $validated['receipt_id'])
                    ->where('user_id', optional($request->user())->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (!in_array($wr->status, ['uploaded', 'submitted'], true)) {
                    return ['ok' => false, 'code' => 'INVALID_STATUS'];
                }

                if ((int) ($wr->card_topup_id ?? 0) !== $receiptTopupId) {
                    return ['ok' => false, 'code' => 'INVALID_CARD_TOPUP'];
                }

                $wr->user_id = optional($request->user())->id;
                $wr->amount = $cardTopup ? (int) $cardTopup->amount_irt : (int) $validated['amount'];
                $wr->status = 'submitted';
                $wr->save();

                // ساخت لاگ تراکنش "pending" برای صورتحساب
                $panelUserId = (int) ($wr->user_id ?? 0);
                $before = (int) DB::table('panel_users')
                    ->where('id', $panelUserId)
                    ->value('credit');

                $meta = ['method' => 'card'];

                if ($cardTopup) {
                    $meta['card_topup_id'] = (int) $cardTopup->id;
                    $meta['payable_amount_irt'] = (int) $cardTopup->payable_amount_irt;
                }

                app(\App\Services\TransactionLogger::class)->start([
                    'panel_user_id'   => $panelUserId,
                    'type'            => 'wallet_topup_card',
                    'direction'       => 'credit',
                    'amount'          => (int) $wr->amount,
                    'balance_before'  => (string) $before,
                    'balance_after'   => (string) $before,
                    'reference_type'  => 'panel_wallet_receipt',
                    'reference_id'    => (int) $wr->id,
                    'idempotency_key' => "pending_receipt:{$wr->id}",
                    'meta'            => $meta,
                ]);

                return ['ok' => true, 'wr' => $wr];
            });

            if (!$result['ok']) {
                $message = match ($result['code'] ?? '') {
                    'INVALID_CARD_TOPUP' =>
                        'invalid_card_topup',

                    'EXPIRED_CARD_TOPUP' =>
                        'مهلت ۳۰ دقیقه‌ای این پرداخت تمام شده است',

                    default =>
                        'invalid_status',
                };

                return response()->json([
                    'ok' => false,
                    'message' => $message,
                ], 422);
            }

            // نوتیف تلگرام بعد از کمیت (بیرون از تراکنش)
            try {
                if (TelegramConfig::enabled() && TelegramConfig::notifyOnCardSubmit()) {
                    /** @var \App\Models\PanelWalletReceipt $wr */
                    $wr       = $result['wr'];
                    $user     = $request->user();
                    $email    = (string)($user->email ?? '');
                    $codeRaw  = (string)($user->code  ?? '');
                    $userCode = $codeRaw !== ''
                        ? preg_replace(['/@/', '/\.com$/i'], ['', ''], $codeRaw)
                        : ($email ?? '');

                    $photoUrl  = null;
                    $localPath = null;
                    if ($wr->disk && $wr->path) {
                        $photoUrl  = Storage::disk($wr->disk)->url($wr->path);   // اگر public URL داشته باشد
                        $localPath = Storage::disk($wr->disk)->path($wr->path); // برای ارسال باینری لوکال
                    }

                    app(TelegramNotifier::class)->sendReceiptToAdmins([
                        'id'         => $wr->id,
                        'user_email' => $email,
                        'user_code'  => $userCode,
                        'amount'     => (int) $wr->amount,
                        'created_at' => JalaliDateFormatter::forTelegram($wr->created_at, 'Y-m-d H:i', '-'),
                        'photo_url'  => $photoUrl,
                        'local_path' => $localPath,
                        'mime'       => (string)($wr->mime ?? ''),
                    ]);
                }
            } catch (\Throwable $te) {

            }

            return response()->json(['ok' => true, 'message' => 'submitted']);
        } catch (\Throwable $e) {

            if (app()->isLocal()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
            }
            return response()->json(['ok' => false, 'message' => 'server_error'], 500);
        }
    }

    public function createCardTopup(Request $request)
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'in:1000000,3000000,5000000,10000000,20000000'],
        ]);

        try {
            $topup = app(PanelWalletCardTopupIntentService::class)->create($request->user(), (int) $validated['amount'], 'panel');

            return response()->json([
                'ok' => true,
                'order' => $this->cardTopupPayload($topup),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function activeCardTopup(Request $request)
    {
        $topup = app(PanelWalletCardTopupIntentService::class)->activeForUser((int) $request->user()->id);

        return response()->json([
            'ok' => true,
            'order' => $topup ? $this->cardTopupPayload($topup) : null,
        ]);
    }

    public function cardTopupStatus(Request $request, PanelWalletCardTopup $topup)
    {
        if ((int) $topup->user_id !== (int) $request->user()->id) {
            return response()->json(['ok' => false, 'message' => 'دسترسی غیرمجاز'], 403);
        }

        return response()->json([
            'ok' => true,
            'order' => $this->cardTopupPayload($topup->fresh()),
        ]);
    }

    public function cancelCardTopup(Request $request, PanelWalletCardTopup $topup)
    {
        try {
            $cancelled = app(PanelWalletCardTopupIntentService::class)->cancel($topup, (int) $request->user()->id);

            try {
                app(TelegramNotifier::class)->finalizePanelWalletCardTopupCancellation((int) $cancelled->id);
            } catch (\Throwable $e) {
                Log::warning('Panel wallet card topup Telegram cancellation sync failed', [
                    'topup_id' => (int) $cancelled->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'ok' => true,
                'message' => 'پرداخت لغو شد.',
                'order' => $this->cardTopupPayload($cancelled),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function cardTopupPayload(PanelWalletCardTopup $topup): array
    {
        $receipt = PanelWalletReceipt::query()->where('card_topup_id', $topup->id)->latest('id')->first();
        $receiptStatus = $receipt?->status;
        $displayStatus = (string) $topup->status;

        if ($displayStatus === 'pending' && !in_array($receiptStatus, ['submitted', 'pending'], true) && $topup->expires_at && $topup->expires_at->isPast()) {
            $displayStatus = 'expired';
        }

        $digits = substr(preg_replace('/\D/', '', (string) ($topup->card_number ?? '')), 0, 16);
        $formattedCard = strlen($digits) === 16 ? implode('-', str_split($digits, 4)) : $digits;
        $remainingSeconds = $topup->expires_at ? max(0, (int) now()->diffInSeconds($topup->expires_at, false)) : 0;

        return [
            'id' => (int) $topup->id,
            'amount_irt' => (int) $topup->amount_irt,
            'payable_amount_irt' => (int) $topup->payable_amount_irt,
            'payable_amount_rial' => (int) $topup->payable_amount_irt * 10,
            'unique_extra' => (int) $topup->unique_extra,
            'card_number' => $digits,
            'card_formatted' => $formattedCard,
            'card_holder' => (string) ($topup->card_holder ?? ''),
            'status' => $displayStatus,
            'stored_status' => (string) $topup->status,
            'approval_source' => $topup->approval_source,
            'created_via' => (string) $topup->created_via,
            'expires_at' => optional($topup->expires_at)->toIso8601String(),
            'paid_at' => optional($topup->paid_at)->toIso8601String(),
            'remaining_seconds' => $remainingSeconds,
            'receipt_id' => $receipt?->id,
            'receipt_status' => $receiptStatus,
            'can_change' => $displayStatus === 'pending' && !in_array($receiptStatus, ['submitted', 'pending'], true),
        ];
    }

    public function pendingCardTopupToasts(Request $request)
    {
        $u = $request->user();

        if (!$u) {
            return response()->json([]);
        }

        $rows = PanelWalletCardTopup::query()
            ->where('user_id', $u->id)
            ->where('status', 'paid')
            ->whereNull('notified_at')
            ->latest('id')
            ->limit(3)
            ->get();

        return response()->json(
            $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'amount' => (int) $r->amount_irt,
                'approval_source' => (string) ($r->approval_source ?? ''),
                'at' => optional($r->updated_at)->toIso8601String(),
            ])->values()
        );
    }

    public function ackCardTopupToast(Request $request, int $id)
    {
        $u = $request->user();

        if (!$u) {
            return response()->json(['ok' => false], 401);
        }

        $topup = PanelWalletCardTopup::query()
            ->where('id', $id)
            ->where('user_id', $u->id)
            ->first();

        if (!$topup) {
            return response()->json(['ok' => false], 404);
        }

        if (!$topup->notified_at) {
            $topup->notified_at = now();
            $topup->save();
        }

        return response()->json(['ok' => true]);
    }

    public function createCryptoTopup(Request $request)
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1000'],
            'asset' => ['required', 'string', 'in:USDT,TRX,BNB'],
            'network' => ['required', 'string', 'in:BEP20,TRC20'],
        ]);

        $pair = strtoupper($validated['asset']) . '_' . strtoupper($validated['network']);

        if (!in_array($pair, ['USDT_BEP20', 'TRX_TRC20', 'BNB_BEP20'], true)) {
            return response()->json([
                'ok' => false,
                'message' => 'ترکیب ارز و شبکه معتبر نیست',
            ], 422);
        }

        $user = $request->user();

        $method = PanelPaymentMethod::where('method', 'crypto')->first();

        if (!$method || !$method->enabled) {
            return response()->json([
                'ok' => false,
                'message' => 'روش پرداخت رمزارز فعال نیست',
            ], 422);
        }

        if (($method->access_mode ?? 'all') === 'selected') {
            $ids = array_map('intval', $method->partner_ids ?: []);

            if (!in_array((int) $user->id, $ids, true)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'روش پرداخت رمزارز برای شما فعال نیست',
                ], 403);
            }
        }

        $wallet = CryptoPaymentOption::query()
            ->where('context', 'panel_wallet')
            ->where('owner_type', 'admin')
            ->whereNull('owner_id')
            ->where('asset', $validated['asset'])
            ->where('network', $validated['network'])
            ->where('enabled', true)
            ->first();

        if (!$wallet || trim((string) $wallet->wallet_address) === '') {
            return response()->json([
                'ok' => false,
                'message' => 'این ارز دیجیتال فعال نیست یا آدرس ولت آن تنظیم نشده است',
            ], 422);
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
            return response()->json([
                'ok' => true,
                'message' => 'شما یک سفارش رمزارزی فعال دارید',
                'order' => $this->cryptoTopupPayload($activeTopup),
                'existing' => true,
            ]);
        }

        $amountIrt = (int) $validated['amount'];

        try {
            $rateToman = app(CryptoRateService::class)->tomanRate($wallet->asset);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'در حال حاضر دریافت نرخ ارز ممکن نیست، چند دقیقه بعد مجدد تلاش کنید.',
            ], 422);
        }

        if ($rateToman <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'نرخ ارز معتبر نیست',
            ], 422);
        }

        $baseCryptoAmount = round($amountIrt / $rateToman, 6);

        $uniqueExtra = $this->makeCryptoUniqueExtra();
        $cryptoAmount = round($baseCryptoAmount + $uniqueExtra, 6);

        $order = PanelCryptoTopup::create([
            'user_id' => (int) $user->id,
            'amount_irt' => $amountIrt,
            'asset' => $wallet->asset ?: 'USDT',
            'network' => $wallet->network ?: 'TRC20',
            'wallet_address' => $wallet->wallet_address,
            'crypto_amount' => $cryptoAmount,
            'base_crypto_amount' => $baseCryptoAmount,
            'unique_extra' => $uniqueExtra,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(30),
            'meta' => [
                'ip' => $request->ip(),
                'source' => 'panel',
                'rate_toman' => $rateToman,
                'rate_source' => 'nobitex',
            ],
        ]);

        return response()->json([
            'ok' => true,
            'order' => $this->cryptoTopupPayload($order),
        ], 201);
    }

    public function activeCryptoTopup(Request $request)
    {
        $user = $request->user();

        PanelCryptoTopup::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'checking'])
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $topup = PanelCryptoTopup::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'checking'])
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        return response()->json([
            'ok' => true,
            'order' => $topup ? $this->cryptoTopupPayload($topup) : null,
        ]);
    }

    public function cancelCryptoTopup(Request $request, PanelCryptoTopup $topup)
    {
        $user = $request->user();

        if ((int) $topup->user_id !== (int) $user->id) {
            return response()->json([
                'ok' => false,
                'message' => 'دسترسی غیرمجاز',
            ], 403);
        }

        if ($topup->status !== 'pending') {
            return response()->json([
                'ok' => false,
                'message' => 'بعد از ثبت پرداخت، امکان تغییر مبلغ وجود ندارد',
            ], 422);
        }

        $topup->update([
            'status' => 'cancelled',
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'سفارش قبلی لغو شد',
        ]);
    }

    public function markCryptoTopupPaid(Request $request, PanelCryptoTopup $topup)
    {
        $user = $request->user();

        if ((int) $topup->user_id !== (int) $user->id) {
            return response()->json([
                'ok' => false,
                'message' => 'دسترسی غیرمجاز',
            ], 403);
        }

        if ($topup->status === 'paid') {
            return response()->json([
                'ok' => true,
                'message' => 'این پرداخت قبلاً تأیید شده است',
                'order' => $this->cryptoTopupPayload($topup),
            ]);
        }

        if ($topup->expires_at && $topup->expires_at->isPast()) {
            $topup->update(['status' => 'expired']);

            return response()->json([
                'ok' => false,
                'message' => 'زمان پرداخت این سفارش تمام شده است',
                'order' => $this->cryptoTopupPayload($topup->fresh()),
            ], 422);
        }

        if (!in_array($topup->status, ['pending', 'checking'], true)) {
            return response()->json([
                'ok' => false,
                'message' => 'وضعیت این سفارش قابل بررسی نیست',
                'order' => $this->cryptoTopupPayload($topup),
            ], 422);
        }

        $topup->update([
            'status' => 'checking',
            'meta' => array_merge($topup->meta ?: [], [
                'paid_clicked_at' => now()->toIso8601String(),
                'paid_clicked_ip' => $request->ip(),
            ]),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'پرداخت شما ثبت شد و در حال بررسی است',
            'order' => $this->cryptoTopupPayload($topup->fresh()),
        ]);
    }

    public function cryptoTopupStatus(Request $request, PanelCryptoTopup $topup)
    {
        $user = $request->user();

        if ((int) $topup->user_id !== (int) $user->id) {
            return response()->json([
                'ok' => false,
                'message' => 'دسترسی غیرمجاز',
            ], 403);
        }

        if ($topup->status !== 'paid' && $topup->expires_at && $topup->expires_at->isPast()) {
            $topup->update(['status' => 'expired']);
            $topup = $topup->fresh();
        }

        return response()->json([
            'ok' => true,
            'order' => $this->cryptoTopupPayload($topup),
        ]);
    }

    private function cryptoTopupPayload(PanelCryptoTopup $topup): array
    {
        return [
            'id' => $topup->id,
            'amount_irt' => $topup->amount_irt,
            'asset' => $topup->asset,
            'network' => $topup->network,
            'wallet_address' => $topup->wallet_address,
            'crypto_amount' => number_format((float) $topup->crypto_amount, 6, '.', ''),
            'expires_at' => optional($topup->expires_at)->toIso8601String(),
            'status' => $topup->status,
            'tx_hash' => $topup->tx_hash,
        ];
    }

    private function makeCryptoUniqueExtra(): float
    {
        for ($i = 0; $i < 10; $i++) {
            $extra = random_int(11, 999) / 1000000;

            $exists = PanelCryptoTopup::whereIn('status', ['pending', 'checking'])
                ->where('unique_extra', $extra)
                ->where('expires_at', '>', now())
                ->exists();

            if (!$exists) {
                return $extra;
            }
        }

        return random_int(1000, 9999) / 100000000;
    }

    public function pendingCryptoTopupToasts(Request $request)
    {
        $u = $request->user();
        if (!$u) return response()->json([]);

        $rows = PanelCryptoTopup::where('user_id', $u->id)
            ->whereIn('status', ['paid', 'expired', 'failed'])
            ->whereNull('notified_at')
            ->latest('id')
            ->limit(3)
            ->get();

        return response()->json($rows->map(function ($r) {
            return [
                'id' => $r->id,
                'status' => $r->status,
                'amount' => (int) $r->amount_irt,
                'asset' => $r->asset,
                'network' => $r->network,
                'crypto_amount' => number_format((float) $r->crypto_amount, 6, '.', ''),
                'tx_hash' => $r->tx_hash,
                'at' => optional($r->updated_at)->toIso8601String(),
            ];
        })->values());
    }

    public function ackCryptoTopupToast(Request $request, int $id)
    {
        $u = $request->user();
        if (!$u) return response()->json(['ok' => false], 401);

        $r = PanelCryptoTopup::where('id', $id)
            ->where('user_id', $u->id)
            ->firstOrFail();

        if (!$r->notified_at) {
            $r->notified_at = now();
            $r->save();
        }

        return response()->json(['ok' => true]);
    }

    // GET /api/wallet/receipt/notifications/pending
    public function pendingReceiptToasts(Request $request)
    {
        $u = $request->user();
        if (!$u) return response()->json([]);

        $r = PanelWalletReceipt::where('user_id', $u->id)
            ->where('status', 'verified')
            ->whereNull('notified_at')
            ->latest('id')
            ->first();

        if (!$r) return response()->json([]);

        return response()->json([[
            'id'     => $r->id,
            'amount' => (int)($r->amount ?? 0),
            'at'     => optional($r->updated_at)->toIso8601String(),
        ]]);
    }

    // POST /api/wallet/receipt/notifications/{id}/ack
    public function ackReceiptToast(Request $request, int $id)
    {
        $u = $request->user();
        if (!$u) return response()->json(['ok' => false], 401);

        $r = PanelWalletReceipt::where('id', $id)
            ->where('user_id', $u->id)
            ->firstOrFail();

        if (!$r->notified_at) {
            $r->notified_at = now();
            $r->save();
        }
        return response()->json(['ok' => true]);
    }

    // GET /api/wallet/commission/notifications/pending
    public function pendingCommissionToasts(Request $request)
    {
        $u = $request->user();

        if (!$u) {
            return response()->json([]);
        }

        /*
        * مسیر قدیمی:
        * کمیسیون پرداخت‌هایی که Receipt دارند.
        */
        $row = DB::table('panel_wallet_receipts as wr')
            ->join('panel_users as payer', 'payer.id', '=', 'wr.user_id')
            ->leftJoin('panel_transactions as t', 't.id', '=', 'wr.commission_tx_id')
            ->where('wr.status', 'verified')
            ->where('wr.commission_paid', true)
            ->whereNotNull('wr.commission_tx_id')
            ->whereNull('wr.commission_notified_at')
            ->where('payer.referred_by_id', $u->id)
            ->select([
                'wr.id',
                'wr.updated_at',
                DB::raw('COALESCE(t.amount, 0) as commission_amount'),
                DB::raw('COALESCE(t.balance_after, 0) as referrer_balance_after'),
                DB::raw('COALESCE(payer.name, payer.email) as payer_name'),
                'payer.code as payer_code',
            ])
            ->orderByDesc('wr.id')
            ->first();

        if ($row) {
            return response()->json([[
                'id' => (int) $row->id,
                'source' => 'receipt',
                'amount' => (int) $row->commission_amount,
                'balance_after' => (int) $row->referrer_balance_after,
                'payer_name' => (string) ($row->payer_name ?? ''),
                'payer_code' => (string) ($row->payer_code ?? ''),
                'at' => optional($row->updated_at)->toIso8601String(),
            ]]);
        }

        /*
        * مسیر جدید:
        * پرداخت پیامکی مستقیم که هیچ Receipt ندارد.
        */
        $cardTopup = DB::table('panel_wallet_card_topups as ct')
            ->join('panel_users as payer', 'payer.id', '=', 'ct.user_id')
            ->leftJoin('panel_transactions as t', 't.id', '=', 'ct.commission_tx_id')
            ->where('ct.status', 'paid')
            ->where('ct.commission_paid', true)
            ->whereNotNull('ct.commission_tx_id')
            ->whereNull('ct.commission_notified_at')
            ->where('payer.referred_by_id', $u->id)
            ->select([
                'ct.id',
                'ct.updated_at',
                DB::raw('COALESCE(t.amount, 0) as commission_amount'),
                DB::raw('COALESCE(t.balance_after, 0) as referrer_balance_after'),
                DB::raw('COALESCE(payer.name, payer.email) as payer_name'),
                'payer.code as payer_code',
            ])
            ->orderByDesc('ct.id')
            ->first();

        if (!$cardTopup) {
            return response()->json([]);
        }

        return response()->json([[
            'id' => (int) $cardTopup->id,
            'source' => 'card_topup',
            'amount' => (int) $cardTopup->commission_amount,
            'balance_after' => (int) $cardTopup->referrer_balance_after,
            'payer_name' => (string) ($cardTopup->payer_name ?? ''),
            'payer_code' => (string) ($cardTopup->payer_code ?? ''),
            'at' => optional($cardTopup->updated_at)->toIso8601String(),
        ]]);
    }

    // POST /api/wallet/commission/notifications/{id}/ack
    public function ackCommissionToast(Request $request, int $id)
    {
        $u = $request->user();

        if (!$u) {
            return response()->json(['ok' => false], 401);
        }

        $source = (string) $request->input('source', 'receipt');

        /*
        * کمیسیون پرداخت مستقیم SMS بدون Receipt.
        */
        if ($source === 'card_topup') {
            $topup = DB::table('panel_wallet_card_topups as ct')
                ->join('panel_users as payer', 'payer.id', '=', 'ct.user_id')
                ->where('ct.id', $id)
                ->where('ct.status', 'paid')
                ->where('ct.commission_paid', true)
                ->whereNotNull('ct.commission_tx_id')
                ->where('payer.referred_by_id', $u->id)
                ->select('ct.id', 'ct.commission_notified_at')
                ->first();

            if (!$topup) {
                return response()->json(['ok' => false], 404);
            }

            DB::table('panel_wallet_card_topups')
                ->where('id', $id)
                ->whereNull('commission_notified_at')
                ->update([
                    'commission_notified_at' => now(),
                ]);

            return response()->json(['ok' => true]);
        }

        /*
        * مسیر قدیمی Receipt.
        */
        $wr = DB::table('panel_wallet_receipts as wr')
            ->join('panel_users as payer', 'payer.id', '=', 'wr.user_id')
            ->where('wr.id', $id)
            ->where('wr.status', 'verified')
            ->where('wr.commission_paid', true)
            ->whereNotNull('wr.commission_tx_id')
            ->where('payer.referred_by_id', $u->id)
            ->select('wr.id', 'wr.commission_notified_at')
            ->first();

        if (!$wr) {
            return response()->json(['ok' => false], 404);
        }

        DB::table('panel_wallet_receipts')
            ->where('id', $id)
            ->whereNull('commission_notified_at')
            ->update([
                'commission_notified_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    public function pendingAdjustToasts(Request $request)
    {
        $u = $request->user();
        if (!$u) return response()->json([]);

        $rows = \App\Models\PanelWalletReceipt::query()
            ->where('user_id', $u->id)
            ->where('status', 'verified')
            ->where('method', 'adjust') // فقط اصلاحیه‌ها
            ->whereNull('notified_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get(['id','amount','updated_at']);

        $out = $rows->map(function($r){
            return [
                'id'     => (int)$r->id,
                'amount' => (int)$r->amount,
                'at'     => optional($r->updated_at)->toIso8601String(),
            ];
        });

        return response()->json($out);
    }

    public function ackAdjustToast(Request $request, int $id)
    {
        $u = $request->user();
        if (!$u) return response()->json(['ok'=>false], 403);

        $r = \App\Models\PanelWalletReceipt::where('id', $id)
            ->where('user_id', $u->id)
            ->where('method', 'adjust')
            ->first();

        if (!$r) return response()->json(['ok'=>false], 404);

        if (!$r->notified_at) {
            $r->notified_at = now();
            $r->save();
        }
        return response()->json(['ok'=>true]);
    }

}
