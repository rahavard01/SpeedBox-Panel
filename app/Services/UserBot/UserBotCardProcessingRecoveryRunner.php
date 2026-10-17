<?php

namespace App\Services\UserBot;

use App\Models\UserBotOrder;
use App\Models\UserBotSetting;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserBotCardProcessingRecoveryRunner
{
    private const STALE_AFTER_MINUTES = 10;

    public function tick(): void
    {
        UserBotOrder::query()
            ->where('payment_method', 'card')
            ->where('status', 'card_processing')
            ->whereNotNull('card_processing_started_at')
            ->where(
                'card_processing_started_at',
                '<=',
                now()->subMinutes(self::STALE_AFTER_MINUTES)
            )
            ->orderBy('id')
            ->chunkById(50, function ($orders) {
                foreach ($orders as $order) {
                    $this->recover((int) $order->id);
                }
            });
    }

    private function recover(int $orderId): void
    {
        $recoveredOrder = DB::transaction(function () use (
            $orderId
        ) {
            $locked = UserBotOrder::query()
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (
                !$locked
                || $locked->payment_method !== 'card'
                || $locked->status !== 'card_processing'
                || !$locked->card_processing_started_at
                || $locked->card_processing_started_at->gt(
                    now()->subMinutes(
                        self::STALE_AFTER_MINUTES
                    )
                )
            ) {
                return null;
            }

            $source = (string) (
                $locked->card_processing_source ?? ''
            );

            $paymentConfirmed =
                (bool) $locked->card_sms_paid_at
                || in_array(
                    $source,
                    ['sms', 'sms_retry'],
                    true
                );

            $message =
                'پردازش قبلی سفارش بیش از '
                . self::STALE_AFTER_MINUTES
                . ' دقیقه بدون نتیجه باقی ماند و به‌صورت خودکار آزاد شد.';

            $locked->update([
                'status' => $paymentConfirmed
                    ? 'card_retry_pending'
                    : 'pending',

                'card_processing_token' => null,
                'card_processing_started_at' => null,
                'card_processing_error' => $message,

                'card_sms_match_key' => $paymentConfirmed
                    ? null
                    : $locked->card_sms_match_key,
            ]);

            return $locked->fresh();
        });

        if (
            !$recoveredOrder
            || $recoveredOrder->status
                !== 'card_retry_pending'
        ) {
            return;
        }

        $setting = UserBotSetting::find(
            $recoveredOrder->setting_id
        );

        if (!$setting) {
            return;
        }

        try {
            app(UserBotOrderDeliveryService::class)
                ->notifyRetryPending(
                    $setting,
                    $recoveredOrder,
                    (string) $recoveredOrder
                        ->card_processing_error
                );
        } catch (Throwable $e) {
            // پایداری اعلان تلگرام در مرحله مخصوص خودش تکمیل می‌شود.
        }
    }
}