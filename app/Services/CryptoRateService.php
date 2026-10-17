<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CryptoRateService
{
    public function tomanRate(string $asset): float
    {
        $asset = strtolower(trim($asset));

        if (!in_array($asset, ['usdt', 'trx', 'bnb'], true)) {
            throw new \InvalidArgumentException('ارز پشتیبانی نمی‌شود');
        }

        $res = Http::timeout(12)->get('https://apiv2.nobitex.ir/market/stats', [
            'srcCurrency' => $asset,
            'dstCurrency' => 'rls',
        ]);

        if (!$res->ok()) {
            Log::warning('nobitex_rate_failed', [
                'asset' => $asset,
                'status' => $res->status(),
                'body' => $res->body(),
            ]);

            throw new \RuntimeException('خطا در دریافت نرخ ارز');
        }

        $key = $asset . '-rls';
        $latestRial = $res->json("stats.$key.latest");

        if (!$latestRial || !is_numeric($latestRial)) {
            Log::warning('nobitex_rate_invalid', [
                'asset' => $asset,
                'key' => $key,
                'response' => $res->json(),
            ]);

            throw new \RuntimeException('نرخ ارز معتبر نیست');
        }

        return ((float) $latestRial) / 10; // ریال به تومان
    }
}