<?php

namespace App\Services\UserBotSms;

use App\Models\PanelWalletCardTopup;
use App\Models\UserBotOrder;
use App\Models\UserBotSetting;
use Illuminate\Support\Carbon;

class SmsPaymentMatcher
{
    public function match(
        UserBotSetting $setting,
        array $amountCandidates,
        Carbon $receivedAt
    ): array {
        $amountCandidates = array_values(array_unique(array_filter(
            array_map('intval', $amountCandidates),
            fn (int $amount) => $amount > 0
        )));

        if ($amountCandidates === []) {
            return $this->emptyResult('unmatched', 'هیچ مبلغ قابل بررسی ارسال نشده است.');
        }

        $matches = [];

        /*
        * سفارش‌های ربات کاربران فقط زمانی بررسی می‌شوند که
        * قابلیت پیامکی همان ربات روشن باشد.
        */
        if ((bool) $setting->card_payment_enabled && (bool) $setting->card_sms_auto_approve_enabled) {
            $orders = UserBotOrder::query()
                ->where('setting_id', $setting->id)
                ->where('payment_method', 'card')
                ->whereNotNull('card_sms_payable_amount')
                ->whereNotNull('card_sms_match_key')
                ->whereIn('status', ['pending', 'card_waiting_payment'])
                ->where('created_at', '<=', $receivedAt->copy()->addMinutes(2))
                ->where(function ($expiry) use ($receivedAt) {
                    $expiry->whereNull('card_sms_expires_at')
                        ->orWhere('card_sms_expires_at', '>=', $receivedAt->copy()->subMinutes(2));
                })
                ->get(['id', 'card_sms_payable_amount']);

            foreach ($orders as $order) {
                $this->appendMatch(
                    $matches,
                    'user_bot_order',
                    (int) $order->id,
                    (int) $order->card_sms_payable_amount,
                    $amountCandidates
                );
            }
        }

        /*
        * Paymentهای کیف پول نمایندگان با همان دستگاه SMS بررسی می‌شوند.
        *
        * عمداً وضعیت Toggle فعلی بررسی نمی‌شود؛ چون اگر Admin قابلیت
        * را بعد از صدور مبلغ دقیق خاموش کند، Payment قبلی باید تا پایان
        * مهلت خودش همچنان قابل تأیید باشد.
        */
        if ((string) $setting->owner_type === 'admin') {
            $topups = PanelWalletCardTopup::query()
                ->where('status', 'pending')
                ->whereNotNull('payable_amount_irt')
                ->whereNotNull('sms_match_key')
                ->where('created_at', '<=', $receivedAt->copy()->addMinutes(2))
                ->where(function ($expiry) use ($receivedAt) {
                    $expiry->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', $receivedAt->copy()->subMinutes(2));
                })
                ->get(['id', 'payable_amount_irt']);

            foreach ($topups as $topup) {
                $this->appendMatch(
                    $matches,
                    'panel_wallet_card_topup',
                    (int) $topup->id,
                    (int) $topup->payable_amount_irt,
                    $amountCandidates
                );
            }
        }

        $matches = array_values($matches);

        if ($matches === []) {
            return $this->emptyResult('unmatched', 'هیچ پرداخت فعالی با مبلغ‌های ارسال‌شده تطبیق پیدا نکرد.');
        }

        if (count($matches) > 1) {
            return $this->emptyResult('ambiguous', 'بیش از یک پرداخت با مبلغ‌های ارسال‌شده تطبیق پیدا کرد.');
        }

        $match = $matches[0];

        return [
            'status' => 'matched',
            'target_type' => $match['target_type'],
            'order_id' => $match['target_type'] === 'user_bot_order' ? $match['target_id'] : null,
            'card_topup_id' => $match['target_type'] === 'panel_wallet_card_topup' ? $match['target_id'] : null,
            'matched_amount_toman' => $match['matched_amount_toman'],
            'matched_numeric_value' => $match['matched_numeric_value'],
            'reason' => null,
        ];
    }

    private function appendMatch(
        array &$matches,
        string $targetType,
        int $targetId,
        int $payableAmount,
        array $amountCandidates
    ): void {
        foreach ([$payableAmount, $payableAmount * 10] as $expectedValue) {
            if (!in_array($expectedValue, $amountCandidates, true)) {
                continue;
            }

            $key = $targetType . ':' . $targetId;

            $matches[$key] = [
                'target_type' => $targetType,
                'target_id' => $targetId,
                'matched_amount_toman' => $payableAmount,
                'matched_numeric_value' => $expectedValue,
            ];

            break;
        }
    }

    private function emptyResult(string $status, string $reason): array
    {
        return [
            'status' => $status,
            'target_type' => null,
            'order_id' => null,
            'card_topup_id' => null,
            'matched_amount_toman' => null,
            'matched_numeric_value' => null,
            'reason' => $reason,
        ];
    }    
}