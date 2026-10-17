<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PanelPaymentMethod;
use App\Models\UserBotSetting;
use App\Models\UserBotSmsDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserBotSmsDeviceController extends Controller
{
    /*
     * فهرست دستگاه‌های متعلق به صاحب ربات فعلی.
     */
    public function index(Request $request)
    {
        $setting = $this->resolveSetting($request);

        $devices = UserBotSmsDevice::query()
            ->where('setting_id', $setting->id)
            ->withCount([
                'events as events_count' =>
                    fn ($query) => $query->where(
                        'created_at',
                        '>=',
                        now()->subDays(30)
                    ),
            ])
            ->orderByDesc('id')
            ->get()
            ->map(
                fn (UserBotSmsDevice $device) =>
                    $this->deviceData($device)
            )
            ->values();

        return response()->json([
            'status' => 'success',

            'data' => [
                'devices' => $devices,

                /*
                * اپ اندروید آماده و قابل اتصال است.
                */
                'android_available' => true,

                /*
                * Shortcut آیفون آماده و قابل نصب است.
                */
                'shortcut_available' => true,
            ],
        ]);
    }

    /*
     * ساخت دستگاه جدید و صدور توکن.
     *
     * توکن خام فقط در همین پاسخ برگردانده می‌شود.
     */
    public function store(Request $request)
    {
        $setting = $this->resolveSetting($request);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                'regex:/\S/u',
            ],

            /*
            * دستگاه آیفون و اندروید هر دو قابل ثبت هستند.
            */
            'platform' => [
                'required',
                'string',
                'in:ios,android',
            ],
        ], [
            'name.regex' =>
                'نام دستگاه نمی‌تواند خالی باشد',
        ]);

        $userBotSmsEnabled =
            (bool) $setting->card_payment_enabled
            && (bool) $setting->card_sms_auto_approve_enabled;

        $panelWalletSmsEnabled =
            (string) $setting->owner_type === 'admin'
            && PanelPaymentMethod::query()
                ->where('method', 'card')
                ->where('enabled', true)
                ->where('sms_auto_approve_enabled', true)
                ->exists();

        if (!$userBotSmsEnabled && !$panelWalletSmsEnabled) {
            return response()->json([
                'status' => 'error',
                'message' => 'ابتدا تأیید آنی پرداخت پیامکی ربات کاربران یا شارژ کیف پول نمایندگان را فعال کنید',
            ], 422);
        }
        $rawToken =
            'SMS-' . Str::random(64);

        $device = UserBotSmsDevice::create([
            'setting_id' =>
                $setting->id,

            'name' =>
                trim($data['name']),

            'platform' =>
                $data['platform'],

            /*
             * توکن خام در دیتابیس ذخیره نمی‌شود.
             */
            'token_hash' =>
                hash(
                    'sha256',
                    $rawToken
                ),

            'is_enabled' =>
                true,

            'last_seen_at' =>
                null,

            'last_ip' =>
                null,

            'metadata' => [
                'created_from' =>
                    'panel',

                'local_filter_version' =>
                    null,

                'last_ping_at' =>
                    null,
            ],
        ]);

        return response()
            ->json([
                'status' => 'success',

                'message' =>
                    'دستگاه ساخته شد. توکن فقط همین یک بار نمایش داده می‌شود',

                'data' => [
                    'device' =>
                        $this->deviceData(
                            $device
                        ),

                    /*
                     * این مقدار فقط همین یک بار
                     * برای قرارگرفتن داخل Shortcut نمایش داده می‌شود.
                     */
                    'token' =>
                        $rawToken,
                ],
            ], 201)
            ->withHeaders([
                'Cache-Control' =>
                    'no-store, no-cache, must-revalidate, private',

                'Pragma' =>
                    'no-cache',
            ]);
    }

    /*
     * تغییر نام دستگاه.
     */
    public function update(
        Request $request,
        int $deviceId
    ) {
        $setting = $this->resolveSetting($request);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                'regex:/\S/u',
            ],
        ], [
            'name.regex' =>
                'نام دستگاه نمی‌تواند خالی باشد',
        ]);

        $device = $this->ownedDevice(
            $setting,
            $deviceId
        );

        $device->update([
            'name' =>
                trim($data['name']),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'نام دستگاه تغییر کرد',

            'data' => [
                'device' =>
                    $this->deviceData(
                        $device->fresh()
                    ),
            ],
        ]);
    }

    /*
     * ساخت توکن جدید برای دستگاه.
     *
     * به‌محض اجرای این متد، توکن قبلی دیگر معتبر نیست.
     */
    public function regenerateToken(
        Request $request,
        int $deviceId
    ) {
        $setting = $this->resolveSetting($request);

        $device = $this->ownedDevice(
            $setting,
            $deviceId
        );

        $rawToken =
            'SMS-' . Str::random(64);

        $metadata =
            $device->metadata ?: [];

        $metadata['token_regenerated_at'] =
            now()->toIso8601String();

        $metadata['last_ping_at'] =
            null;

        $device->update([
            'token_hash' =>
                hash(
                    'sha256',
                    $rawToken
                ),

            'is_enabled' =>
                true,

            'last_seen_at' =>
                null,

            'last_ip' =>
                null,

            'metadata' =>
                $metadata,
        ]);

        return response()
            ->json([
                'status' => 'success',

                'message' =>
                    'توکن جدید ساخته شد و توکن قبلی دیگر معتبر نیست',

                'data' => [
                    'device' =>
                        $this->deviceData(
                            $device->fresh()
                        ),

                    /*
                     * توکن جدید هم فقط یک بار نمایش داده می‌شود.
                     */
                    'token' =>
                        $rawToken,
                ],
            ])
            ->withHeaders([
                'Cache-Control' =>
                    'no-store, no-cache, must-revalidate, private',

                'Pragma' =>
                    'no-cache',
            ]);
    }

    /*
     * قطع اتصال دستگاه.
     *
     * رکورد و Eventهای قبلی حذف نمی‌شوند؛
     * فقط توکن دستگاه از دسترس خارج می‌شود.
     */
    public function disconnect(
        Request $request,
        int $deviceId
    ) {
        $setting = $this->resolveSetting($request);

        $device = $this->ownedDevice(
            $setting,
            $deviceId
        );

        $metadata =
            $device->metadata ?: [];

        $metadata['disconnected_at'] =
            now()->toIso8601String();

        $device->update([
            'is_enabled' =>
                false,

            'last_ip' =>
                null,

            'metadata' =>
                $metadata,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'اتصال دستگاه قطع شد',

            'data' => [
                'device' =>
                    $this->deviceData(
                        $device->fresh()
                    ),
            ],
        ]);
    }

    /*
     * حذف کامل دستگاهی که قبلاً اتصال آن قطع شده است.
     *
     * به‌دلیل رابطه cascade، رویدادهای متعلق به دستگاه نیز حذف می‌شوند.
     */
    public function destroy(
        Request $request,
        int $deviceId
    ) {
        $setting = $this->resolveSetting($request);

        $device = $this->ownedDevice(
            $setting,
            $deviceId
        );

        if ((bool) $device->is_enabled) {
            return response()->json([
                'status' => 'error',

                'message' =>
                    'ابتدا اتصال دستگاه را قطع کنید',
            ], 422);
        }

        $device->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'دستگاه با موفقیت حذف شد',
        ]);
    }

    /*
     * این Endpoint توسط Shortcut یا اپ اجرا می‌شود.
     * 
     *
     * پنل نمی‌تواند مستقیماً Shortcut آیفون را صدا بزند؛
     * Shortcut درخواست تست را به این Endpoint می‌فرستد.
     */
    public function ping(Request $request)
    {
        $data = $request->validate([
            /*
             * Endpoint تست نیز اجازه دریافت
             * هیچ متن یا عدد بانکی را ندارد.
             */
            'sms' => ['prohibited'],
            'raw_sms' => ['prohibited'],
            'sanitized_sms' => ['prohibited'],
            'numeric_tokens' => ['prohibited'],
            'amount_candidates' => ['prohibited'],

            'platform' => [
                'required',
                'string',
                'in:ios,android',
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
                    'توکن دستگاه ارسال نشده است',
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
                'ok' => false,

                'message' =>
                    'دستگاه معتبر یا فعال نیست',
            ], 401);
        }

        if (
            $device->platform
            !== $data['platform']
        ) {
            return response()->json([
                'ok' => false,

                'message' =>
                    'نوع دستگاه با اتصال ثبت‌شده مطابقت ندارد',
            ], 422);
        }

        $metadata =
            $device->metadata ?: [];

        $metadata['last_ping_at'] =
            now()->toIso8601String();

        $metadata['local_filter_version'] =
            $data['local_filter_version']
            ?? null;

        $device->update([
            'last_seen_at' =>
                now(),

            'last_ip' =>
                $request->ip(),

            'metadata' =>
                $metadata,
        ]);

        $device->refresh();

        return response()->json([
            'ok' => true,
            'status' => 'connected',

            'device' => [
                'id' =>
                    $device->id,

                'name' =>
                    $device->name,

                'platform' =>
                    $device->platform,

                'last_seen_at' =>
                    optional(
                        $device->last_seen_at
                    )->toIso8601String(),
            ],
        ]);
    }

    /*
     * پیدا کردن تنظیمات ربات متعلق به کاربر واردشده.
     *
     * ادمین فقط دستگاه خودش و نماینده نیز
     * فقط دستگاه ربات خودش را می‌بیند.
     */
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

        $panelUser = DB::table('panel_users')
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

    /*
     * جلوگیری از دسترسی صاحب یک ربات
     * به دستگاه ربات شخص دیگر.
     */
    private function ownedDevice(
        UserBotSetting $setting,
        int $deviceId
    ): UserBotSmsDevice {
        $device = UserBotSmsDevice::query()
            ->where(
                'setting_id',
                $setting->id
            )
            ->where(
                'id',
                $deviceId
            )
            ->first();

        if (!$device) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'دستگاه پیدا نشد',
            ], 404));
        }

        return $device;
    }

    private function deviceData(
        UserBotSmsDevice $device
    ): array {
        $metadata =
            $device->metadata ?: [];

        return [
            'id' =>
                $device->id,

            'name' =>
                $device->name,

            'platform' =>
                $device->platform,

            'is_enabled' =>
                (bool) $device->is_enabled,

            'last_seen_at' =>
                optional(
                    $device->last_seen_at
                )->toIso8601String(),

            'created_at' =>
                optional(
                    $device->created_at
                )->toIso8601String(),

            'events_count' =>
                (int) (
                    $device->events_count
                    ??
                    $device
                        ->events()
                        ->where(
                            'created_at',
                            '>=',
                            now()->subDays(30)
                        )
                        ->count()
                ),

            'last_ping_at' =>
                $metadata['last_ping_at']
                ?? null,

            'local_filter_version' =>
                $metadata[
                    'local_filter_version'
                ] ?? null,
        ];
    }
}