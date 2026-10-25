<?php

namespace App\Services;

use App\Models\PanelPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PanelAccountActionService
{
    private function emailBelongsToPanelCode(string $email, string $panelCode): bool
    {
        return \App\Services\PanelAccountOwnership::matches($email, $panelCode);
    }

    public function createAccountForPanelUser(
        object $panelUser,
        string $planKey,
        int $count = 1,
        ?int $tariffGroupId = null,
        ?int $volumeGb = null,
        bool $downloadExcel = false,
        ?string $operatorSource = null
    ): array {
        $creator = app(V2UserCreator::class);
        $pricing = app(PanelPricingService::class);
        $logger  = app(TransactionLogger::class);

        $count = max(1, $count);

        $lookupPlanKey = $planKey === '1m_noexp' ? '1m' : $planKey;
        $noExpire = $planKey === '1m_noexp';

        $partnerPlanType = $panelUser->partner_plan_type ?? 'time';
        $isVolumePartner = $partnerPlanType === 'volume';
        $isBothPartner = $partnerPlanType === 'both';

        $requestedGroup = $tariffGroupId
            ? DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->first()
            : null;

        $isVolumeRequest = $planKey === 'volume_gig'
            || (($requestedGroup->group_type ?? '') === 'volume');
        if (!app(PartnerTariffAccessService::class)->canCreateType($panelUser, $isVolumeRequest ? 'volume' : 'time')) {
            return ['ok' => false, 'message' => 'این نوع پلن برای شما فعال نیست'];
        }
        if ($isVolumePartner || ($isBothPartner && $isVolumeRequest) || $planKey === 'volume_gig') {
            $planKey = 'volume_gig';
            $lookupPlanKey = 'volume_gig';
            $noExpire = true;
            $volumeGb = max(1, (int)($volumeGb ?? 1));
            $quantity = $volumeGb * $count;
        } else {
            $quantity = $count;
        }

        $tariffGroupId = $this->resolveTariffGroupId(
            $tariffGroupId,
            $lookupPlanKey === 'volume_gig' ? 'volume' : 'time'
        );

        if (!app(PartnerTariffAccessService::class)->canSeeTariffGroup($panelUser, (int)$tariffGroupId)) {
            return ['ok' => false, 'message' => 'این طرح برای شما فعال نیست'];
        }

        $pp = PanelPlan::where('enable', 1)
            ->where('tariff_group_id', $tariffGroupId)
            ->where('plan_key', $lookupPlanKey)
            ->first();

        if (!$pp || !$pp->v2_plan_id) {
            return ['ok' => false, 'message' => 'پلن انتخابی پیدا نشد یا به پلن اصلی متصل نیست'];
        }

        if ($lookupPlanKey === 'volume_gig') {
            $minimum = \App\Services\VolumeTrafficPolicy::minimum($tariffGroupId);
            if ($volumeGb < $minimum) {
                return ['ok' => false, 'message' => \App\Services\VolumeTrafficPolicy::message($minimum)];
            }
        }

        $tariffGroup = DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->first();

        $accountSnapshot = [
            'account_plan_name'       => (string) ($tariffGroup->name ?? ''),
            'account_group_type'      => ($lookupPlanKey === 'volume_gig') ? 'volume' : 'time',
            'account_tariff_group_id' => (int) $tariffGroupId,
            'account_plan_key'        => (string) $lookupPlanKey,
        ];

        DB::beginTransaction();

        try {
            $trxId = null;

            if ((int)($panelUser->role ?? 2) !== 1) {
                $charge = $pricing->ensureBalanceAndCharge(
                    (int)$panelUser->id,
                    $planKey,
                    $quantity,
                    $tariffGroupId
                );

                if (empty($charge['ok'])) {
                    DB::rollBack();

                    return [
                        'ok' => false,
                        'insufficient_credit' => (($charge['reason'] ?? null) === 'INSUFFICIENT_CREDIT'),
                        'message' => (($charge['reason'] ?? null) === 'INSUFFICIENT_CREDIT')
                            ? 'اعتبار شما کافی نیست'
                            : ($charge['reason'] ?? 'خطا در محاسبه قیمت'),
                    ];
                }

                $trxId = $charge['transaction_id'] ?? null;

                if ($trxId) {
                    DB::table('panel_transactions')->where('id', $trxId)->update([
                        'type' => 'account_purchase',
                        'plan_key_after' => $planKey,
                    ]);
                }} else {
                $trxId = $this->startAdminZeroTransaction(
                    $panelUser,
                    'account_purchase',
                    $planKey,
                    $quantity,
                    $tariffGroupId,
                    [
                        'source' => 'admin_panel',
                        'is_admin_free_operation' => true,
                        'is_volume_partner' => $planKey === 'volume_gig',
                        'volume_gb' => $volumeGb,
                        'account_count' => $count,
                        'total_volume_gb' => ($planKey === 'volume_gig') ? $quantity : null,
                    ]
                );
            }

            $result = $creator->createUsers(
                panelCode: (string)($panelUser->code ?? ''),
                v2PlanId: (int)$pp->v2_plan_id,
                count: $count,
                planKey: $lookupPlanKey,
                noExpire: $noExpire,
                customTrafficGb: ($planKey === 'volume_gig') ? $volumeGb : null,
                accountSnapshot: $accountSnapshot,
                panelOwnerId: (int) $panelUser->id
            );

            $created = $result['created'] ?? [];
            $errors = $result['errors'] ?? [];

            if (count($created) === 0) {
                throw new \RuntimeException('ساخت اکانت ناموفق بود');
            }

            if ($trxId) {
                $logger->finalize($trxId, [
                    'reference_type' => 'v2_user',
                    'reference_id' => count($created) === 1 ? (int)$created[0]['id'] : null,
                    'user_ids' => array_values(array_filter(array_map(
                        fn ($it) => isset($it['id']) ? (int)$it['id'] : null,
                        $created
                    ))),
                    'extra_meta' => [
                        'source' => $operatorSource ?: 'panel',
                        'operator_source' => $operatorSource,
                        'plan_key_after' => $planKey,
                        'tariff_group_id' => $tariffGroupId,
                        'is_volume_partner' => $planKey === 'volume_gig',
                        'volume_gb' => $volumeGb,
                        'account_count' => $count,
                        'total_volume_gb' => ($planKey === 'volume_gig') ? $quantity : null,
                        'errors' => $errors,
                    ],
                ]);
            }

            DB::commit();

            return [
                'ok' => true,
                'created' => $created,
                'errors' => $errors,
                'transaction_id' => $trxId,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            return [
                'ok' => false,
                'message' => $e instanceof \Illuminate\Validation\ValidationException ? $e->getMessage() : 'ساخت کاربر ناموفق بود',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function renewAccountForPanelUser(
        object $panelUser,
        string $email,
        string $period,
        ?int $tariffGroupId = null,
        ?string $operatorSource = null
    ): array {
        $pricing = app(PanelPricingService::class);
        $logger = app(TransactionLogger::class);

        $email = strtolower(trim($email));
        $tariffGroupId = $this->resolveTariffGroupId($tariffGroupId);

        if (!app(PartnerTariffAccessService::class)->canSeeTariffGroup($panelUser, (int)$tariffGroupId)) {
            return ['ok' => false, 'message' => 'این طرح برای شما فعال نیست'];
        }

        if (!$email || !in_array($period, ['test', '1m', '3m', '6m', '12m'], true)) {
            return ['ok' => false, 'message' => 'ورودی تمدید نامعتبر است'];
        }

        if ((int)($panelUser->role ?? 2) !== 1) {
            $panelCode = $panelUser->code ?? '';
            if (!$this->emailBelongsToPanelCode($email, $panelCode)) {
                return ['ok' => false, 'message' => 'Forbidden'];
            }
        }

        $user = $this->findV2UserByEmailSafe($email);
        if (!$user) {
            return ['ok' => false, 'message' => 'کاربر یافت نشد'];
        }

        DB::beginTransaction();

        try {
            $trxId = null;
            DB::table('panel_users')->where('id', $panelUser->id)->lockForUpdate()->first();
            $user = DB::table('v2_user')->where('id', $user->id)->lockForUpdate()->first();
            if (!$user) throw new \RuntimeException('کاربر یافت نشد.');

            if ((int)($panelUser->role ?? 2) !== 1) {
                $charge = $pricing->ensureBalanceAndCharge(
                    (int)$panelUser->id,
                    $period,
                    1,
                    $tariffGroupId,
                    (int) $user->id
                );

                if (empty($charge['ok'])) {
                    DB::rollBack();

                    return [
                        'ok' => false,
                        'insufficient_credit' => (($charge['reason'] ?? null) === 'INSUFFICIENT_CREDIT'),
                        'message' => (($charge['reason'] ?? null) === 'INSUFFICIENT_CREDIT')
                            ? 'اعتبار شما کافی نیست'
                            : ($charge['message'] ?? $charge['reason'] ?? 'خطا در محاسبه هزینه تمدید'),
                    ];
                }

                $trxId = $charge['transaction_id'] ?? null;

                if ($trxId) {
                    DB::table('panel_transactions')->where('id', $trxId)->update([
                        'type' => 'account_extend',
                    ]);
                }} else {
                $trxId = $this->startAdminZeroTransaction(
                    $panelUser,
                    'account_extend',
                    $period,
                    1,
                    $tariffGroupId,
                    [
                        'source' => 'admin_panel',
                        'is_admin_free_operation' => true,
                    ]
                );
            }

            $panelPlan = PanelPlan::where('tariff_group_id', $tariffGroupId)
                ->where('plan_key', $period)
                ->where('enable', 1)
                ->first();

            if (!$panelPlan || empty($panelPlan->v2_plan_id)) {
                throw new \RuntimeException('پلن تمدید پیدا نشد');
            }

            $v2Plan = DB::table('v2_plan')->where('id', $panelPlan->v2_plan_id)->first();

            $daysMap = ['test' => 1, '1m' => 30, '3m' => 90, '6m' => 180, '12m' => 365];
            $addDays = $daysMap[$period] ?? 30;

            $nowTs = time();
            $oldExpTs = (int)($user->expired_at ?? 0);
            $baseTs = $oldExpTs > $nowTs ? $oldExpTs : $nowTs;
            $newExpTs = Carbon::createFromTimestamp($baseTs)->addDays($addDays)->timestamp;

            $update = [
                'expired_at' => $newExpTs,
                'updated_at' => $nowTs,
                'plan_id' => $panelPlan->v2_plan_id,
                'u' => 0,
                'd' => 0,
                'account_plan_name'       => (string) DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->value('name'),
                'account_group_type'      => 'time',
                'account_tariff_group_id' => (int) $tariffGroupId,
                'account_plan_key'        => $period,
            ];

            if ($v2Plan && isset($v2Plan->transfer_enable)) {
                $gb = (int)preg_replace('/\D+/', '', (string)$v2Plan->transfer_enable);
                if ($gb > 0) {
                    $update['transfer_enable'] = $gb * 1024 * 1024 * 1024;
                }
            }

            $update['device_limit'] = app(\App\Services\TariffConnectionPolicy::class)->resolve((int) $update['plan_id'], (int) $update['account_tariff_group_id'], $update['account_plan_key']);

            if ($v2Plan && isset($v2Plan->speed_limit)) {
                $update['speed_limit'] = $v2Plan->speed_limit;
            }

            if ($v2Plan && isset($v2Plan->group_id)) {
                $update['group_id'] = $v2Plan->group_id;
            }

            $update = app(SubscriptionOrderPolicy::class)->prepare($user, $update);
            $newExpTs = $update['expired_at'];
            DB::table('v2_user')->where('id', $user->id)->update($update);
            app(TrafficResetHistory::class)->renewal($user, $update);

            if ($trxId) {
                $logger->finalize($trxId, [
                    'reference_type' => 'v2_user',
                    'reference_id' => $user->id,
                    'user_ids' => [$user->id],
                    'extra_meta' => [
                        'source' => $operatorSource ?: 'panel',
                        'operator_source' => $operatorSource,
                        'expired_before' => $oldExpTs,
                        'expired_after' => $newExpTs,
                        'plan_key_after' => $period,
                        'tariff_group_id' => $tariffGroupId,
                    ],
                ]);
            }

            DB::commit();

            return [
                'ok' => true,
                'email' => $email,
                'expired_at' => $newExpTs,
                'transaction_id' => $trxId,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            return [
                'ok' => false,
                'message' => $e instanceof \DomainException ? $e->getMessage() : 'تمدید انجام نشد',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function topupTrafficForPanelUser(
        object $panelUser,
        string $email,
        int $gb,
        ?int $tariffGroupId = null,
        ?string $operatorSource = null,
        bool $blockExpiredTimeAccount = false
    ): array {
        $pricing = app(PanelPricingService::class);
        $logger = app(TransactionLogger::class);

        $email = strtolower(trim($email));
        $gb = max(0, $gb);

        if (!$email || $gb < 1) {
            return ['ok' => false, 'message' => 'ورودی نامعتبر است'];
        }

        if ((int)($panelUser->role ?? 2) !== 1) {
            $panelCode = $panelUser->code ?? '';
            if (!$this->emailBelongsToPanelCode($email, $panelCode)) {
                return ['ok' => false, 'message' => 'Forbidden'];
            }
        }

        $user = $this->findV2UserByEmailSafe($email);
        if (!$user) {
            return ['ok' => false, 'message' => 'کاربر یافت نشد'];
        }

        DB::beginTransaction();

        try {
            DB::table('panel_users')->where('id', $panelUser->id)->lockForUpdate()->first();
            $user = DB::table('v2_user')->where('id', $user->id)->lockForUpdate()->first();
            if (!$user) throw new \RuntimeException('کاربر یافت نشد');
            $trxId = null;
            $bytes = $gb * 1024 * 1024 * 1024;

            $isAdmin = ((int)($panelUser->role ?? 2) === 1);

            $resolver=app(AccountTariffResolver::class);
            $accountGroup=$resolver->group($user);
            if (!$accountGroup) {
                DB::rollBack();
                return ['ok'=>false,'message'=>'طرح این اکانت قابل تشخیص نیست؛ طرح اکانت را در پنل بررسی کنید.'];
            }
            $isVolumeMode=$resolver->isVolume($user);
            if (!app(PartnerTariffAccessService::class)->canCreateType($panelUser,$isVolumeMode?'volume':'time')) {
                DB::rollBack();
                return ['ok'=>false,'message'=>'این نوع پلن برای شما فعال نیست'];
            }

            if (
                $blockExpiredTimeAccount
                && !$isVolumeMode
                && !SubscriptionValidity::unexpired($user->expired_at ?? null)
            ) {
                DB::rollBack();

                return [
                    'ok' => false,
                    'account_expired' => true,
                    'message' => 'اکانت منقضی شده است؛ ابتدا باید اکانت را تمدید کنید',
                ];
            }

            $planKeyForTopup = $isVolumeMode ? 'volume_gig' : 'gig';

            // Time top-ups always use the account's own group. Volume purchases may
            // explicitly select another allowed volume group, but never a global default.
            $tariffGroupId=(!$isVolumeMode || !$tariffGroupId)?(int)$accountGroup->id:(int)$tariffGroupId;
            $selectedGroup=DB::table('panel_tariff_groups')->where('id',$tariffGroupId)
                ->where('group_type',$isVolumeMode?'volume':'time')->where('enable',1)->first();
            if (!$selectedGroup) {
                DB::rollBack();
                return ['ok'=>false,'message'=>'طرح اکانت غیرفعال است یا با نوع اکانت مطابقت ندارد'];
            }

            if (!app(PartnerTariffAccessService::class)->canSeeTariffGroup($panelUser, (int)$tariffGroupId)) {
                DB::rollBack();

                return ['ok' => false, 'message' => 'این طرح برای شما فعال نیست'];
            }

            if ($isVolumeMode) {
                $minimum = VolumeTrafficPolicy::minimum($tariffGroupId);
                if ($gb < $minimum) {
                    DB::rollBack();
                    return ['ok' => false, 'message' => VolumeTrafficPolicy::message($minimum)];
                }
            }

            if ((int)($panelUser->role ?? 2) !== 1) {
                $charge = $pricing->ensureBalanceAndCharge(
                    (int)$panelUser->id,
                    $planKeyForTopup,
                    $gb,
                    $tariffGroupId
                );

                if (empty($charge['ok'])) {
                    DB::rollBack();

                    return [
                        'ok' => false,
                        'insufficient_credit' => (($charge['reason'] ?? null) === 'INSUFFICIENT_CREDIT'),
                        'message' => (($charge['reason'] ?? null) === 'INSUFFICIENT_CREDIT')
                            ? 'اعتبار شما کافی نیست'
                            : ($charge['reason'] ?? 'خطا در محاسبه قیمت ترافیک'),
                    ];
                }

                $trxId = $charge['transaction_id'] ?? null;

                if ($trxId) {
                    DB::table('panel_transactions')->where('id', $trxId)->update([
                        'type' => 'traffic_purchase',
                    ]);
                }} else {
                $trxId = $this->startAdminZeroTransaction(
                    $panelUser,
                    'traffic_purchase',
                    $planKeyForTopup,
                    $gb,
                    $tariffGroupId,
                    [
                        'source' => 'admin_panel',
                        'is_admin_free_operation' => true,
                        'quantity' => "{$gb}GB",
                        'is_volume_mode' => $isVolumeMode,
                    ]
                );
            }

            $nowTs = time();

            $dBefore = (int)($user->d ?? 0);
            $dAfter = $dBefore - $bytes;

            $updateData = [
                'd' => $dAfter,
                'traffic_warning_version' => (int) ($user->traffic_warning_version ?? 0) + 1,
                'updated_at' => $nowTs,
            ];

            if ($isVolumeMode) {
                $isExpiredVolumeAccount = !SubscriptionValidity::unexpired($user->expired_at ?? null, $nowTs);

                if ($isExpiredVolumeAccount) {
                    // دوره قبلی تمام شده؛ مانده ترافیک قبلی نباید منتقل شود.
                    $dAfter = 0;

                    $updateData['u'] = 0;
                    $updateData['d'] = 0;
                    $updateData['transfer_enable'] = $bytes;
                }

                $updateData['expired_at'] = $nowTs + (30 * 86400);

                $panelPlan = DB::table('panel_plan')
                    ->where('tariff_group_id', $tariffGroupId)
                    ->where('plan_key', 'volume_gig')
                    ->where('enable', 1)
                    ->first();

                if (!$panelPlan || empty($panelPlan->v2_plan_id)) {
                    DB::rollBack();

                    return [
                        'ok' => false,
                        'message' => 'پلن حجمی انتخاب‌شده با پلن ثبت‌شده مطابقت ندارد',
                    ];
                }

                $v2Plan = DB::table('v2_plan')
                    ->where('id', $panelPlan->v2_plan_id)
                    ->first();

                $updateData['plan_id'] = (int) $panelPlan->v2_plan_id;

                $tariffGroup = DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->first();

                $updateData['account_plan_name'] = (string) ($tariffGroup->name ?? '');
                $updateData['account_group_type'] = 'volume';
                $updateData['account_tariff_group_id'] = (int) $tariffGroupId;
                $updateData['account_plan_key'] = 'volume_gig';

                $updateData['device_limit'] = app(\App\Services\TariffConnectionPolicy::class)->resolve((int) $updateData['plan_id'], (int) $updateData['account_tariff_group_id'], $updateData['account_plan_key']);

                if ($v2Plan && isset($v2Plan->speed_limit)) {
                    $updateData['speed_limit'] = $v2Plan->speed_limit;
                }

                if ($v2Plan && isset($v2Plan->group_id)) {
                    $updateData['group_id'] = $v2Plan->group_id;
                }
            }

            DB::table('v2_user')->where('id', $user->id)->update($updateData);

            if ($trxId) {
                $logger->finalize($trxId, [
                    'reference_type' => 'v2_user',
                    'reference_id' => (int)$user->id,
                    'user_ids' => [(int)$user->id],
                    'extra_meta' => [
                        'source' => $operatorSource ?: 'panel',
                        'operator_source' => $operatorSource,
                        'quantity' => "{$gb}GB",
                        'plan_key_after' => $planKeyForTopup,
                        'tariff_group_id' => $tariffGroupId,
                        'is_volume_mode' => $isVolumeMode,
                    ],
                ]);
            }

            DB::commit();

            return [
                'ok' => true,
                'email' => $email,
                'traffic_gb' => $gb,
                'd_after' => $dAfter,
                'expired_at' => $updateData['expired_at'] ?? ($user->expired_at ?? null),
                'transaction_id' => $trxId,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            return [
                'ok' => false,
                'message' => 'افزایش ترافیک انجام نشد',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function startAdminZeroTransaction(
        object $panelUser,
        string $type,
        string $planKey,
        int $quantity,
        int $tariffGroupId,
        array $extraMeta = []
    ): ?int {
        $balance = (string) ((int)($panelUser->credit ?? 0));
        $group = DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->first();

        return app(TransactionLogger::class)->start([
            'panel_user_id' => (int)$panelUser->id,
            'type' => $type,
            'direction' => 'debit',
            'amount' => 0,
            'balance_before' => $balance,
            'balance_after' => $balance,
            'quantity' => max(1, (int)$quantity),
            'plan_key_after' => $planKey,
            'performed_by_id' => (int)$panelUser->id,
            'performed_by_role' => $panelUser->role ?? 1,
            'meta' => array_merge([
                'unit_price' => 0,
                'tariff_group_id' => $tariffGroupId,
                'tariff_group_name' => $group->name ?? null,
            ], $extraMeta),
        ]);
    }

    private function resolveTariffGroupId(?int $tariffGroupId, string $groupType = 'time'): int
    {
        $groupType = $groupType === 'volume' ? 'volume' : 'time';

        if ($tariffGroupId && $tariffGroupId > 0) {
            $exists = DB::table('panel_tariff_groups')
                ->where('id', $tariffGroupId)
                ->where('group_type', $groupType)
                ->where('enable', 1)
                ->exists();

            if ($exists) {
                return (int)$tariffGroupId;
            }
        }

        $defaultId = DB::table('panel_tariff_groups')
            ->where('group_type', $groupType)
            ->where('is_default', 1)
            ->where('enable', 1)
            ->value('id');

        if ($defaultId) {
            return (int)$defaultId;
        }

        return (int)DB::table('panel_tariff_groups')
            ->where('group_type', $groupType)
            ->where('enable', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');
    }

    private function findV2UserByEmailSafe(string $email): ?object
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        return DB::table('v2_user')
            // Imported tables may use utf8 or utf8mb4. Preserve case-insensitive
            // matching while letting the column supply its own character set.
            ->whereRaw('LOWER(`email`) = ?', [$email])
            ->first();
    }    
}


