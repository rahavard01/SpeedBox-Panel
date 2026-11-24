<?php

namespace App\Services;

use App\Support\JalaliDateFormatter;

final class BotAccountMessages
{
    public static function service(object $account): string
    {
        return SubscriptionServiceType::label(SubscriptionServiceType::of($account));
    }

    public static function username(object $account): string
    {
        if (SubscriptionServiceType::of($account) === 'anyconnect') return e(app(AnyConnectService::class)->credentials($account)['username']);
        return e(str_replace(['@', '.com'], ['-', ''], (string) $account->email));
    }

    public static function guidance(): string
    {
        return 'فایل یا QR را در برنامه رسمی وایرگارد وارد کنید.';
    }

    public static function anyConnectDetails(object $account, string $url, bool $showUsername = true): string
    {
        $connection = app(AnyConnectService::class)->credentials($account);
        return ($showUsername ? "👤 نام کاربری:\n<code>".e($connection['username'])."</code>\n\n" : '')
            ."🔑 رمز عبور:\n<code>".e($connection['password'])."</code>\n\n"
            ."🌐 آدرس سرور:\n<code>".e($connection['address'])."</code>\n\n"
            ."🔗 لینک اطلاعات اشتراک:\n<code>".e($url)."</code>";
    }

    public static function created(object $account, string $url): string
    {
        if (SubscriptionServiceType::of($account) === 'anyconnect') {
            return "✅ اکانت شما با موفقیت ساخته شد\n\n".self::anyConnectDetails($account, $url)
                ."\n\n🛡 سرویس: AnyConnect\nاز Cisco Secure Client یا OpenConnect برای اتصال استفاده کنید.";
        }
        $wireguard = SubscriptionServiceType::of($account) === 'wireguard';
        return "✅ اکانت شما با موفقیت ساخته شد\n\n👤 نام کاربری:\n<code>" . self::username($account)
            . "</code>\n\n🔗 " . ($wireguard ? 'لینک اشتراک:' : 'لینک سابسکرایب:')
            . "\n<code>" . e($url) . "</code>\n\n🛡 سرویس: " . self::service($account)
            . ($wireguard ? "\n" . self::guidance() : '');
    }

    public static function renewal(object $account, array $result, string $title): string
    {
        $title = preg_replace('/ \\[#[0-9]+\\]/u', '', $title);
        if (!empty($result['reserved'])) {
            $reserve = $result['reservation'];
            return "✅ طرح رزرو شما با موفقیت ثبت شد.\n\n👤 نام کاربری: <code>" . self::username($account)
                . "</code>\n🛡 سرویس: " . self::service($account)
                . "\n📦 طرح رزرو: <b>" . e($reserve['title']) . "</b>\n📊 ترافیک: " . e($reserve['traffic_label'])
                . "\n⏳ " . e($reserve['activation_label']);
        }
        return "✅ اکانت زیر با موفقیت تمدید شد:\n\n👤 <code>" . self::username($account)
            . "</code>\n🛡 سرویس: " . self::service($account)
            . "\n\n🧩 طرح: <b>" . e($title) . "</b>\n📅 تاریخ انقضای جدید: <b>"
            . JalaliDateFormatter::forTelegram($result['new_expired_at'] ?? $result['expired_at'] ?? $account->expired_at, 'H:i d-m-Y', '-') . '</b>';
    }
}
