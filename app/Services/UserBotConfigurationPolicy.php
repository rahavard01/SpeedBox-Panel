<?php

namespace App\Services;

/** Readiness rules shared by saving an active bot and activating a saved bot. */
final class UserBotConfigurationPolicy
{
    public function errors(array $data, bool $requireComplete = false): array
    {
        if (empty($data['is_enabled']) && !$requireComplete) {
            return [];
        }

        $errors = [];
        if (!empty($data['is_enabled']) && trim((string) ($data['bot_token'] ?? '')) === '') {
            $errors['bot_token'] = 'برای فعال‌سازی ربات کاربران، ابتدا توکن ربات را ذخیره کنید';
        }
        $card = !empty($data['card_payment_enabled']);
        $crypto = !empty($data['crypto_payment_enabled']);
        if (!$card && !$crypto) {
            $errors['card_payment_enabled'] = 'حداقل یک روش پرداخت برای ربات کاربران باید فعال باشد';
        }
        if ($card && strlen(preg_replace('/\D+/', '', (string) ($data['card_number'] ?? ''))) !== 16) {
            $errors['card_number'] = 'برای فعال‌سازی پرداخت کارت به کارت، شماره کارت ربات کاربران باید 16 رقم باشد';
        }
        if ($crypto) {
            $ready = false;
            foreach ($data['crypto_options'] ?? [] as $option) {
                if (!empty($option['enabled']) && trim((string) ($option['wallet_address'] ?? '')) !== '') {
                    $ready = true;
                    break;
                }
            }
            if (!$ready) {
                $errors['crypto_options'] = 'برای فعال‌سازی پرداخت ارز دیجیتال، حداقل یک ارز را فعال و آدرس ولت آن را وارد کنید';
            }
        }
        if (!$card && (!empty($data['card_sms_auto_approve_enabled']) || !empty($data['card_ai_auto_approve_enabled']))) {
            $errors['card_payment_enabled'] = 'برای فعال‌سازی تأیید خودکار رسید، پرداخت کارت به کارت باید فعال باشد';
        }
        foreach (['help_ios' => 'iOS', 'help_android' => 'Android', 'help_windows' => 'Windows'] as $field => $label) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[$field] = "برای فعال‌سازی ربات کاربران، راهنمای اتصال {$label} را وارد کنید";
            }
        }
        if (!empty($data['force_channel_join_enabled']) && trim((string) ($data['force_channel_join_chat'] ?? '')) === '') {
            $errors['force_channel_join_chat'] = 'برای فعال‌سازی عضویت اجباری، آیدی کانال تلگرام را وارد کنید';
        }
        foreach ($data['plans'] ?? [] as $plan) {
            if (!empty($plan['is_enabled']) && (int) ($plan['price'] ?? 0) <= 0) {
                $errors['plans'] = 'برای فعال‌سازی طرح در ربات کاربران، ابتدا قیمت آن را تعیین کنید';
                break;
            }
        }
        return $errors;
    }
}
