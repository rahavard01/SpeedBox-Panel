<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class LoginController extends Controller
{

    public function login(Request $request)
    {

        // ⬇️ چک banned در جدول panel_users بر اساس ایمیل
        $email  = $request->input('email');
        $banned = DB::table('panel_users')->where('email', $email)->value('banned');

        if ((int) $banned === 1) {
            return response()->json([
                'status'  => 'error',
                'message' => 'دسترسی شما به پنل محدود شده است'
            ], 403);
        }
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $limited = (bool) \App\Services\RuntimeSetting::get('password_limit_enable', false);
        $limit = max(1, (int) \App\Services\RuntimeSetting::get('password_limit_count', 5));
        $decay = max(1, (int) \App\Services\RuntimeSetting::get('password_limit_expire', 60)) * 60;
        $key = 'speedbox:login:'.hash('sha256', strtolower(trim((string) $request->email)).'|'.$request->ip());
        if ($limited && \Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, $limit)) {
            $retry = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
            $minutes = intdiv($retry, 60);
            $seconds = $retry % 60;
            $parts = [];
            if ($minutes > 0) $parts[] = $minutes.' دقیقه';
            if ($seconds > 0 || !$parts) $parts[] = max(1, $seconds).' ثانیه';
            $remaining = strtr(implode(' و ', $parts), array_combine(range(0, 9), preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY)));
            return response()->json([
                'status' => 'error',
                'message' => 'به‌دلیل تلاش‌های ناموفق مکرر، ورود موقتاً محدود شده است. لطفاً '.$remaining.' دیگر دوباره تلاش کنید.',
                'retry_after' => $retry,
            ], 429)->header('Retry-After', $retry);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            if ($limited) \Illuminate\Support\Facades\RateLimiter::hit($key, $decay);
            return response()->json([
                'status' => 'error',
                'message' => 'ایمیل یا رمز عبور اشتباه است',
            ], 401);
        }

        \Illuminate\Support\Facades\RateLimiter::clear($key);

        // بعد از اینکه $user را پیدا و اعتبارسنجی رمز را چک کردی، و قبل از createToken:
        DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', \App\Models\User::class)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();


        // ساخت توکن جدید
        $token = $user->createToken('user-token')->plainTextToken;
        DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(1)
            ->update(['expires_at' => now()->addHours(12)]);
        // نقش کاربر
        $redirect = 'panel';
        if ($user->role == 1) {
            $redirect = 'admin';
        } elseif ($user->role == 3) {
            $redirect = 'staff';
        }

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'redirect' => $redirect,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        // Sanctum: توکن فعلی کاربر را باطل کن
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        return response()->json(['ok' => true]);
    }
}

