<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\PanelBotSetting;
use App\Services\PartnerBotEntry;
use App\Services\TelegramConfig;

class BotAdminController extends Controller
{
    /**
     * GET /api/admin/bot/status
     * وضعیت فعلی: پریویوی امن توکن/سکرت + وضعیت وبهوک
     */
    public function status(Request $request)
    {
        $token  = (string) (TelegramConfig::token() ?? '');
        $secret = (string) (TelegramConfig::webhookSecret() ?? '');

        $mask = function (?string $val, int $keepStart = 5, int $keepEnd = 4) {
            $s = (string) ($val ?? '');
            $len = mb_strlen($s);
            if ($len === 0) return '';
            if ($len <= $keepStart + $keepEnd) return $s;
            return mb_substr($s, 0, $keepStart) . '…' . mb_substr($s, -$keepEnd);
        };

        $webhookUrl = TelegramConfig::webhookUrl($request->getSchemeAndHttpHost());

        $webhookSet  = false;
        $webhookInfo = null;
        if (trim($token) !== '') {
            try {
                $api  = "https://api.telegram.org/bot{$token}";
                $resp = Http::timeout(8)->get("{$api}/getWebhookInfo");
                if ($resp->ok()) {
                    $j = $resp->json();
                    $webhookInfo = $j;
                    $webhookSet  = (bool) data_get($j, 'result.url');
                }
            } catch (\Throwable) {
                $webhookInfo = ['error' => 'پاسخ تلگرام در دسترس نیست'];
            }
        }

        return response()->json([
            'ok'             => true,
            'has_token'      => trim($token) !== '',
            'token_preview'  => trim($token) ? $mask($token) : '',
            'secret_preview' => trim($secret) ? $mask($secret, 4, 3) : '',
            'webhook_url'    => $webhookUrl,
            'webhook_set'    => $webhookSet,
            'webhook_info'   => $webhookInfo,
            'bot_url'        => data_get($webhookInfo, 'result.url') === $webhookUrl
                ? PartnerBotEntry::configuredUrl($request->getSchemeAndHttpHost())
                : null,
        ]);
    }

    /**
     * POST /api/admin/bot/set-webhook
     * Body: { "token"?: "xxxxx:yyyyy" }
     *
     * اگر token در بدنه نیاید، از مقدار فعلی DB/fallback استفاده می‌شود.
     * secret اگر خالی باشد، ساخته و در DB ذخیره می‌شود.
     */
    public function setWebhook(Request $request)
    {
        $result = Cache::lock(PartnerBotEntry::SETTINGS_LOCK, 30)->get(function () use ($request) {
            $token = trim((string) $request->input('token', TelegramConfig::token() ?? ''));
            if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/D', $token)) {
                return response()->json(['ok' => false, 'message' => 'توکن معتبر ربات را وارد کنید'], 422);
            }

            $secret = trim((string) (TelegramConfig::webhookSecret() ?? '')) ?: Str::random(48);
            $url = TelegramConfig::webhookUrl($request->getSchemeAndHttpHost());

            try {
                $botUrl = PartnerBotEntry::fetchPublicUrl($token);
                if ($botUrl === null) {
                    return response()->json(['ok' => false, 'message' => 'نام کاربری ربات از تلگرام تأیید نشد'], 422);
                }

                $webhook = Http::timeout(8)->asForm()->post("https://api.telegram.org/bot{$token}/setWebhook", [
                    'url'                  => $url,
                    'secret_token'         => $secret,
                    'allowed_updates'      => json_encode(['message', 'callback_query'], JSON_UNESCAPED_UNICODE),
                    'drop_pending_updates' => true,
                ]);
                if (!$webhook->ok() || $webhook->json('ok') !== true) {
                    return response()->json(['ok' => false, 'message' => 'تلگرام تنظیم وبهوک را تأیید نکرد'], 422);
                }
            } catch (\Throwable) {
                return response()->json(['ok' => false, 'message' => 'خطا در ارتباط با تلگرام هنگام تنظیم وبهوک'], 422);
            }

            $setting = PanelBotSetting::getSingleton();
            $setting->bot_token = $token;
            $setting->webhook_secret = $secret;
            $setting->enabled = true;
            $setting->save();

            PartnerBotEntry::remember($token, $url, $botUrl);

            return response()->json([
                'ok'      => true,
                'message' => 'وبهوک با موفقیت تنظیم شد',
                'webhook' => $url,
                'bot_url' => $botUrl,
            ]);
        });

        return $result ?: response()->json(['ok' => false, 'message' => 'تنظیمات ربات در حال بررسی است؛ دوباره تلاش کنید'], 409);
    }

    /**
     * POST /api/admin/bot/remove-webhook
     */
    public function removeWebhook()
    {
        $result = Cache::lock(PartnerBotEntry::SETTINGS_LOCK, 30)->get(function () {
            $token = (string) (TelegramConfig::token() ?? '');
            if (trim($token) !== '') {
                try {
                    Http::timeout(8)->asForm()->post("https://api.telegram.org/bot{$token}/deleteWebhook", [
                        'drop_pending_updates' => true,
                    ])->throw();
                } catch (\Throwable) {
                    // The panel settings are removed even if Telegram is unreachable.
                }
            }

            $setting = PanelBotSetting::getSingleton();
            $setting->bot_token = null;
            $setting->webhook_secret = null;
            $setting->enabled = false;
            $setting->save();

            return response()->json(['ok' => true, 'message' => 'وبهوک حذف شد']);
        });

        return $result ?: response()->json(['ok' => false, 'message' => 'تنظیمات ربات در حال بررسی است؛ دوباره تلاش کنید'], 409);
    }
}
