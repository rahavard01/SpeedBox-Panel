<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PanelBotSession;
use App\Models\UserBotSession;
use App\Models\UserBotSetting;
use App\Services\TelegramConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BotBroadcastController extends Controller
{
    public function userBot(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $owner = $this->resolveOwner($request);

        $setting = UserBotSetting::where('owner_type', $owner['type'])
            ->where('owner_id', $owner['id'])
            ->first();

        if (!$setting || empty($setting->bot_token)) {
            return response()->json([
                'status' => 'error',
                'message' => 'توکن ربات کاربران تنظیم نشده است',
            ], 422);
        }

        $chatIds = UserBotSession::where('setting_id', $setting->id)
            ->whereNotNull('telegram_chat_id')
            ->when(!empty($setting->owner_telegram_chat_id), function ($q) use ($setting) {
                $q->where('telegram_chat_id', '!=', (string) $setting->owner_telegram_chat_id);
            })
            ->pluck('telegram_chat_id')
            ->unique()
            ->values();

        $sent = 0;
        $failed = 0;

        foreach ($chatIds as $chatId) {
            try {
                $res = Http::timeout(15)->post("https://api.telegram.org/bot{$setting->bot_token}/sendMessage", [
                    'chat_id' => (string) $chatId,
                    'text' => $data['message'],
                    'disable_web_page_preview' => true,
                ]);

                data_get($res->json(), 'ok') ? $sent++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        return response()->json([
            'status' => 'success',
            'sent' => $sent,
            'failed' => $failed,
            'message' => "پیام گروهی با موفقیت برای {$sent} اکانت تلگرام ارسال شد",
        ]);
    }

    public function partnersBot(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $token = TelegramConfig::token();

        if (!$token) {
            return response()->json([
                'status' => 'error',
                'message' => 'توکن ربات نمایندگان تنظیم نشده است',
            ], 422);
        }

        $panelUserId = (int) $this->resolveOwner($request)['id'];

        $chatIds = PanelBotSession::whereNotNull('chat_id')
            ->where(function ($q) use ($panelUserId) {
                $q->whereNull('panel_user_id')
                    ->orWhere('panel_user_id', '!=', $panelUserId);
            })
            ->pluck('chat_id')
            ->unique()
            ->values();

        $sent = 0;
        $failed = 0;

        foreach ($chatIds as $chatId) {
            try {
                $res = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => (string) $chatId,
                    'text' => $data['message'],
                    'disable_web_page_preview' => true,
                ]);

                data_get($res->json(), 'ok') ? $sent++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        return response()->json([
            'status' => 'success',
            'sent' => $sent,
            'failed' => $failed,
            'message' => "پیام گروهی با موفقیت برای {$sent} اکانت تلگرام ارسال شد",
        ]);
    }

    private function resolveOwner(Request $request): array
    {
        $user = $request->user();

        $panelUser = DB::table('panel_users')
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
            ->first();

        return [
            'type' => ((int) ($panelUser->role ?? 0) === 1) ? 'admin' : 'partner',
            'id' => (int) $panelUser->id,
        ];
    }
}