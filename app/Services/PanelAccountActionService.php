<?php

namespace App\Services;

use App\Models\PanelPlan;
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
        object $panelUser, string $email, string $period, ?int $tariffGroupId = null,
        ?string $operatorSource = null, ?int $botOrderId = null, ?array $purchaseSnapshot = null, ?string $requestId = null
    ): array {
        if (!in_array($period, ['test', '1m', '3m', '6m', '12m'], true)) return ['ok' => false, 'message' => 'ورودی تمدید نامعتبر است'];
        return $this->purchaseExisting($panelUser, $email, $period, 1, $tariffGroupId, $operatorSource, $botOrderId, $purchaseSnapshot, $requestId);
    }

    public function topupTrafficForPanelUser(
        object $panelUser, string $email, int $gb, ?int $tariffGroupId = null,
        ?string $operatorSource = null, bool $blockExpiredTimeAccount = false,
        ?int $botOrderId = null, ?array $purchaseSnapshot = null, ?string $requestId = null
    ): array {
        if ($gb < 1) return ['ok' => false, 'message' => 'ورودی نامعتبر است'];
        $user = $this->findV2UserByEmailSafe($email);
        if (!$user) return ['ok' => false, 'message' => 'کاربر یافت نشد'];
        $volume = app(AccountTariffResolver::class)->isVolume($user);
        return $this->purchaseExisting($panelUser, $email, $volume ? 'volume_gig' : 'gig', $gb,
            $tariffGroupId, $operatorSource, $botOrderId, $purchaseSnapshot, $requestId);
    }

    private function purchaseExisting(
        object $owner, string $email, string $key, int $quantity, ?int $groupId,
        ?string $source, ?int $orderId, ?array $snapshot, ?string $requestId
    ): array {
        $email = strtolower(trim($email));
        if ((int) ($owner->role ?? 2) !== 1 && !$this->emailBelongsToPanelCode($email, (string) ($owner->code ?? ''))) {
            return ['ok' => false, 'message' => 'Forbidden'];
        }
        try {
            return DB::transaction(function () use ($owner, $email, $key, $quantity, $groupId, $source, $orderId, $snapshot, $requestId) {
                DB::table('panel_users')->where('id', $owner->id)->lockForUpdate()->first();
                $requestKey = $requestId ? hash('sha256', $owner->id.':'.$requestId) : null;
                $fingerprint = hash('sha256', json_encode([$email, $key, $quantity, $groupId]));
                if ($requestKey && ($previous = DB::table('speedbox_account_purchase_requests')->where('request_key', $requestKey)->first())) {
                    if (!hash_equals($previous->fingerprint, $fingerprint)) throw new \DomainException('شناسه درخواست با مشخصات سفارش مطابقت ندارد.');
                    return json_decode($previous->result, true, 512, JSON_THROW_ON_ERROR);
                }
                $user = DB::table('v2_user')->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
                if (!$user) throw new \DomainException('کاربر یافت نشد');
                $reservation = app(SubscriptionReservationService::class);
                $volume = app(AccountTariffResolver::class)->isVolume($user);
                $extra = $key === 'gig';
                if ($extra && (!$reservation->periodic($user) || $volume)) throw new \DomainException('خرید ترافیک اضافه برای طرح‌های بدون ریست دوره‌ای فعال نیست؛ از گزینه تمدید اکانت استفاده کنید.');
                if ($extra && !SubscriptionValidity::unexpired($user->expired_at ?? null)) throw new \DomainException('اکانت منقضی شده است؛ ابتدا اکانت را تمدید کنید.');
                if (!$extra && $key !== 'volume_gig' && $volume && !$reservation->ended($user)) throw new \DomainException('برای این اکانت از گزینه خرید ترافیک استفاده کنید.');
                if (!$extra) $reservation->assertAvailable($user, $orderId);
                $currentGroup = app(AccountTariffResolver::class)->group($user);
                if ($extra && (!$currentGroup || !$currentGroup->enable || !app(PartnerTariffAccessService::class)->canSeeTariffGroup($owner, (int) $currentGroup->id)
                    || !app(PartnerTariffAccessService::class)->canCreateType($owner, 'time'))) throw new \DomainException('تعرفه فروش ترافیک برای این اکانت فعال نیست.');
                $selectedGroup = $extra ? (int) ($currentGroup->id ?? 0) : ($groupId ?: (int) ($currentGroup->id ?? 0));
                if (!$selectedGroup) throw new \DomainException('طرح اکانت قابل تشخیص نیست؛ با پشتیبانی تماس بگیرید.');
                // Only an internally stored, paid bot order may supply a frozen purchase snapshot.
                if ($snapshot !== null && !$orderId) throw new \LogicException('Purchase snapshot requires its bot order.');
                if (!$extra) {
                    $reservation->ensureGenerationSupport($user);
                    $snapshot ??= $reservation->snapshot($owner, $selectedGroup, $key, $key === 'volume_gig' ? $quantity : null);
                    if ((int) $snapshot['update']['account_tariff_group_id'] !== $selectedGroup || $snapshot['update']['account_plan_key'] !== $key) throw new \DomainException('اطلاعات طرح سفارش مطابقت ندارد.');
                    $reservation->assertCompatible($user, $snapshot);
                    $reservation->ensureGenerationSupport((object) array_merge((array) $user, $snapshot['update']));
                }
                if ((int) ($owner->role ?? 2) === 1) {
                    $tx = $this->startAdminZeroTransaction($owner, $extra ? 'traffic_purchase' : 'account_extend', $key, $quantity, $selectedGroup);
                } else {
                    $charge = $extra
                        ? app(PanelPricingService::class)->ensureBalanceAndCharge((int) $owner->id, $key, $quantity, $selectedGroup)
                        : app(PanelPricingService::class)->chargeSnapshot((int) $owner->id, $snapshot);
                    if (empty($charge['ok'])) throw new \DomainException(($charge['reason'] ?? '') === 'INSUFFICIENT_CREDIT' ? 'اعتبار شما کافی نیست' : ($charge['message'] ?? 'قیمت طرح مشخص نشده است'));
                    $tx = (int) $charge['transaction_id'];
                }
                DB::table('panel_transactions')->where('id', $tx)->update(['type' => $extra ? 'traffic_purchase' : 'account_extend']);
                $result = ['reserved' => false, 'expired_at' => $user->expired_at];
                if ($extra) {
                    $down = (int) $user->d - $quantity * 1073741824;
                    DB::table('v2_user')->where('id', $user->id)->update(['d' => $down,
                        'traffic_warning_version' => (int) ($user->traffic_warning_version ?? 0) + 1, 'updated_at' => time()]);
                } else {
                    $result = $reservation->purchase($user, $owner, $snapshot, $tx, $orderId);
                }
                app(TransactionLogger::class)->finalize($tx, [
                    'reference_type' => 'v2_user', 'reference_id' => $user->id, 'user_ids' => [$user->id],
                    'performed_by_id' => $owner->id, 'performed_by_role' => $owner->role ?? 2,
                    'extra_meta' => ['source' => $source ?: 'panel', 'operator_source' => $source,
                        'expired_before' => $user->expired_at, 'expired_after' => $result['expired_at'],
                        'plan_key_after' => $key, 'tariff_group_id' => $selectedGroup,
                        'reservation_id' => $result['reservation_id'] ?? null, 'reservation_pending' => $result['reserved'],
                        'force_new_cycle' => !empty($result['fresh_cycle']), 'is_volume_mode' => $key === 'volume_gig',
                        'purchase_snapshot' => $extra ? null : $snapshot, 'quantity' => $extra || $key === 'volume_gig' ? $quantity.'GB' : null],
                ]);
                $result = ['ok' => true, 'email' => $email, 'transaction_id' => $tx,
                    'traffic_gb' => $quantity, 'd_after' => DB::table('v2_user')->where('id', $user->id)->value('d')] + $result;
                if ($requestKey) DB::table('speedbox_account_purchase_requests')->insert([
                    'request_key' => $requestKey, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
                return $result;
            }, 3);
        } catch (\DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'insufficient_credit' => $e->getMessage() === 'اعتبار شما کافی نیست'];
        } catch (\Throwable $e) {
            report($e);
            return ['ok' => false, 'message' => 'انجام سفارش ناموفق بود؛ دوباره تلاش کنید.'];
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


