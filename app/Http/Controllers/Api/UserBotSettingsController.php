<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserBotPlan;
use App\Models\UserBotSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\CryptoPaymentOption;
use App\Services\UserBotConfigurationPolicy;
use Illuminate\Validation\ValidationException;

class UserBotSettingsController extends Controller
{
    public function show(Request $request)
    {
        $owner = $this->resolveOwner($request);

        $setting = UserBotSetting::firstOrCreate(
            [
                'owner_type' => $owner['type'],
                'owner_id' => $owner['id'],
            ],
            [
                'webhook_secret' => Str::random(40),
                'owner_connect_code' => 'SBMGR-' . strtoupper(Str::random(16)),
                'active_users_only' => true,
                'is_enabled' => false,
                'card_payment_enabled' => true,
                'card_ai_auto_approve_enabled' => false,
                'card_sms_auto_approve_enabled' => false,
                'crypto_payment_enabled' => false,
                'crypto_asset' => 'USDT',
                'crypto_network' => 'TRC20',
            ]
        );

        if (empty($setting->webhook_secret) || empty($setting->owner_connect_code)) {
            $setting->update([
                'webhook_secret' => $setting->webhook_secret ?: Str::random(40),
                'owner_connect_code' => $setting->owner_connect_code ?: 'SBMGR-' . strtoupper(Str::random(16)),
            ]);
        }

        $setting->load('plans');

        if ($owner['type'] === 'partner') {
            $adminSetting = $this->adminDefaultSetting();

            if ($adminSetting) {
                foreach ([
                    'help_ios',
                    'help_android',
                    'help_windows',
                    'card_sms_shortcut_url',
                    'card_sms_android_app_url',
                ] as $field) {
                    if (
                        blank($setting->{$field})
                        && filled($adminSetting->{$field})
                    ) {
                        $setting->{$field} = $adminSetting->{$field};
                    }
                }
            }
        }

        $this->ensureDefaultCryptoOptions('user_bot', $owner['type'], $owner['id']);

        $cryptoOptions = CryptoPaymentOption::query()
            ->where('context', 'user_bot')
            ->where('owner_type', $owner['type'])
            ->where('owner_id', $owner['id'])
            ->orderBy('sort_order')
            ->get();

        $data = $setting->toArray();

        $data['crypto_options'] = $cryptoOptions->map(fn ($item) => [
            'id' => $item->id,
            'asset' => $item->asset,
            'network' => $item->network,
            'title' => $item->title ?: "{$item->asset} - {$item->network}",
            'wallet_address' => $item->wallet_address ?: '',
            'enabled' => (bool) $item->enabled,
        ])->values();

        $data['bot_token'] = !empty($setting->bot_token)
            ? $this->maskToken((string) $setting->bot_token)
            : '';

        $data['has_bot_token'] = !empty($setting->bot_token);

        $cardMethod = \App\Models\PanelPaymentMethod::where("method", "card")->first();

        $data['ai_receipt_check_fee'] = $cardMethod
            ? (int) ($cardMethod->ai_receipt_check_fee ?? 0)
            : 0;

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    public function update(Request $request)
    {
        $owner = $this->resolveOwner($request);

        $data = $request->validate([
            'bot_token' => ['nullable', 'string', 'max:500'],
            'active_users_only' => ['required', 'boolean'],
            'is_enabled' => ['required', 'boolean'],
            'card_payment_enabled' => ['required', 'boolean'],
            'card_ai_auto_approve_enabled' => [
                'required',
                'boolean',
            ],

            'card_sms_auto_approve_enabled' => [
                'required',
                'boolean',
            ],

            'card_sms_shortcut_url' => [
                'nullable',
                'string',
                'max:2048',
                'regex:/^(https?:\/\/|\/)/i',
            ],

            'card_sms_android_app_url' => [
                'nullable',
                'string',
                'max:2048',
                'regex:/^(https?:\/\/|\/)/i',
            ],

            'crypto_payment_enabled' => [
                'required',
                'boolean',
            ],
            'crypto_options' => ['nullable', 'array'],
            'crypto_options.*.asset' => ['required', 'string', 'in:USDT,TRX,BNB'],
            'crypto_options.*.network' => ['required', 'string', 'in:BEP20,TRC20'],
            'crypto_options.*.wallet_address' => ['nullable', 'string', 'max:255'],
            'crypto_options.*.enabled' => ['required', 'boolean'],
            'crypto_trongrid_api_key' => ['nullable', 'string', 'max:500'],
            'crypto_bscscan_api_key' => ['nullable', 'string', 'max:500'],
            'card_number' => ['nullable', 'string', 'max:32'],
            'card_holder' => ['nullable', 'string', 'max:120'],
            'card_description' => ['nullable', 'string'],
            'support_username' => ['nullable', 'string', 'max:160'],
            'force_channel_join_enabled' => ['required', 'boolean'],
            'force_channel_join_chat' => ['nullable', 'string', 'max:160'],
            'help_ios' => ['nullable', 'string'],
            'help_android' => ['nullable', 'string'],
            'help_windows' => ['nullable', 'string'],
            'plans' => ['nullable', 'array'],
            'plans.*.plan_key' => ['required_with:plans', 'string', 'max:80'],
            'plans.*.title' => ['required_with:plans', 'string', 'max:160'],
            'plans.*.price' => ['nullable', 'integer', 'min:0'],
            'plans.*.description' => ['nullable', 'string'],
            'plans.*.is_enabled' => ['required_with:plans', 'boolean'],
            'plans.*.group_name' => ['nullable', 'string', 'max:160'],
        ]);

        $setting = UserBotSetting::firstOrCreate(
            [
                'owner_type' => $owner['type'],
                'owner_id' => $owner['id'],
            ],
            [
                'webhook_secret' => Str::random(40),
                'owner_connect_code' => 'SBMGR-' . strtoupper(Str::random(16)),
            ]
        );

        $resolvedBotToken = $this->resolveTokenForSave(
            $setting,
            $data['bot_token'] ?? null,
            (bool) $data['is_enabled']
        );

        $forceJoinChat = $this->normalizeChannelUsername($data['force_channel_join_chat'] ?? null);
        $data['bot_token'] = $resolvedBotToken;
        $data['force_channel_join_chat'] = $forceJoinChat;
        $data = $this->withHelpDefaults($data, $owner['type']);
        $this->validateActiveConfiguration($data, $owner['type'] === 'partner');

        $cardSmsShortcutUrl = $owner['type'] === 'admin'
            ? trim(
                (string) (
                    $data['card_sms_shortcut_url']
                    ?? ''
                )
            )
            : trim(
                (string) (
                    $setting->card_sms_shortcut_url
                    ?? ''
                )
            );

        $cardSmsAndroidAppUrl = $owner['type'] === 'admin'
            ? trim(
                (string) (
                    $data['card_sms_android_app_url']
                    ?? ''
                )
            )
            : trim(
                (string) (
                    $setting->card_sms_android_app_url
                    ?? ''
                )
            );

        $cardSmsShortcutUrl = $cardSmsShortcutUrl !== ''
            ? $cardSmsShortcutUrl
            : null;

        $cardSmsAndroidAppUrl = $cardSmsAndroidAppUrl !== ''
            ? $cardSmsAndroidAppUrl
            : null;

        foreach (($data['crypto_options'] ?? []) as $option) {
            $pair = strtoupper($option['asset']) . '_' . strtoupper($option['network']);

            if (!in_array($pair, ['USDT_BEP20', 'TRX_TRC20', 'BNB_BEP20'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ترکیب ارز و شبکه معتبر نیست',
                ], 422);
            }
        }

        DB::transaction(function () use (
            $setting,
            $data,
            $forceJoinChat,
            $resolvedBotToken,
            $cardSmsShortcutUrl,
            $cardSmsAndroidAppUrl
        ) {
            $setting->update([
                'bot_token' => $resolvedBotToken,
                'active_users_only' => (bool) $data['active_users_only'],
                'is_enabled' => (bool) $data['is_enabled'],
                'card_payment_enabled' => (bool) $data['card_payment_enabled'],
                'card_ai_auto_approve_enabled' => (bool) $data['card_ai_auto_approve_enabled'],
                'card_sms_auto_approve_enabled' => (bool) $data['card_sms_auto_approve_enabled'],
                'card_sms_shortcut_url' =>
                    $cardSmsShortcutUrl,

                'card_sms_android_app_url' =>
                    $cardSmsAndroidAppUrl,
                'crypto_payment_enabled' => (bool) $data['crypto_payment_enabled'],
                'crypto_asset' => 'USDT',
                'crypto_network' => 'BEP20',
                'crypto_wallet_address' => null,
                'crypto_trongrid_api_key' => $data['crypto_trongrid_api_key'] ?? null,
                'crypto_bscscan_api_key' => $data['crypto_bscscan_api_key'] ?? null,
                'card_number' => $data['card_number'] ?? null,
                'card_holder' => $data['card_holder'] ?? null,
                'card_description' => $data['card_description'] ?? null,
                'support_username' => $this->normalizeSupportUsername($data['support_username'] ?? null),
                'force_channel_join_enabled' => (bool) $data['force_channel_join_enabled'],
                'force_channel_join_chat' => $forceJoinChat,
                'help_ios' => $data['help_ios'],
                'help_android' => $data['help_android'],
                'help_windows' => $data['help_windows'],                
                'webhook_secret' => $setting->webhook_secret ?: Str::random(40),
                'owner_connect_code' => $setting->owner_connect_code ?: 'SBMGR-' . strtoupper(Str::random(16)),
            ]);

            $this->ensureDefaultCryptoOptions('user_bot', $setting->owner_type, $setting->owner_id);

            foreach (($data['crypto_options'] ?? []) as $option) {
                CryptoPaymentOption::updateOrCreate(
                    [
                        'context' => 'user_bot',
                        'owner_type' => $setting->owner_type,
                        'owner_id' => $setting->owner_id,
                        'asset' => $option['asset'],
                        'network' => $option['network'],
                    ],
                    [
                        'title' => "{$option['asset']} - {$option['network']}",
                        'wallet_address' => trim((string) ($option['wallet_address'] ?? '')),
                        'enabled' => (bool) $option['enabled'],
                        'sort_order' => match ($option['asset']) {
                            'USDT' => 1,
                            'TRX' => 2,
                            'BNB' => 3,
                            default => 99,
                        },
                    ]
                );
            }

            $incomingPlanKeys = [];

            foreach (($data['plans'] ?? []) as $plan) {
                $incomingPlanKeys[] = $plan['plan_key'];

                $price = (int) ($plan['price'] ?? 0);
                $isEnabled = (bool) ($plan['is_enabled'] ?? false);

                UserBotPlan::updateOrCreate(
                    [
                        'setting_id' => $setting->id,
                        'plan_key' => $plan['plan_key'],
                    ],
                    [
                        'group_name' => $plan['group_name'] ?? null,
                        'title' => $plan['title'],
                        'price' => $price,
                        'description' => $plan['description'] ?? null,
                        'is_enabled' => $isEnabled,
                    ]
                );
            }

            if (!empty($incomingPlanKeys)) {
                UserBotPlan::where('setting_id', $setting->id)
                    ->whereNotIn('plan_key', $incomingPlanKeys)
                    ->delete();
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => $setting->is_enabled
                ? 'تنظیمات ربات کاربران ذخیره شد'
                : 'تنظیمات ربات کاربران ذخیره شد؛ ربات غیرفعال است',
            'data' => ['owner_connect_code' => $setting->owner_connect_code, 'is_enabled' => (bool) $setting->is_enabled],
        ]);
    }

    public function toggleSetting(Request $request)
    {
        $owner = $this->resolveOwner($request);

        $data = $request->validate([
            'field' => ['required', 'string', 'in:is_enabled,active_users_only,force_channel_join_enabled,card_payment_enabled,card_ai_auto_approve_enabled,card_sms_auto_approve_enabled,crypto_payment_enabled'],
            'value' => ['required', 'boolean'],
        ]);

        $setting = UserBotSetting::firstOrCreate(
            [
                'owner_type' => $owner['type'],
                'owner_id' => $owner['id'],
            ],
            [
                'webhook_secret' => Str::random(40),
                'owner_connect_code' => 'SBMGR-' . strtoupper(Str::random(16)),
                'active_users_only' => true,
                'is_enabled' => false,
            ]
        );

        $effective = array_merge($setting->toArray(), [$data['field'] => (bool) $data['value']]);
        // Use the decrypted token, never its masked display value.
        $effective['bot_token'] = $setting->bot_token;
        $effective['crypto_options'] = CryptoPaymentOption::query()
            ->where('context', 'user_bot')->where('owner_type', $owner['type'])
            ->where('owner_id', $owner['id'])->get()->toArray();
        $effective['plans'] = $setting->plans()->get()->toArray();
        if ($data['field'] === 'card_payment_enabled' && !(bool) $data['value']) {
            $effective['card_sms_auto_approve_enabled'] = false;
            $effective['card_ai_auto_approve_enabled'] = false;
        }
        $this->validateActiveConfiguration($this->withHelpDefaults($effective, $owner['type']));

        $updates = [
            $data['field'] => (bool) $data['value'],
        ];

        if ($data['field'] === 'card_payment_enabled' && (bool) $data['value'] === false) {
            $updates['card_sms_auto_approve_enabled'] = false;
            $updates['card_ai_auto_approve_enabled'] = false;
        }

        $setting->update($updates);

        $labels = [
            'is_enabled' => 'ربات',
            'active_users_only' => 'ورود اجباری کاربران',
            'force_channel_join_enabled' => 'عضویت اجباری کانال',
            'card_payment_enabled' => 'پرداخت کارت به کارت',
            'card_ai_auto_approve_enabled' => 'تایید خودکار رسید با هوش مصنوعی',
            'card_sms_auto_approve_enabled' => 'تأیید آنی پرداخت با پیامک بانکی',
            'crypto_payment_enabled' => 'پرداخت ارز دیجیتال',
        ];

        $label = $labels[$data['field']] ?? 'تنظیمات';

        return response()->json([
            'status' => 'success',
            'message' => ((bool) $data['value'])
                ? "{$label} با موفقیت فعال شد"
                : "{$label} با موفقیت غیرفعال شد",
            'data' => ['is_enabled' => (bool) $setting->is_enabled],
        ]);
    }

    public function togglePlan(string $planKey, Request $request)
    {
        $owner = $this->resolveOwner($request);

        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
        ]);

        $setting = UserBotSetting::where('owner_type', $owner['type'])
            ->where('owner_id', $owner['id'])
            ->first();

        if (!$setting) {
            return response()->json([
                'status' => 'error',
                'message' => 'ابتدا تنظیمات ربات را ذخیره کنید',
            ], 422);
        }

        $plan = UserBotPlan::where('setting_id', $setting->id)
            ->where('plan_key', $planKey)
            ->first();

        if (!$plan) {
            return response()->json([
                'status' => 'error',
                'message' => 'ابتدا تعرفه‌های ربات را ذخیره کنید',
            ], 422);
        }

        if ($setting->is_enabled && (bool) $data['is_enabled'] && (int) $plan->price <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'برای فعال‌سازی طرح، ابتدا قیمت آن را تعیین و ذخیره کنید',
            ], 422);
        }

        $plan->update([
            'is_enabled' => (bool) $data['is_enabled'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => ((bool) $data['is_enabled'])
                ? 'طرح با موفقیت فعال شد'
                : 'طرح با موفقیت غیرفعال شد',
                'data' => $plan->refresh(),
        ]);
    }

    public function setWebhook(Request $request)
    {
        $owner = $this->resolveOwner($request);

        $setting = UserBotSetting::where('owner_type', $owner['type'])
            ->where('owner_id', $owner['id'])
            ->first();

        if (!$setting || !$setting->bot_token) {
            return response()->json([
                'status' => 'error',
                'message' => 'ابتدا توکن ربات را ذخیره کنید',
            ], 422);
        }

        if (!$setting->webhook_secret) {
            $setting->update(['webhook_secret' => Str::random(40)]);
            $setting->refresh();
        }

        $baseUrl = rtrim(config('app.url'), '/');

        if (!$baseUrl || !str_starts_with($baseUrl, 'https://')) {
            return response()->json([
                'status' => 'error',
                'message' => 'APP_URL باید با https تنظیم شده باشد',
            ], 422);
        }

        $webhookUrl = $baseUrl . '/api/user-bot/webhook/' .
            $setting->owner_type . '/' .
            $setting->owner_id . '/' .
            $setting->webhook_secret;

        $res = Http::timeout(20)->post(
            "https://api.telegram.org/bot{$setting->bot_token}/setWebhook",
            [
                'url' => $webhookUrl,
                'drop_pending_updates' => true,
                'allowed_updates' => ['message', 'callback_query'],
            ]
        );

        $body = $res->json();

        if (!$res->ok() || !($body['ok'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => $body['description'] ?? 'خطا در تنظیم Webhook تلگرام',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook ربات با موفقیت تنظیم شد',
        ]);
    }

    public function deleteWebhook(Request $request)
    {
        $owner = $this->resolveOwner($request);

        $setting = UserBotSetting::where('owner_type', $owner['type'])
            ->where('owner_id', $owner['id'])
            ->first();

        if (!$setting || !$setting->bot_token) {
            return response()->json([
                'status' => 'error',
                'message' => 'توکن ربات ذخیره نشده است',
            ], 422);
        }

        $res = Http::timeout(20)->post(
            "https://api.telegram.org/bot{$setting->bot_token}/deleteWebhook",
            ['drop_pending_updates' => true]
        );

        $body = $res->json();

        if (!$res->ok() || !($body['ok'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => $body['description'] ?? 'خطا در حذف Webhook تلگرام',
            ], 422);
        }

        $setting->update([
            'bot_token' => null,
            'is_enabled' => false,
            'owner_telegram_chat_id' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook ربات حذف شد',
        ]);
    }

    public function regenerateOwnerCode(Request $request)
    {
        $owner = $this->resolveOwner($request);

        $setting = UserBotSetting::firstOrCreate(
            [
                'owner_type' => $owner['type'],
                'owner_id' => $owner['id'],
            ],
            [
                'webhook_secret' => Str::random(40),
                'active_users_only' => true,
                'is_enabled' => false,
            ]
        );

        $setting->update([
            'owner_connect_code' => 'SBMGR-' . strtoupper(Str::random(16)),
            'owner_telegram_chat_id' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'کد اتصال مدیر جدید ساخته شد',
            'code' => $setting->owner_connect_code,
        ]);
    }

    private function withHelpDefaults(array $data, string $ownerType): array
    {
        $defaults = $ownerType === 'partner' ? $this->adminDefaultSetting() : null;
        foreach (['help_ios', 'help_android', 'help_windows'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $data[$field] = $value !== '' ? $value : ($defaults->{$field} ?? null);
        }
        return $data;
    }

    private function validateActiveConfiguration(array $data, bool $requireComplete = false): void
    {
        $errors = app(UserBotConfigurationPolicy::class)->errors($data, $requireComplete);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if (empty($data['is_enabled']) && !$requireComplete) {
            return;
        }
        if (!empty($data['card_ai_auto_approve_enabled'])) {
            $method = \App\Models\PanelPaymentMethod::where('method', 'card')->first();
            if (!$method || !$method->ai_auto_approve_enabled || trim((string) $method->gemini_api_key) === '' || (int) $method->ai_receipt_check_fee <= 0) {
                throw ValidationException::withMessages(['card_ai_auto_approve_enabled' => 'قابلیت تأیید خودکار رسید با هوش مصنوعی فعال نیست']);
            }
        }
        if (!empty($data['force_channel_join_enabled'])) {
            $chat = $this->normalizeChannelUsername($data['force_channel_join_chat'] ?? null);
            $check = $this->checkBotIsAdminInChannel((string) $data['bot_token'], (string) $chat);
            if (!$check['ok']) {
                throw ValidationException::withMessages(['force_channel_join_chat' => $check['message']]);
            }
        }
    }

    private function maskToken(?string $token): string
    {
        $token = trim((string) $token);

        if ($token === '') {
            return '';
        }

        $length = mb_strlen($token);

        if ($length <= 12) {
            return $token;
        }

        return mb_substr($token, 0, 8) . '********' . mb_substr($token, -6);
    }

    private function resolveTokenForSave(UserBotSetting $setting, ?string $incomingToken, bool $isEnabled): ?string
    {
        $incomingToken = trim((string) $incomingToken);

        if ($incomingToken === '') {
            return $setting->bot_token;
        }

        if (str_contains($incomingToken, '*')) {
            return $setting->bot_token;
        }

        return $incomingToken;
    }

    private function normalizeSupportUsername(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('#^https?://t\.me/#i', '', $value);
        $value = preg_replace('#^t\.me/#i', '', $value);
        $value = ltrim($value, '@/');

        $value = trim($value);

        return $value !== '' ? $value : null;
    }    

    private function normalizeChannelUsername(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = str_replace(['https://t.me/', 'http://t.me/', 't.me/'], '', $value);
        $value = trim($value, " \t\n\r\0\x0B/");

        return '@' . ltrim($value, '@');
    }

    private function checkBotIsAdminInChannel(string $botToken, string $channel): array
    {
        try {
            $me = Http::timeout(15)->post("https://api.telegram.org/bot{$botToken}/getMe")->json();

            $botId = data_get($me, 'result.id');

            if (!$botId) {
                return ['ok' => false, 'message' => 'توکن ربات معتبر نیست'];
            }

            $res = Http::timeout(15)->post("https://api.telegram.org/bot{$botToken}/getChatMember", [
                'chat_id' => $channel,
                'user_id' => $botId,
            ])->json();

            $status = data_get($res, 'result.status');

            if (in_array($status, ['administrator', 'creator'], true)) {
                return ['ok' => true, 'message' => 'ok'];
            }

            return ['ok' => false, 'message' => 'ربات در کانال تلگرام شما ادمین نیست'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'ربات در کانال تلگرام شما ادمین نیست یا کانال پیدا نشد'];
        }
    }

    private function adminDefaultSetting(): ?UserBotSetting
    {
        $adminIds = DB::table('panel_users')
            ->where('role', 1)
            ->pluck('id');

        if ($adminIds->isEmpty()) {
            return null;
        }

        return UserBotSetting::where('owner_type', 'admin')
            ->whereIn('owner_id', $adminIds)
            ->where(function ($q) {
                $q->whereNotNull('help_ios')
                    ->orWhereNotNull('help_android')
                    ->orWhereNotNull('help_windows')
                    ->orWhereNotNull('card_sms_shortcut_url')
                    ->orWhereNotNull('card_sms_android_app_url');
            })
            ->orderByDesc('updated_at')
            ->first();
    }

    private function resolveOwner(Request $request): array
    {
        $user = $request->user();

        if (!$user) {
            abort(response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401));
        }

        $panelUser = DB::table('panel_users')
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
            ->first();

        if (!$panelUser) {
            abort(response()->json(['status' => 'error', 'message' => 'Panel user not found'], 404));
        }

        return [
            'type' => ((int) ($panelUser->role ?? 0) === 1) ? 'admin' : 'partner',
            'id' => (int) $panelUser->id,
        ];
    }

    private function ensureDefaultCryptoOptions(string $context, string $ownerType, ?int $ownerId): void
    {
        $defaults = [
            [
                'asset' => 'USDT',
                'network' => 'BEP20',
                'title' => 'USDT - BEP20',
                'sort_order' => 1,
            ],
            [
                'asset' => 'TRX',
                'network' => 'TRC20',
                'title' => 'TRX - TRC20',
                'sort_order' => 2,
            ],
            [
                'asset' => 'BNB',
                'network' => 'BEP20',
                'title' => 'BNB - BEP20',
                'sort_order' => 3,
            ],            
        ];

        foreach ($defaults as $row) {
            CryptoPaymentOption::firstOrCreate(
                [
                    'context' => $context,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'asset' => $row['asset'],
                    'network' => $row['network'],
                ],
                [
                    'title' => $row['title'],
                    'wallet_address' => null,
                    'enabled' => false,
                    'sort_order' => $row['sort_order'],
                ]
            );
        }
    }

}
