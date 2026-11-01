<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SubscriptionReservationService
{
    public const INSTANT_KEEP = 0;
    public const INSTANT_RESET = 1;
    public const RESERVE_KEEP = 2;
    public const RESERVE_RESET = 3;

    public function resetMethod(object $user): int
    {
        if (($user->account_group_type ?? '') === 'volume' || ($user->account_plan_key ?? '') === 'volume_gig') return 2;
        if (empty($user->account_group_type) && app(AccountTariffResolver::class)->isVolume($user)) return 2;
        if (isset($user->account_reset_traffic_method)) return (int) $user->account_reset_traffic_method;
        $plan = DB::table('v2_plan')->where('id', (int) ($user->plan_id ?? 0))->first(['reset_traffic_method']);
        return (int) ($plan->reset_traffic_method ?? RuntimeSetting::get('reset_traffic_method', 2));
    }

    public function periodic(object $user): bool
    {
        return in_array($this->resetMethod($user), [0, 1, 3, 4], true);
    }

    public function ended(object $user, ?int $now = null, ?bool $onQuota = null): bool
    {
        $now ??= time();
        if (!SubscriptionValidity::unexpired($user->expired_at ?? null, $now)) return true;
        $onQuota ??= !$this->periodic($user);
        return $onQuota && (int) ($user->transfer_enable ?? 0) > 0
            && (int) ($user->u ?? 0) + (int) ($user->d ?? 0) >= (int) $user->transfer_enable;
    }

    public function current(int $userId): ?array
    {
        if (!Schema::hasTable('speedbox_subscription_reservations')) return null;
        $row = DB::table('speedbox_subscription_reservations')->where('reserved_user_id', $userId)->first();
        return $row ? $this->present($row) : null;
    }

    public function present(object $row): array
    {
        $s = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $method = (int) $s['update']['account_reset_traffic_method'];
        $suffix = in_array($method, [0, 1], true) ? ' ماهانه' : (in_array($method, [3, 4], true) ? ' سالانه' : '');
        return [
            'id' => (int) $row->id, 'title' => (string) ($s['update']['account_plan_name'] ?? $s['title']), 'duration_days' => (int) $s['duration_days'],
            'traffic_gb' => $s['update']['transfer_enable'] / 1073741824,
            'traffic_label' => number_format($s['update']['transfer_enable'] / 1073741824, 0).' گیگابایت'.$suffix,
            'activation_label' => $row->trigger_on_quota ? 'پس از پایان ترافیک یا اعتبار طرح فعلی، طرح رزرو فعال خواهد شد.' : 'پس از پایان اعتبار طرح فعلی، طرح رزرو فعال خواهد شد.',
            'state' => $row->state,
            'can_refund' => true, 'is_bot_order' => (bool) $row->bot_order_id,
        ];
    }

    /** The caller holds the account lock, including for pending payment checks. */
    public function assertAvailable(object $user, ?int $exceptOrder = null): void
    {
        if ($r = $this->current((int) $user->id)) {
            throw new \DomainException('برای این اکانت یک طرح رزرو ثبت شده است. 📦 طرح رزرو: '.$r['title']);
        }
        if (!Schema::hasTable('user_bot_orders')) return;
        $pending = DB::table('user_bot_orders')->where('account_email', $user->email)
            ->whereIn('order_type', ['renew_account', 'buy_traffic'])
            ->where(fn ($q) => $q->where('order_type', 'renew_account')->orWhere('plan_key', 'volume_gig')->orWhere('plan_key', 'like', '%_volume_gig'))
            ->when($exceptOrder, fn ($q) => $q->where('id', '<>', $exceptOrder))
            ->where(function ($q) {
                $q->whereIn('status', ['pending', 'card_processing', 'card_retry_pending', 'crypto_processing', 'crypto_retry_pending', 'crypto_paid', 'paid'])
                    ->orWhere(fn ($p) => $p->where('status', 'card_waiting_payment')->where('card_sms_expires_at', '>', now()->subMinutes(12)))
                    ->orWhere(fn ($p) => $p->whereIn('status', ['crypto_pending', 'crypto_checking', 'crypto_waiting_payment', 'crypto_waiting', 'crypto_confirming'])->where('crypto_expires_at', '>', now()));
            })->exists();
        if ($pending) throw new \DomainException('یک سفارش تمدید یا خرید طرح برای این اکانت در انتظار تعیین تکلیف است.');
    }

    public function snapshot(object $owner, int $groupId, string $key, ?int $volumeGb = null): array
    {
        $group = DB::table('panel_tariff_groups')->where('id', $groupId)->where('enable', 1)->first();
        $pp = DB::table('panel_plan')->where('tariff_group_id', $groupId)->where('plan_key', $key)->where('enable', 1)->first();
        $plan = $pp ? DB::table('v2_plan')->find($pp->v2_plan_id) : null;
        if (!$group || !$pp || !$plan) throw new \DomainException('طرح تمدید فعالی وجود ندارد؛ از گزینه خرید اکانت استفاده کنید.');
        $volume = $key === 'volume_gig';
        if (($group->group_type === 'volume') !== $volume) throw new \DomainException('نوع طرح انتخاب‌شده معتبر نیست.');
        if (!app(PartnerTariffAccessService::class)->canSeeTariffGroup($owner, $groupId)
            || !app(PartnerTariffAccessService::class)->canCreateType($owner, $volume ? 'volume' : 'time')) {
            throw new \DomainException('این طرح برای شما فعال نیست.');
        }
        if ($volume && $volumeGb < VolumeTrafficPolicy::minimum($groupId)) throw new \DomainException(VolumeTrafficPolicy::message(VolumeTrafficPolicy::minimum($groupId)));
        $days = $volume ? 30 : (['test' => 1, '1m' => 30, '3m' => 90, '6m' => 180, '12m' => 365][$key] ?? 0);
        if (!$days) throw new \DomainException('مدت اعتبار طرح نامعتبر است.');
        $method = $volume ? 2 : (int) ($plan->reset_traffic_method ?? RuntimeSetting::get('reset_traffic_method', 2));
        $quota = $volume ? $volumeGb * 1073741824 : (int) preg_replace('/\D+/', '', (string) $plan->transfer_enable) * 1073741824;
        $duration = ['test' => 'آزمایشی', '1m' => 'یک‌ماهه', '3m' => 'سه‌ماهه', '6m' => 'شش‌ماهه', '12m' => 'یک‌ساله', 'volume_gig' => 'حجمی'][$key];
        $unitPrice = (int) ($owner->role ?? 2) === 1 ? '0' : app(PanelPricingService::class)->quoteUnitPrice((int) $owner->id, $key, $groupId);
        if ($unitPrice === null) throw new \DomainException('قیمت طرح مشخص نشده است.');
        return ['title' => $duration.' '.$group->name, 'duration_days' => $days, 'renewal_mode' => (int) RuntimeSetting::get('renew_order_event_id', self::RESERVE_KEEP),
            'unit_price' => $unitPrice, 'quantity' => $volume ? $volumeGb : 1,
            'update' => [
                'plan_id' => (int) $plan->id, 'group_id' => $plan->group_id,
                'transfer_enable' => $quota, 'speed_limit' => $plan->speed_limit,
                'device_limit' => app(TariffConnectionPolicy::class)->resolve((int) $plan->id, $groupId, $key),
                'account_plan_name' => (string) $group->name, 'account_group_type' => $volume ? 'volume' : 'time',
                'account_tariff_group_id' => $groupId, 'account_plan_key' => $key,
                'account_reset_traffic_method' => $method,
            ]];
    }

    public function assertCompatible(object $user, array $snapshot): void
    {
        if ($this->ended($user)) return;
        $mode = (int) ($snapshot['renewal_mode'] ?? RuntimeSetting::get('renew_order_event_id', self::RESERVE_KEEP));
        // A deferred purchase changes reset policy only when the frozen plan activates.
        if (!$this->periodic($user) || in_array($mode, [self::RESERVE_KEEP, self::RESERVE_RESET], true)) return;
        $nextPeriodic = in_array((int) $snapshot['update']['account_reset_traffic_method'], [0, 1, 3, 4], true);
        if ($this->periodic($user) !== $nextPeriodic) throw new \DomainException('در تمدید فوری، فقط طرح با نوع ریست یکسان قابل انتخاب است؛ برای تغییر نوع ریست از حالت رزرو استفاده کنید.');
    }

    public function ensureGenerationSupport(object $user): void
    {
        if (!Schema::hasTable('v2_server')) return;
        $nodes = DB::table('v2_server')->where('enabled', 1)->where(fn ($q) => $q->whereJsonContains('group_ids', (int) ($user->group_id ?? 0))->orWhereJsonContains('group_ids', (string) ($user->group_id ?? '0')))->get();
        foreach ($nodes as $node) {
            if ($node->parent_id) { $node = DB::table('v2_server')->find($node->parent_id); if (!$node || !$node->enabled) continue; }
            $capabilities = (array) \Illuminate\Support\Facades\Cache::get('speedbox:node:'.$node->id.':capabilities', []);
            if ($node->machine_id && Schema::hasTable('v2_server_machine')) {
                $status = DB::table('v2_server_machine')->where('id', $node->machine_id)->value('load_status');
                $capabilities = array_merge($capabilities, (array) data_get(json_decode($status ?? '{}', true), 'capabilities', []));
            }
            if (!in_array('subscription-generation-v1', $capabilities, true)) {
                throw new \DomainException('برای تمدید این اکانت، نرم‌افزار سرورها باید به‌روزرسانی شود؛ با پشتیبانی تماس بگیرید.');
            }
        }
    }

    /** Purchase and activation are part of the caller's wallet/account transaction. */
    public function purchase(object $user, object $owner, array $s, int $transactionId, ?int $botOrderId = null): array
    {
        $this->assertAvailable($user, $botOrderId);
        $this->assertCompatible($user, $s);
        $ended = $this->ended($user);
        $mode = (int) ($s['renewal_mode'] ?? RuntimeSetting::get('renew_order_event_id', self::RESERVE_KEEP));
        $defer = !$ended && (!$this->periodic($user) || in_array($mode, [self::RESERVE_KEEP, self::RESERVE_RESET], true));
        $s['current_usage_reset'] = $defer && $this->periodic($user) && $mode === self::RESERVE_RESET;
        $id = DB::table('speedbox_subscription_reservations')->insertGetId([
            'user_id' => $user->id, 'reserved_user_id' => $defer ? $user->id : null,
            'transaction_id' => $transactionId, 'bot_order_id' => $botOrderId,
            'panel_user_id' => $owner->id, 'plan_id' => $s['update']['plan_id'],
            'tariff_group_id' => $s['update']['account_tariff_group_id'], 'state' => $defer ? 'reserved' : 'activating',
            'trigger_on_quota' => !$this->periodic($user), 'snapshot' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($defer) {
            // Later sale-plan edits must not change the reserved transition's current policy.
            DB::table('v2_user')->where('id', $user->id)->update(['account_reset_traffic_method' => $this->resetMethod($user)]);
            if ($this->periodic($user) && $mode === self::RESERVE_RESET) {
                $update = $this->resetUpdate($user);
                DB::table('v2_user')->where('id', $user->id)->update($update);
                app(TrafficResetHistory::class)->renewal($user, $update);
            }
        } else {
            $base = $ended ? time() : max(time(), (int) ($user->expired_at ?? 0));
            $this->apply($user, DB::table('speedbox_subscription_reservations')->find($id), $base, $ended || $mode === self::INSTANT_RESET, false);
        }
        return ['reserved' => $defer, 'fresh_cycle' => !$defer && $ended, 'reservation_id' => $id,
            'reservation' => $defer ? $this->current((int) $user->id) : null,
            'expired_at' => DB::table('v2_user')->where('id', $user->id)->value('expired_at')];
    }

    private function resetUpdate(object $user): array
    {
        return ['u' => 0, 'd' => 0, 'last_reset_at' => time(), 'reset_count' => (int) ($user->reset_count ?? 0) + 1,
            'traffic_warning_version' => (int) ($user->traffic_warning_version ?? 0) + 1,
            'subscription_generation' => (int) ($user->subscription_generation ?? 0) + 1, 'updated_at' => time()];
    }

    private function apply(object $user, object $row, int $base, bool $reset, bool $automatic): void
    {
        $s = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->ensureGenerationSupport((object) array_merge((array) $user, $s['update']));
        if (!DB::table('v2_plan')->where('id', $row->plan_id)->exists()) throw new \DomainException('طرح فنی رزرو حذف شده است؛ با مدیر تماس بگیرید.');
        $update = $s['update'] + ['expired_at' => $base + $s['duration_days'] * 86400, 'updated_at' => time()];
        if ($reset) $update = array_merge($update, $this->resetUpdate($user));
        $nextUser = (object) array_merge((array) $user, $update);
        $update['next_reset_at'] = app(SubscriptionTrafficReset::class)->next($nextUser, (object) ['reset_traffic_method' => $s['update']['account_reset_traffic_method']]);
        DB::table('v2_user')->where('id', $user->id)->update($update);
        if ($reset) app(TrafficResetHistory::class)->record($user, $update, 'renewal', $automatic ? 'reservation' : 'order', ['reason' => 'فعال‌سازی طرح', 'reservation_id' => $row->id]);
        DB::table('speedbox_subscription_reservations')->where('id', $row->id)->update([
            'state' => 'activated', 'reserved_user_id' => null, 'activated_at' => now(),
            'notify_after' => $automatic ? now() : null, 'notified_at' => $automatic ? null : now(), 'updated_at' => now(), 'last_error' => null,
        ]);
        DB::table('speedbox_account_operations')->insert([
            'user_id' => $user->id, 'account_email' => $user->email,
            'operator_id' => $automatic ? null : (auth()->id() ?: $row->panel_user_id),
            'operator_email' => $automatic ? null : auth()->user()?->email,
            'action' => 'reservation_activate',
            'metadata' => json_encode(['reservation_id' => $row->id, 'title' => $s['title'], 'automatic' => $automatic, 'operator_role' => $automatic ? 'system' : (auth()->user()?->role ?? null), 'expired_at' => $update['expired_at']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
        $transaction = DB::table('panel_transactions')->find($row->transaction_id);
        if ($automatic && $transaction) {
            $meta = json_decode($transaction->meta ?? '{}', true) ?: [];
            $meta['reservation_pending'] = false;
            $meta['reservation_activated_at'] = time();
            DB::table('panel_transactions')->where('id', $transaction->id)->update(['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
        // During immediate purchase the caller finalizes afterwards. Automatic activation owns cycle creation.
        if ($automatic && $transaction) app(SubscriptionOrderPolicy::class)->recordPurchase($transaction, ['user_ids' => [$user->id], 'force_new_cycle' => true, 'reservation_activation' => true]);
        if ($automatic && Schema::hasTable('user_bot_sessions')) {
            $sessions = DB::table('user_bot_sessions')->where('account_email', $user->email)->where('is_logged_in', 1)->pluck('id');
            if ($row->bot_order_id && Schema::hasTable('user_bot_orders')) {
                $originalSession = DB::table('user_bot_orders')->where('id', $row->bot_order_id)->value('session_id');
                if ($originalSession) $sessions->push($originalSession);
            }
            $text = '✅ طرح فعلی اکانت شما به پایان رسید و طرح رزرو فعال شد.'."\n\n📦 طرح: ".e($s['title'])
                ."\n📅 تاریخ انقضا: ".\App\Support\JalaliDateFormatter::forTelegram($update['expired_at']);
            foreach ($sessions->unique() as $session) DB::table('speedbox_reservation_notifications')->insertOrIgnore([
                'reservation_id' => $row->id, 'session_id' => $session, 'message' => $text, 'retry_at' => now(),
            ]);
        }
    }

    /** Safe to call within a consumption transaction; always locks account before reservation. */
    public function activateIfDue(int $userId): bool
    {
        return DB::transaction(function () use ($userId) {
            $user = DB::table('v2_user')->where('id', $userId)->lockForUpdate()->first();
            if (!$user || $user->banned) return false;
            $row = DB::table('speedbox_subscription_reservations')->where('reserved_user_id', $userId)->lockForUpdate()->first();
            if (!$row || !$this->ended($user, null, (bool) $row->trigger_on_quota)) return false;
            $this->apply($user, $row, time(), true, true);
            return true;
        });
    }

    public function tick(): void
    {
        DB::table('speedbox_subscription_reservations')->where('state', 'reserved')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                try { $this->activateIfDue((int) $row->user_id); }
                catch (\Throwable $e) {
                    report($e);
                    DB::table('speedbox_subscription_reservations')->where('id', $row->id)->update(['last_error' => mb_substr($e->getMessage(), 0, 1000)]);
                }
            }
        });
    }

    public function cancel(int $userId, int $reservationId, object $actor, bool $refund): void
    {
        if (!in_array((int) ($actor->role ?? 0), [1, 2], true)) throw new \DomainException('اجازه لغو این رزرو را ندارید.');
        DB::transaction(function () use ($userId, $reservationId, $actor, $refund) {
            $original = DB::table('speedbox_subscription_reservations')->where('id', $reservationId)->where('user_id', $userId)->first();
            if (!$original) throw new \DomainException('طرح رزرو یافت نشد.');
            // Match the purchase's wallet -> account lock order.
            $payer = DB::table('panel_users')->where('id', $original->panel_user_id)->lockForUpdate()->first();
            $user = DB::table('v2_user')->where('id', $userId)->lockForUpdate()->first();
            $row = DB::table('speedbox_subscription_reservations')->where('id', $reservationId)->lockForUpdate()->first();
            if (!$user || ((int) $actor->role !== 1 && !PanelAccountOwnership::matches((string) $user->email, (string) ($actor->code ?? '')))) {
                throw new \DomainException('اجازه لغو رزرو این اکانت را ندارید.');
            }
            if ($row->state === 'cancelled') return;
            if (!$user || $row->state !== 'reserved') throw new \DomainException('این طرح دیگر در وضعیت رزرو نیست.');
            $s = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $transaction = DB::table('panel_transactions')->find($row->transaction_id);
            if ($refund && $transaction && bccomp((string) $transaction->amount, '0', 0) > 0) {
                if (!$payer) throw new \DomainException('کیف پول پرداخت‌کننده یافت نشد.');
                $amount = (string) $transaction->amount;
                $after = bcadd((string) $payer->credit, $amount, 0);
                DB::table('panel_users')->where('id', $payer->id)->update(['credit' => $after]);
                $logger = app(TransactionLogger::class);
                $refundId = $logger->start(['panel_user_id' => $payer->id, 'type' => 'reservation_refund', 'direction' => 'credit',
                    'amount' => $amount, 'balance_before' => $payer->credit, 'balance_after' => $after,
                    'performed_by_id' => $actor->id, 'performed_by_role' => $actor->role,
                    'meta' => ['reservation_id' => $row->id, 'original_transaction_id' => $transaction->id, 'reservation_cancelled' => true, 'source' => (int) $actor->role === 1 ? 'admin_panel' : 'panel']]);
                $logger->finalize($refundId, ['reference_type' => 'v2_user', 'reference_id' => $userId, 'user_ids' => [$userId]]);
            }
            DB::table('speedbox_subscription_reservations')->where('id', $row->id)->update([
                'state' => 'cancelled', 'reserved_user_id' => null, 'updated_at' => now(),
            ]);
            if ($transaction) {
                $meta = json_decode($transaction->meta ?? '{}', true) ?: [];
                $meta['reservation_pending'] = false; $meta['reservation_cancelled'] = true;
                $meta['reservation_cancelled_at'] = now()->toDateTimeString();
                $meta['reservation_cancelled_by_role'] = (int) $actor->role;
                $meta['reservation_cancelled_by_id'] = (int) $actor->id;
                DB::table('panel_transactions')->where('id', $transaction->id)->update(['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            }
            DB::table('speedbox_account_operations')->insert(['user_id' => $userId, 'account_email' => $user->email,
                'operator_id' => $actor->id, 'operator_email' => $actor->email ?? null, 'action' => 'reservation_cancel',
                'metadata' => json_encode(['reservation_id' => $row->id, 'refund' => $refund, 'operator_role' => (int) $actor->role, 'title' => $s['title']], JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
            if ($row->bot_order_id && Schema::hasTable('user_bot_orders')) {
                $session = DB::table('user_bot_orders')->where('id', $row->bot_order_id)->value('session_id');
                if ($session) DB::table('speedbox_reservation_notifications')->insertOrIgnore([
                    'reservation_id' => $row->id, 'session_id' => $session, 'retry_at' => now(),
                    'message' => '⚠️ طرح رزرو «'.e($s['title']).'» توسط پشتیبانی لغو شد. برای بررسی هزینه پرداختی با پشتیبانی تماس بگیرید.',
                ]);
            }
        }, 3);
    }
}

