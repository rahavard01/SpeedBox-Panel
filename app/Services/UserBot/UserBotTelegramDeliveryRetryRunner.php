<?php

namespace App\Services\UserBot;

use App\Models\UserBotOrder;
use App\Models\UserBotSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserBotTelegramDeliveryRetryRunner
{
    private const MAX_ATTEMPTS = 15;

    public function tick(): void
    {
        UserBotOrder::query()
            ->where('payment_method', 'card')
            ->where('status', 'done')
            ->whereIn(
                'payload->telegram_delivery->status',
                [
                    'pending',
                    'sending',
                ]
            )
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (UserBotOrder $order) {
                $this->retry($order);
            });
    }

    private function retry(
        UserBotOrder $order
    ): void {
        $delivery = data_get(
            $order->payload,
            'telegram_delivery',
            []
        );

        if (!is_array($delivery)) {
            return;
        }

        $status = (string) data_get(
            $delivery,
            'status',
            ''
        );

        if ($status === 'pending') {
            $nextRetryAt = data_get(
                $delivery,
                'next_retry_at'
            );

            if (
                $nextRetryAt
                && Carbon::parse(
                    $nextRetryAt
                )->isFuture()
            ) {
                return;
            }
        }

        if ($status === 'sending') {
            $startedAt = data_get(
                $delivery,
                'started_at'
            );

            if (
                $startedAt
                && Carbon::parse(
                    $startedAt
                )->gt(
                    now()->subMinutes(5)
                )
            ) {
                return;
            }
        }

        $attempts = (int) data_get(
            $delivery,
            'attempts',
            0
        );

        if (
            $attempts
            >= self::MAX_ATTEMPTS
        ) {
            $this->markFailed($order);

            return;
        }

        $setting = UserBotSetting::find(
            $order->setting_id
        );

        if (!$setting) {
            $this->markFailed(
                $order,
                'تنظیمات ربات پیدا نشد.'
            );

            return;
        }

        try {
            app(
                UserBotOrderDeliveryService::class
            )->retryApproved(
                $setting,
                $order
            );
        } catch (Throwable $e) {
            // سفارش در اجرای بعدی دوباره بررسی می‌شود.
        }
    }

    private function markFailed(
        UserBotOrder $order,
        string $error =
            'ارسال پیام تلگرام پس از چند تلاش ناموفق متوقف شد.'
    ): void {
        DB::transaction(function () use (
            $order,
            $error
        ) {
            $locked = UserBotOrder::query()
                ->where(
                    'id',
                    $order->id
                )
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return;
            }

            $payload =
                $locked->payload ?: [];

            $delivery = data_get(
                $payload,
                'telegram_delivery',
                []
            );

            if (!is_array($delivery)) {
                return;
            }

            $status = (string) data_get(
                $delivery,
                'status',
                ''
            );

            if (
                !in_array(
                    $status,
                    [
                        'pending',
                        'sending',
                    ],
                    true
                )
            ) {
                return;
            }

            $delivery['status'] =
                'failed';

            $delivery['failed_at'] =
                now()->toDateTimeString();

            $delivery['next_retry_at'] =
                null;

            $delivery['token'] =
                null;

            $delivery['started_at'] =
                null;

            $delivery['general_error'] =
                $error;

            $payload['telegram_delivery'] =
                $delivery;

            $locked->update([
                'payload' => $payload,
            ]);
        });
    }
}