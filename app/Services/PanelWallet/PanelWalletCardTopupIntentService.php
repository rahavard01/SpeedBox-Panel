<?php

namespace App\Services\PanelWallet;

use App\Models\PanelCardNumber;
use App\Models\PanelPaymentMethod;
use App\Models\PanelWalletCardTopup;
use App\Models\User;
use App\Models\UserBotOrder;
use App\Models\UserBotSetting;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PanelWalletCardTopupIntentService
{
    /*
     * SMSای که قبل از پایان مهلت دریافت شده ولی با تأخیر
     * به سرور رسیده، تا 12 دقیقه فرصت Match دارد.
     */
    public const MATCH_GRACE_MINUTES = 12;

    /*
     * مبلغ دقیق صادرشده تا 48 ساعت مجدداً استفاده نمی‌شود
     * تا SMS دیررس با یک پرداخت جدید Match نشود.
     */
    public const QUARANTINE_HOURS = 48;

    /*
     * مهلت پرداخت مبلغ دقیق.
     */
    public const EXPIRES_MINUTES = 30;

    /*
     * محدوده شناسه یکتای پرداخت همکاران.
     *
     * ربات کاربران فعلاً از 11 تا 999 استفاده می‌کند.
     * همکاران از 1001 تا 1999 استفاده خواهند کرد.
     */
    public const EXTRA_MIN = 1001;
    public const EXTRA_MAX = 1999;

    /**
     * ساخت یا برگرداندن پرداخت فعال کاربر.
     */
    public function create(
        User $user,
        int $amount,
        string $source = 'panel'
    ): PanelWalletCardTopup {
        /*
         * دقیقاً همان مبلغ‌هایی که الان برای شارژ
         * کارت‌به‌کارت پنل داریم مجاز هستند.
         */
        if (!in_array($amount, [
            1000000,
            3000000,
            5000000,
            10000000,
            20000000,
        ], true)) {
            throw new RuntimeException(
                'مبلغ شارژ معتبر نیست.'
            );
        }

        /*
         * منبع فقط می‌تواند پنل یا ربات همکاران باشد.
         */
        if (!in_array($source, [
            'panel',
            'partner_bot',
        ], true)) {
            $source = 'panel';
        }

        return DB::transaction(function () use (
            $user,
            $amount,
            $source
        ) {
            /*
             * تنظیمات کارت‌به‌کارت را Lock می‌کنیم
             * تا ساخت هم‌زمان Payment باعث Race نشود.
             */
            $paymentMethod = PanelPaymentMethod::query()
                ->where('method', 'card')
                ->lockForUpdate()
                ->first();

            if (
                !$paymentMethod
                || !$paymentMethod->enabled
                || !$paymentMethod->sms_auto_approve_enabled
            ) {
                throw new RuntimeException(
                    'تأیید آنی پرداخت کارت‌به‌کارت فعال نیست.'
                );
            }

            /*
             * اگر کارت‌به‌کارت فقط برای بعضی نماینده‌ها
             * فعال شده باشد، همان محدودیت فعلی رعایت می‌شود.
             */
            if (
                !$this->canUserUseMethod(
                    $paymentMethod,
                    (int) $user->id
                )
            ) {
                throw new RuntimeException(
                    'روش پرداخت کارت‌به‌کارت برای شما فعال نیست.'
                );
            }

            /*
             * همان کارتی را می‌گیریم که WalletController فعلی
             * پروژه هم نمایش می‌دهد: آخرین کارت ثبت‌شده.
             */
            $card = PanelCardNumber::query()
                ->orderByDesc('id')
                ->first();

            $digits = $card
                ? substr(
                    preg_replace(
                        '/\D/',
                        '',
                        (string) ($card->card ?? '')
                    ),
                    0,
                    16
                )
                : '';

            if (strlen($digits) !== 16) {
                throw new RuntimeException(
                    'شماره کارت معتبر ثبت نشده است.'
                );
            }

            /*
            * برای تأیید پیامکی کیف پول نمایندگان باید حداقل
            * یک SMS Device فعال متعلق به Admin وجود داشته باشد.
            *
            * شماره کارت ربات کاربران و شماره کارت Panel
            * می‌توانند متفاوت باشند.
            */
            if (!$this->hasActivePanelSmsReceiver()) {
                throw new RuntimeException(
                    'دستگاه پیامکی فعال متصل نشده است.'
                );
            }

            /*
             * Paymentهای واقعاً منقضی‌شده را آزاد می‌کنیم.
             *
             * تا 12 دقیقه بعد از expires_at کلید Match را
             * نگه می‌داریم تا SMS دیررس از دست نرود.
             *
             * اگر رسید دستی برای Payment فرستاده شده باشد،
             * Payment را Expire نمی‌کنیم چون هنوز در حال بررسی است.
             */
            $releaseBefore = now()->subMinutes(
                self::MATCH_GRACE_MINUTES
            );

            PanelWalletCardTopup::query()
                ->where('status', 'pending')
                ->whereDoesntHave(
                    'receipts',
                    function ($query) {
                        $query->whereIn(
                            'status',
                            [
                                'submitted',
                                'pending',
                            ]
                        );
                    }
                )
                ->whereNotNull('sms_match_key')
                ->whereNotNull('expires_at')
                ->where(
                    'expires_at',
                    '<=',
                    $releaseBefore
                )
                ->update([
                    'status' => 'expired',
                    'sms_match_key' => null,
                ]);

            /*
             * یک نماینده هم‌زمان فقط یک Payment فعال دارد.
             *
             * مهم نیست Payment را در پنل ساخته یا در ربات.
             * هر دو همان رکورد را خواهند دید.
             */
            $active = PanelWalletCardTopup::query()
                ->where(
                    'user_id',
                    $user->id
                )
                ->where(
                    'status',
                    'pending'
                )
                ->where(function ($query) {
                    $query
                        ->where(function ($fresh) {
                            $fresh
                                ->whereNotNull(
                                    'sms_match_key'
                                )
                                ->where(
                                    'expires_at',
                                    '>',
                                    now()
                                );
                        })
                        ->orWhereHas(
                            'receipts',
                            function ($receipt) {
                                $receipt->whereIn(
                                    'status',
                                    [
                                        'submitted',
                                        'pending',
                                    ]
                                );
                            }
                        );
                })
                ->latest('id')
                ->first();

            /*
             * اگر Payment فعال دارد، Payment جدید نمی‌سازیم.
             */
            if ($active) {
                return $active;
            }

            /*
             * مبلغ‌هایی که طی 48 ساعت گذشته استفاده شده‌اند
             * دوباره صادر نمی‌شوند.
             */
            $quarantineSince = now()->subHours(
                self::QUARANTINE_HOURS
            );

            /*
             * شناسه همکاران از 1001 تا 1999 تومان.
             */
            $extras = range(
                self::EXTRA_MIN,
                self::EXTRA_MAX
            );

            shuffle($extras);

            foreach ($extras as $extra) {
                $payable = $amount + $extra;

                /*
                 * برای جلوگیری از برخورد تومان/ریال،
                 * شکل‌های احتمالی همان مبلغ را هم بررسی می‌کنیم.
                 */
                $collisionValues = $this->collisionValues(
                    $payable
                );

                /*
                 * اول Paymentهای خود همکاران را بررسی می‌کنیم.
                 */
                $panelCollision =
                    PanelWalletCardTopup::query()
                        ->whereIn(
                            'payable_amount_irt',
                            $collisionValues
                        )
                        ->where(function ($query) use (
                            $quarantineSince
                        ) {
                            $query
                                /*
                                 * هنوز مبلغ فعال است.
                                 */
                                ->whereNotNull(
                                    'sms_match_key'
                                )

                                /*
                                 * یا در 48 ساعت اخیر ساخته شده.
                                 */
                                ->orWhere(
                                    'created_at',
                                    '>=',
                                    $quarantineSince
                                )

                                /*
                                 * یا اخیراً تأیید شده.
                                 */
                                ->orWhere(
                                    'paid_at',
                                    '>=',
                                    $quarantineSince
                                )

                                /*
                                 * یا SMS آن اخیراً دریافت شده.
                                 */
                                ->orWhere(
                                    'sms_paid_at',
                                    '>=',
                                    $quarantineSince
                                );
                        })
                        ->exists();

                if ($panelCollision) {
                    continue;
                }

                /*
                 * حالا مبلغ‌های ربات کاربران را هم بررسی می‌کنیم.
                 *
                 * پس Payment همکار نمی‌تواند مبلغی بگیرد
                 * که با یک سفارش User Bot برخورد داشته باشد.
                 */
                $userBotCollision =
                    UserBotOrder::query()
                        ->where(
                            'payment_method',
                            'card'
                        )
                        ->whereIn(
                            'card_sms_payable_amount',
                            $collisionValues
                        )
                        ->where(function ($query) use (
                            $quarantineSince
                        ) {
                            $query
                                ->whereNotNull(
                                    'card_sms_match_key'
                                )
                                ->orWhere(
                                    'created_at',
                                    '>=',
                                    $quarantineSince
                                )
                                ->orWhere(
                                    'approved_at',
                                    '>=',
                                    $quarantineSince
                                )
                                ->orWhere(
                                    'rejected_at',
                                    '>=',
                                    $quarantineSince
                                )
                                ->orWhere(
                                    'card_sms_paid_at',
                                    '>=',
                                    $quarantineSince
                                );
                        })
                        ->exists();

                if ($userBotCollision) {
                    continue;
                }

                /*
                 * مبلغ امن پیدا شد؛ Payment ساخته می‌شود.
                 */
                return PanelWalletCardTopup::create([
                    'user_id' =>
                        $user->id,

                    /*
                     * مبلغی که واقعاً به کیف پول می‌رود.
                     */
                    'amount_irt' =>
                        $amount,

                    /*
                     * مبلغی که کاربر باید به بانک پرداخت کند.
                     */
                    'payable_amount_irt' =>
                        $payable,

                    'unique_extra' =>
                        $extra,

                    /*
                     * کلید Match داخلی.
                     */
                    'sms_match_key' =>
                        'panel_wallet:'
                        . $payable
                        . ':'
                        . bin2hex(
                            random_bytes(5)
                        ),

                    'status' =>
                        'pending',

                    'created_via' =>
                        $source,

                    /*
                     * Snapshot کارت؛ اگر بعداً شماره کارت
                     * در Settings عوض شد Payment قبلی مشخص است
                     * برای کدام کارت صادر شده بود.
                     */
                    'card_number' =>
                        $digits,

                    'card_holder' =>
                        (string) ($card->name ?? ''),

                    /*
                     * مهلت دقیق 30 دقیقه.
                     */
                    'expires_at' =>
                        now()->addMinutes(
                            self::EXPIRES_MINUTES
                        ),

                    'meta' => [],
                ]);
            }

            throw new RuntimeException(
                'در حال حاضر امکان ساخت مبلغ دقیق یکتا وجود ندارد.'
            );
        }, 3);
    }

    /**
     * لغو Payment فعال.
     *
     * اگر رسید قبلاً ثبت شده باشد دیگر اجازه لغو نداریم.
     */
    public function cancel(
        PanelWalletCardTopup $topup,
        int $userId
    ): PanelWalletCardTopup {
        return DB::transaction(
            function () use (
                $topup,
                $userId
            ) {
                $locked =
                    PanelWalletCardTopup::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $topup->id
                        );

                if (
                    (int) $locked->user_id
                    !==
                    $userId
                ) {
                    throw new RuntimeException(
                        'اجازه دسترسی ندارید.'
                    );
                }

                if ($locked->status !== 'pending') {
                    throw new RuntimeException(
                        'این پرداخت دیگر قابل لغو نیست.'
                    );
                }

                /*
                 * بعد از Submit رسید دیگر:
                 *
                 * - تغییر مبلغ
                 * - لغو Payment
                 *
                 * مجاز نیست.
                 */
                $hasSubmittedReceipt =
                    $locked
                        ->receipts()
                        ->whereIn(
                            'status',
                            [
                                'submitted',
                                'pending',
                            ]
                        )
                        ->exists();

                if ($hasSubmittedReceipt) {
                    throw new RuntimeException(
                        'پس از ارسال رسید امکان تغییر مبلغ یا لغو پرداخت وجود ندارد.'
                    );
                }

                /*
                 * خود مبلغ در Quarantine باقی می‌ماند
                 * چون رکورد حذف نمی‌شود.
                 */
                $locked->update([
                    'status' => 'cancelled',
                    'sms_match_key' => null,
                ]);

                return $locked->fresh();
            }
        );
    }

    /**
     * Payment فعال یک نماینده.
     *
     * این متد بعداً هم توسط پنل و هم ربات استفاده می‌شود.
     */
    public function activeForUser(
        int $userId
    ): ?PanelWalletCardTopup {
        return PanelWalletCardTopup::query()
            ->where(
                'user_id',
                $userId
            )
            ->where(
                'status',
                'pending'
            )
            ->where(function ($query) {
                $query
                    ->where(
                        'expires_at',
                        '>',
                        now()
                    )
                    ->orWhereHas(
                        'receipts',
                        function ($receipt) {
                            $receipt->whereIn(
                                'status',
                                [
                                    'submitted',
                                    'pending',
                                ]
                            );
                        }
                    );
            })
            ->latest('id')
            ->first();
    }

    /**
     * بررسی وجود SMS Receiver فعال متعلق به Admin.
     */
    private function hasActivePanelSmsReceiver(): bool
    {
        return UserBotSetting::query()
            ->where('owner_type', 'admin')
            ->whereHas('smsDevices', function ($q) {
                $q->where('is_enabled', true);
            })
            ->exists();
    }

    /**
     * همان محدودیت access_mode فعلی PanelPaymentMethod.
     */
    private function canUserUseMethod(
        PanelPaymentMethod $method,
        int $userId
    ): bool {
        if (
            ($method->access_mode ?? 'all')
            ===
            'all'
        ) {
            return true;
        }

        return in_array(
            $userId,
            array_map(
                'intval',
                $method->partner_ids ?: []
            ),
            true
        );
    }

    /**
     * جلوگیری از Collision در حالت تومان/ریال.
     */
    private function collisionValues(
        int $amount
    ): array {
        $values = [
            $amount,
            $amount * 10,
        ];

        /*
         * اگر مبلغ بر 10 بخش‌پذیر بود، حالت معکوس
         * ریال → تومان را هم بررسی می‌کنیم.
         */
        if ($amount % 10 === 0) {
            $values[] = intdiv(
                $amount,
                10
            );
        }

        return array_values(
            array_unique($values)
        );
    }
}