<?php

namespace App\Services\UserBot;

use Illuminate\Support\Facades\Http;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

class UserBotTelegram
{
    public function __construct(private string $token) {}

    public function sendMessage(
        string|int $chatId,
        string $text,
        ?array $keyboard = null
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $payload['reply_markup'] = $keyboard;
        }

        return $this->request(
            'sendMessage',
            $payload
        );
    }

    public function sendPlainMessage(string|int $chatId, string $text, ?array $keyboard = null): ?array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($keyboard) {
            $payload['reply_markup'] = $keyboard;
        }

        return Http::timeout(15)->post($this->apiUrl('sendMessage'), $payload)->json();
    }

    public function sendPhoto(
        string|int $chatId,
        string $photoFileId,
        string $caption,
        ?array $keyboard = null
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'photo' => $photoFileId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $payload['reply_markup'] = $keyboard;
        }

        return $this->request(
            'sendPhoto',
            $payload
        );
    }

    public function sendQrPhotoOrMessage(
        string|int $chatId,
        string $qrText,
        string $caption,
        ?array $keyboard = null
    ): array {
        $tmp = null;

        try {
            if (trim($qrText) === '') {
                return $this->sendMessage(
                    $chatId,
                    $caption,
                    $keyboard
                );
            }

            $result = Builder::create()
                ->writer(new PngWriter())
                ->data($qrText)
                ->size(800)
                ->margin(20)
                ->build();

            $tmp = tempnam(
                sys_get_temp_dir(),
                'sb_qr_'
            ) . '.png';

            $result->saveToFile($tmp);

            $payload = [
                'chat_id' => $chatId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ];

            if ($keyboard) {
                $payload['reply_markup'] =
                    json_encode(
                        $keyboard,
                        JSON_UNESCAPED_UNICODE
                    );
            }

            $handle = fopen($tmp, 'rb');

            if ($handle === false) {
                throw new \RuntimeException(
                    'فایل QR قابل خواندن نیست'
                );
            }

            $response = Http::timeout(20)
                ->attach(
                    'photo',
                    $handle,
                    'qrcode.png'
                )
                ->post(
                    $this->apiUrl('sendPhoto'),
                    $payload
                );

            fclose($handle);

            $response->throw();

            $data = $response->json();

            if (
                !is_array($data)
                || empty($data['ok'])
            ) {
                $description = is_array($data)
                    ? (string) (
                        $data['description']
                        ?? 'پاسخ نامعتبر تلگرام'
                    )
                    : 'پاسخ نامعتبر تلگرام';

                throw new \RuntimeException(
                    $description
                );
            }

            return $data;
        } catch (\Throwable $e) {
            return $this->sendMessage(
                $chatId,
                $caption,
                $keyboard
            );
        } finally {
            if (
                is_string($tmp)
                && is_file($tmp)
            ) {
                @unlink($tmp);
            }
        }
    }

    private function apiUrl(string $method): string
    {
        return "https://api.telegram.org/bot{$this->token}/{$method}";
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): void
    {
        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text !== '') {
            $payload['text'] = $text;
            $payload['show_alert'] = $showAlert;
        }

        $this->post('answerCallbackQuery', $payload);
    }

    public function editMessageReplyMarkup(string|int $chatId, string|int $messageId): void
    {
        $this->post('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
        ]);
    } 

    public function editMessageText(string|int $chatId, string|int $messageId, string $text, ?array $keyboard = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }

        $this->post('editMessageText', $payload);
    }

    public function deleteMessage(string|int $chatId, string|int $messageId): void
    {
        $this->post('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    private function post(
        string $method,
        array $payload
    ): void {
        $this->request($method, $payload);
    }

    private function request(
        string $method,
        array $payload
    ): array {
        $response = Http::timeout(15)
            ->post(
                $this->apiUrl($method),
                $payload
            );

        $response->throw();

        $data = $response->json();

        if (
            !is_array($data)
            || empty($data['ok'])
        ) {
            $description = is_array($data)
                ? (string) (
                    $data['description']
                    ?? 'پاسخ نامعتبر تلگرام'
                )
                : 'پاسخ نامعتبر تلگرام';

            throw new \RuntimeException(
                $description
            );
        }

        return $data;
    }
}