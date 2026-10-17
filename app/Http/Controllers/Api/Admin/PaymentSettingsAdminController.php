<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PanelCryptoWalletSetting;
use App\Models\PanelPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\CryptoPaymentOption;
use App\Models\UserBotSetting;

class PaymentSettingsAdminController extends Controller
{
    public function index()
    {
        $card = PanelPaymentMethod::firstOrCreate(
            ['method' => 'card'],
            ['enabled' => true, 'access_mode' => 'all', 'partner_ids' => null]
        );

        $crypto = PanelPaymentMethod::firstOrCreate(
            ['method' => 'crypto'],
            ['enabled' => false, 'access_mode' => 'all', 'partner_ids' => null]
        );

        $cryptoWallet = PanelCryptoWalletSetting::query()->first();

        if (!$cryptoWallet) {
            $cryptoWallet = PanelCryptoWalletSetting::create([
                'asset' => 'USDT',
                'network' => 'TRC20',
                'wallet_address' => null,
            ]);
        }

        $this->ensureDefaultCryptoOptions('panel_wallet', 'admin', null);

        $cryptoOptions = CryptoPaymentOption::query()
            ->where('context', 'panel_wallet')
            ->where('owner_type', 'admin')
            ->whereNull('owner_id')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => 'ok',
            'data' => [
                'methods' => [
                    'card' => [
                        'enabled' => (bool) $card->enabled,
                        'access_mode' => $card->access_mode ?: 'all',
                        'partner_ids' => $card->partner_ids ?: [],
                        'ai_auto_approve_enabled' => (bool) ($card->ai_auto_approve_enabled ?? false),
                        'sms_auto_approve_enabled' => (bool) ($card->sms_auto_approve_enabled ?? false),
                        'gemini_api_key' => $card->gemini_api_key ?: '',
                        'ai_receipt_check_fee' => $card->ai_receipt_check_fee,
                    ],
                    'crypto' => [
                        'enabled' => (bool) $crypto->enabled,
                        'access_mode' => $crypto->access_mode ?: 'all',
                        'partner_ids' => $crypto->partner_ids ?: [],
                    ],
                ],
                'crypto_wallet' => [
                    'asset' => $cryptoWallet->asset ?: 'USDT',
                    'network' => $cryptoWallet->network ?: 'TRC20',
                    'wallet_address' => $cryptoWallet->wallet_address ?: '',
                    'trongrid_api_key' => $cryptoWallet->trongrid_api_key ?: '',
                    'bscscan_api_key' => $cryptoWallet->bscscan_api_key ?: '',
                ],

                'crypto_options' => $cryptoOptions->map(fn ($item) => [
                    'id' => $item->id,
                    'asset' => $item->asset,
                    'network' => $item->network,
                    'title' => $item->title ?: "{$item->asset} - {$item->network}",
                    'wallet_address' => $item->wallet_address ?: '',
                    'enabled' => (bool) $item->enabled,
                ])->values(),

            ],
        ]);
    }

    public function update(Request $request)
    {
        $section = $request->validate(['section' => ['sometimes', Rule::in(['all', 'payment', 'crypto'])]])['section'] ?? 'all';
        $rules = [
            'methods.card.enabled' => ['required', 'boolean'],
            'methods.card.sms_auto_approve_enabled' => ['required', 'boolean'],
            'methods.card.ai_auto_approve_enabled' => ['required', 'boolean'],
            'methods.card.gemini_api_key' => ['nullable', 'string', 'max:1000'],
            'methods.card.ai_receipt_check_fee' => ['nullable', 'integer', 'min:0'],
            'methods.card.access_mode' => ['required', Rule::in(['all', 'selected'])],
            'methods.card.partner_ids' => ['nullable', 'array'],
            'methods.card.partner_ids.*' => [
                'integer',
                Rule::exists('panel_users', 'id')->where(fn ($q) => $q->where('role', 2)),
            ],

            'methods.crypto.enabled' => ['required', 'boolean'],
            'methods.crypto.access_mode' => ['required', Rule::in(['all', 'selected'])],
            'methods.crypto.partner_ids' => ['nullable', 'array'],
            'methods.crypto.partner_ids.*' => [
                'integer',
                Rule::exists('panel_users', 'id')->where(fn ($q) => $q->where('role', 2)),
            ],

            'crypto_wallet.asset' => ['required', Rule::in(['USDT', 'TRX', 'BNB'])],
            'crypto_wallet.network' => ['required', Rule::in(['TRC20', 'BEP20'])],
            'crypto_wallet.wallet_address' => ['nullable', 'string', 'max:255'],
            'crypto_wallet.trongrid_api_key' => ['nullable', 'string', 'max:500'],
            'crypto_wallet.bscscan_api_key' => ['nullable', 'string', 'max:500'],
            'crypto_options' => ['nullable', 'array'],
            'crypto_options.*.asset' => ['required', Rule::in(['USDT', 'TRX', 'BNB'])],
            'crypto_options.*.network' => ['required', Rule::in(['BEP20', 'TRC20'])],
            'crypto_options.*.wallet_address' => ['nullable', 'string', 'max:255'],
            'crypto_options.*.enabled' => ['required', 'boolean'],
        ];
        if ($section !== 'all') {
            $rules = array_filter($rules, fn ($key) => $section === 'payment'
                ? str_starts_with($key, 'methods.')
                : str_starts_with($key, 'crypto_'), ARRAY_FILTER_USE_KEY);
        }
        $validated = $request->validate($rules);

        foreach (($validated['crypto_options'] ?? []) as $option) {
            $pair = strtoupper($option['asset']) . '_' . strtoupper($option['network']);

            if (!in_array($pair, ['USDT_BEP20', 'TRX_TRC20', 'BNB_BEP20'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ترکیب ارز و شبکه معتبر نیست',
                ], 422);
            }
        }

        $wasCryptoEnabled = (bool) PanelPaymentMethod::where('method', 'crypto')->value('enabled');
        $cryptoEnabled = $validated['methods']['crypto']['enabled']
            ?? $wasCryptoEnabled;
        $options = $validated['crypto_options'] ?? CryptoPaymentOption::query()
            ->where('context', 'panel_wallet')->where('owner_type', 'admin')->whereNull('owner_id')->get()->toArray();
        if ($cryptoEnabled && ($section !== 'payment' || !$wasCryptoEnabled) && !collect($options)->contains(fn ($option) => !empty($option['enabled']) && trim((string) ($option['wallet_address'] ?? '')) !== '')) {
            return response()->json(['status' => 'error', 'message' => 'حداقل یک ارز دیجیتال فعال با آدرس ولت باید وجود داشته باشد'], 422);
        }

        DB::transaction(function () use ($validated, $section) {
            foreach ($section === 'crypto' ? [] : ['card', 'crypto'] as $method) {
                $item = $validated['methods'][$method];

                $payload = [
                    'enabled' => (bool) $item['enabled'],
                    'access_mode' => $item['access_mode'],
                    'partner_ids' => $item['access_mode'] === 'selected'
                        ? array_values(array_unique(array_map('intval', $item['partner_ids'] ?? [])))
                        : null,
                ];

                if ($method === 'card') {
                    $geminiApiKey = trim((string) ($item['gemini_api_key'] ?? ''));
                    $aiFee = isset($item['ai_receipt_check_fee']) && $item['ai_receipt_check_fee'] !== ''
                        ? (int) $item['ai_receipt_check_fee']
                        : null;

                    $smsEnabled =
                        (bool) $item['enabled']
                        &&
                        (bool) ($item['sms_auto_approve_enabled'] ?? false);

                    $aiEnabled = (bool) ($item['ai_auto_approve_enabled'] ?? false);

                    if ($geminiApiKey === '' || !$aiFee || $aiFee <= 0) {
                        $aiEnabled = false;
                    }

                    if (!$aiEnabled) {
                        UserBotSetting::query()
                            ->where("card_ai_auto_approve_enabled", true)
                            ->update(["card_ai_auto_approve_enabled" => false]);
                    }

                    $payload['sms_auto_approve_enabled'] = $smsEnabled;
                    $payload['ai_auto_approve_enabled'] = $aiEnabled;
                    $payload['gemini_api_key'] = $geminiApiKey;
                    $payload['ai_receipt_check_fee'] = $aiFee;
                }

                PanelPaymentMethod::updateOrCreate(
                    ['method' => $method],
                    $payload
                );
            }

            if ($section !== 'payment') {
                $wallet = $validated['crypto_wallet'];

                $row = PanelCryptoWalletSetting::query()->first();

                if ($row) {
                    $row->update([
                        'asset' => $wallet['asset'],
                        'network' => $wallet['network'],
                        'wallet_address' => trim((string) ($wallet['wallet_address'] ?? '')),
                        'trongrid_api_key' => trim((string) ($wallet['trongrid_api_key'] ?? '')),
                        'bscscan_api_key' => trim((string) ($wallet['bscscan_api_key'] ?? '')),
                    ]);
                } else {
                    PanelCryptoWalletSetting::create([
                        'asset' => $wallet['asset'],
                        'network' => $wallet['network'],
                        'wallet_address' => trim((string) ($wallet['wallet_address'] ?? '')),
                        'trongrid_api_key' => trim((string) ($wallet['trongrid_api_key'] ?? '')),
                        'bscscan_api_key' => trim((string) ($wallet['bscscan_api_key'] ?? '')),
                    ]);
                }

                $this->ensureDefaultCryptoOptions('panel_wallet', 'admin', null);

                foreach (($validated['crypto_options'] ?? []) as $option) {
                    CryptoPaymentOption::updateOrCreate(
                        [
                            'context' => 'panel_wallet',
                            'owner_type' => 'admin',
                            'owner_id' => null,
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
            }

        });

        return response()->json([
            'status' => 'ok',
            'message' => $section === 'crypto' ? 'تنظیمات ارز دیجیتال ذخیره شد' : 'تنظیمات پرداخت ذخیره شد',
        ]);
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
