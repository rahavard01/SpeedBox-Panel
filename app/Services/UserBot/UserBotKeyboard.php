<?php

namespace App\Services\UserBot;

class UserBotKeyboard
{
    public static function guest(bool $activeUsersOnly, bool $isVolumePartner = false): array
    {
        if ($activeUsersOnly) {
            return self::reply([
                ['🔐 ورود'],
                ['❓ سوالات متداول'],
                ['💬 پشتیبانی'],
            ]);
        }

        if ($isVolumePartner) {
            return self::reply([
                ['🛒 خرید اکانت'],
                ['➕ خرید ترافیک'],
                ['👤 مشاهده اطلاعات کاربری'],
                ['📘 راهنمای اتصال'],
                ['❓ سوالات متداول'],
                ['🔐 ورود', '💬 پشتیبانی'],
            ]);
        }

        return self::reply([
            ['🛒 خرید اکانت', '🔄 تمدید اکانت'],
            ['➕ خرید ترافیک'],
            ['👤 مشاهده اطلاعات کاربری'],
            ['📘 راهنمای اتصال'],
            ['❓ سوالات متداول'],
            ['🔐 ورود', '💬 پشتیبانی'],
        ]);
    }

    public static function user(bool $isVolumePartner = false): array
    {
        if ($isVolumePartner) {
            return self::reply([
                ['🛒 خرید اکانت'],
                ['➕ خرید ترافیک'],
                ['👤 مشاهده اطلاعات کاربری'],
                ['📘 راهنمای اتصال'],
                ['❓ سوالات متداول'],
                ['🚪 خروج', '💬 پشتیبانی'],
            ]);
        }

        return self::reply([
            ['🛒 خرید اکانت', '🔄 تمدید اکانت'],
            ['➕ خرید ترافیک'],
            ['👤 مشاهده اطلاعات کاربری'],
            ['📘 راهنمای اتصال'],
            ['❓ سوالات متداول'],
            ['🚪 خروج', '💬 پشتیبانی'],
        ]);
    }

    public static function manager(): array
    {
        return self::reply([
            ['🧾 رسیدهای بررسی ‌نشده'],
            ['📣 ارسال پیام گروهی'],
            ['🚪 خروج'],
        ]);
    }

    public static function back(): array
    {
        return self::reply([
            ['⬅️ بازگشت'],
        ]);
    }

    public static function plans(array $plans): array
    {
        $rows = [];

        foreach ($plans as $plan) {
            $price = number_format((int)($plan['price'] ?? 0));

            $planKey = (string)($plan['plan_key'] ?? '');

            if ($planKey === 'volume_gig' || str_ends_with($planKey, '_volume_gig')) {
                $rows[] = ['🧾 ' . $plan['title'] . ' - هر گیگ ' . $price . ' تومان'];
            } else {
                $rows[] = ['🧾 ' . $plan['title'] . ' - ' . $price . ' تومان'];
            }
        }

        $rows[] = ['⬅️ بازگشت'];

        return self::reply($rows);
    }

    public static function planGroups(array $groups): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $rows[] = ['📁 ' . $group];
        }

        $rows[] = ['⬅️ بازگشت'];

        return self::reply($rows);
    }

    public static function approveReject(int $orderId): array
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => "✅ تایید",
                        'callback_data' => "userbot_approve:{$orderId}",
                    ],
                    [
                        'text' => "❌ رد",
                        'callback_data' => "userbot_reject:{$orderId}",
                    ],
                ],
            ],
        ];
    }

    public static function smsRetryOrder(
        int $orderId
    ): array {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' =>
                            '🔁 تأیید مجدد سفارش',

                        'callback_data' =>
                            "userbot_sms_retry:{$orderId}",
                    ],
                ],
            ],
        ];
    }

    public static function paymentMethods(bool $cardEnabled, bool $cryptoEnabled): array
    {
        $rows = [];

        if ($cardEnabled) {
            $rows[] = ['💳 کارت به کارت'];
        }

        if ($cryptoEnabled) {
            $rows[] = ['💲 ارز دیجیتال'];
        }

        $rows[] = ['⬅️ بازگشت'];

        return self::reply($rows);
    }

    public static function cryptoOptions(array $options): array
    {
        $rows = [];

        foreach ($options as $option) {
            $asset = is_array($option) ? ($option['asset'] ?? '') : ($option->asset ?? '');
            $network = is_array($option) ? ($option['network'] ?? '') : ($option->network ?? '');

            if ($asset && $network) {
                $rows[] = ["💲 {$asset} - {$network}"];
            }
        }

        $rows[] = ['⬅️ بازگشت'];

        return self::reply($rows);
    }

    public static function cryptoOrderActions(int $orderId): array
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => '✅ پرداخت کردم',
                        'callback_data' => 'userbot_crypto_paid:' . $orderId,
                    ],
                ],
                [
                    [
                        'text' => '❌ لغو سفارش',
                        'callback_data' => 'userbot_crypto_cancel:' . $orderId,
                    ],
                ],
            ],
        ];
    }

    public static function faqMenu(): array
    {
        return ['inline_keyboard' => app(GlobalUserBotFaq::class)->active()->map(fn ($item) => [[
            'text' => $item->question,
            'callback_data' => 'userbot_faq:item_'.$item->id,
        ]])->all()];
    }

    public static function faqBack(): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '⬅️ بازگشت به سوالات متداول',
                    'callback_data' => 'userbot_faq:menu',
                ]],
            ],
        ];
    }

    public static function support(string $username): array
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => '💬 ورود به چت پشتیبانی',
                        'url' => 'https://t.me/' . ltrim($username, '@'),
                    ],
                ],
            ],
        ];
    }

    public static function helpPlatforms(): array
    {
        return self::reply([
            ['📱 iOS', '🤖 Android'],
            ['🖥 Windows'],
            ['⬅️ بازگشت'],
        ]);
    }

    private static function reply(array $rows): array
    {
        if (app(GlobalUserBotFaq::class)->active()->isEmpty()) {
            $rows = array_values(array_filter(array_map(fn ($row) => array_values(array_filter($row, fn ($text) => $text !== '❓ سوالات متداول')), $rows)));
        }

        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'selective' => false,
        ];
    }

    public static function forceChannelJoin(string $channel): array
    {
        $username = ltrim($channel, '@');

        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => '📢 عضویت در کانال',
                        'url' => 'https://t.me/' . $username,
                    ],
                ],
                [
                    [
                        'text' => '✅ عضو شدم',
                        'callback_data' => 'userbot_check_join',
                    ],
                ],
            ],
        ];
    }

    public static function cryptoRetryOrder(int $orderId): array
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => '✅ تایید مجدد سفارش',
                        'callback_data' => 'userbot_crypto_retry:' . $orderId,
                    ],
                ],
            ],
        ];
    }    

}