<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Services\TelegramNotifier;

class ReferralController extends Controller
{
    /**
     * GET /api/validate/email-available?email=...
     * آیا ایمیل برای ثبت معرفی آزاد است؟
     * - آزاد نیست اگر: در panel_users وجود داشته باشد
     *   یا در panel_referrals با status='pending' باشد.
     */
    public function emailAvailable(Request $request)
    {
        $email = Str::lower(trim((string)$request->query('email', '')));
        if ($email === '') {
            return response()->json(['available' => false], 200);
        }

        $existsUsers = DB::table('panel_users')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();

        $existsPending = DB::table('panel_referrals')
            ->where('status', 'pending')
            ->whereRaw('LOWER(referee_email) = ?', [$email])
            ->exists();

        return response()->json(['available' => !($existsUsers || $existsPending)], 200);
    }

    /**
     * GET /api/validate/panel-code-available?code=...
     * آیا کُد پنل برای ثبت معرفی آزاد است؟
     * - آزاد نیست اگر: در panel_users.code وجود داشته باشد
     *   یا در panel_referrals.referee_code با status='pending' باشد.
     */
    public function panelCodeAvailable(Request $request)
    {
        $code = trim((string)$request->query('code', ''));
        $codeLower = Str::lower($code);
        if ($codeLower === '') {
            return response()->json(['available' => false], 200);
        }

        $existsUsers = DB::table('panel_users')
            ->whereRaw('LOWER(`code`) = ?', [$codeLower])
            ->exists();

        $existsPending = DB::table('panel_referrals')
            ->where('status', 'pending')
            ->whereRaw('LOWER(`referee_code`) = ?', [$codeLower])
            ->exists();

        return response()->json(['available' => !($existsUsers || $existsPending)], 200);
    }

    /**
     * POST /api/referrals
     * ثبت درخواست معرفی نماینده جدید توسط کاربر لاگین‌شده (مُعرّف).
     * بدنه:
     *  - full_name, panel_code, email, password
     */
    public function store(Request $request)
    {
        $me = $request->user(); // نیازمند auth
        if (!$me) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'full_name'  => ['required', 'string', 'min:3'],
            'panel_code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{3,32}$/'],
            'email'      => ['required', 'email'],
            'password'   => ['required', 'string', 'min:4'],
        ], [
            'full_name.required'  => 'نام و نام خانوادگی الزامی است',
            'panel_code.required' => 'کُد پنل الزامی است',
            'panel_code.regex'    => 'کُد پنل معتبر نیست',
            'email.required'      => 'ایمیل الزامی است',
            'email.email'         => 'ایمیل معتبر نیست',
            'password.required'   => 'رمز عبور الزامی است',
            'password.min'        => 'رمز عبور حداقل ۴ کاراکتر باشد',
        ]);

        $emailLower = Str::lower($data['email']);
        $codeLower  = Str::lower($data['panel_code']);

        // ایمیل تکراری؟
        $emailTaken = DB::table('panel_users')
                ->whereRaw('LOWER(email) = ?', [$emailLower])
                ->exists()
            || DB::table('panel_referrals')
                ->where('status', 'pending')
                ->whereRaw('LOWER(referee_email) = ?', [$emailLower])
                ->exists();

        if ($emailTaken) {
            return response()->json(['ok' => false, 'message' => 'این ایمیل قبلاً ثبت شده است'], 422);
        }

        // کُد پنل تکراری؟
        $codeTaken = DB::table('panel_users')
                ->whereRaw('LOWER(`code`) = ?', [$codeLower])
                ->exists()
            || DB::table('panel_referrals')
                ->where('status', 'pending')
                ->whereRaw('LOWER(`referee_code`) = ?', [$codeLower])
                ->exists();

        if ($codeTaken) {
            return response()->json(['ok' => false, 'message' => 'این کُد پنل قبلاً استفاده شده است'], 422);
        }

        // درج رکورد در صف معرفی‌ها (پسوردِ کَندید فقط به‌شکل hash در meta نگهداری می‌شود)
        $refId = DB::table('panel_referrals')->insertGetId([
            'referrer_id'      => $me->id,
            'referee_name'     => $data['full_name'],
            'referee_email'    => $data['email'],
            'referee_code'     => $data['panel_code'], // ⬅️ ذخیره‌ی مستقیم کُد پنل در ستون جدید
            'status'           => 'pending',
            'pricing_strategy' => null,
            'pricing_payload'  => null,
            'meta'             => json_encode([
                'password_hash' => Hash::make($data['password']),
                'source'        => 'user_panel',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // پیام تلگرام به ادمین‌ها — شامل کُد پنل مُعرّف
        try {
            $referrer = DB::table('panel_users')
                ->select('name', 'email', 'code')
                ->where('id', $me->id)
                ->first();

            $referrerName = $referrer->name ?? '—';
            $referrerCode = $referrer->code ?? '—';
            $candidateName  = $data['full_name'];
            $candidateEmail = $data['email'];
            $candidateCode  = $data['panel_code'];

            $msg =
                "👥 درخواست نمایندگی جدید\n"
                ."🗣 معرّف: {$referrerName} ({$referrerCode})\n"
                ."نام: {$candidateName}\n"
                ."ایمیل: {$candidateEmail}\n"
                ."کد پنل: {$candidateCode}\n"
                ."لطفاً برای بررسی به پنل ادمین مراجعه کنید.";

            app(TelegramNotifier::class)->sendTextToAdmins($msg);
        } catch (\Throwable $e) {
            // لاگ اختیاری
            // \Log::warning('Telegram notify failed: '.$e->getMessage());
        }

        return response()->json(['ok' => true, 'referral_id' => $refId], 201);
    }

    /**
     * GET /api/admin/referrals/count
     * شمارش درخواست‌های در انتظار برای Badge ادمین.
     * (فرض پروژه: role == 1 یعنی ادمین)
     */
    public function adminPendingCount(Request $request)
    {
        $u = $request->user();
        if (!$u || (int)($u->role ?? 0) !== 1) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $count = DB::table('panel_referrals')->where('status', 'pending')->count();
        return response()->json(['count' => $count], 200);
    }

    protected function ensureAdmin(Request $request)
    {
        $me = $request->user();
        if (!$me || (int)($me->role ?? 0) !== 1) {
            abort(response()->json(['ok'=>false,'message'=>'Forbidden'], 403));
        }
        return $me;
    }

    /**
     * شمارندهٔ pending برای Badge ادمین
     * GET /api/admin/referrals/count
     */
    public function adminCount(Request $request)
    {
        $this->ensureAdmin($request);

        $ids = DB::table('panel_referrals')->where('status','pending')->orderBy('id')->pluck('id');
        return response()->json(['ok'=>true,'count'=>$ids->count(),'notification_ids'=>$ids->all()], 200);
    }

    /**
     * لیست درخواست‌ها برای جدول ادمین (بدون جستجو)
     * GET /api/admin/referrals?status=pending|approved|rejected|all&page=1&per_page=15
     */
    public function adminIndex(Request $request)
    {
        $this->ensureAdmin($request);

        $status  = strtolower($request->query('status','pending'));
        $page    = max(1, (int)$request->query('page', 1));
        $perPage = min(500, max(5, (int)$request->query('per_page', 15)));

        $q = DB::table('panel_referrals as r')
            ->leftJoin('panel_users as ref', 'ref.id', '=', 'r.referrer_id')    // معرف
            ->leftJoin('panel_users as u',   'u.id',   '=', 'r.referee_id')     // کاربر تاییدشده (ممکن است NULL)
            ->selectRaw("
                r.id, r.status, r.created_at,
                r.referee_name, r.referee_email, r.referee_code,
                ref.id as referrer_user_id,
                COALESCE(ref.name, ref.email) as referrer_name,
                ref.code as referrer_code
            ");

        if (in_array($status, ['pending','approved','rejected'], true)) {
            $q->where('r.status', $status);
        }

        $q->orderByDesc('r.created_at');

        $total = (clone $q)->count();
        $items = $q->forPage($page, $perPage)->get();

        return response()->json([
            'ok' => true,
            'items' => $items,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int)ceil($total / $perPage),
            ],
        ], 200);
    }

    /**
     * جزئیات یک درخواست برای مودال بررسی
     * GET /api/admin/referrals/{id}
     */
    public function adminShow(Request $request, $id)
    {
        $this->ensureAdmin($request);

        $row = DB::table('panel_referrals as r')
            ->leftJoin('panel_users as ref', 'ref.id', '=', 'r.referrer_id')
            ->leftJoin('panel_users as u',   'u.id',   '=', 'r.referee_id')
            ->selectRaw("
                r.*,
                ref.id as referrer_user_id,
                COALESCE(ref.name, ref.email) as referrer_name,
                ref.code as referrer_code,
                ref.partner_plan_type as referrer_partner_plan_type,
                ref.enable_personalized_price as ref_enable_personalized_price,
                ref.personalized_price_test as ref_price_test,        
                ref.personalized_price_1 as ref_price_1,
                ref.personalized_price_3 as ref_price_3,
                ref.personalized_price_6 as ref_price_6,
                ref.personalized_price_12 as ref_price_12,
                ref.traffic_price as ref_traffic_price
            ")
            ->where('r.id', (int)$id)
            ->first();

        if (!$row) {
            return response()->json(['ok'=>false, 'message'=>'Not found'], 404);
        }

        // پیش‌فرض کمیسیون از panel_settings
        $defaultCommission = (float) DB::table('panel_settings')
            ->where('key','referral_default_commission_rate')
            ->value('value') ?? 10.0;

        // پکیج "مشابه معرف" برای نمایش در مودال
        $likeReferrer = [
            'enable_personalized_price' => (int)($row->ref_enable_personalized_price ?? 0),
            'personalized_price_test'   => (int)($row->ref_price_test ?? 0),
            'personalized_price_1'      => (int)($row->ref_price_1 ?? 0),
            'personalized_price_3'      => (int)($row->ref_price_3 ?? 0),
            'personalized_price_6'      => (int)($row->ref_price_6 ?? 0),
            'personalized_price_12'     => (int)($row->ref_price_12 ?? 0),
            'traffic_price'             => (int)($row->ref_traffic_price ?? 0),
        ];

        return response()->json([
            'ok'   => true,
            'item' => [
                'id'             => $row->id,
                'status'         => $row->status,
                'created_at'     => $row->created_at,
                'referrer'       => [
                    'id'   => $row->referrer_user_id,
                    'name' => $row->referrer_name,
                    'code' => $row->referrer_code,
                    'partner_plan_type' => $row->referrer_partner_plan_type ?? 'time',
                ],
                'candidate'      => [
                    'name'  => $row->referee_name,
                    'email' => $row->referee_email,
                    'code'  => $row->referee_code,
                ],
                'pricing_strategy' => $row->pricing_strategy,
                'pricing_payload'  => $row->pricing_payload ? json_decode($row->pricing_payload, true) : null,
                'meta'             => $row->meta ? json_decode($row->meta, true) : null,
            ],
            'defaults' => [
                'commission_percent' => $defaultCommission,
            ],
            'pricing_like_referrer' => $likeReferrer,
        ], 200);
    }

    /**
     * Toast ادمین: یک مورد جدید که هنوز اعلان نشده
     * GET /api/admin/referrals/notifications/pending
     */
    public function adminToastPending(Request $request)
    {
        $this->ensureAdmin($request);

        $row = DB::table('panel_referrals as r')
            ->leftJoin('panel_users as ref', 'ref.id', '=', 'r.referrer_id')
            ->selectRaw("
                r.id, r.created_at, r.referee_name, r.referee_email, r.referee_code,
                COALESCE(ref.name, ref.email) as referrer_name,
                ref.code as referrer_code
            ")
            ->whereNull('r.admin_notified_at')
            ->where('r.status', 'pending')
            ->orderByDesc('r.created_at')
            ->first();

        return response()->json([
            'ok'  => true,
            'item'=> $row ?: null,
        ], 200);
    }

    /**
     * Toast ادمین: تیک خوردن مورد اعلان‌شده
     * POST /api/admin/referrals/notifications/{id}/ack
     */
    public function adminToastAck(Request $request, $id)
    {
        $this->ensureAdmin($request);

        $upd = DB::table('panel_referrals')
            ->where('id', (int)$id)
            ->update(['admin_notified_at' => now()]);

        return response()->json(['ok'=> (bool)$upd], 200);
    }

    public function refToastPending(\Illuminate\Http\Request $request)
    {
        $u = $request->user();
        if (!$u) return response()->json([]);

        // آخرین معرفی که رسیدگی شده ولی هنوز به مُعرّف اعلام نشده
        $row = \DB::table('panel_referrals as r')
            ->select('r.id', 'r.status', 'r.referee_name', 'r.referee_email', 'r.decide_reason', 'r.decided_at')
            ->where('r.referrer_id', $u->id)
            ->whereIn('r.status', ['approved', 'rejected'])
            ->whereNull('r.referrer_notified_at')
            ->orderByDesc('r.decided_at')
            ->first();

        if (!$row) return response()->json([]);

        return response()->json([[
            'id'     => (int)$row->id,
            'status' => (string)$row->status,
            'name'   => (string)($row->referee_name ?: $row->referee_email),
            'reason' => (string)($row->decide_reason ?? ''),
        ]]);
    }

    public function refToastAck(\Illuminate\Http\Request $request, int $id)
    {
        $u = $request->user();
        if (!$u) return response()->json(['ok' => false], 401);

        $upd = \DB::table('panel_referrals')
            ->where('id', $id)
            ->where('referrer_id', $u->id)
            ->update(['referrer_notified_at' => now()]);

        return response()->json(['ok' => (bool)$upd]);
    }
        
    /** هلسپر: گرفتن درصد پیش‌فرض کمیسیون از settings (Fallback=10) */
    protected function defaultCommission(): float
    {
        $val = DB::table('panel_settings')->where('key','referral_default_commission_rate')->value('value');
        return (float)($val ?? 10);
    }

    /**
     * ادمین - تأیید درخواست نمایندگی
     * POST /api/admin/referrals/{id}/approve
     * payload:
     *  - commission_percent?: number (0..100)  [اختیاری: اگر نده، از defaultCommission()]
     *  - pricing_strategy: 'like_referrer' | 'custom'
     *  - pricing_payload?: {
     *      personalized_price_1, personalized_price_3, personalized_price_6, personalized_price_12, traffic_price
     *    }  (وقتی custom)
     */
    public function adminApprove(Request $request, $id)
    {
        $me = $this->ensureAdmin($request);

        // اعتبارسنجی ورودی
        $data = $request->validate([
            'commission_percent' => ['nullable','numeric','min:0','max:100'],
            'pricing_strategy'   => ['required', Rule::in(['like_referrer','custom'])],
            'pricing_payload'    => ['nullable','array'],
            'partner_plan_type'  => ['nullable', Rule::in(['time','volume','both'])],
            'pricing_groups_payload' => ['nullable', 'array'],
            'pricing_groups_payload.*.id' => ['required_with:pricing_groups_payload', 'integer', 'exists:panel_tariff_groups,id'],
            'pricing_groups_payload.*.prices' => ['required_with:pricing_groups_payload', 'array'],
            'pricing_payload.personalized_price_1'  => ['nullable','integer','min:0'],
            'pricing_payload.personalized_price_3'  => ['nullable','integer','min:0'],
            'pricing_payload.personalized_price_6'  => ['nullable','integer','min:0'],
            'pricing_payload.personalized_price_12' => ['nullable','integer','min:0'],
            'pricing_payload.traffic_price'         => ['nullable','integer','min:0'],
        ], [
            'pricing_strategy.required' => 'انتخاب استراتژی قیمت الزامی است',
        ]);

        // گرفتن رکورد درخواست (فقط pending)
        $ref = DB::table('panel_referrals')->where('id',(int)$id)->first();
        if (!$ref) {
            return response()->json(['ok'=>false,'message'=>'درخواست یافت نشد'], 404);
        }
        if (Str::lower($ref->status) !== 'pending') {
            return response()->json(['ok'=>false,'message'=>'این درخواست قبلاً رسیدگی شده است'], 409);
        }

        // استخراج مقادیر پایه
        $email = Str::lower(trim((string)$ref->referee_email));
        $code  = Str::lower(trim((string)$ref->referee_code));

        // Re-validate یکتایی (race condition)
        $emailTaken = DB::table('panel_users')->whereRaw('LOWER(email)=?',[$email])->exists();
        if ($emailTaken) {
            return response()->json(['ok'=>false,'message'=>'این ایمیل قبلاً ثبت شده است'], 422);
        }
        $codeTaken = DB::table('panel_users')->whereRaw('LOWER(`code`)=?',[$code])->exists();
        if ($codeTaken) {
            return response()->json(['ok'=>false,'message'=>'این کُد پنل قبلاً استفاده شده است'], 422);
        }

        // گرفتن اطلاعات مُعرّف (برای «مشابه مُعرّف»)
        $referrer = null;
        if ($ref->referrer_id) {
            $referrer = DB::table('panel_users')->where('id', $ref->referrer_id)->first();
        }

        $partnerPlanType = $data['partner_plan_type'] ?? ($referrer->partner_plan_type ?? 'time');
        
        // خواندن پسورد هش از meta
        $meta = $ref->meta ? json_decode($ref->meta, true) : null;
        $passHash = $meta['password_hash'] ?? null;
        if (!$passHash) {
            return response()->json(['ok'=>false,'message'=>'اطلاعات رمز عبور در درخواست موجود نیست'], 422);
        }

        // آماده‌سازی قیمت‌ها بر اساس استراتژی
        $enablePersonal = 0;
        $pt=null; $p1=$p3=$p6=$p12=$tgb = null;

        if ($data['pricing_strategy'] === 'like_referrer') {
            if ($referrer) {
                $enablePersonal = (int)($referrer->enable_personalized_price ?? 0);
                if ($enablePersonal === 1) {
                    $pt  = (int)($referrer->personalized_price_test ?? 0);
                    $p1  = (int)($referrer->personalized_price_1  ?? 0);
                    $p3  = (int)($referrer->personalized_price_3  ?? 0);
                    $p6  = (int)($referrer->personalized_price_6  ?? 0);
                    $p12 = (int)($referrer->personalized_price_12 ?? 0);
                    $tgb = (int)($referrer->traffic_price        ?? 0);
                }
            }
            // اگر مُعرّف نبود یا شخصی‌سازی نداشت → enable=0 بگذار؛ کاربر جدید از پیش‌فرض سیستم می‌خواند.
            if (!$referrer || (int)($referrer->enable_personalized_price ?? 0) !== 1) {
                // شخصی‌سازی فعال نیست → قیمت‌های پلن‌ها خالی بماند تا از دیفالت خوانده شود
                $enablePersonal = 0;
                $p1 = $p3 = $p6 = $p12 = null;

                // اما ترافیک را از ستون خود مُعرّف کپی کن (اگر مقدار دارد)
                // تا کاربر جدید هم همان قیمت ترافیک مُعرّف را داشته باشد
                if ($referrer && $referrer->traffic_price !== null) {
                    $tgb = (int) $referrer->traffic_price;
                } else {
                    $tgb = null; // اگر مُعرّف مقدار نداشت، خالی بماند
                }
            }

        } else { // custom
            $pp = $data['pricing_payload'] ?? [];
            $enablePersonal = 1;
            $pt  = isset($pp['personalized_price_test']) ? (int)$pp['personalized_price_test'] : 0;
            $p1  = isset($pp['personalized_price_1'])  ? (int)$pp['personalized_price_1']  : 0;
            $p3  = isset($pp['personalized_price_3'])  ? (int)$pp['personalized_price_3']  : 0;
            $p6  = isset($pp['personalized_price_6'])  ? (int)$pp['personalized_price_6']  : 0;
            $p12 = isset($pp['personalized_price_12']) ? (int)$pp['personalized_price_12'] : 0;
            $tgb = isset($pp['traffic_price'])         ? (int)$pp['traffic_price']         : 0;
        }

        // کمیسیون
        $commission = isset($data['commission_percent']) && $data['commission_percent'] !== null
            ? (float)$data['commission_percent']
            : $this->defaultCommission();

        // تراکنش اتمیک: ساخت کاربر + آپدیت درخواست
        try {
            $newUserId = DB::transaction(function() use ($ref, $referrer, $email, $code, $passHash, $enablePersonal, $pt,$p1,$p3,$p6,$p12,$tgb, $commission, $data, $me, $partnerPlanType) {
                // ساخت کاربر جدید
                $uid = DB::table('panel_users')->insertGetId([
                    'name'                       => $ref->referee_name,
                    'email'                      => $email,
                    'password'                   => $passHash,        // هش از قبل
                    'code'                       => $ref->referee_code,
                    'role'                       => 2,                // کاربر عادی
                    'partner_plan_type'          => $partnerPlanType,
                    'referred_by_id'             => $ref->referrer_id,
                    'ref_commission_rate'        => $commission,      // DECIMAL(5,2)
                    'enable_personalized_price'  => $enablePersonal,  // 0|1
                    'personalized_price_test'    => $pt,
                    'personalized_price_1'       => $p1,
                    'personalized_price_3'       => $p3,
                    'personalized_price_6'       => $p6,
                    'personalized_price_12'      => $p12,
                    'traffic_price'              => $tgb,
                    'created_at'                 => now(),
                    'updated_at'                 => now(),
                ]);

                if ($data['pricing_strategy'] === 'like_referrer' && $referrer && (int)($referrer->enable_personalized_price ?? 0) === 1) {
                    $rows = DB::table('panel_user_tariff_prices')
                        ->where('panel_user_id', (int) $referrer->id)
                        ->get()
                        ->map(function ($r) use ($uid) {
                            return [
                                'panel_user_id' => (int) $uid,
                                'tariff_group_id' => (int) $r->tariff_group_id,
                                'plan_key' => (string) $r->plan_key,
                                'price' => (int) $r->price,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        })
                        ->values()
                        ->all();

                    if (!empty($rows)) {
                        DB::table('panel_user_tariff_prices')->insert($rows);
                    }
                }

                if ($data['pricing_strategy'] === 'custom' && !empty($data['pricing_groups_payload']) && is_array($data['pricing_groups_payload'])) {
                    $rows = [];

                    foreach ($data['pricing_groups_payload'] as $groupPayload) {
                        $groupId = (int)($groupPayload['id'] ?? 0);
                        $prices = $groupPayload['prices'] ?? [];

                        $groupRow = DB::table('panel_tariff_groups')->where('id', $groupId)->first();
                        $groupType = (string)($groupRow->group_type ?? 'time');

                        $map = $groupType === 'volume'
                            ? ['overage_per_gb' => 'volume_gig']
                            : [
                                'overage_per_gb' => 'gig',
                                'test' => 'test',
                                'm1' => '1m',
                                'm3' => '3m',
                                'm6' => '6m',
                                'm12' => '12m',
                            ];

                        foreach ($map as $frontendKey => $planKey) {
                            $rows[] = [
                                'panel_user_id' => (int) $uid,
                                'tariff_group_id' => $groupId,
                                'plan_key' => $planKey,
                                'price' => (int)($prices[$frontendKey] ?? 0),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }

                    if (!empty($rows)) {
                        DB::table('panel_user_tariff_prices')->insert($rows);
                    }
                }

                // آپدیت درخواست
                DB::table('panel_referrals')->where('id', $ref->id)->update([
                    'status'           => 'approved',
                    'referee_id'       => $uid,
                    'decided_by_id'    => $me->id,
                    'decided_at'       => now(),
                    'decide_reason'    => null,
                    'pricing_strategy' => $data['pricing_strategy'],
                    'pricing_payload'  => isset($data['pricing_payload']) ? json_encode($data['pricing_payload'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
                    'updated_at'       => now(),
                ]);

                return $uid;
            });
        } catch (\Throwable $e) {
            // اگر خطای یکتا/کنترل رقابتی رخ داد
            return response()->json([
                'ok'=>false,
                'message'=>'ثبت کاربر جدید با خطا مواجه شد',
                'error'   => config('app.debug', false) ? $e->getMessage() : null,
                'trace' => config('app.debug', false) ? $e->getTraceAsString() : null,            
            ], 500);
        }

        // تلگرام به مُعرّف پس از تایید (بعد از COMMIT)
        \DB::afterCommit(function () use ($ref) {
            try {
                if ($ref->referrer_id) {
                    $chatId = \DB::table('panel_users')->where('id', $ref->referrer_id)->value('telegram_user_id');
                    if ($chatId) {
                        $name = $ref->referee_name ?: $ref->referee_email;
                        $msg  = "✅ نمایندهٔ جدید شما «{$name}» تأیید شد.";
                        app(\App\Services\TelegramNotifier::class)->sendTextTo((string)$chatId, $msg);
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('[tg.notify.ref.approve] '.$e->getMessage());
            }
        });

        return response()->json(['ok'=>true, 'user_id'=>$newUserId], 200);
    }

    /**
     * ادمین - رد درخواست نمایندگی
     * POST /api/admin/referrals/{id}/reject
     * payload: { reason?: string }
     */
    public function adminReject(Request $request, $id)
    {
        $me = $this->ensureAdmin($request);

        $data = $request->validate([
            'reason' => ['nullable','string','max:500']
        ]);

        $ref = DB::table('panel_referrals')->where('id',(int)$id)->first();
        if (!$ref) {
            return response()->json(['ok'=>false,'message'=>'درخواست یافت نشد'], 404);
        }
        if (Str::lower($ref->status) !== 'pending') {
            return response()->json(['ok'=>false,'message'=>'این درخواست قبلاً رسیدگی شده است'], 409);
        }

        DB::table('panel_referrals')->where('id',$ref->id)->update([
            'status'        => 'rejected',
            'decide_reason' => $data['reason'] ?? null,
            'decided_by_id' => $me->id,
            'decided_at'    => now(),
            'updated_at'    => now(),
        ]);

        // تلگرام به مُعرّف پس از رد (بعد از COMMIT)
        \DB::afterCommit(function () use ($ref, $data) {
            try {
                if ($ref->referrer_id) {
                    $chatId = \DB::table('panel_users')->where('id', $ref->referrer_id)->value('telegram_user_id');
                    if ($chatId) {
                        $name = $ref->referee_name ?: $ref->referee_email;
                        $base = "❌ نمایندهٔ جدید شما «{$name}» تأیید نشد.";
                        $msg  = (!empty($data['reason'])) ? ($base . "\nدلیل: " . $data['reason']) : $base;
                        app(\App\Services\TelegramNotifier::class)->sendTextTo((string)$chatId, $msg);
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('[tg.notify.ref.reject] '.$e->getMessage());
            }
        });

        return response()->json(['ok'=>true], 200);
    }

}
