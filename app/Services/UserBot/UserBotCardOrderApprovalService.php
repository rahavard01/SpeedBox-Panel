<?php

namespace App\Services\UserBot;

use App\Models\UserBotOrder;
use App\Models\UserBotSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class UserBotCardOrderApprovalService
{
    private const SOURCES = [
        'manual',
        'ai',
        'sms',
        'sms_retry',
    ];

    private const SAFE_PROCESSOR_MESSAGES = [
        'صاحب ربات پیدا نشد',
        'اکانت ساخته نشد',
        'این طرح برای شما فعال نیست',
        'پلن انتخابی پیدا نشد یا به پلن اصلی متصل نیست',
        'ساخت کاربر ناموفق بود',
        'ورودی تمدید نامعتبر است',
        'کاربر یافت نشد',
        'تمدید انجام نشد',
        'ورودی نامعتبر است',
        'اکانت منقضی شده است؛ ابتدا باید اکانت را تمدید کنید',
        'پلن حجمی انتخاب‌شده با پلن ثبت‌شده مطابقت ندارد',
        'افزایش ترافیک انجام نشد',
        'اعتبار شما کافی نیست',
    ];

    public function approve(
        UserBotOrder|int $order,
        string $source
    ): array {
        $orderId = $order instanceof UserBotOrder
            ? (int) $order->id
            : (int) $order;

        if ($orderId <= 0) {
            return $this->failure(
                'not_found',
                'سفارش معتبر نیست'
            );
        }

        if (!in_array($source, self::SOURCES, true)) {
            return $this->failure(
                'invalid_source',
                'منبع تایید سفارش معتبر نیست'
            );
        }

        /*
         * این توکن مخصوص همین اجرای فعلی است.
         * هیچ پردازش دیگری حق نهایی‌کردن آن را ندارد.
         */
        $processingToken = Str::uuid()->toString();

        $claim = DB::transaction(function () use (
            $orderId,
            $source,
            $processingToken
        ) {
            $locked = UserBotOrder::query()
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return [
                    'claimed' => false,
                    'state' => 'not_found',
                    'message' => 'سفارش پیدا نشد',
                    'order' => null,
                ];
            }

            if ($locked->payment_method !== 'card') {
                return [
                    'claimed' => false,
                    'state' => 'not_approvable',
                    'message' => 'این سفارش کارت‌به‌کارت نیست',
                    'order' => $locked,
                ];
            }

            /*
             * سفارش انجام‌شده نباید دوباره اجرا شود.
             *
             * اگر SMS دقیقاً هم‌زمان با پایان تایید مدیر رسیده باشد،
             * فقط زمان پرداخت پیامکی برای سابقه ثبت می‌شود.
             */
            if ($locked->status === 'done') {
                if ($source === 'sms') {
                    if (!$locked->card_sms_paid_at) {
                        $locked->update([
                            'card_sms_paid_at' => now(),
                            'card_sms_match_key' => null,
                        ]);
                    }

                    $this->closeSmsPaymentSession(
                        $locked->fresh() ?: $locked
                    );
                }

                return [
                    'claimed' => false,
                    'state' => 'already_done',
                    'message' => 'سفارش قبلاً انجام شده است',
                    'order' => $locked->fresh(),
                ];
            }

            /*
             * ممکن است مدیر یا AI چند لحظه زودتر سفارش را
             * قفل کرده باشد و همان لحظه SMS پرداخت برسد.
             *
             * در این حالت پرداخت ثبت می‌شود ولی سفارش
             * برای بار دوم اجرا نمی‌شود.
             */
            if ($locked->status === 'card_processing') {
                if ($source === 'sms') {
                    if (!$locked->card_sms_paid_at) {
                        $locked->update([
                            'card_sms_paid_at' => now(),
                            'card_sms_match_key' => null,
                        ]);
                    }

                    $this->closeSmsPaymentSession(
                        $locked->fresh() ?: $locked
                    );
                }

                return [
                    'claimed' => false,
                    'state' => 'already_processing',
                    'message' => 'سفارش هم‌اکنون در حال انجام است',
                    'order' => $locked->fresh(),
                ];
            }

            /*
             * هر مسیر فقط وضعیت‌های مخصوص خودش را می‌پذیرد.
             */
            $allowedStatuses = match ($source) {
                'sms' => [
                    'card_waiting_payment',
                    'pending',
                ],

                'sms_retry' => [
                    'card_retry_pending',
                ],

                default => [
                    'pending',
                ],
            };

            if (!in_array(
                $locked->status,
                $allowedStatuses,
                true
            )) {
                return [
                    'claimed' => false,
                    'state' => 'not_approvable',
                    'message' =>
                        'سفارش در وضعیت قابل تایید نیست',
                    'order' => $locked,
                ];
            }

            /*
             * تایید مجدد SMS فقط زمانی مجاز است که
             * پرداخت پیامکی قبلاً ثبت شده باشد.
             */
            if (
                $source === 'sms_retry'
                && !$locked->card_sms_paid_at
            ) {
                return [
                    'claimed' => false,
                    'state' => 'not_approvable',
                    'message' =>
                        'پرداخت پیامکی این سفارش ثبت نشده است',
                    'order' => $locked,
                ];
            }

            $updates = [
                'status' => 'card_processing',

                'card_processing_token' =>
                    $processingToken,

                'card_processing_source' =>
                    $source,

                'card_processing_started_at' =>
                    now(),

                'card_processing_attempts' =>
                    (int) $locked->card_processing_attempts + 1,

                'card_processing_error' =>
                    null,
            ];

            /*
             * در مسیر SMS، پرداخت قطعی شده است.
             * پس مبلغ دیگر نباید با Event دیگری Match شود.
             */
            if ($source === 'sms') {
                $updates['card_sms_paid_at'] =
                    $locked->card_sms_paid_at ?: now();

                $updates['card_sms_match_key'] =
                    null;
            }

            $locked->update($updates);

            if ($source === 'sms') {
                $this->closeSmsPaymentSession(
                    $locked->fresh() ?: $locked
                );
            }

            return [
                'claimed' => true,
                'state' => 'claimed',
                'message' => null,
                'order' => $locked->fresh(),
            ];
        });

        /*
         * سفارش توسط این درخواست قفل نشده است؛
         * بنابراین Processor نباید اجرا شود.
         */
        if (empty($claim['claimed'])) {
            return [
                'ok' => false,
                'state' => $claim['state'],
                'message' => $claim['message'],
                'order' => $claim['order'],
            ];
        }

        /** @var UserBotOrder $claimedOrder */
        $claimedOrder = $claim['order'];

        try {
            $processorResult = app(
                UserBotOrderProcessor::class
            )->approve(
                $claimedOrder->fresh()
            );
        } catch (Throwable $e) {
            report($e);

            $processorResult = [
                'ok' => false,
                'message' =>
                    'خطای غیرمنتظره در انجام سفارش رخ داد',
                'internal_error' => $e->getMessage(),
            ];
        }

        if (empty($processorResult['ok'])) {
            return $this->finishFailed(
                $orderId,
                $processingToken,
                $source,
                $this->internalFailureMessage(
                    $processorResult
                ),
                $this->publicFailureMessage(
                    $processorResult
                )
            );
        }

        /*
         * Processor عملیات اصلی را انجام داده و وضعیت را done کرده است.
         * حالا اطلاعات قفل موقتی پاک می‌شود.
         */
        $approvedOrder = DB::transaction(function () use (
            $orderId,
            $processingToken,
            $source
        ) {
            $locked = UserBotOrder::query()
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return null;
            }

            /*
             * فقط همان پردازشی که سفارش را Claim کرده
             * اجازه پاک‌کردن قفل را دارد.
             */
            if (
                (string) $locked->card_processing_token
                !== $processingToken
            ) {
                return $locked;
            }

            $locked->update([
                'card_processing_token' => null,
                'card_processing_source' => $source,
                'card_processing_started_at' => null,
                'card_processing_error' => null,
                'card_sms_match_key' => null,
            ]);

            return $locked->fresh();
        });

        /*
         * خروجی اصلی Processor حفظ می‌شود تا کدهای فعلی
         * بتوانند email، subscribe_url و سایر موارد را بخوانند.
         */
        return array_merge(
            $processorResult,
            [
                'ok' => true,
                'state' => 'approved',

                'order' => $approvedOrder
                    ?: UserBotOrder::find($orderId),
            ]
        );
    }

    public function retrySms(int $orderId): array
    {
        return $this->approve(
            $orderId,
            'sms_retry'
        );
    }

    private function finishFailed(
        int $orderId,
        string $processingToken,
        string $source,
        string $internalMessage,
        string $publicMessage
    ): array {
        $failedOrder = DB::transaction(function () use (
            $orderId,
            $processingToken,
            $source,
            $internalMessage
        ) {
            $locked = UserBotOrder::query()
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return null;
            }

            if (
                (string) $locked->card_processing_token
                !== $processingToken
            ) {
                return $locked;
            }

            $paymentConfirmed =
                (bool) $locked->card_sms_paid_at
                || in_array(
                    $source,
                    [
                        'sms',
                        'sms_retry',
                    ],
                    true
                );

            $locked->update([
                'status' => $paymentConfirmed
                    ? 'card_retry_pending'
                    : 'pending',

                'card_processing_token' => null,

                'card_processing_source' =>
                    $source,

                'card_processing_started_at' =>
                    null,

                /*
                 * خطای کامل فقط برای بررسی داخلی
                 * در دیتابیس ذخیره می‌شود.
                 */
                'card_processing_error' =>
                    $internalMessage,

                'card_sms_match_key' =>
                    $paymentConfirmed
                        ? null
                        : $locked->card_sms_match_key,
            ]);

            return $locked->fresh();
        });

        $retryPending =
            $failedOrder
            && $failedOrder->status
                === 'card_retry_pending';

        return [
            'ok' => false,

            'state' => $retryPending
                ? 'retry_pending'
                : 'failed',

            /*
             * فقط پیام امن به Controller و تلگرام می‌رسد.
             */
            'message' => $publicMessage,

            'order' => $failedOrder,
        ];
    }

    private function publicFailureMessage(
        array $result
    ): string {
        if (!empty($result['insufficient_credit'])) {
            return 'اعتبار شما کافی نیست';
        }

        if (!empty($result['account_expired'])) {
            return 'اکانت منقضی شده است؛ ابتدا باید اکانت را تمدید کنید';
        }

        $message = trim(
            (string) ($result['message'] ?? '')
        );

        if (in_array(
            $message,
            self::SAFE_PROCESSOR_MESSAGES,
            true
        )) {
            return $message;
        }

        return 'انجام سفارش با خطای موقت روبه‌رو شد. لطفاً تنظیمات و وضعیت پنل را بررسی کرده و دوباره تلاش کنید.';
    }

    private function internalFailureMessage(
        array $result
    ): string {
        foreach (
            [
                'internal_error',
                'error',
                'message',
            ] as $key
        ) {
            $message = trim(
                (string) ($result[$key] ?? '')
            );

            if ($message !== '') {
                return $message;
            }
        }

        return 'Unknown user bot order processing error';
    }
    
    private function closeSmsPaymentSession(
        UserBotOrder $order
    ): void {
        $session = UserBotSession::query()
            ->where('id', $order->session_id)
            ->lockForUpdate()
            ->first();

        if (
            !$session
            || $session->state !== 'buy_waiting_receipt'
        ) {
            return;
        }

        $stateData = $session->state_data ?: [];

        if (
            (int) ($stateData['card_sms_order_id'] ?? 0)
            !== (int) $order->id
        ) {
            return;
        }

        $session->update([
            'state' => null,
            'state_data' => null,
        ]);
    }

    private function failure(
        string $state,
        string $message
    ): array {
        return [
            'ok' => false,
            'state' => $state,
            'message' => $message,
            'order' => null,
        ];
    }
}