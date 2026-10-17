<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthLinkController extends Controller
{
    public function validateLink(Request $request)
    {
        $token = $request->query('token');

        if (!$token || !is_string($token)) {
            return response()->json([
                'ok' => false,
                'code' => 'invalid',
                'message' => 'توکن نامعتبر است.',
            ]);
        }

        $row = DB::table('v2_user')
            ->where('token', $token)
            ->first();

        if (!$row) {
            return response()->json([
                'ok' => false,
                'code' => 'not_found',
                'message' => 'کاربری یافت نشد.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | حساب مسدود
        |--------------------------------------------------------------------------
        |
        | فقط تعلیق باعث نامعتبرشدن Session و خروج کاربر می‌شود.
        |
        */

        if ((int) ($row->banned ?? 0) === 1) {
            return response()->json([
                'ok' => false,
                'code' => 'suspended',
                'message' => 'اکانت شما مسدود شده است.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | بررسی تاریخ انقضا
        |--------------------------------------------------------------------------
        |
        | عیناً با همان فرمت قبلی پروژه.
        | فقط دیگر در اینجا return نمی‌کنیم.
        |
        */

        $expiredAtInt = (int) ($row->expired_at ?? 0);
        $accountExpired = !\App\Services\SubscriptionValidity::unexpired($row->expired_at ?? null);

        /*
        |--------------------------------------------------------------------------
        | محاسبه ترافیک باقی‌مانده
        |--------------------------------------------------------------------------
        |
        | عیناً همان محاسبه قبلی پروژه.
        |
        */

        $u = (int) ($row->u ?? 0);
        $d = (int) ($row->d ?? 0);
        $transferEnable = (int) ($row->transfer_enable ?? 0);

        $trafficRemaining = max(
            $transferEnable - ($u + $d),
            0
        );

        $trafficExhausted =
            $trafficRemaining <= 0;

        /*
        |--------------------------------------------------------------------------
        | تعیین وضعیت حساب
        |--------------------------------------------------------------------------
        |
        | انقضا و پایان ترافیک هر دو ok=true هستند.
        | اپ کاربر را داخل Home نگه می‌دارد و فقط اتصال را محدود می‌کند.
        |
        */

        $code = 'ok';
        $message = '';

        if ($accountExpired) {
            $code = 'expired';
            $message = 'اکانت شما منقضی شده است.';
        } elseif ($trafficExhausted) {
            $code = 'traffic_exhausted';
            $message = 'ترافیک شما به پایان رسیده است.';
        }

        return response()->json([
            'ok' => true,
            'code' => $code,
            'message' => $message,

            'user' => [
                'email' => $row->email ?? null,

                // همان Unix Timestamp قبلی
                'expired_at' => $expiredAtInt,

                // همان محاسبه قبلی ترافیک
                'traffic_remaining' => $trafficRemaining,

                // فلگ‌های جدید برای رفتار اپ
                'account_expired' => $accountExpired,
                'traffic_exhausted' => $trafficExhausted,
            ],
        ]);
    }
}
