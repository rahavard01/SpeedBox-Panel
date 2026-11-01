<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Services\TransactionLogger;

class PanelPricingService
{
    /** Internal immutable quote captured before the customer starts payment. */
    public function chargeSnapshot(int $ownerId, array $snapshot): array
    {
        return DB::transaction(function () use ($ownerId, $snapshot) {
            $owner = DB::table('panel_users')->where('id', $ownerId)->lockForUpdate()->first();
            if (!$owner) return ['ok' => false];
            $price = (string) $snapshot['unit_price'];
            if (!preg_match('/^\d+$/D', $price)) throw new \DomainException('قیمت ذخیره‌شده طرح نامعتبر است.');
            $total = bcmul($price, (string) $snapshot['quantity'], 0);
            $before = (string) $owner->credit;
            if (bccomp($before, $total, 0) < 0) return ['ok' => false, 'reason' => 'INSUFFICIENT_CREDIT'];
            $after = bcsub($before, $total, 0);
            DB::table('panel_users')->where('id', $ownerId)->update(['credit' => $after]);
            $id = app(TransactionLogger::class)->start([
                'panel_user_id' => $ownerId, 'type' => 'account_extend', 'direction' => 'debit',
                'amount' => $total, 'balance_before' => $before, 'balance_after' => $after,
                'quantity' => $snapshot['quantity'], 'plan_key_after' => $snapshot['update']['account_plan_key'],
                'performed_by_id' => $ownerId, 'performed_by_role' => $owner->role,
                'meta' => ['unit_price' => $price, 'tariff_group_id' => $snapshot['update']['account_tariff_group_id'],
                    'tariff_group_name' => $snapshot['update']['account_plan_name']],
            ]);
            return ['ok' => true, 'transaction_id' => $id];
        });
    }

    public function ensureBalanceAndCharge(
        int $panelUserId,
        string $planKey,
        int $quantity = 1,
        ?int $tariffGroupId = null,
        ?int $subscriptionUserId = null
    ): array {
        $encodedGroupId = $this->extractGroupIdFromPlanKey($planKey);
        $planKey = $this->normalizePlanKey($planKey);
        $quantity = max(1, (int) $quantity);
        $tariffGroupId = $this->resolveTariffGroupId($tariffGroupId ?: $encodedGroupId, $planKey);

        return DB::transaction(function () use ($panelUserId, $planKey, $quantity, $tariffGroupId, $subscriptionUserId) {
            $panelUser = DB::table('panel_users')->where('id', $panelUserId)->lockForUpdate()->first();

            if (!$panelUser) {
                return ['ok' => false, 'unit_price' => '0', 'total_price' => '0', 'reason' => 'Panel user not found'];
            }

            $unitPrice = $this->resolveUnitPrice($panelUser, $planKey, $tariffGroupId);

            if ($unitPrice === null) {
                return ['ok' => false, 'unit_price' => '0', 'total_price' => '0', 'reason' => 'Price not configured'];
            }

            $total = bcmul($unitPrice, (string) $quantity, 0);
            $currentCredit = (string) ($panelUser->credit ?? '0');
            if (bccomp($currentCredit, $total, 0) < 0) {
                return ['ok' => false, 'unit_price' => $unitPrice, 'total_price' => $total, 'reason' => 'INSUFFICIENT_CREDIT'];
            }

            $after = bcsub($currentCredit, $total, 0);

            DB::table('panel_users')->where('id', $panelUserId)->update([
                'credit' => $after,
            ]);

            $group = DB::table('panel_tariff_groups')->where('id', $tariffGroupId)->first();

            $logger = new TransactionLogger();
            $trxId = $logger->start([
                'panel_user_id'  => $panelUserId,
                'type'           => 'account_purchase',
                'direction'      => 'debit',
                'amount'         => $total,
                'balance_before' => $currentCredit,
                'balance_after'  => $after,
                'quantity'       => $quantity,
                'plan_key_after' => $planKey,
                'meta'           => [
                    'unit_price' => $unitPrice,
                    'tariff_group_id' => $tariffGroupId,
                    'tariff_group_name' => $group->name ?? null,
                    'subscription_user_id' => $subscriptionUserId,
                ],
            ]);

            return [
                'ok' => true,
                'unit_price' => $unitPrice,
                'total_price' => $total,
                'before' => $currentCredit,
                'after' => $after,
                'transaction_id' => $trxId,
                'tariff_group_id' => $tariffGroupId,
                'tariff_group_name' => $group->name ?? null,
            ];
        });
    }

    public function quoteUnitPrice(int $panelUserId, string $planKey, ?int $tariffGroupId = null): ?string
    {
        $encodedGroupId = $this->extractGroupIdFromPlanKey($planKey);
        $planKey = $this->normalizePlanKey($planKey);
        $tariffGroupId = $this->resolveTariffGroupId($tariffGroupId ?: $encodedGroupId, $planKey);

        $panelUser = DB::table('panel_users')->where('id', $panelUserId)->first();

        if (!$panelUser) {
            return null;
        }

        return $this->resolveUnitPrice($panelUser, $planKey, $tariffGroupId);
    }

    private function normalizePlanKey(string $planKey): string
    {
        $k = strtolower(trim($planKey));

        if ($k === '1m_noexp') {
            return '1m';
        }

        if (preg_match('/^\d+_volume_gig$/', $k)) {
            return 'volume_gig';
        }

        if (preg_match('/^\d+_traffic_gig$/', $k)) {
            return 'gig';
        }

        if (preg_match('/^\d+_(test|1m|3m|6m|12m)$/', $k, $m)) {
            return $m[1];
        }

        return $k;
    }

    private function extractGroupIdFromPlanKey(string $planKey): ?int
    {
        $k = strtolower(trim($planKey));

        if (preg_match('/^(\d+)_(volume_gig|traffic_gig|test|1m|3m|6m|12m)$/', $k, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function resolveTariffGroupId(?int $tariffGroupId, string $planKey): int
    {
        $groupType = $planKey === 'volume_gig' ? 'volume' : 'time';

        if ($tariffGroupId && $tariffGroupId > 0) {
            $exists = DB::table('panel_tariff_groups')
                ->where('id', $tariffGroupId)
                ->where('group_type', $groupType)
                ->where('enable', 1)
                ->exists();

            if ($exists) {
                return (int) $tariffGroupId;
            }
        }

        $defaultId = DB::table('panel_tariff_groups')
            ->where('group_type', $groupType)
            ->where('is_default', 1)
            ->where('enable', 1)
            ->value('id');

        if ($defaultId) {
            return (int) $defaultId;
        }

        return (int) DB::table('panel_tariff_groups')
            ->where('group_type', $groupType)
            ->where('enable', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');
    }

    private function resolveUnitPrice(object $panelUser, string $planKey, int $tariffGroupId): ?string
    {
        $usePersonalized = (int) ($panelUser->enable_personalized_price ?? 0) === 1;

        if ($usePersonalized) {
            $customPrice = DB::table('panel_user_tariff_prices')
                ->where('panel_user_id', (int) $panelUser->id)
                ->where('tariff_group_id', $tariffGroupId)
                ->where('plan_key', $planKey)
                ->value('price');

            if ($customPrice !== null && $customPrice !== '') {
                return (string) $customPrice;
            }

            $groupType = $planKey === 'volume_gig' ? 'volume' : 'time';

            $defaultGroupId = (int) DB::table('panel_tariff_groups')
                ->where('group_type', $groupType)
                ->where('is_default', 1)
                ->value('id');

            if ($tariffGroupId === $defaultGroupId) {
                $legacyMap = [
                    'test' => 'personalized_price_test',
                    '1m' => 'personalized_price_1',
                    '3m' => 'personalized_price_3',
                    '6m' => 'personalized_price_6',
                    '12m' => 'personalized_price_12',
                    'gig' => 'traffic_price',
                    'volume_gig' => 'traffic_price',
                ];

                $col = $legacyMap[$planKey] ?? null;

                if ($col && ($panelUser->{$col} ?? null) !== null) {
                    return (string) $panelUser->{$col};
                }
            }

            return null;
        }

        $price = DB::table('panel_plan')
            ->where('tariff_group_id', $tariffGroupId)
            ->where('plan_key', $planKey)
            ->where('enable', 1)
            ->value('default_price');

        return $price !== null && $price !== '' ? (string) $price : null;
    }
}

