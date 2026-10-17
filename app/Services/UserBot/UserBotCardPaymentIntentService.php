<?php

namespace App\Services\UserBot;

use App\Models\PanelWalletCardTopup;
use App\Models\UserBotOrder;
use App\Models\UserBotSession;
use App\Models\UserBotSetting;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UserBotCardPaymentIntentService
{
    private const SMS_MATCH_KEY_RELEASE_GRACE_MINUTES = 12;
    private const SMS_AMOUNT_QUARANTINE_HOURS = 48;

    public function create(
        UserBotSetting $setting,
        UserBotSession $session,
        array $stateData
    ): UserBotOrder {
        $selected = $stateData['selected_plan'] ?? null;
        $amount = (int) ($stateData['amount'] ?? 0);
        $trafficGb = $stateData['traffic_gb'] ?? null;
        $orderType = $stateData['order_type'] ?? 'buy_account';

        if (!is_array($selected) || $amount <= 0) {
            throw new RuntimeException(
                'اطلاعات سفارش برای ساخت مبلغ دقیق کامل نیست.'
            );
        }

        return DB::transaction(function () use (
            $setting,
            $session,
            $selected,
            $amount,
            $trafficGb,
            $orderType
        ) {
            /*
             * ساخت مبلغ‌ها برای هر ربات به‌صورت ترتیبی انجام می‌شود
             * تا دو سفارش هم‌زمان مبلغ یکسان نگیرند.
             */
            DB::table('user_bot_settings')
                ->where('id', $setting->id)
                ->lockForUpdate()
                ->first();

            /*
             * پیش از انتخاب مبلغ جدید، کلیدهای قدیمی
             * همین ربات آزاد می‌شوند.
             *
             * مهلت ۱۲ دقیقه‌ای برای SMS معتبری است که
             * پیش از انقضا دریافت شده، اما ارسال آن به
             * سرور با تأخیر شبکه انجام شده است.
             */
            $releaseBefore = now()->subMinutes(
                self::SMS_MATCH_KEY_RELEASE_GRACE_MINUTES
            );

            UserBotOrder::query()
                ->where(
                    'setting_id',
                    $setting->id
                )
                ->where(
                    'payment_method',
                    'card'
                )
                ->whereNotNull(
                    'card_sms_match_key'
                )
                ->whereNotNull(
                    'card_sms_expires_at'
                )
                ->where(
                    'card_sms_expires_at',
                    '<=',
                    $releaseBefore
                )
                ->where(
                    'status',
                    'card_waiting_payment'
                )
                ->update([
                    'status' => 'expired',
                    'card_sms_match_key' => null,
                ]);

            UserBotOrder::query()
                ->where(
                    'setting_id',
                    $setting->id
                )
                ->where(
                    'payment_method',
                    'card'
                )
                ->whereNotNull(
                    'card_sms_match_key'
                )
                ->whereNotNull(
                    'card_sms_expires_at'
                )
                ->where(
                    'card_sms_expires_at',
                    '<=',
                    $releaseBefore
                )
                ->where(
                    'status',
                    'pending'
                )
                ->update([
                    'card_sms_match_key' => null,
                ]);

            UserBotOrder::query()
                ->where(
                    'setting_id',
                    $setting->id
                )
                ->where(
                    'payment_method',
                    'card'
                )
                ->whereNotNull(
                    'card_sms_match_key'
                )
                ->whereNotIn('status', [
                    'card_waiting_payment',
                    'pending',
                    'card_processing',
                ])
                ->update([
                    'card_sms_match_key' => null,
                ]);

            /*
             * سفارش موقت قبلی همین کاربر، در صورت وجود، منقضی شود.
             */
            UserBotOrder::query()
                ->where('setting_id', $setting->id)
                ->where('session_id', $session->id)
                ->where('status', 'card_waiting_payment')
                ->update([
                    'status' => 'expired',
                    'card_sms_match_key' => null,
                ]);

            /*
            * مبلغ‌های صادرشده در 48 ساعت گذشته دوباره
            * استفاده نمی‌شوند؛ حتی اگر سفارش نهایی،
            * منقضی، رد یا کلید Match آن آزاد شده باشد.
            *
            * این کار از Match شدن SMS دیررس یک سفارش
            * قدیمی با سفارش جدید جلوگیری می‌کند.
            */
            $quarantineSince = now()->subHours(
                self::SMS_AMOUNT_QUARANTINE_HOURS
            );

            /*
             * از 11 تا 999 تومان، یک مقدار یکتا انتخاب می‌شود.
             */
            $extras = range(11, 999);
            shuffle($extras);

            foreach ($extras as $uniqueExtra) {
                $payableAmount = $amount + $uniqueExtra;
                $matchKey = $setting->id . ':' . $payableAmount;

                /*
                * مبلغ ممکن است در SMS به تومان یا ریال نمایش داده شود.
                *
                * برای مثال:
                * 310755 تومان
                * 3107550 ریال
                *
                * پس مبلغ جدید نباید با نمایش تومانی یا ریالی
                * هیچ سفارش فعال دیگری تداخل داشته باشد.
                */
                $collisionAmounts = [
                    $payableAmount,
                    $payableAmount * 10,
                ];

                if ($payableAmount % 10 === 0) {
                    $collisionAmounts[] = intdiv(
                        $payableAmount,
                        10
                    );
                }

                $collisionAmounts = array_values(
                    array_unique($collisionAmounts)
                );

                $hasCollision = UserBotOrder::query()
                    ->where(
                        'setting_id',
                        $setting->id
                    )
                    ->where(
                        'payment_method',
                        'card'
                    )
                    ->whereIn(
                        'card_sms_payable_amount',
                        $collisionAmounts
                    )
                    ->where(function ($collision) use (
                        $quarantineSince
                    ) {
                        $collision
                            /*
                            * مبلغ هنوز فعال و قابل Match است.
                            */
                            ->whereNotNull(
                                'card_sms_match_key'
                            )

                            /*
                            * یا مبلغ طی 48 ساعت گذشته صادر شده است.
                            */
                            ->orWhere(
                                'created_at',
                                '>=',
                                $quarantineSince
                            )

                            /*
                            * سفارش قدیمی‌تر بوده ولی اخیراً تأیید شده است.
                            */
                            ->orWhere(
                                'approved_at',
                                '>=',
                                $quarantineSince
                            )

                            /*
                            * سفارش اخیراً رد شده است.
                            */
                            ->orWhere(
                                'rejected_at',
                                '>=',
                                $quarantineSince
                            )

                            /*
                            * پرداخت SMS اخیراً ثبت شده ولی ممکن است
                            * سفارش هنوز در Retry باشد.
                            */
                            ->orWhere(
                                'card_sms_paid_at',
                                '>=',
                                $quarantineSince
                            );
                    })
                    ->exists();

                if ($hasCollision) {
                    continue;
                }

                /*
                * مبلغ جدید ربات کاربران نباید با مبلغ دقیقی که برای
                * شارژ کیف پول نمایندگان صادر شده نیز تداخل داشته باشد.
                */
                $hasPanelWalletCollision = PanelWalletCardTopup::query()
                    ->whereIn(
                        'payable_amount_irt',
                        $collisionAmounts
                    )
                    ->where(function ($collision) use (
                        $quarantineSince
                    ) {
                        $collision
                            ->whereNotNull('sms_match_key')
                            ->orWhere('created_at', '>=', $quarantineSince)
                            ->orWhere('paid_at', '>=', $quarantineSince)
                            ->orWhere('sms_paid_at', '>=', $quarantineSince);
                    })
                    ->exists();

                if ($hasPanelWalletCollision) {
                    continue;
                }

                return UserBotOrder::create([
                    'setting_id' => $setting->id,
                    'session_id' => $session->id,
                    'order_type' => $orderType,
                    'order_code' => $this->generateOrderCode(),

                    'plan_key' => $selected['plan_key'] ?? null,
                    'account_email' => $session->account_email,

                    'traffic_gb' => $trafficGb
                        ? (int) $trafficGb
                        : null,

                    /*
                     * amount مبلغ واقعی سفارش است و تغییر نمی‌کند.
                     */
                    'amount' => $amount,

                    /*
                     * مبلغ دقیق فقط برای شناسایی پرداخت است.
                     */
                    'card_sms_payable_amount' => $payableAmount,
                    'card_sms_unique_extra' => $uniqueExtra,
                    'card_sms_expires_at' => now()->addMinutes(30),
                    'card_sms_match_key' => $matchKey,

                    'payment_method' => 'card',
                    'status' => 'card_waiting_payment',

                    'payload' => [
                        'selected_plan' => $selected,
                        'telegram_chat_id' => $session->telegram_chat_id,
                        'telegram_username' => $session->telegram_username,
                    ],
                ]);
            }

            throw new RuntimeException(
                'در حال حاضر امکان ساخت مبلغ دقیق یکتا وجود ندارد.'
            );
        }, 3);
    }

    private function generateOrderCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (
            UserBotOrder::where('order_code', $code)->exists()
        );

        return $code;
    }
}