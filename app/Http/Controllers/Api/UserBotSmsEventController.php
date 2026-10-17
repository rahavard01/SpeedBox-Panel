<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserBotSetting;
use App\Models\UserBotSmsDevice;
use App\Models\UserBotSmsEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserBotSmsEventController extends Controller
{
    private const RETENTION_DAYS = 30;
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 50;

    public function index(Request $request)
    {
        $setting = $this->resolveSetting($request);

        $data = $request->validate([
            'device_id' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:' . self::MAX_PER_PAGE,
            ],
        ]);

        $deviceId = isset($data['device_id'])
            ? (int) $data['device_id']
            : null;

        /*
         * جلوگیری از مشاهده دستگاه متعلق به ربات دیگر.
         */
        if ($deviceId !== null) {
            $deviceExists = UserBotSmsDevice::query()
                ->where(
                    'setting_id',
                    $setting->id
                )
                ->where(
                    'id',
                    $deviceId
                )
                ->exists();

            if (!$deviceExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'دستگاه پیدا نشد',
                ], 404);
            }
        }

        $perPage = (int) (
            $data['per_page']
            ?? self::DEFAULT_PER_PAGE
        );

        $cutoff = now()->subDays(
            self::RETENTION_DAYS
        );

        $paginator = UserBotSmsEvent::query()
            ->where(
                'setting_id',
                $setting->id
            )
            ->where(
                'created_at',
                '>=',
                $cutoff
            )
            ->when(
                $deviceId !== null,
                fn ($query) =>
                    $query->where(
                        'device_id',
                        $deviceId
                    )
            )
            ->with([
                'device:id,name,platform',
                'matchedOrder:id,order_code',
            ])
            ->orderByDesc(
                'received_at_server'
            )
            ->orderByDesc('id')
            ->paginate($perPage);

        $events = $paginator
            ->getCollection()
            ->map(
                fn (UserBotSmsEvent $event) =>
                    $this->eventData($event)
            )
            ->values();

        return response()->json([
            'status' => 'success',

            'data' => [
                'events' => $events,

                'meta' => [
                    'current_page' =>
                        $paginator->currentPage(),

                    'last_page' =>
                        $paginator->lastPage(),

                    'per_page' =>
                        $paginator->perPage(),

                    'total' =>
                        $paginator->total(),

                    'has_more' =>
                        $paginator->hasMorePages(),

                    'retention_days' =>
                        self::RETENTION_DAYS,
                ],
            ],
        ]);
    }

    public function deviceHistory(Request $request)
    {
        $data = $request->validate([
            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:' . self::MAX_PER_PAGE,
            ],
        ]);

        $rawToken = trim(
            (string) $request->bearerToken()
        );

        if ($rawToken === '') {
            return response()->json([
                'status' => 'error',
                'message' =>
                    'توکن دستگاه ارسال نشده است.',
            ], 401);
        }

        $device = UserBotSmsDevice::query()
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

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' =>
                    'دستگاه معتبر یا فعال نیست.',
            ], 401);
        }

        $perPage = (int) (
            $data['per_page']
            ?? self::DEFAULT_PER_PAGE
        );

        $cutoff = now()->subDays(
            self::RETENTION_DAYS
        );

        $paginator = UserBotSmsEvent::query()
            ->where(
                'setting_id',
                $device->setting_id
            )
            ->where(
                'device_id',
                $device->id
            )
            ->where(
                'created_at',
                '>=',
                $cutoff
            )
            ->with([
                'device:id,name,platform',
                'matchedOrder:id,order_code',
            ])
            ->orderByDesc(
                'received_at_server'
            )
            ->orderByDesc('id')
            ->paginate($perPage);

        $events = $paginator
            ->getCollection()
            ->map(
                fn (UserBotSmsEvent $event) =>
                    $this->eventData($event)
            )
            ->values();

        return response()->json([
            'status' => 'success',

            'data' => [
                'events' => $events,

                'meta' => [
                    'current_page' =>
                        $paginator->currentPage(),

                    'last_page' =>
                        $paginator->lastPage(),

                    'per_page' =>
                        $paginator->perPage(),

                    'total' =>
                        $paginator->total(),

                    'has_more' =>
                        $paginator->hasMorePages(),

                    'retention_days' =>
                        self::RETENTION_DAYS,
                ],
            ],
        ]);
    }

    /*
     * فقط اطلاعات امن و لازم برای نمایش در پنل.
     *
     * موارد زیر عمداً برگردانده نمی‌شوند:
     * request_ip
     * event_id
     * matched_numeric_value
     * matched_order_id
     */
    private function eventData(
        UserBotSmsEvent $event
    ): array {
        $presentation =
            $this->statusPresentation(
                (string) $event->status
            );

        $receivedAt =
            $event->received_at_device
            ?: $event->received_at_server;

        return [
            /*
             * کلید نمایشی غیرقابل حدس
             * به‌جای ID داخلی دیتابیس.
             */
            'key' => substr(
                hash(
                    'sha256',
                    $event->id
                    . '|'
                    . $event->event_id
                ),
                0,
                24
            ),

            'status' =>
                (string) $event->status,

            'status_label' =>
                $presentation['label'],

            'status_tone' =>
                $presentation['tone'],

            'device_name' =>
                $event->device?->name
                ?: 'دستگاه حذف‌شده',

            'device_platform' =>
                $event->device?->platform
                ?: $event->platform,

            'matched_amount_toman' =>
                $event->matched_amount_toman !== null
                    ? (int) $event
                        ->matched_amount_toman
                    : null,

            /*
             * فقط کد سفارش قابل نمایش،
             * نه ID داخلی سفارش.
             */
            'order_code' =>
                $event->matchedOrder
                    ?->order_code,

            'payment_type' =>
                $event->matched_card_topup_id
                    ? 'partner_wallet'
                    : ($event->matched_order_id
                        ? 'user_bot_order'
                        : null),

            'payment_type_label' =>
                $event->matched_card_topup_id
                    ? 'شارژ کیف پول نماینده'
                    : ($event->matched_order_id
                        ? 'سفارش ربات کاربران'
                        : null),

            'reason' =>
                $this->publicReason($event),

            'received_at' =>
                optional(
                    $receivedAt
                )->toIso8601String(),
        ];
    }

    private function statusPresentation(
        string $status
    ): array {
        return match ($status) {
            'approved' => [
                'label' => 'تأیید موفق',
                'tone' => 'success',
            ],

            'already_done' => [
                'label' => 'قبلاً انجام شده',
                'tone' => 'success',
            ],

            'matched',
            'processing',
            'already_processing' => [
                'label' => 'در حال پردازش',
                'tone' => 'warning',
            ],

            'retry_pending' => [
                'label' => 'نیازمند بررسی مدیر',
                'tone' => 'warning',
            ],

            'unmatched' => [
                'label' => 'بدون تطبیق',
                'tone' => 'neutral',
            ],

            'ambiguous' => [
                'label' => 'تطبیق مبهم',
                'tone' => 'warning',
            ],

            'stale_event' => [
                'label' => 'پیامک قدیمی',
                'tone' => 'neutral',
            ],

            'future_event' => [
                'label' => 'زمان دستگاه نامعتبر',
                'tone' => 'danger',
            ],

            'feature_disabled' => [
                'label' => 'قابلیت غیرفعال',
                'tone' => 'neutral',
            ],

            'processing_error',
            'approval_failed',
            'order_not_found',
            'not_approvable' => [
                'label' => 'پردازش ناموفق',
                'tone' => 'danger',
            ],

            default => [
                'label' => 'ثبت‌شده',
                'tone' => 'neutral',
            ],
        };
    }

    private function publicReason(
        UserBotSmsEvent $event
    ): ?string {
        /*
         * failure_reason در جریان پرداخت پیامکی
         * فقط با پیام امن و قابل نمایش پر می‌شود.
         * بنابراین در اولویت قرار می‌گیرد تا علت واقعی
         * مواردی مثل کمبود اعتبار یا انقضای اکانت
         * در سوابق پنل به مدیر نمایش داده شود.
         */
        $storedReason = trim(
            (string) ($event->failure_reason ?? '')
        );

        if ($storedReason !== '') {
            return $storedReason;
        }

        return match (
            (string) $event->status
        ) {
            'processing_error' =>
                'خطای موقت در پردازش سفارش رخ داد؛ درخواست دوباره بررسی خواهد شد.',

            'approval_failed' =>
                'پرداخت پیدا شد، اما انجام سفارش موقتاً انجام نشد.',

            'retry_pending' =>
                'پرداخت تأیید شد، اما انجام سفارش نیازمند بررسی مدیر است.',

            'order_not_found' =>
                'سفارش تطبیق‌یافته پیدا نشد.',

            'not_approvable' =>
                'سفارش در وضعیت قابل پردازش نیست.',

            'already_processing' =>
                'سفارش هم‌اکنون در حال پردازش است.',

            'already_done' =>
                'سفارش قبلاً انجام شده است.',

            default => null,
        };
    }

    private function resolveSetting(
        Request $request
    ): UserBotSetting {
        $user = $request->user();

        if (!$user) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 401));
        }

        $panelUser = DB::table(
            'panel_users'
        )
            ->whereRaw(
                'LOWER(email) = ?',
                [
                    strtolower(
                        (string) $user->email
                    ),
                ]
            )
            ->first();

        if (!$panelUser) {
            abort(response()->json([
                'status' => 'error',

                'message' =>
                    'Panel user not found',
            ], 404));
        }

        $ownerType =
            (int) ($panelUser->role ?? 0) === 1
                ? 'admin'
                : 'partner';

        $setting = UserBotSetting::query()
            ->where(
                'owner_type',
                $ownerType
            )
            ->where(
                'owner_id',
                (int) $panelUser->id
            )
            ->first();

        if (!$setting) {
            abort(response()->json([
                'status' => 'error',

                'message' =>
                    'ابتدا تنظیمات ربات کاربران را ذخیره کنید',
            ], 422));
        }

        return $setting;
    }
}