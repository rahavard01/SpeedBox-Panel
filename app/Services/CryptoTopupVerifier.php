<?php

namespace App\Services;

use App\Models\PanelCryptoTopup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CryptoTopupVerifier
{
    private const USDT_TRC20_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    private const USDT_BEP20_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';
    private const TRX_BEP20_CONTRACT = '0xce7de646e7208a4ef112cb6ed5038fa6cc6b12e3';
    private const ERC20_TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    private const NODEREAL_BSC_RPC_BASE = 'https://bsc-mainnet.nodereal.io/v1/';

    public function run(): void
    {
        PanelCryptoTopup::query()
            ->whereIn('status', ['pending', 'checking'])
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->each(fn ($topup) => $this->verifyOne($topup));

        PanelCryptoTopup::query()
            ->whereIn('status', ['pending', 'checking'])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function ($topup) {
                $changed = PanelCryptoTopup::whereKey($topup->id)
                    ->whereIn('status', ['pending', 'checking'])
                    ->whereNull('paid_at')->where('expires_at', '<=', now())
                    ->update(['status' => 'expired', 'updated_at' => now()]);
                if (!$changed) return;

                $this->notifyUserTelegram($topup->fresh(), 'expired');
            });
    }

    private function verifyOne(PanelCryptoTopup $topup): void
    {
        $tx = null;

        if ($topup->asset === 'USDT' && $topup->network === 'TRC20') {
            $tx = $this->findTrc20Usdt($topup);
        }

        if ($topup->asset === 'USDT' && $topup->network === 'BEP20') {
            $tx = $this->findBep20Usdt($topup);
        }

        if ($topup->asset === 'TRX' && $topup->network === 'TRC20') {
            $tx = $this->findTrxTransfer($topup);
        }

        if ($topup->asset === 'TRX' && $topup->network === 'BEP20') {
            $tx = $this->findBep20Trx($topup);
        }

        if ($topup->asset === 'BNB' && $topup->network === 'BEP20') {
            $tx = $this->findBep20Bnb($topup);
        }

        if (!$tx) {
            return;
        }

        $this->markPaid($topup, $tx);
    }

    private function findTrc20Usdt(PanelCryptoTopup $topup): ?array
    {
        $url = "https://api.trongrid.io/v1/accounts/{$topup->wallet_address}/transactions/trc20";

        $headers = [];
        $apiKey = $this->trongridApiKey();

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
            Log::warning('trongrid_failed', ['status' => $res->status()]);
            return null;
        }

        foreach (($res->json('data') ?? []) as $item) {
            $to = (string)($item['to'] ?? '');
            $hash = (string)($item['transaction_id'] ?? '');
            $value = (string)($item['value'] ?? '0');
            $decimals = (int)($item['token_info']['decimals'] ?? 6);

            if ($to !== $topup->wallet_address) {
                continue;
            }

            $amount = ((float)$value) / (10 ** $decimals);

            if ($this->amountMatches($amount, (float)$topup->crypto_amount)) {
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

    private function findTrxTransfer(PanelCryptoTopup $topup): ?array
    {
        $url = "https://api.trongrid.io/v1/accounts/{$topup->wallet_address}/transactions";

        $headers = [];
        $apiKey = $this->trongridApiKey();

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
            Log::warning('trongrid_trx_failed', ['status' => $res->status()]);
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

            if ($toBase58 !== $topup->wallet_address) {
                continue;
            }

            $amount = $amountSun / 1000000;

            if ($this->amountMatches($amount, (float)$topup->crypto_amount)) {
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

    private function findBep20Usdt(PanelCryptoTopup $topup): ?array
    {
        return $this->findBep20TokenTransfer($topup, self::USDT_BEP20_CONTRACT, 18, 'USDT');
    }

    private function findBep20Trx(PanelCryptoTopup $topup): ?array
    {
        return $this->findBep20TokenTransfer($topup, self::TRX_BEP20_CONTRACT, 6, 'TRX');
    }

    private function findBep20Bnb(PanelCryptoTopup $topup): ?array
    {
        $wallet = strtolower(trim((string) $topup->wallet_address));

        if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
            Log::warning('nodereal_invalid_bnb_wallet', [
                'topup_id' => $topup->id,
                'wallet' => $topup->wallet_address,
            ]);
            return null;
        }

        $latestHex = $this->bscRpc('eth_blockNumber');

        if (!is_string($latestHex) || !str_starts_with($latestHex, '0x')) {
            return null;
        }

        $latest = hexdec($latestHex);
        $latestSafe = max(0, $latest - 3);

        if (!$topup->bsc_start_block) {
            $ageSeconds = max(60, now()->diffInSeconds($topup->created_at ?? now(), true));
            $backBlocks = min(5000, (int) ceil($ageSeconds / 2) + 300);
            $startBlock = max(0, $latestSafe - $backBlocks);

            $topup->forceFill([
                'bsc_start_block' => $startBlock,
                'bsc_last_checked_block' => null,
            ])->save();

            $topup->refresh();
        }

        $from = $topup->bsc_last_checked_block
            ? ((int) $topup->bsc_last_checked_block + 1)
            : (int) $topup->bsc_start_block;

        if ($from > $latestSafe) {
            return null;
        }

        $lastChecked = $topup->bsc_last_checked_block;

        for ($block = $from; $block <= $latestSafe; $block++) {
            $data = $this->bscRpc('eth_getBlockByNumber', [
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

                if (!$this->amountMatches($amount, (float)$topup->crypto_amount)) {
                    continue;
                }

                $receipt = $this->bscRpc('eth_getTransactionReceipt', [$hash]);

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
            $topup->forceFill([
                'bsc_last_checked_block' => $lastChecked,
            ])->save();
        }

        return null;
    }

    private function findBep20TokenTransfer(PanelCryptoTopup $topup, string $contract, int $decimals, string $asset): ?array
    {
        $wallet = strtolower(trim((string) $topup->wallet_address));

        if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
            Log::warning('nodereal_invalid_wallet', [
                'topup_id' => $topup->id,
                'wallet' => $topup->wallet_address,
            ]);
            return null;
        }

        $latestHex = $this->bscRpc('eth_blockNumber');

        if (!is_string($latestHex) || !str_starts_with($latestHex, '0x')) {
            Log::warning('nodereal_block_failed', [
                'topup_id' => $topup->id,
                'result' => $latestHex,
            ]);
            return null;
        }

        $latest = hexdec($latestHex);
        $latestSafe = max(0, $latest - 3);

        if (!$topup->bsc_start_block) {
            $ageSeconds = max(60, now()->diffInSeconds($topup->created_at ?? now(), true));
            $backBlocks = min(5000, (int) ceil($ageSeconds / 2) + 300);
            $startBlock = max(0, $latestSafe - $backBlocks);

            $topup->forceFill([
                'bsc_start_block' => $startBlock,
                'bsc_last_checked_block' => null,
            ])->save();

            $topup->refresh();
        }

        $from = $topup->bsc_last_checked_block
            ? ((int) $topup->bsc_last_checked_block + 1)
            : (int) $topup->bsc_start_block;

        if ($from > $latestSafe) {
            return null;
        }

        $toTopic = '0x' . str_pad(substr($wallet, 2), 64, '0', STR_PAD_LEFT);
        $chunkSize = 100;
        $lastChecked = $topup->bsc_last_checked_block;

        for ($start = $from; $start <= $latestSafe; $start += $chunkSize) {
            $end = min($latestSafe, $start + $chunkSize - 1);

            $logs = $this->bscRpc('eth_getLogs', [[
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
                Log::warning('nodereal_logs_failed', [
                    'topup_id' => $topup->id,
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

                if ($this->amountMatches($amount, (float)$topup->crypto_amount)) {
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
            $topup->forceFill([
                'bsc_last_checked_block' => $lastChecked,
            ])->save();
        }

        return null;
    }

    private function bscRpc(string $method, array $params = [])
    {
        $apiKey = $this->nodeRealApiKey();

        if (!$apiKey) {
            Log::warning('nodereal_missing_api_key');
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
                Log::warning('nodereal_http_failed', [
                    'method' => $method,
                    'status' => $res->status(),
                    'body' => $res->body(),
                ]);
                return null;
            }

            $json = $res->json();

            if (isset($json['error'])) {
                Log::warning('nodereal_rpc_error', [
                    'method' => $method,
                    'error' => $json['error'],
                ]);
                return null;
            }

            return $json['result'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('nodereal_exception', [
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

    private function nodeRealApiKey(): ?string
    {
        $key = DB::table('panel_crypto_wallet_settings')
            ->orderBy('id')
            ->value('bscscan_api_key');

        $key = trim((string) $key);

        return $key !== '' ? $key : null;
    }

    private function amountMatches(float $chainAmount, float $expected): bool
    {
        return abs($chainAmount - $expected) <= 0.000001;
    }

    private function markPaid(PanelCryptoTopup $topup, array $tx): void
    {
        DB::transaction(function () use ($topup, $tx) {
            $locked = PanelCryptoTopup::query()
                ->where('id', $topup->id)
                ->lockForUpdate()
                ->first();

            if (!$locked || $locked->status === 'paid') {
                return;
            }

            if (!app(CryptoPaymentClaim::class)->claim((string) $locked->network, (string) $tx['tx_hash'], 'panel_crypto_topup', (int) $locked->id)) {
                $locked->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);

                $this->notifyUserTelegram($locked->fresh(), 'failed');

                return;
            }

            $before = (int) DB::table('panel_users')
                ->where('id', $locked->user_id)
                ->lockForUpdate()
                ->value('credit');

            $after = $before + (int) $locked->amount_irt;

            DB::table('panel_users')
                ->where('id', $locked->user_id)
                ->update([
                    'credit' => $after,
                    'updated_at' => now(),
                ]);

            DB::table('panel_transactions')->insert([
                'panel_user_id' => $locked->user_id,
                'type' => 'wallet_topup_crypto',
                'direction' => 'credit',
                'amount' => $locked->amount_irt,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => 'panel_crypto_topup',
                'reference_id' => $locked->id,
                'quantity' => 1,
                'currency' => 'IRT',
                'status' => 'success',
                'idempotency_key' => "crypto_topup:{$locked->id}",
                'meta' => json_encode([
                    'method' => 'crypto',
                    'asset' => $locked->asset,
                    'network' => $locked->network,
                    'crypto_amount' => (string)$locked->crypto_amount,
                    'tx_hash' => $tx['tx_hash'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $locked->update([
                'status' => 'paid',
                'tx_hash' => $tx['tx_hash'],
                'paid_at' => now(),
                'meta' => array_merge($locked->meta ?: [], [
                    'verified_at' => now()->toIso8601String(),
                    'chain_amount' => $tx['amount'],
                    'chain_network' => $tx['network'],
                ]),
            ]);
            $this->notifyUserTelegram($locked->fresh(), 'paid', $after);
        });
    }

    private function notifyUserTelegram(PanelCryptoTopup $topup, string $status, ?int $balanceAfter = null): void
    {
        if ($topup->telegram_notified_at) {
            return;
        }

        $chatId = DB::table('panel_users')
            ->where('id', $topup->user_id)
            ->value('telegram_user_id');

        if (!$chatId) {
            return;
        }

        $amount = number_format((int) $topup->amount_irt);
        $cryptoAmount = number_format((float) $topup->crypto_amount, 6, '.', '');

        if ($status === 'paid') {
            $balance = number_format((int) $balanceAfter);

            $msg  = "☑️ شارژ کیف پول با رمزارز تأیید شد.\n";
            $msg .= "ارز: <code>{$topup->asset}</code>\n";
            $msg .= "شبکه: <code>{$topup->network}</code>\n";
            $msg .= "مبلغ پرداخت: <code>{$cryptoAmount}</code>\n\n";
            $msg .= "✅ کیف پول شما با مبلغ {$amount} تومان شارژ شد.\n";
            $msg .= "💰 موجودی فعلی:  {$balance} تومان";
        } elseif ($status === 'expired') {
            $msg  = "⏳ سفارش شارژ رمزارزی شما منقضی شد.\n\n";
            $msg .= "مبلغ: {$amount} تومان\n";
            $msg .= "ارز: <code>{$topup->asset}</code>\n";
            $msg .= "شبکه: <code>{$topup->network}</code>";
        } else {
            $msg  = "❌ شارژ رمزارزی شما تأیید نشد.\n\n";
            $msg .= "مبلغ: {$amount} تومان\n";
            $msg .= "ارز: <code>{$topup->asset}</code>\n";
            $msg .= "شبکه: <code>{$topup->network}</code>";
        }

        try {
            app(\App\Services\TelegramNotifier::class)->sendTextTo((string) $chatId, $msg);

            $topup->update([
                'telegram_notified_at' => now(),
            ]);
        } catch (\Throwable $e) {}
    }

    private function trongridApiKey(): ?string
    {
        $key = DB::table('panel_crypto_wallet_settings')
            ->orderBy('id')
            ->value('trongrid_api_key');

        $key = trim((string) $key);

        return $key !== '' ? $key : null;
    }

}