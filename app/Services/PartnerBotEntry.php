<?php

namespace App\Services;

use App\Models\PanelBotSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class PartnerBotEntry
{
    public const SETTINGS_LOCK = 'partner-bot:settings';

    public static function forPartner(?object $user, string $host): ?string
    {
        if ((int) ($user->role ?? 0) !== 2
            || ($user->partner_bot_has_logged_in ?? false)
            || ($user->telegram_user_id ?? null)) {
            return null;
        }

        return self::configuredUrl($host);
    }

    public static function configuredUrl(?string $host = null): ?string
    {
        try {
            $setting = PanelBotSetting::first();
            if (!$setting?->enabled || !($token = TelegramConfig::token($setting))) {
                return null;
            }
            $webhook = TelegramConfig::webhookUrl($host);
            $key = self::cacheKey($token, $webhook);
            $url = Cache::get($key);

            if (is_string($url)) {
                return $url === '' ? null : $url;
            }

            // Older setups have no cached username. Only one request checks Telegram.
            $url = Cache::lock(self::SETTINGS_LOCK, 30)->get(function () use ($host) {
                $setting = PanelBotSetting::first();
                if (!$setting?->enabled || !($token = TelegramConfig::token($setting))) {
                    return null;
                }
                $webhook = TelegramConfig::webhookUrl($host);
                $key = self::cacheKey($token, $webhook);
                $url = Cache::get($key);
                if (is_string($url)) {
                    return $url === '' ? null : $url;
                }

                try {
                    $info = Http::timeout(3)->get("https://api.telegram.org/bot{$token}/getWebhookInfo");
                    $url = $info->ok() && $info->json('ok') === true && $info->json('result.url') === $webhook
                        ? self::fetchPublicUrl($token)
                        : null;
                } catch (\Throwable) {
                    $url = null;
                }

                Cache::put($key, $url ?? '', $url === null ? 60 : 600);
                return $url;
            });

            return is_string($url) ? $url : null;
        } catch (\Throwable) {
            // A failed discovery must not affect the dashboard cards.
            return null;
        }
    }

    public static function fetchPublicUrl(string $token): ?string
    {
        $response = Http::timeout(3)->get("https://api.telegram.org/bot{$token}/getMe");
        $username = $response->json('result.username');

        if (!$response->ok() || $response->json('ok') !== true || $response->json('result.is_bot') !== true
            || !is_string($username) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/D', $username)) {
            return null;
        }

        return 'https://t.me/' . $username;
    }

    public static function remember(string $token, string $webhook, string $url): void
    {
        try {
            Cache::put(self::cacheKey($token, $webhook), $url, 600);
        } catch (\Throwable) {
            // A valid setup can be rediscovered when the cache is available.
        }
    }

    private static function cacheKey(string $token, string $webhook): string
    {
        return 'partner-bot:url:' . hash('sha256', $token . '|' . $webhook);
    }
}
