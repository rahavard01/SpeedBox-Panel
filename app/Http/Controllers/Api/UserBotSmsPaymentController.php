<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PanelWalletCardTopup;
use App\Models\UserBotOrder;
use App\Models\UserBotSetting;
use App\Models\UserBotSmsDevice;
use App\Models\UserBotSmsEvent;
use App\Services\PanelWallet\PanelWalletCardTopupApprovalService;
use App\Services\UserBot\UserBotCardOrderApprovalService;
use App\Services\UserBot\UserBotOrderDeliveryService;
use App\Services\UserBotSms\SmsPaymentMatcher;
use App\Services\TelegramNotifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserBotSmsPaymentController extends Controller
{
    private const EVENT_MAX_AGE_MINUTES = 10;
    private const EVENT_MAX_FUTURE_DRIFT_MINUTES = 2;

    public function store(
        Request $request,
        SmsPaymentMatcher $matcher
    ) {
        /*
        * Shortcuts ممکن است متغیر لیستی FinalCandidates را
        * به‌صورت آرایه معمولی یا یک آرایه داخل آرایه ارسال کند.
        *
        * فقط حالت یک‌لایه باز می‌شود؛ ساختارهای عمیق‌تر
        * همچنان توسط Validation رد خواهند شد.
        */
        $amountCandidatesInput = $request->input(
            "amount_candidates",
            []
        );

        if (
            is_array($amountCandidatesInput)
            && count($amountCandidatesInput) === 1
            && isset($amountCandidatesInput[0])
            && is_array($amountCandidatesInput[0])
        ) {
            $request->merge([
                "amount_candidates" => array_values(
                    $amountCandidatesInput[0]
                ),
            ]);
        }

        $data = $request->validate([
            'sms' => ['prohibited'],
            'raw_sms' => ['prohibited'],
            'sanitized_sms' => ['prohibited'],
            'numeric_tokens' => ['prohibited'],

            "event_id" => [
                "required",
                "string",
                "size:64",
                "regex:/\A[a-f0-9]{64}\z/i",
            ],

            'platform' => [
                'required',
                'string',
                'in:ios,android',
            ],

            'received_at' => [
                'required',
                'date',
            ],

            'credit_signal_detected' => [
                'required',
                'accepted',
            ],

            'debit_signal_detected' => [
                'required',
                'declined',
            ],

            'sensitive_content_detected' => [
                'required',
                'declined',
            ],

            'amount_candidates' => [
                'required',
                'array',
                'min:1',
                'max:5',
            ],

            'amount_candidates.*' => [
                'required',
                'integer',
                'min:1000',
                'max:9999999999999',
            ],

            'local_filter_version' => [
                'nullable',
                'string',
                'max:40',
            ],
        ]);

        $rawToken = trim(
            (string) $request->bearerToken()
        );

        if ($rawToken === '') {
            return response()->json([
                'ok' => false,
                'message' =>
                    'توکن دستگاه ارسال نشده است.',
            ], 401);
        }

        $device = UserBotSmsDevice::query()
            ->with('setting')
            ->where(
                'token_hash',
                hash(
                    'sha256',
                    $rawToken
                )
            )
            ->where(
                'is_enabled',
                true
            )
            ->first();

        if (!$device || !$device->setting) {
            return response()->json([
                'ok' => false,
                'message' =>
                    'دستگاه معتبر یا فعال نیست.',
            ], 401);
        }

        if (
            $device->platform
            !== $data['platform']
        ) {
            return response()->json([
                'ok' => false,
                'message' =>
                    'نوع دستگاه با اتصال ثبت‌شده مطابقت ندارد.',
            ], 422);
        }

        /** @var UserBotSetting $setting */
        $setting = $device->setting;

        $existingEvent =
            UserBotSmsEvent::query()
                ->where(
                    'device_id',
                    $device->id
                )
                ->where(
                    'event_id',
                    $data['event_id']
                )
                ->first();

        if ($existingEvent) {
            $this->touchDevice(
                $device,
                $request
            );

            $existingEvent =
                $this->resumeEventIfNeeded(
                    $existingEvent,
                    $setting
                );

            return $this->eventResponse(
                $existingEvent,
                true
            );
        }

        $amountCandidates = array_values(
            array_unique(
                array_map(
                    'intval',
                    $data['amount_candidates']
                )
            )
        );

        $receivedAt = Carbon::parse(
            $data['received_at']
        );

        $serverNow = now();

        if (
            $receivedAt->lt(
                $serverNow->copy()->subMinutes(
                    self::EVENT_MAX_AGE_MINUTES
                )
            )
        ) {
            $matchResult = [
                'status' => 'stale_event',
                'order_id' => null,
                'card_topup_id' => null,
                'matched_amount_toman' => null,
                'matched_numeric_value' => null,
                'reason' =>
                    'زمان پیامک بیش از حد قدیمی است و برای جلوگیری از پردازش دوباره پذیرفته نشد.',
            ];
        } elseif (
            $receivedAt->gt(
                $serverNow->copy()->addMinutes(
                    self::EVENT_MAX_FUTURE_DRIFT_MINUTES
                )
            )
        ) {
            $matchResult = [
                'status' => 'future_event',
                'order_id' => null,
                'card_topup_id' => null,
                'matched_amount_toman' => null,
                'matched_numeric_value' => null,
                'reason' =>
                    'زمان پیامک از زمان سرور جلوتر است؛ ساعت دستگاه را بررسی کنید.',
            ];
        } elseif (
            (!(bool) $setting->card_payment_enabled || !(bool) $setting->card_sms_auto_approve_enabled)
            && !$this->hasPendingPanelWalletTopupForSetting($setting, $receivedAt)
        ) {
            $matchResult = [
                'status' => 'feature_disabled',
                'order_id' => null,
                'card_topup_id' => null,
                'matched_amount_toman' => null,
                'matched_numeric_value' => null,
                'reason' => 'تأیید آنی پیامکی برای این ربات یا کیف پول نمایندگان فعال نیست.',
            ];
        } else {
            $matchResult = [
                'status' => 'processing',
                'order_id' => null,
                'matched_amount_toman' => null,
                'matched_numeric_value' => null,
                'reason' =>
                    'درخواست دریافت شد و در حال پردازش است.',
            ];
        }

        try {
            $event =
                UserBotSmsEvent::create([
                    'device_id' =>
                        $device->id,

                    'setting_id' =>
                        $setting->id,

                    'event_id' =>
                        $data['event_id'],

                    'platform' =>
                        $data['platform'],

                    'local_filter_version' =>
                        $data[
                            'local_filter_version'
                        ] ?? null,

                    'candidate_count' =>
                        count(
                            $amountCandidates
                        ),

                    'status' =>
                        $matchResult['status'],

                    'matched_order_id' =>
                        $matchResult['order_id'] ?? null,

                    'matched_card_topup_id' =>
                        $matchResult['card_topup_id'] ?? null,

                    'matched_amount_toman' =>
                        $matchResult[
                            'matched_amount_toman'
                        ],

                    'matched_numeric_value' =>
                        $matchResult[
                            'matched_numeric_value'
                        ],

                    'failure_reason' =>
                        $matchResult['reason'],

                    'received_at_device' =>
                        $receivedAt,

                    'received_at_server' =>
                        now(),

                    'request_ip' =>
                        $request->ip(),
                ]);
        } catch (QueryException $e) {
            $event =
                UserBotSmsEvent::query()
                    ->where(
                        'device_id',
                        $device->id
                    )
                    ->where(
                        'event_id',
                        $data['event_id']
                    )
                    ->first();

            if (!$event) {
                throw $e;
            }

            $this->touchDevice(
                $device,
                $request
            );

            $event =
                $this->resumeEventIfNeeded(
                    $event,
                    $setting
                );

            return $this->eventResponse(
                $event,
                true
            );
        }

        $this->touchDevice(
            $device,
            $request
        );

        if ((string) $event->status === 'processing') {
            $this->processEventAfterResponse(
                (int) $event->id,
                (int) $setting->id,
                $amountCandidates,
                $receivedAt,
                $matcher
            );
        }

        return $this->eventResponse(
            $event,
            false
        );
    }

    private function processEventAfterResponse(
        int $eventId,
        int $settingId,
        array $amountCandidates,
        Carbon $receivedAt,
        SmsPaymentMatcher $matcher
    ): void {
        app()->terminating(function () use (
            $eventId,
            $settingId,
            $amountCandidates,
            $receivedAt,
            $matcher
        ): void {
            try {
                $event = UserBotSmsEvent::find($eventId);
                $setting = UserBotSetting::find($settingId);

                if (
                    !$event
                    || !$setting
                    || (string) $event->status !== 'processing'
                    || $event->matched_order_id
                    || $event->matched_card_topup_id
                ) {
                    return;
                }

                $matchResult = $matcher->match(
                    $setting,
                    $amountCandidates,
                    $receivedAt
                );

                $updated = UserBotSmsEvent::query()
                    ->where('id', $eventId)
                    ->where('status', 'processing')
                    ->whereNull('matched_order_id')
                    ->whereNull('matched_card_topup_id')
                    ->update([
                        'status' =>
                            $matchResult['status'],

                        'matched_order_id' =>
                            $matchResult['order_id'] ?? null,

                        'matched_card_topup_id' =>
                            $matchResult['card_topup_id'] ?? null,

                        'matched_amount_toman' =>
                            $matchResult['matched_amount_toman'],

                        'matched_numeric_value' =>
                            $matchResult['matched_numeric_value'],

                        'failure_reason' =>
                            $matchResult['reason'],
                    ]);

                if ($updated !== 1) {
                    return;
                }

                $event = UserBotSmsEvent::find($eventId);

                if ($event) {
                    $this->resumeEventIfNeeded(
                        $event,
                        $setting
                    );
                }
            } catch (Throwable $e) {
                report($e);

                UserBotSmsEvent::query()
                    ->where('id', $eventId)
                    ->where('status', 'processing')
                    ->whereNull('matched_order_id')
                    ->whereNull('matched_card_topup_id')
                    ->update([
                        'status' =>
                            'matching_error',

                        'failure_reason' =>
                            'خطای موقت در تطبیق پرداخت رخ داد.',
                    ]);
            }
        });
    }

    private function resumeEventIfNeeded(
        UserBotSmsEvent $event,
        UserBotSetting $setting
    ): UserBotSmsEvent {
        if (
            !in_array(
                $event->status,
                [
                    'matched',
                    'processing_error',
                ],
                true
            )
        ) {
            return $event->fresh()
                ?: $event;
        }

        return $this->processMatchedEvent(
            $event,
            $setting
        );
    }

    private function processMatchedEvent(
        UserBotSmsEvent $event,
        UserBotSetting $setting
    ): UserBotSmsEvent {
        $claimedEvent =
            DB::transaction(
                function () use ($event) {
                    $locked =
                        UserBotSmsEvent::query()
                            ->where(
                                'id',
                                $event->id
                            )
                            ->lockForUpdate()
                            ->first();

                    if (!$locked) {
                        return null;
                    }

                    if (
                        !in_array(
                            $locked->status,
                            [
                                'matched',
                                'processing_error',
                            ],
                            true
                        )
                    ) {
                        return null;
                    }

                    if (!$locked->matched_order_id && !$locked->matched_card_topup_id) {
                        $locked->update([
                            'status' => 'order_not_found',
                            'failure_reason' => 'شناسه پرداخت تطبیق‌یافته موجود نیست.',
                        ]);

                        return null;
                    }

                    $locked->update([
                        'status' =>
                            'processing',

                        'failure_reason' =>
                            null,
                    ]);

                    return $locked->fresh();
                }
            );

        if (!$claimedEvent) {
            return $event->fresh()
                ?: $event;
        }

        try {
            if ($claimedEvent->matched_card_topup_id) {
                $approval = app(PanelWalletCardTopupApprovalService::class)->approve(
                    (int) $claimedEvent->matched_card_topup_id,
                    'sms',
                    null
                );

                if (!empty($approval['ok'])) {
                    $claimedEvent->update([
                        'status' => 'approved',
                        'failure_reason' => null,
                    ]);

                    try {
                        $receiptId = \App\Models\PanelWalletReceipt::query()
                            ->where('card_topup_id', (int)$claimedEvent->matched_card_topup_id)
                            ->where('status', 'verified')
                            ->latest('id')
                            ->value('id');

                        app(TelegramNotifier::class)->notifyPanelWalletSmsApproved(
                            (int)$claimedEvent->matched_card_topup_id,
                            $receiptId ? (int)$receiptId : null
                        );
                    } catch (Throwable $e) {
                        report($e);
                    }

                    return $claimedEvent->fresh();
                }

                $state = (string) ($approval['state'] ?? '');
                $code = (string) ($approval['code'] ?? '');

                if ($state === 'already_done' || $code === 'ALREADY_PAID') {
                    $eventStatus = 'already_done';
                } elseif ($state === 'not_found' || $code === 'NOT_FOUND') {
                    $eventStatus = 'order_not_found';
                } elseif ($state === 'not_approvable' || in_array($code, ['INVALID_STATE', 'EXPIRED', 'CANCELLED'], true)) {
                    $eventStatus = 'not_approvable';
                } else {
                    $eventStatus = 'approval_failed';
                }

                $claimedEvent->update([
                    'status' => $eventStatus,
                    'failure_reason' => (string) ($approval['message'] ?? 'خطا در شارژ کیف پول'),
                ]);

                return $claimedEvent->fresh();
            }

            $approval = app(
                UserBotCardOrderApprovalService::class
            )->approve(
                (int) $claimedEvent
                    ->matched_order_id,
                'sms'
            );

            $approvedOrder =
                $approval['order']
                    instanceof UserBotOrder
                        ? $approval['order']
                        : UserBotOrder::find(
                            $claimedEvent
                                ->matched_order_id
                        );

            if (!empty($approval['ok'])) {
                $claimedEvent->update([
                    'status' =>
                        'approved',

                    'failure_reason' =>
                        null,
                ]);

                if ($approvedOrder) {
                    try {
                        app(
                            UserBotOrderDeliveryService::class
                        )->notifyApproved(
                            $setting,
                            $approvedOrder,
                            $approval,
                            'sms'
                        );
                    } catch (Throwable $e) {
                        report($e);

                        $claimedEvent->update([
                            'failure_reason' =>
                                'سفارش انجام شد، اما ارسال اعلان تلگرام در صف تلاش مجدد قرار گرفت.',
                        ]);
                    }
                }

                return $claimedEvent
                    ->fresh();
            }

            $state = (string) (
                $approval['state']
                ?? 'failed'
            );

            $eventStatus =
                match ($state) {
                    'retry_pending' =>
                        'retry_pending',

                    'already_done' =>
                        'already_done',

                    'already_processing' =>
                        'already_processing',

                    'not_approvable' =>
                        'not_approvable',

                    'not_found' =>
                        'order_not_found',

                    default =>
                        'approval_failed',
                };

            $message = (string) (
                $approval['message']
                ?? 'خطا در انجام سفارش'
            );

            $claimedEvent->update([
                'status' =>
                    $eventStatus,

                'failure_reason' =>
                    $message,
            ]);

            if (
                $state === 'retry_pending'
                && $approvedOrder
            ) {
                try {
                    app(
                        UserBotOrderDeliveryService::class
                    )->notifyRetryPending(
                        $setting,
                        $approvedOrder,
                        $message
                    );
                } catch (Throwable $e) {
                    report($e);

                    $claimedEvent->update([
                        'failure_reason' =>
                            $message
                            . ' | ارسال اعلان مدیر موقتاً ناموفق بود.',
                    ]);
                }
            }

            return $claimedEvent->fresh();
        } catch (Throwable $e) {
            report($e);

            $claimedEvent->update([
                'status' =>
                    'processing_error',

                'failure_reason' =>
                    'خطای موقت در پردازش سفارش رخ داد؛ درخواست در اجرای بعدی دوباره بررسی می‌شود.',
            ]);

            return $claimedEvent->fresh();
        }
    }

    private function publicEventReason(
        UserBotSmsEvent $event
    ): ?string {
        return match ((string) $event->status) {
            'matching_error' =>
                'خطای موقت در تطبیق پرداخت رخ داد.',

            'processing_error' =>
                'خطای موقت در پردازش پرداخت رخ داد؛ درخواست دوباره بررسی خواهد شد.',

            'approval_failed' =>
                'پرداخت پیدا شد، اما تکمیل عملیات موقتاً انجام نشد.',

            'retry_pending' =>
                'پرداخت تأیید شد، اما انجام سفارش نیازمند بررسی مدیر است.',

            'order_not_found' =>
                'پرداخت تطبیق‌یافته پیدا نشد.',

            'not_approvable' =>
                'پرداخت در وضعیت قابل پردازش نیست.',

            'already_processing' =>
                'سفارش هم‌اکنون در حال پردازش است.',

            'already_done' =>
                'سفارش قبلاً انجام شده است.',

            default => $event->failure_reason
                ? (string) $event->failure_reason
                : null,
        };
    }

    private function hasPendingPanelWalletTopupForSetting(
        UserBotSetting $setting,
        Carbon $receivedAt
    ): bool {
        if ((string) $setting->owner_type !== 'admin') {
            return false;
        }

        return PanelWalletCardTopup::query()
            ->where('status', 'pending')
            ->whereNotNull('sms_match_key')
            ->where('expires_at', '>=', $receivedAt->copy()->subMinutes(2))
            ->exists();
    }

    private function touchDevice(
        UserBotSmsDevice $device,
        Request $request
    ): void {
        $device->update([
            'last_seen_at' =>
                now(),

            'last_ip' =>
                $request->ip(),
        ]);
    }

    private function eventResponse(
        UserBotSmsEvent $event,
        bool $duplicate
    ) {
        return response()->json([
            'ok' => true,

            'mode' =>
                'live',

            'duplicate' =>
                $duplicate,

            'event' => [
                'id' =>
                    $event->id,

                'status' =>
                    $event->status,

                'safe_match' =>
                    (bool) ($event->matched_order_id || $event->matched_card_topup_id),

                'order_processed' =>
                    in_array(
                        (string) $event->status,
                        [
                            'approved',
                            'already_done',
                        ],
                        true
                    ),

                'matched_order_id' =>
                    $event->matched_order_id,

                'matched_card_topup_id' =>
                    $event->matched_card_topup_id,

                'matched_amount_toman' =>
                    $event
                        ->matched_amount_toman,

                'matched_numeric_value' =>
                    $event
                        ->matched_numeric_value,

                'candidate_count' =>
                    $event
                        ->candidate_count,

                'reason' =>
                    $this->publicEventReason(
                        $event
                    ),

                'received_at_server' =>
                    optional(
                        $event
                            ->received_at_server
                    )->toIso8601String(),
            ],
        ]);
    }
}