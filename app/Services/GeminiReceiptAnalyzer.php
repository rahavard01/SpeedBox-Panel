<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class GeminiReceiptAnalyzer
{
    public function analyzeLocalFile(string $path, string $apiKey): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('فایل رسید پیدا نشد');
        }

        $mime = mime_content_type($path) ?: 'image/jpeg';
        $base64 = base64_encode(file_get_contents($path));

        return $this->analyzeBase64($base64, $mime, $apiKey);
    }

    public function analyzeTelegramFile(string $botToken, string $fileId, string $apiKey): array
    {
        $info = Http::timeout(20)
            ->get("https://api.telegram.org/bot{$botToken}/getFile", [
                'file_id' => $fileId,
            ])
            ->throw()
            ->json();

        $filePath = (string) data_get($info, 'result.file_path');

        if ($filePath === '') {
            throw new \RuntimeException('فایل رسید از تلگرام دریافت نشد');
        }

        $content = Http::timeout(45)
            ->get("https://api.telegram.org/file/bot{$botToken}/{$filePath}")
            ->throw()
            ->body();

        $mime = str_ends_with(strtolower($filePath), '.png') ? 'image/png' : 'image/jpeg';

        return $this->analyzeBase64(base64_encode($content), $mime, $apiKey);
    }

    private function analyzeBase64(string $base64, string $mime, string $apiKey): array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new \RuntimeException('GapGPT API Key تنظیم نشده است');
        }

        $prompt = <<<TXT
    You are a precise OCR-style extractor for Iranian bank/payment receipt images.

    Return ONLY valid JSON. No markdown. No explanations.

    Output exactly:
    {
    "is_receipt": true,
    "is_successful": true,
    "receiver_name": null,
    "sender_name": null,
    "amount_text": null,
    "amount_rial": null,
    "amount_toman": null,
    "tracking_code": null,
    "transaction_time": null,
    "transaction_date_jalali": null,
    "bank_or_app": null,
    "confidence": 0
    }

    Very important amount rules:
    - First read the BIG main amount shown near the top/center of the receipt.
    - Then read the currency word immediately next to it: ریال or تومان.
    - The field amount_text must be the exact visible amount string, including all zeros and separators.
    - Count the digits carefully from the visible amount_text.
    - Do not use any other number as amount.
    - If currency is ریال, amount_rial = exact visible integer and amount_toman = amount_rial / 10.
    - If currency is تومان, amount_toman = exact visible integer and amount_rial = amount_toman * 10.
    - If you are not certain about every digit of the amount, set amount_text, amount_rial and amount_toman to null.

    Other rules:
    - is_successful is true only if success is clearly visible: موفق، انتقال موفق، پرداخت موفق، تراکنش موفق، انجام شد.
    - If ناموفق، failed، لغو، برگشت، خطا is visible, is_successful must be false.
    - receiver_name is the destination/recipient/card owner name.
    - sender_name is the person who sent the money.
    - tracking_code is the real unique transaction/reference/trace number. In Persian receipts it may be labeled as: شماره مرجع، کد مرجع، شماره ارجاع، کد ارجاع، شماره پیگیری، کد پیگیری، شماره رهگیری، کد رهگیری، شماره تراکنش، شناسه تراکنش، شماره سند، شناسه پرداخت، کد رسید، شماره رسید.
    - Do NOT use card number, IBAN/sheba, account number, سپرده مبدا، سپرده مقصد، شماره کارت، شماره حساب، شبا، موبایل، تاریخ، ساعت, or amount as tracking_code.
    - transaction_time must be HH:MM only if visible.
    - transaction_date_jalali must be the exact visible Persian/Jalali transaction date only.
    - The image may be rotated or low quality; mentally rotate it before reading.
    - Be very careful with Persian digits, especially ۰ and ۵.
    - Do not confuse ۱۴۰۵ with ۱۴۰۰.
    - If the year/day/month is not clearly readable, set transaction_date_jalali to null.
    - Do not guess or infer the date.
    - bank_or_app is the app/bank name if visible.
    - confidence must be between 0 and 1.
    TXT;

        $response = Http::timeout(60)
            ->withToken($apiKey)
            ->post('https://api.gapgpt.app/v1/chat/completions', [
                'model' => 'gemini-2.5-flash',
                'temperature' => 0,
                'max_tokens' => 1500,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $mime . ';base64,' . $base64,
                            ],
                        ],
                    ],
                ]],
            ])
            ->throw()
            ->json();

        $text = trim((string) data_get($response, 'choices.0.message.content', ''));

        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim((string) $text);

        $json = json_decode($text, true);

        if (!is_array($json)) {
            throw new \RuntimeException('خروجی GapGPT JSON معتبر نبود');
        }

        return $json;
    }
}