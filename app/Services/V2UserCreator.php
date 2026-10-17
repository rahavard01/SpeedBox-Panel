<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * V2UserCreator
 * - ساخت اکانت و قفل کد مالک در یک تراکنش انجام می‌شوند؛ تراکنش بیرونی نیز پشتیبانی می‌شود.
 * - برای برخورد یکتا، چند ریتِرای محدود داریم.
 * - امکان کاهش rounds برای bcrypt در ساخت‌های حجیم با ENV: BULK_BCRYPT_ROUNDS
 */
class V2UserCreator
{
    /**
     * @param string $panelCode
     * @param int    $v2PlanId
     * @param int    $count
     * @param string $planKey     test|1m|3m|6m|12m|1m_noexp
     * @param bool   $noExpire
     * @return array{created: array<int, array{id:int,email:string,token:string}>, errors: string[]}
     */
    public function createUsers(
        string $panelCode,
        int $v2PlanId,
        int $count,
        string $planKey,
        bool $noExpire = false,
        ?int $customTrafficGb = null,
        ?array $accountSnapshot = null,
        ?int $panelOwnerId = null
    ): array
    {
        return $this->createUsersWithUsername($panelCode, $v2PlanId, $count, $planKey, $noExpire, $customTrafficGb, $accountSnapshot, $panelOwnerId);
    }

    public function createUsersWithUsername(
        string $panelCode,
        int $v2PlanId,
        int $count,
        string $planKey,
        bool $noExpire = false,
        ?int $customTrafficGb = null,
        ?array $accountSnapshot = null,
        ?int $panelOwnerId = null,
        ?string $customUsername = null
    ): array
    {
        if ($customUsername !== null) {
            $customUsername = strtolower(trim($customUsername));
            \Illuminate\Support\Facades\Validator::make(['custom_username' => $customUsername, 'count' => $count], [
                'custom_username' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9][a-z0-9_-]*$/D'],
                'count' => ['in:1'],
            ], [
                'custom_username.*' => 'نام کاربری باید با حرف انگلیسی یا عدد شروع شود و فقط شامل حروف انگلیسی، عدد، خط تیره و زیرخط باشد؛ حداکثر ۳۲ کاراکتر.',
                'count.in' => 'نام کاربری دلخواه فقط برای ساخت تکی قابل استفاده است.',
            ])->validate();
        }
        return DB::transaction(function () use ($panelCode, $v2PlanId, $count, $planKey, $noExpire, $customTrafficGb, $accountSnapshot, $panelOwnerId, $customUsername) {
            $panelCode = trim($panelCode);
            if ($customUsername !== null && strlen($customUsername.'@'.$panelCode.'.com') > 64) {
                throw \Illuminate\Validation\ValidationException::withMessages(['custom_username' => 'نام کاربری برای این کد پنل طولانی است؛ نام کوتاه‌تری وارد کنید.']);
            }
            if ($panelCode === '') throw \Illuminate\Validation\ValidationException::withMessages(['panel_code' => 'کد پنل نمی‌تواند خالی باشد']);
            $ownerQuery = DB::table('panel_users');
            $owner = ($panelOwnerId !== null ? $ownerQuery->where('id', $panelOwnerId) : $ownerQuery->whereRaw('LOWER(TRIM(code)) = ?', [strtolower($panelCode)]))->lockForUpdate()->first();
            if ($panelOwnerId !== null && (!$owner || (string) $owner->code !== $panelCode)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['panel_code' => 'کد پنل تغییر کرده است؛ صفحه را تازه‌سازی کنید']);
            }
            $result = $this->createUsersLocked($panelCode, $v2PlanId, $count, $planKey, $noExpire, $customTrafficGb, $accountSnapshot, $customUsername);
            if ($owner && (int) $owner->role !== 1 && count($result['created']) > 0) {
                DB::table('panel_users')->where('id', $owner->id)->update(['partner_code_locked' => true]);
            }
            return $result;
        });
    }

    private function createUsersLocked(string $panelCode, int $v2PlanId, int $count, string $planKey, bool $noExpire, ?int $customTrafficGb, ?array $accountSnapshot, ?string $customUsername): array
    {
        $created = [];
        $errors  = [];

        // Creation runs in the caller's transaction; serialize capacity checks per plan.
        $plan = DB::table('v2_plan')->where('id', $v2PlanId)->lockForUpdate()->first();
        if (!$plan) return ['created' => [], 'errors' => ['v2_plan not found']];

        if ($plan->capacity_limit !== null) {
            $activeCount = DB::table('v2_user')->where('plan_id', $v2PlanId)
                ->tap(fn ($query) => SubscriptionValidity::whereUnexpired($query))
                ->count();
            if ($activeCount + $count > (int) $plan->capacity_limit) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'plan_id' => 'ظرفیت این پلن برای ساخت تعداد کاربران درخواستی کافی نیست.',
                ]);
            }
        }

        $nowTs   = time();
        $isNoExp = $noExpire || str_contains($planKey, 'noexp');
        $normKey = ($planKey === '1m_noexp') ? '1m' : $planKey;

        if ($planKey === 'volume_gig') {
            $isNoExp = false;
        }       

        $expiredTs = $isNoExp ? null : match ($normKey) {
            'test' => $nowTs + 3600,
            '1m'   => $nowTs + 30 * 86400,
            '3m'   => $nowTs + 90 * 86400,
            '6m'   => $nowTs + 180 * 86400,
            '12m'  => $nowTs + 365 * 86400,
            default => $nowTs + 30 * 86400,
        };

        $transferEnableBytes = 0;

        if (!is_null($customTrafficGb) && $customTrafficGb > 0) {
            $transferEnableBytes = (int) $customTrafficGb * 1024 * 1024 * 1024;
        } elseif (!is_null($plan->transfer_enable) && $plan->transfer_enable !== '') {
            $gb = (float) preg_replace('/[^\d.]/', '', (string) $plan->transfer_enable);
            $transferEnableBytes = (int) round($gb * 1024 * 1024 * 1024);
        }

        $bcryptRounds = (int) env('BULK_BCRYPT_ROUNDS', 0);
        $hashOpts     = $bcryptRounds > 0 ? ['rounds' => $bcryptRounds] : [];

        $maxRetriesPerUser = 3;

        $connectionLimit = app(TariffConnectionPolicy::class)->resolve($v2PlanId, $accountSnapshot['account_tariff_group_id'] ?? null, $accountSnapshot['account_plan_key'] ?? null);

        for ($i = 0; $i < $count; $i++) {
            $attempt = 0;
            while (true) {
                $attempt++;

                $email = $customUsername !== null ? $customUsername.'@'.$panelCode.'.com' : $this->makeCandidateEmail($panelCode);
                if ($this->emailExists($email, $customUsername !== null)) {
                    if ($customUsername !== null) throw \Illuminate\Validation\ValidationException::withMessages(['custom_username' => 'این نام کاربری در این پنل قبلاً استفاده شده است.']);
                    if ($attempt >= $maxRetriesPerUser) { $errors[] = "unique-email-failed"; break; }
                    continue;
                }

                $uuid = (string) Str::uuid();
                if ($this->uuidExists($uuid)) {
                    if ($attempt >= $maxRetriesPerUser) { $errors[] = "unique-uuid-failed"; break; }
                    continue;
                }

                $token = Str::lower(bin2hex(random_bytes(16)));
                if ($this->tokenExists($token)) {
                    if ($attempt >= $maxRetriesPerUser) { $errors[] = "unique-token-failed"; break; }
                    continue;
                }

                $passwordHash = $hashOpts ? Hash::make($email, $hashOpts) : Hash::make($email);

                $insert = [
                    'email'            => ($email),
                    'password'         => $passwordHash,
                    'uuid'             => $uuid,
                    'token'            => $token,
                    'plan_id'          => $v2PlanId,
                    'account_plan_name'       => $accountSnapshot['account_plan_name'] ?? null,
                    'account_group_type'      => $accountSnapshot['account_group_type'] ?? null,
                    'account_tariff_group_id' => $accountSnapshot['account_tariff_group_id'] ?? null,
                    'account_plan_key'        => $accountSnapshot['account_plan_key'] ?? null,                    
                    'u'                => 0,
                    'd'                => 0,
                    't'                => 0,
                    'transfer_enable'  => $transferEnableBytes,
                    'device_limit'     => $connectionLimit,
                    'speed_limit'      => $plan->speed_limit    ?? null,
                    'group_id'         => $plan->group_id       ?? null,
                    'banned'           => 0,
                    'remind_traffic'   => (int) (bool) RuntimeSetting::get('default_remind_traffic', true),
                    'remind_expire'    => (int) (bool) RuntimeSetting::get('default_remind_expire', true),
                    'expired_at'       => $expiredTs,
                    'created_at'       => $nowTs,
                    'updated_at'       => $nowTs,
                ];

                $insert['next_reset_at'] = app(SubscriptionTrafficReset::class)->next((object) $insert, $plan, $nowTs);
                $insert['last_reset_at'] = null;
                $insert['reset_count'] = 0;

                try {
                    $id = DB::table('v2_user')->insertGetId($insert);
                    $created[] = ['id' => (int)$id, 'email' => $email, 'token' => $token];
                    break;
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    $isDuplicate = str_contains($msg, 'Integrity constraint violation') ||
                                   str_contains($msg, 'Duplicate entry') ||
                                   str_contains($msg, 'SQLSTATE[23000]');
                    if ($isDuplicate && $customUsername !== null && $this->emailExists($email, true)) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['custom_username' => 'این نام کاربری در این پنل قبلاً استفاده شده است.']);
                    }
                    if ($isDuplicate && $attempt < $maxRetriesPerUser) continue;

                    $errors[] = $msg;
                    break;
                }
            }
        }

        return compact('created','errors');
    }

    // ---------- Helpers ----------

    private function makeCandidateEmail(string $panelCode): string
    {
        $local = strtolower(preg_replace('/[^a-z0-9]/i', '', Str::random(8)));
        return "{$local}@{$panelCode}.com";
    }

    private function emailExists(string $email, bool $current = false): bool
    {
        $query = DB::table('v2_user')->where('email', $email);
        // A concurrent winner may commit after this transaction's first read.
        // Use an InnoDB current read for an explicit name, not an older snapshot.
        return $current ? $query->lockForUpdate()->first(['id']) !== null : $query->exists();
    }

    private function uuidExists(string $uuid): bool
    {
        return DB::table('v2_user')->where('uuid', $uuid)->exists();
    }

    private function tokenExists(string $token): bool
    {
        return DB::table('v2_user')->where('token', $token)->exists();
    }
}




