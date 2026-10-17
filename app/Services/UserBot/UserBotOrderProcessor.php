<?php

namespace App\Services\UserBot;

use App\Models\UserBotOrder;
use App\Services\PanelAccountActionService;
use Illuminate\Support\Facades\DB;

class UserBotOrderProcessor
{
    public function approve(UserBotOrder $order): array
    {
        return match ($order->order_type) {
            'buy_traffic' => $this->approveBuyTraffic($order),
            'renew_account' => $this->approveRenewAccount($order),
            default => $this->approveBuyAccount($order),
        };
    }

    private function approveBuyAccount(UserBotOrder $order): array
    {
        [$setting, $owner] = $this->resolveOwner($order);
        if (!$setting || !$owner) {
            return ['ok' => false, 'message' => 'صاحب ربات پیدا نشد'];
        }

        [$tariffGroupId, $planKey] = $this->parsePlanKey((string) $order->plan_key);

        $result = app(PanelAccountActionService::class)->createAccountForPanelUser(
            $owner,
            $planKey,
            1,
            $tariffGroupId,
            $order->traffic_gb ? (int) $order->traffic_gb : null,
            false,
            'telegram_bot'
        );

        if (empty($result['ok'])) {
            return $result;
        }

        $created = $result['created'][0] ?? null;
        if (!$created) {
            return ['ok' => false, 'message' => 'اکانت ساخته نشد'];
        }

        $subUrl = $this->makeSubscribeUrl((string) ($created['token'] ?? ''));

        $order->update([
            'status' => 'done',
            'approved_at' => now(),
            'card_sms_match_key' => null,
            'payload' => array_merge($order->payload ?: [], [
                'created_user' => $created,
                'subscribe_url' => $subUrl,
                'transaction_id' => $result['transaction_id'] ?? null,
            ]),
        ]);

        return [
            'ok' => true,
            'type' => 'buy_account',
            'email' => $created['email'] ?? '',
            'subscribe_url' => $subUrl,
        ];
    }

    private function approveRenewAccount(UserBotOrder $order): array
    {
        [$setting, $owner] = $this->resolveOwner($order);
        if (!$setting || !$owner) {
            return ['ok' => false, 'message' => 'صاحب ربات پیدا نشد'];
        }

        [$tariffGroupId, $planKey] = $this->parsePlanKey((string) $order->plan_key);

        $result = app(PanelAccountActionService::class)->renewAccountForPanelUser(
            $owner,
            (string) $order->account_email,
            $planKey,
            $tariffGroupId,
            'telegram_bot'
        );

        if (empty($result['ok'])) {
            return $result;
        }

        $order->update([
            'status' => 'done',
            'approved_at' => now(),
            'card_sms_match_key' => null,
            'payload' => array_merge($order->payload ?: [], [
                'new_expired_at' => $result['expired_at'] ?? null,
                'transaction_id' => $result['transaction_id'] ?? null,
            ]),
        ]);

        return [
            'ok' => true,
            'type' => 'renew_account',
            'email' => $result['email'] ?? $order->account_email,
            'plan_title' => $order->payload['selected_plan']['title'] ?? $planKey,
            'new_expired_at' => $result['expired_at'] ?? null,
        ];
    }

    private function approveBuyTraffic(UserBotOrder $order): array
    {
        [$setting, $owner] = $this->resolveOwner($order);
        if (!$setting || !$owner) {
            return ['ok' => false, 'message' => 'صاحب ربات پیدا نشد'];
        }

        [$tariffGroupId] = $this->parsePlanKey((string) $order->plan_key);

        $result = app(PanelAccountActionService::class)->topupTrafficForPanelUser(
            $owner,
            (string) $order->account_email,
            (int) $order->traffic_gb,
            $tariffGroupId,
            'telegram_bot',
            true
        );

        if (empty($result['ok'])) {
            return $result;
        }

        $v2User = DB::table('v2_user')
            ->whereRaw('LOWER(`email`) = ?', [
                strtolower(trim((string) $order->account_email))
            ])
            ->first();

        $trafficRemainingBytes = null;

        if ($v2User) {
            $trafficRemainingBytes = max(
                0,
                (int) ($v2User->transfer_enable ?? 0) - ((int) ($v2User->u ?? 0) + (int) ($v2User->d ?? 0))
            );
        }

        $order->update([
            'status' => 'done',
            'approved_at' => now(),
            'card_sms_match_key' => null,
            'payload' => array_merge($order->payload ?: [], [
                'transaction_id' => $result['transaction_id'] ?? null,
                'd_after' => $result['d_after'] ?? null,
                'new_expired_at' => $result['expired_at'] ?? null,
            ]),
        ]);

        return [
            'ok' => true,
            'type' => 'buy_traffic',
            'email' => $result['email'] ?? $order->account_email,
            'traffic_gb' => $result['traffic_gb'] ?? $order->traffic_gb,
            'new_expired_at' => $result['expired_at'] ?? null,
            'traffic_remaining_bytes' => $trafficRemainingBytes,
        ];
    }

    private function resolveOwner(UserBotOrder $order): array
    {
        $setting = DB::table('user_bot_settings')
            ->where('id', $order->setting_id)
            ->first();

        if (!$setting) return [null, null];

        $owner = DB::table('panel_users')
            ->where('id', $setting->owner_id)
            ->first();

        if (!$owner) return [$setting, null];

        if ((string) ($setting->owner_type ?? '') === 'admin') {
            $owner->role = 1;
        }

        return [$setting, $owner];
    }

    private function parsePlanKey(string $raw): array
    {
        if (preg_match('/^(\d+)_(.+)$/', $raw, $m)) {
            return [(int) $m[1], (string) $m[2]];
        }

        $planKey = trim($raw);
        $groupType = $planKey === 'volume_gig' ? 'volume' : 'time';

        $defaultGroupId = (int) DB::table('panel_tariff_groups')
            ->where('group_type', $groupType)
            ->where('is_default', 1)
            ->where('enable', 1)
            ->value('id');

        if (!$defaultGroupId) {
            $defaultGroupId = (int) DB::table('panel_tariff_groups')
                ->where('group_type', $groupType)
                ->where('enable', 1)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');
        }

        return [$defaultGroupId, $planKey];
    }

    private function makeSubscribeUrl(string $token): string
    {
        if ($token === '') return '';

        return \App\Utils\Helper::getSubscribeUrl($token);
    }
}
