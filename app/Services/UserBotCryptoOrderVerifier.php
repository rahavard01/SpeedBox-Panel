<?php

namespace App\Services;

use App\Models\PanelCryptoTopup;
use App\Models\UserBotOrder;
use App\Models\UserBotSession;
use App\Models\UserBotSetting;
use App\Services\UserBot\UserBotKeyboard;
use App\Services\UserBot\UserBotOrderProcessor;
use App\Services\UserBot\UserBotTelegram;
use App\Support\JalaliDateFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UserBotCryptoOrderVerifier
{
    private const USDT_TRC20_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    private const USDT_BEP20_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';
    private const TRX_BEP20_CONTRACT = '0xce7de646e7208a4ef112cb6ed5038fa6cc6b12e3';
    private const ERC20_TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    private const NODEREAL_BSC_RPC_BASE = 'https://bsc-mainnet.nodereal.io/v1/';

    public function run(): void
    {
        UserBotOrder::query()
            ->whereIn('status', ['crypto_pending', 'crypto_checking'])
            ->where('payment_method', 'crypto')
            ->where('crypto_expires_at', '>', now())
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->each(fn ($order) => $this->verifyOne($order));

        UserBotOrder::query()
            ->whereIn('status', ['crypto_pending', 'crypto_checking'])
            ->where('payment_method', 'crypto')
            ->where('crypto_expires_at', '<=', now())
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (UserBotOrder $order) {
                $changed = UserBotOrder::whereKey($order->id)
                    ->whereIn('status', ['crypto_pending', 'crypto_checking'])
                    ->whereNull('crypto_paid_at')->where('crypto_expires_at', '<=', now())
                    ->update(['status' => 'expired', 'crypto_meta' => array_merge($order->crypto_meta ?: [], ['expired_at' => now()->toIso8601String()])]);
                if (!$changed) return;

                $this->notifyBuyer($order->fresh(), 'expired');
            });
    }

    private function verifyOne(UserBotOrder $order): void
    {
        $tx = null;

        if ($order->crypto_asset === 'USDT' && $order->crypto_network === 'TRC20') {
            $tx = $this->findTrc20Usdt($order);
        }

        if ($order->crypto_asset === 'USDT' && $order->crypto_network === 'BEP20') {
            $tx = $this->findBep20Usdt($order);
        }

        if ($order->crypto_asset === 'TRX' && $order->crypto_network === 'TRC20') {
            $tx = $this->findTrxTransfer($order);
        }

        if ($order->crypto_asset === 'TRX' && $order->crypto_network === 'BEP20') {
            $tx = $this->findBep20Trx($order);
        }

        if ($order->crypto_asset === 'BNB' && $order->crypto_network === 'BEP20') {
            $tx = $this->findBep20Bnb($order);
        }

        if (!$tx) {
            return;
        }

        $this->markPaidAndApprove($order, $tx);
    }

    private function findTrc20Usdt(UserBotOrder $order): ?array
    {
        $url = "https://api.trongrid.io/v1/accounts/{$order->crypto_wallet_address}/transactions/trc20";

        $headers = [];
        $apiKey = $this->trongridApiKey($order);

        if ($apiKey) {
            $headers['TRON-PRO-API-KEY'] = $apiKey;
        }

        $res = Http::withHeaders($headers)
            ->timeout(15)
            ->get($url, [
                'limit' => 50,
                'only_confirmed' => 'true',
                'only_to' => 'true',
                'contract_address' => self::USDT_TRC20_CONTRACT,
            ]);

        if (!$res->ok()) {
            Log::warning('userbot_trongrid_failed', [
                'order_id' => $order->id,
                'status' => $res->status(),
            ]);
            return null;
        }

        foreach (($res->json('data') ?? []) as $item) {
            $to = (string)($item['to'] ?? '');
            $hash = (string)($item['transaction_id'] ?? '');
            $value = (string)($item['value'] ?? '0');
            $decimals = (int)($item['token_info']['decimals'] ?? 6);

            if ($to !== $order->crypto_wallet_address) {
                continue;
            }

            $amount = ((float)$value) / (10 ** $decimals);

            if ($this->amountMatches($amount, (float)$order->crypto_amount)) {
                return [
                    'tx_hash' => $hash,
                    'amount' => $amount,
                    'network' => 'TRC20',
                    'raw' => $item,
                ];
            }
        }

        return null;
    }

    private function findTrxTransfer(UserBotOrder $order): ?array
    {
        $url = "https://api.trongrid.io/v1/accounts/{$order->crypto_wallet_address}/transactions";

        $headers = [];
        $apiKey = $this->trongridApiKey($order);

        if ($apiKey) {
            $headers['TRON-PRO-API-KEY'] = $apiKey;
        }

        $res = Http::withHeaders($headers)
            ->timeout(15)
            ->get($url, [
                'limit' => 50,
                'only_confirmed' => 'true',
                'only_to' => 'true',
            ]);

        if (!$res->ok()) {
            Log::warning('userbot_trongrid_trx_failed', [
                'order_id' => $order->id,
                'status' => $res->status(),
            ]);
            return null;
        }

        foreach (($res->json('data') ?? []) as $item) {
            $hash = (string)($item['txID'] ?? '');
            $contract = $item['raw_data']['contract'][0] ?? [];
            $type = (string)($contract['type'] ?? '');

            if ($type !== 'TransferContract') {
                continue;
            }

            $value = $contract['parameter']['value'] ?? [];
            $to = (string)($value['to_address'] ?? '');
            $amountSun = (int)($value['amount'] ?? 0);

            if (!$to || $amountSun <= 0) {
                continue;
            }

            $toBase58 = $this->tronHexToBase58($to);

            if ($toBase58 !== $order->crypto_wallet_address) {
                continue;
            }

            $amount = $amountSun / 1000000;

            if ($this->amountMatches($amount, (float)$order->crypto_amount)) {
                return [
                    'tx_hash' => $hash,
                    'amount' => $amount,
                    'network' => 'TRC20',
                    'raw' => $item,
                ];
            }
        }

        return null;
    }

    private function findBep20Usdt(UserBotOrder $order): ?array
    {
        return $this->findBep20TokenTransfer($order, self::USDT_BEP20_CONTRACT, 18, 'USDT');
    }

    private function findBep20Trx(UserBotOrder $order): ?array
    {
        return $this->findBep20TokenTransfer($order, self::TRX_BEP20_CONTRACT, 6, 'TRX');
    }

    private function findBep20Bnb(UserBotOrder $order): ?array
    {
        $wallet = strtolower(trim((string) $order->crypto_wallet_address));

        if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
            Log::warning('userbot_nodereal_invalid_bnb_wallet', [
                'order_id' => $order->id,
                'wallet' => $order->crypto_wallet_address,
            ]);
            return null;
        }

        $latestHex = $this->bscRpc($order, 'eth_blockNumber');

        if (!is_string($latestHex) || !str_starts_with($latestHex, '0x')) {
            return null;
        }

        $latest = hexdec($latestHex);
        $latestSafe = max(0, $latest - 3);

        if (!$order->bsc_start_block) {
            $ageSeconds = max(60, now()->diffInSeconds($order->created_at ?? now(), true));
            $backBlocks = min(5000, (int) ceil($ageSeconds / 2) + 300);
            $startBlock = max(0, $latestSafe - $backBlocks);

            $order->forceFill([
                'bsc_start_block' => $startBlock,
                'bsc_last_checked_block' => null,
            ])->save();

            $order->refresh();
        }

        $from = $order->bsc_last_checked_block
            ? ((int) $order->bsc_last_checked_block + 1)
            : (int) $order->bsc_start_block;

        if ($from > $latestSafe) {
            return null;
        }

        $lastChecked = $order->bsc_last_checked_block;

        for ($block = $from; $block <= $latestSafe; $block++) {
            $data = $this->bscRpc($order, 'eth_getBlockByNumber', [
                '0x' . dechex($block),
                true,
            ]);

            if (!is_array($data)) {
                break;
            }

            foreach (($data['transactions'] ?? []) as $item) {
                $to = strtolower((string)($item['to'] ?? ''));

                if ($to !== $wallet) {
                    continue;
                }

                $hash = (string)($item['hash'] ?? '');
                $valueHex = (string)($item['value'] ?? '0x0');
                $amount = (float) $this->hexWeiToDecimal($valueHex);

                if (!$this->amountMatches($amount, (float)$order->crypto_amount)) {
                    continue;
                }

                $receipt = $this->bscRpc($order, 'eth_getTransactionReceipt', [$hash]);

                if (!is_array($receipt)) break 2;
                if (strtolower((string)($receipt['status'] ?? '')) !== '0x1') {
                    continue;
                }

                return [
                    'tx_hash' => $hash,
                    'amount' => $amount,
                    'network' => 'BEP20',
                    'raw' => $item,
                ];
            }

            $lastChecked = $block;
        }

        if ($lastChecked) {
            $order->forceFill([
                'bsc_last_checked_block' => $lastChecked,
            ])->save();
        }

        return null;
    }

    private function findBep20TokenTransfer(UserBotOrder $order, string $contract, int $decimals, string $asset): ?array
    {
        $wallet = strtolower(trim((string) $order->crypto_wallet_address));

        if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
            Log::warning('userbot_nodereal_invalid_wallet', [
                'order_id' => $order->id,
                'wallet' => $order->crypto_wallet_address,
            ]);
            return null;
        }

        $latestHex = $this->bscRpc($order, 'eth_blockNumber');

        if (!is_string($latestHex) || !str_starts_with($latestHex, '0x')) {
            Log::warning('userbot_nodereal_block_failed', [
                'order_id' => $order->id,
                'result' => $latestHex,
            ]);
            return null;
        }

        $latest = hexdec($latestHex);
        $latestSafe = max(0, $latest - 3);

        if (!$order->bsc_start_block) {
            $ageSeconds = max(60, now()->diffInSeconds($order->created_at ?? now(), true));
            $backBlocks = min(5000, (int) ceil($ageSeconds / 2) + 300);
            $startBlock = max(0, $latestSafe - $backBlocks);

            $order->forceFill([
                'bsc_start_block' => $startBlock,
                'bsc_last_checked_block' => null,
            ])->save();

            $order->refresh();
        }

        $from = $order->bsc_last_checked_block
            ? ((int) $order->bsc_last_checked_block + 1)
            : (int) $order->bsc_start_block;

        if ($from > $latestSafe) {
            return null;
        }

        $toTopic = '0x' . str_pad(substr($wallet, 2), 64, '0', STR_PAD_LEFT);
        $chunkSize = 100;
        $lastChecked = $order->bsc_last_checked_block;

        for ($start = $from; $start <= $latestSafe; $start += $chunkSize) {
            $end = min($latestSafe, $start + $chunkSize - 1);

            $logs = $this->bscRpc($order, 'eth_getLogs', [[
                'fromBlock' => '0x' . dechex($start),
                'toBlock' => '0x' . dechex($end),
                'address' => strtolower($contract),
                'topics' => [
                    self::ERC20_TRANSFER_TOPIC,
                    null,
                    $toTopic,
                ],
            ]]);

            if (!is_array($logs)) {
                Log::warning('userbot_nodereal_logs_failed', [
                    'order_id' => $order->id,
                    'asset' => $asset,
                    'from' => $start,
                    'to' => $end,
                    'result' => $logs,
                ]);
                break;
            }

            foreach (array_reverse($logs) as $item) {
                $hash = (string)($item['transactionHash'] ?? '');
                $data = (string)($item['data'] ?? '0x0');

                $amount = (float) $this->hexTokenAmountToDecimal($data, $decimals);

                if ($this->amountMatches($amount, (float)$order->crypto_amount)) {
                    return [
                        'tx_hash' => $hash,
                        'amount' => $amount,
                        'network' => 'BEP20',
                        'raw' => $item,
                    ];
                }
            }

            $lastChecked = $end;
        }

        if ($lastChecked) {
            $order->forceFill([
                'bsc_last_checked_block' => $lastChecked,
            ])->save();
        }

        return null;
    }

    private function markPaidAndApprove(UserBotOrder $order, array $tx): void
    {
        $outcome = $this->fulfillPaidOrder((int) $order->id, $tx);
        $this->notifyFulfillment($outcome);
    }

    /** Keep the order lock, payment claim and all business effects in one commit. */
    private function fulfillPaidOrder(int $orderId, ?array $tx = null): array
    {
        return DB::transaction(function () use ($orderId, $tx) {
            $order = UserBotOrder::query()->whereKey($orderId)->lockForUpdate()->first();
            $allowed = $tx === null ? ['crypto_retry_pending'] : ['crypto_pending', 'crypto_checking'];
            if (!$order || $order->payment_method !== 'crypto' || !in_array($order->status, $allowed, true)) {
                return ['ok' => false, 'state' => 'not_approvable', 'message' => 'سفارش پیدا نشد یا قبلاً انجام شده است'];
            }
            $payment = $tx ?? ['tx_hash' => $order->crypto_tx_hash, 'amount' => (float) $order->crypto_amount, 'network' => $order->crypto_network];
            if (!app(CryptoPaymentClaim::class)->claim((string) $order->crypto_network, (string) $payment['tx_hash'], 'user_bot_order', (int) $order->id)) {
                $order->update(['status' => 'failed', 'crypto_meta' => array_merge($order->crypto_meta ?: [], ['failed_reason' => 'duplicate_tx_hash'])]);
                return ['ok' => false, 'state' => 'duplicate', 'message' => 'این پرداخت قبلاً برای درخواست دیگری ثبت شده است', 'order' => $order->fresh()];
            }
            $order->update([
                'status' => 'crypto_checking', 'crypto_tx_hash' => $payment['tx_hash'],
                'crypto_paid_at' => $order->crypto_paid_at ?: now(),
                'crypto_meta' => array_merge($order->crypto_meta ?: [], [
                    'verified_at' => now()->toIso8601String(), 'chain_amount' => $payment['amount'],
                    'chain_network' => $payment['network'], 'tx_hash' => $payment['tx_hash'],
                ]),
            ]);
            $result = null;
            try {
                DB::transaction(function () use ($order, &$result) {
                    $result = app(UserBotOrderProcessor::class)->approve($order->fresh());
                    if (empty($result['ok'])) throw new \DomainException('Order fulfillment failed.');
                });
            } catch (\Throwable $error) {
                if (!$error instanceof \DomainException) report($error);
                $order->refresh();
                $order->update([
                    'status' => 'crypto_retry_pending',
                    'crypto_meta' => array_merge($order->crypto_meta ?: [], [
                        'approve_error' => $result['message'] ?? 'خطای موقت در انجام سفارش',
                        'approve_error_at' => now()->toIso8601String(),
                    ]),
                ]);
                return ['ok' => false, 'state' => 'retry_pending', 'message' => $result['message'] ?? 'خطای موقت در انجام سفارش', 'order' => $order->fresh()];
            }
            $order->refresh();
            $this->updateTransactionCryptoMeta($order, $payment);
            return ['ok' => true, 'state' => 'approved', 'message' => 'سفارش با موفقیت انجام شد', 'order' => $order, 'result' => $result];
        }, 3);
    }

    private function notifyFulfillment(array $outcome): void
    {
        if (!isset($outcome['order'])) return;
        $order = $outcome['order'];
        try {
            if ($outcome['state'] === 'approved') {
                $this->notifyBuyer($order, 'paid', $outcome['result']);
                $this->notifyOwnerForPaidSuccess($order->fresh(), $outcome['result']);
            } elseif ($outcome['state'] === 'retry_pending') {
                $this->notifyBuyerWaitingManual($order);
                $this->notifyOwnerForRetryNeeded($order, $outcome['message']);
            } elseif ($outcome['state'] === 'duplicate') {
                $this->notifyBuyer($order, 'failed');
            }
        } catch (\Throwable $error) {
            Log::warning('Crypto fulfillment notification failed.', ['order_id' => $order->id, 'error' => $error->getMessage()]);
        }
    }

    private function updateTransactionCryptoMeta(UserBotOrder $order, array $tx): void
    {
        $payload = $order->payload ?: [];
        $transactionId = $payload['transaction_id'] ?? null;

        if (!$transactionId) {
            return;
        }

        $row = DB::table('panel_transactions')->where('id', (int) $transactionId)->first();

        if (!$row) {
            return;
        }

        $meta = [];
        if (!empty($row->meta)) {
            try {
                $meta = is_string($row->meta) ? json_decode($row->meta, true) : (array) $row->meta;
                if (!is_array($meta)) {
                    $meta = [];
                }
            } catch (\Throwable $e) {
                $meta = [];
            }
        }

        $meta['payment_method'] = 'crypto';
        $meta['crypto_asset'] = $order->crypto_asset;
        $meta['crypto_network'] = $order->crypto_network;
        $meta['crypto_amount'] = number_format((float) $order->crypto_amount, 6, '.', '');
        $meta['crypto_tx_hash'] = $tx['tx_hash'];
        $meta['user_bot_order_id'] = $order->id;
        $meta['user_bot_order_code'] = $order->order_code;

        DB::table('panel_transactions')
            ->where('id', (int) $transactionId)
            ->update([
                'reference_type' => 'user_bot_order_crypto',
                'reference_id' => $order->id,
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    public function retryApprove(int $orderId): array
    {
        $outcome = $this->fulfillPaidOrder($orderId);
        $this->notifyFulfillment($outcome);
        return $outcome;
    }

    private function notifyBuyerWaitingManual(UserBotOrder $order): void
    {
        $setting = UserBotSetting::find($order->setting_id);
        $session = UserBotSession::find($order->session_id);

        if (!$setting || !$session || !$setting->bot_token) {
            return;
        }

        $bot = new UserBotTelegram($setting->bot_token);

        $isVolumePartner = $this->isVolumePartner($setting);
        $isBothPartner = $this->isBothPartner($setting);
        $isVolumeAccount = $session->is_logged_in
            ? $this->isLoggedInVolumeAccount($session)
            : ($isVolumePartner && !$isBothPartner);

        $keyboard = $session->is_logged_in
            ? UserBotKeyboard::user($isVolumeAccount)
            : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner);

        $bot->sendMessage(
            $session->telegram_chat_id,
            "✅ پرداخت رمزارزی شما تایید شد.\n\n"
            . "سفارش شما در صف انجام قرار گرفت و پس از بررسی، نتیجه از همین ربات به شما اطلاع داده می‌شود.",
            $keyboard
        );
    }

    private function notifyOwnerForPaidSuccess(UserBotOrder $order, array $result = []): void
    {
        $setting = UserBotSetting::find($order->setting_id);

        if (!$setting || !$setting->bot_token || !$setting->owner_telegram_chat_id) {
            return;
        }

        $bot = new UserBotTelegram($setting->bot_token);

        $bot->sendMessage(
            $setting->owner_telegram_chat_id,
            "✅ <b>سفارش رمزارزی با موفقیت انجام شد</b>\n\n"
            . $this->ownerCryptoOrderSummary($order)
            . "\n\n✅ سفارش با موفقیت به کاربر تحویل داده شد.",
            UserBotKeyboard::manager()
        );
    }

    private function notifyOwnerForRetryNeeded(UserBotOrder $order, string $message): void
    {
        $setting = UserBotSetting::find($order->setting_id);

        if (!$setting || !$setting->bot_token || !$setting->owner_telegram_chat_id) {
            return;
        }

        $bot = new UserBotTelegram($setting->bot_token);

        $bot->sendMessage(
            $setting->owner_telegram_chat_id,
            "⚠️ <b>پرداخت رمزارزی تایید شد اما انجام سفارش ناموفق بود</b>\n\n"
            . $this->ownerCryptoOrderSummary($order)
            . "\n\n<b>خطا:</b>\n{$message}\n\n"
            . "لطفاً کیف پول خود را شارژ کنید و سپس دکمه زیر را بزنید تا سفارش به کاربر تحویل داده شود.",
            UserBotKeyboard::cryptoRetryOrder($order->id)
        );
    }

    private function ownerCryptoOrderSummary(UserBotOrder $order): string
    {
        $session = UserBotSession::find($order->session_id);
        $payload = $order->payload ?: [];
        $selected = $payload['selected_plan'] ?? [];
        $orderNo = $order->order_code ?: $order->id;

        $orderTypeText = match ($order->order_type) {
            'buy_traffic' => $this->isVolumeOrder($order) ? 'خرید ترافیک' : 'خرید ترافیک اضافه',
            'renew_account' => 'تمدید اکانت',
            default => 'خرید اکانت',
        };

        $telegramUser = ($session && $session->telegram_username)
            ? '@' . e($session->telegram_username)
            : '-';

        return "🔢 شماره سفارش: <b>{$orderNo}</b>\n"
            . "👤 کاربر تلگرام: <b>{$telegramUser}</b>\n"
            . "👤 نام کاربری اکانت: <code>" . e($this->formatUsername($order->account_email ?? ($session->account_email ?? null))) . "</code>\n"
            . "📦 نوع سفارش: <b>{$orderTypeText}</b>\n"
            . "🧩 طرح: <b>" . e($this->planFullTitle(is_array($selected) ? $selected : [])) . "</b>\n"
            . ($order->traffic_gb ? "📊 حجم: <b>{$order->traffic_gb} گیگ</b>\n" : "")
            . "💰 مبلغ سفارش: <b>" . number_format((int) $order->amount) . " تومان</b>\n"
            . "💲 ارز: <code>{$order->crypto_asset}</code>\n"
            . "🌐 شبکه: <code>{$order->crypto_network}</code>\n"
            . "💵 مبلغ پرداختی: <code>" . number_format((float) $order->crypto_amount, 6, '.', '') . "</code>\n"
            . "🔗 هش تراکنش:\n<code>{$order->crypto_tx_hash}</code>";
    }

    private function notifyBuyer(UserBotOrder $order, string $status, ?array $result = null): void
    {
        $setting = UserBotSetting::find($order->setting_id);
        $session = UserBotSession::find($order->session_id);

        if (!$setting || !$session || !$setting->bot_token) {
            return;
        }

        $bot = new UserBotTelegram($setting->bot_token);

        $isVolumePartner = $this->isVolumePartner($setting);
        $isBothPartner = $this->isBothPartner($setting);
        $isVolumeAccount = $session->is_logged_in
            ? $this->isLoggedInVolumeAccount($session)
            : ($isVolumePartner && !$isBothPartner);

        $keyboard = $session->is_logged_in
            ? UserBotKeyboard::user($isVolumeAccount)
            : UserBotKeyboard::guest((bool) $setting->active_users_only, $isVolumePartner && !$isBothPartner);

        $amount = number_format((int) $order->amount);
        $cryptoAmount = number_format((float) $order->crypto_amount, 6, '.', '');
        $orderNo = $order->order_code ?: $order->id;

        if ($status === 'expired') {
            $bot->sendMessage(
                $session->telegram_chat_id,
                "⏳ سفارش رمزارزی شما منقضی شد.\n\n"
                . "شماره سفارش: <b>{$orderNo}</b>\n\n"
                . "مبلغ سفارش: {$amount} تومان\n"
                . "ارز: <code>{$order->crypto_asset}</code>\n"
                . "شبکه: <code>{$order->crypto_network}</code>",
                $keyboard
            );
            return;
        }

        if ($status === 'failed') {
            $bot->sendMessage(
                $session->telegram_chat_id,
                "❌ پرداخت رمزارزی شما تأیید نشد.\n\n"
                . "شماره سفارش: <b>{$orderNo}</b>\n\n"
                . "مبلغ سفارش: {$amount} تومان\n"
                . "ارز: <code>{$order->crypto_asset}</code>\n"
                . "شبکه: <code>{$order->crypto_network}</code>\n"
                . "مبلغ پرداخت: <code>{$cryptoAmount}</code>",
                $keyboard
            );
            return;
        }

        if ($status !== 'paid' || !$result) {
            return;
        }

        $header =
            "☑️ پرداخت رمزارزی شما تأیید شد.\n"
            . "ارز: <code>{$order->crypto_asset}</code>\n"
            . "شبکه: <code>{$order->crypto_network}</code>\n"
            . "مبلغ پرداخت: <code>{$cryptoAmount}</code>\n\n";

        $selected = $order->payload['selected_plan'] ?? [];

        $buyerText = match ($order->order_type) {
            'buy_traffic' =>
                $header
                . "✅ {$result['traffic_gb']} گیگ ترافیک با موفقیت به اکانت زیر اضافه شد:\n\n"
                . "👤 نام کاربری: <code>" . $this->formatUsername($result['email'] ?? null) . "</code>"
                . ($this->isVolumeOrder($order) ? "\n📅 تاریخ انقضای جدید: <b>" . $this->formatTimestamp($result['new_expired_at'] ?? null) . "</b>" : "")
                . ($this->isVolumeOrder($order) ? "\n📊 ترافیک باقی‌مانده: <b>" . $this->formatTrafficBytes($result['traffic_remaining_bytes'] ?? null) . "</b>" : ""),

            'renew_account' =>
                $header
                . "✅ اکانت زیر با موفقیت تمدید شد:\n\n"
                . "👤 <code>" . $this->formatUsername($result['email'] ?? null) . "</code>\n\n"
                . "🧩 طرح: <b>" . e($this->planFullTitle(is_array($selected) ? $selected : ['title' => $result['plan_title'] ?? '-'])) . "</b>\n"
                . "📅 تاریخ انقضای جدید: <b>" . $this->formatTimestamp($result['new_expired_at'] ?? null) . "</b>",

            default =>
                $header
                . "✅ اکانت شما با موفقیت ساخته شد\n\n"
                . "👤 نام کاربری:\n<code>" . $this->formatUsername($result['email'] ?? null) . "</code>\n\n"
                . "🔗 لینک سابسکرایب:\n<code>{$result['subscribe_url']}</code>",
        };

        if ($order->order_type === 'buy_account' && !empty($result['subscribe_url'])) {
            $bot->sendQrPhotoOrMessage(
                $session->telegram_chat_id,
                (string) $result['subscribe_url'],
                $buyerText,
                $keyboard
            );
        } else {
            $bot->sendMessage($session->telegram_chat_id, $buyerText, $keyboard);
        }
    }

    private function notifyOwnerForFailedApprove(UserBotOrder $order, string $message): void
    {
        $setting = UserBotSetting::find($order->setting_id);

        if (!$setting || !$setting->bot_token || !$setting->owner_telegram_chat_id) {
            return;
        }

        $bot = new UserBotTelegram($setting->bot_token);
        $orderNo = $order->order_code ?: $order->id;

        $bot->sendMessage(
            $setting->owner_telegram_chat_id,
            "⚠️ پرداخت رمزارزی سفارش شماره {$orderNo} تأیید شد اما انجام سفارش با خطا مواجه شد.\n\n"
            . "خطا: {$message}\n\n"
            . "لطفاً سفارش را به صورت دستی بررسی کنید.",
            UserBotKeyboard::manager()
        );
    }

    private function bscRpc(UserBotOrder $order, string $method, array $params = [])
    {
        $apiKey = $this->nodeRealApiKey($order);

        if (!$apiKey) {
            Log::warning('userbot_nodereal_missing_api_key', [
                'order_id' => $order->id,
            ]);
            return null;
        }

        try {
            $res = Http::timeout(20)->post(self::NODEREAL_BSC_RPC_BASE . $apiKey, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params,
            ]);

            if (!$res->ok()) {
                Log::warning('userbot_nodereal_http_failed', [
                    'order_id' => $order->id,
                    'method' => $method,
                    'status' => $res->status(),
                    'body' => $res->body(),
                ]);
                return null;
            }

            $json = $res->json();

            if (isset($json['error'])) {
                Log::warning('userbot_nodereal_rpc_error', [
                    'order_id' => $order->id,
                    'method' => $method,
                    'error' => $json['error'],
                ]);
                return null;
            }

            return $json['result'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('userbot_nodereal_exception', [
                'order_id' => $order->id,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function hexTokenAmountToDecimal(string $hex, int $decimals): string
    {
        $hex = strtolower($hex);

        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }

        $hex = ltrim($hex, '0');

        if ($hex === '') {
            return '0';
        }

        $num = '0';

        for ($i = 0, $len = strlen($hex); $i < $len; $i++) {
            $num = bcmul($num, '16', 0);
            $num = bcadd($num, (string) hexdec($hex[$i]), 0);
        }

        return bcdiv($num, bcpow('10', (string)$decimals, 0), 18);
    }

    private function hexWeiToDecimal(string $hex): string
    {
        $hex = strtolower($hex);

        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }

        $hex = ltrim($hex, '0');

        if ($hex === '') {
            return '0';
        }

        $num = '0';

        for ($i = 0, $len = strlen($hex); $i < $len; $i++) {
            $num = bcmul($num, '16', 0);
            $num = bcadd($num, (string) hexdec($hex[$i]), 0);
        }

        return bcdiv($num, bcpow('10', '18', 0), 18);
    }

    private function tronHexToBase58(string $hex): string
    {
        $hex = strtolower($hex);

        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }

        $bin = hex2bin($hex);
        if ($bin === false) {
            return '';
        }

        $hash0 = hash('sha256', $bin, true);
        $hash1 = hash('sha256', $hash0, true);
        $checksum = substr($hash1, 0, 4);

        return $this->base58Encode($bin . $checksum);
    }

    private function base58Encode(string $binary): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

        $num = '0';
        for ($i = 0, $len = strlen($binary); $i < $len; $i++) {
            $num = bcmul($num, '256');
            $num = bcadd($num, (string)ord($binary[$i]));
        }

        $encoded = '';
        while (bccomp($num, '0') > 0) {
            $rem = bcmod($num, '58');
            $num = bcdiv($num, '58', 0);
            $encoded = $alphabet[(int)$rem] . $encoded;
        }

        for ($i = 0; $i < strlen($binary) && $binary[$i] === "\x00"; $i++) {
            $encoded = '1' . $encoded;
        }

        return $encoded;
    }

    private function amountMatches(float $chainAmount, float $expected): bool
    {
        return abs($chainAmount - $expected) <= 0.000001;
    }

    private function trongridApiKey(UserBotOrder $order): ?string
    {
        $setting = UserBotSetting::find($order->setting_id);
        $key = trim((string) ($setting->crypto_trongrid_api_key ?? ''));

        return $key !== '' ? $key : null;
    }

    private function nodeRealApiKey(UserBotOrder $order): ?string
    {
        $setting = UserBotSetting::find($order->setting_id);
        $key = trim((string) ($setting->crypto_bscscan_api_key ?? ''));

        return $key !== '' ? $key : null;
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

    private function isVolumeOrder(UserBotOrder $order): bool
    {
        $payload = $order->payload ?: [];
        $selected = $payload['selected_plan'] ?? [];

        return $this->isVolumePlanKey((string) $order->plan_key)
            || $this->isVolumePlanKey((string) ($selected['plan_key'] ?? ''));
    }

    private function isVolumePlanKey(string $key): bool
    {
        return $key === 'volume_gig'
            || (bool) preg_match('/^\d+_volume_gig$/', $key);
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
}