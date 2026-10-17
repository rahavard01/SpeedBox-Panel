<?php

namespace App\Services\UserBot;

use App\Models\UserBotSetting;
use App\Support\JalaliDateFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserBotAccountResolver
{
    public function resolve(UserBotSetting $setting, string $input): ?array
    {
        $token = $this->extractToken($input);
        if (!$token) return null;

        return $this->findByToken($setting, $token, $input);
    }

    public function findCurrent(UserBotSetting $setting, ?string $subLink): ?array
    {
        if (!$subLink) return null;

        $token = $this->extractToken($subLink);
        if (!$token) return null;

        return $this->findByToken($setting, $token, $subLink);
    }

    public function isVolumeAccount(array $snapshot): bool
    {
        if (
            (string) ($snapshot['account_group_type'] ?? '')
            === 'volume'
        ) {
            return true;
        }

        $planKey = (string) (
            $snapshot['account_plan_key'] ?? ''
        );

        if (
            $planKey === 'volume_gig'
            || str_ends_with($planKey, '_volume_gig')
        ) {
            return true;
        }

        $planId = (int) ($snapshot['plan_id'] ?? 0);

        if (
            $planId <= 0
            || !Schema::hasTable('panel_plan')
            || !Schema::hasTable('panel_tariff_groups')
        ) {
            return false;
        }

        return DB::table('panel_plan as plans')
            ->join(
                'panel_tariff_groups as groups',
                'groups.id',
                '=',
                'plans.tariff_group_id'
            )
            ->where('plans.v2_plan_id', $planId)
            ->where('plans.enable', 1)
            ->where(function ($query) {
                $query->where('plans.plan_key', 'volume_gig')
                    ->orWhere('groups.group_type', 'volume');
            })
            ->exists();
    }

    public function hasVipPlanName(array $snapshot): bool
    {
        return stripos($this->resolvePlanTitle($snapshot), 'VIP') !== false;
    }

    public function trafficText(array $snapshot): string
    {
        $total = (int) ($snapshot['transfer_enable'] ?? 0);
        $used = (int)($snapshot['u'] ?? 0) + (int)($snapshot['d'] ?? 0);
        $remain = max(0, $total - $used);

        return "📊 <b>وضعیت ترافیک اکانت</b>\n\n"
            . "👤 نام کاربری: <code>" . e($this->formatUsername($snapshot['email'] ?? null)) . "</code>\n"
            . "📦 ترافیک کل: <b>" . $this->formatBytes($total) . "</b>\n"
            . "🔥 ترافیک مصرفی: <b>" . $this->formatBytes($used) . "</b>\n"
            . "✅ ترافیک باقی‌مانده: <b>" . $this->formatBytes($remain) . "</b>";
    }

    public function expiryText(array $snapshot): string
    {
        $email = e($this->formatUsername($snapshot['email'] ?? null));
        $expiredAt = $snapshot['expired_at'] ?? null;

        if (!\App\Services\SubscriptionValidity::hasExpiry($expiredAt)) {
            return "⏳ <b>تاریخ انقضا</b>\n\n"
                . "👤 نام کاربری: <code>{$email}</code>\n"
                . "♾ این اکانت تاریخ انقضا ندارد.";
        }

        $ts = (int) $expiredAt;
        $date = JalaliDateFormatter::forTelegram($ts, 'H:i d-m-Y', '-');
        $days = $this->formatRemainingTime($ts);

        return "⏳ <b>تاریخ انقضا</b>\n\n"
            . "👤 نام کاربری: <code>{$email}</code>\n"
            . "📅 تاریخ انقضا: <b>{$date}</b>\n"
            . "🕒 زمان باقی‌مانده: <b>{$days}</b>";
    }

    public function accountInfoText(array $snapshot, ?string $subLink): string
    {
        $total = (int) ($snapshot['transfer_enable'] ?? 0);
        $used = (int) ($snapshot['u'] ?? 0) + (int) ($snapshot['d'] ?? 0);
        $remain = max(0, $total - $used);

        $email = e($this->formatUsername($snapshot['email'] ?? null));
        $planTitle = $this->resolvePlanTitle($snapshot);
        $expiredAt = $snapshot['expired_at'] ?? null;

        if (!\App\Services\SubscriptionValidity::hasExpiry($expiredAt)) {
            $expireText = "♾ بدون تاریخ انقضا";
            $daysText = "♾ نامحدود";
        } else {
            $ts = (int) $expiredAt;
            $expireText = JalaliDateFormatter::forTelegram($ts, 'H:i d-m-Y', '-');
            $daysText = $this->formatRemainingTime($ts);
        }

        $subLink = trim((string) $subLink);
        $subText = $subLink !== '' ? e($subLink) : '-';

        return "🔰 <b>اطلاعات کاربری</b>\n\n"
            . "👤 نام کاربری: <code>{$email}</code>\n"
            . ($planTitle !== '' ? "🧩 طرح فعال: <b>" . e($planTitle) . "</b>\n" : "")
            . "📦 ترافیک کل: <b>" . $this->formatBytes($total) . "</b>\n"
            . "🔥 ترافیک مصرفی: <b>" . $this->formatBytes($used) . "</b>\n"
            . "✅ ترافیک باقی‌مانده: <b>" . $this->formatBytes($remain) . "</b>\n"
            . "📅 تاریخ انقضا: <b>{$expireText}</b>\n"
            . "🕒 زمان باقی‌مانده: <b>{$daysText}</b>\n\n"
            . "🔗 لینک سابسکرایب:\n"
            . "<code>{$subText}</code>";
    }

    private function formatRemainingTime(?int $expiredAt): string
    {
        if (!\App\Services\SubscriptionValidity::hasExpiry($expiredAt)) {
            return '♾ نامحدود';
        }

        $seconds = $expiredAt - time();

        if ($seconds <= 0) {
            return 'منقضی شده';
        }

        if ($seconds >= 86400) {
            return JalaliDateFormatter::persianDigits((string) ceil($seconds / 86400)) . ' روز';
        }

        if ($seconds >= 3600) {
            return JalaliDateFormatter::persianDigits((string) ceil($seconds / 3600)) . ' ساعت';
        }

        return JalaliDateFormatter::persianDigits((string) ceil($seconds / 60)) . ' دقیقه';
    }

    private function findByToken(UserBotSetting $setting, string $token, string $subLink): ?array
    {
        if (!Schema::hasTable('v2_user')) return null;

        $query = DB::table('v2_user');

        if (Schema::hasColumn('v2_user', 'token')) {
            $query->where('token', $token);
        } elseif (Schema::hasColumn('v2_user', 'uuid')) {
            $query->where('uuid', $token);
        } else {
            return null;
        }

        $user = $query->first();
        if (!$user) return null;

        if (!$this->belongsToOwner($setting, $user)) {
            return null;
        }

        return [
            'email' => $user->email ?? null,
            'token' => $token,
            'sub_link' => $subLink,
            'snapshot' => [
                'id' => $user->id ?? null,
                'email' => $user->email ?? null,
                'transfer_enable' => $user->transfer_enable ?? null,
                'banned' => $user->banned ?? 0,
                'traffic_warning_version' => $user->traffic_warning_version ?? 0,
                'last_reset_at' => $user->last_reset_at ?? null,
                'reset_count' => $user->reset_count ?? 0,
                'u' => $user->u ?? null,
                'd' => $user->d ?? null,
                'expired_at' => $user->expired_at ?? null,
                'plan_id' => $user->plan_id ?? null,
                'account_plan_name' => $user->account_plan_name ?? null,
                'account_group_type' => $user->account_group_type ?? null,
                'account_tariff_group_id' => $user->account_tariff_group_id ?? null,
                'account_plan_key' => $user->account_plan_key ?? null,                
            ],
        ];
    }

    private function extractToken(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') return null;

        $parts = parse_url($input);

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);

            foreach (['token', 'uuid', 'id'] as $key) {
                if (!empty($query[$key]) && is_scalar($query[$key])) {
                    return trim((string) $query[$key]);
                }
            }
        }

        if (preg_match('/token=([A-Za-z0-9_\-]+)/', $input, $m)) return $m[1];

        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : $input;
        if (preg_match('/\/([A-Za-z0-9_\-]{16,})$/', $path, $m)) return $m[1];

        return null;
    }

    private function belongsToOwner(UserBotSetting $setting, object $user): bool
    {
        if ($setting->owner_type === 'admin') {
            return true;
        }

        $partner = DB::table('panel_users')->where('id', $setting->owner_id)->first();
        if (!$partner) return false;

        $partnerCode = trim((string) ($partner->code ?? ''));
        $email = strtolower((string) ($user->email ?? ''));

        if ($partnerCode === '' || !str_contains($email, '@')) return false;
        [$local, $domain] = explode('@', $email, 2);
        $code = strtolower($partnerCode);
        return $local === $code || (explode('.', $domain)[0] ?? '') === $code;
    }

    private function resolvePlanTitle(array $snapshot): string
    {
        $snapshotName = trim((string)($snapshot['account_plan_name'] ?? ''));

        if ($snapshotName !== '') {
            return $snapshotName;
        }

        $planId = (int)($snapshot['plan_id'] ?? 0);

        if ($planId <= 0) {
            return '';
        }

        $volumePlan = DB::table('panel_plan as p')
            ->leftJoin('panel_tariff_groups as g', 'g.id', '=', 'p.tariff_group_id')
            ->where('p.v2_plan_id', $planId)
            ->where('p.plan_key', 'volume_gig')
            ->where('p.enable', 1)
            ->where('g.group_type', 'volume')
            ->select('g.name as group_name')
            ->first();

        if ($volumePlan && trim((string)($volumePlan->group_name ?? '')) !== '') {
            return trim((string)$volumePlan->group_name);
        }

        $plan = DB::table('panel_plan as p')
            ->leftJoin('panel_tariff_groups as g', 'g.id', '=', 'p.tariff_group_id')
            ->where('p.v2_plan_id', $planId)
            ->where('p.enable', 1)
            ->select(
                'p.plan_key',
                'g.name as group_name',
                'g.group_type'
            )
            ->first();

        if (!$plan) {
            return '';
        }

        $groupName = trim((string)($plan->group_name ?? ''));
        $planKey = trim((string)($plan->plan_key ?? ''));

        if ($groupName !== '') {
            return $groupName;
        }

        return match ($planKey) {
            'test' => 'تست',
            '1m' => 'یک‌ماهه',
            '3m' => 'سه‌ماهه',
            '6m' => 'شش‌ماهه',
            '12m' => 'یک‌ساله',
            'volume_gig' => 'حجمی',
            default => $planKey,
        };
    }

    private function formatUsername(?string $email): string
    {
        if (!$email) {
            return '-';
        }

        $name = str_replace('@', '-', $email);
        $name = str_replace('.com', '', $name);

        return $name ?: '-';
    }

    private function formatBytes(int $bytes): string
    {
        $negative = $bytes < 0;

        $absBytes = abs($bytes);

        if ($absBytes >= 1073741824) {
            $result = number_format($absBytes / 1073741824, 2) . ' گیگابایت';
        } else {
            $result = number_format($absBytes / 1048576, 0) . ' مگابایت';
        }

        return $negative ? ('-' . $result) : $result;
    }
}
