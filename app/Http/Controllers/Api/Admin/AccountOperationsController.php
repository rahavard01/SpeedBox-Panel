<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AccountIdentityOperations;
use App\Services\AccountOnlineStatus;
use App\Services\SubscriptionTrafficReset;
use App\Services\TariffConnectionPolicy;
use App\Services\TrafficResetHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountOperationsController extends Controller
{
    private const FIELDS = AccountIdentityOperations::FIELDS;

    public function online(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer', 'min:1']]);
        $users = DB::table('v2_user')->whereIn('id', $data['ids'])->get(['id', 'device_limit']);
        $counts = app(AccountOnlineStatus::class)->counts($users->pluck('id')->all());
        return response()->json(['data' => $users->map(fn ($user) => [
            'id' => $user->id, 'online_count' => $counts[$user->id] ?? 0, 'device_limit' => $user->device_limit,
        ])])->header('Cache-Control', 'no-store');
    }

    public function show(int $id)
    {
        $user = $this->account($id);
        $current = $this->currentTariff($user);
        $plans = DB::table('panel_plan as pp')
            ->join('panel_tariff_groups as tg', 'tg.id', '=', 'pp.tariff_group_id')
            ->join('v2_plan as vp', 'vp.id', '=', 'pp.v2_plan_id')
            ->where(function ($query) use ($current) {
                $query->where('pp.enable', 1);
                if ($current) $query->orWhere('pp.id', $current->id);
            })->where('vp.service_type',$user->service_type??'v2ray')->when(($user->service_type??'v2ray')==='wireguard',fn($q)=>$q->where('vp.wireguard_instance_id',$user->wireguard_instance_id))
                ->when(($user->service_type ?? 'v2ray') === 'anyconnect', fn ($q) => $q->where('vp.anyconnect_instance_id', $user->anyconnect_instance_id))->orderBy('tg.name')->orderBy('pp.id')
            ->get(['pp.id', 'pp.plan_key', 'pp.enable', 'pp.v2_plan_id', 'tg.name', 'tg.group_type', 'vp.speed_limit', 'vp.device_limit as default_device_limit', 'pp.device_limit as tariff_device_limit']);
        return response()->json(['data' => AccountIdentityOperations::publicAccount($user), 'panel_plan_id' => $current?->id,
            'plans' => $plans])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'email' => ['sometimes', 'required', 'email', 'max:64', Rule::unique('v2_user', 'email')->ignore($id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:128'],
            'u' => ['sometimes', 'required', 'integer', 'min:0', 'max:9007199254740991'],
            'd' => ['sometimes', 'required', 'integer', 'min:-9007199254740991', 'max:9007199254740991'],
            'transfer_enable' => ['sometimes', 'required', 'integer', 'min:0', 'max:9007199254740991'],
            'expired_at' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4102444800'],
            'panel_plan_id' => ['sometimes', 'required', 'integer', 'exists:panel_plan,id'],
            'banned' => ['sometimes', 'required', 'boolean'],
            'speed_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'device_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $result = DB::transaction(function () use ($data, $id, $request) {
            $user = $this->account($id, true);
            $update = array_intersect_key($data, array_flip(self::FIELDS));
            if (app(\App\Services\SubscriptionReservationService::class)->current($id)) {
                abort_if(isset($data['panel_plan_id']), 422, 'این اکانت طرح رزرو دارد؛ پیش از تغییر نوع طرح، رزرو را تعیین تکلیف کنید.');
            }
            if (isset($data['email'])) {
                $email = strtolower($data['email']);
                [$local, $domain] = explode('@', $email, 2);
                [$oldLocal, $oldDomain] = explode('@', $user->email, 2);
                // The domain (and, for legacy accounts, the local part) identifies the owner.
                $legacyOwner = DB::table('panel_users')->whereRaw('LOWER(code) = ?', [strtolower($oldLocal)])->exists();
                if (strtolower($domain) !== strtolower($oldDomain) || ($legacyOwner && strtolower($local) !== strtolower($oldLocal))) {
                    throw ValidationException::withMessages(['email' => 'کد مالک اکانت در ایمیل باید حفظ شود؛ فقط بخش نام کاربری را ویرایش کنید.']);
                }
                abort_if($email !== strtolower($user->email) && AccountIdentityOperations::hasOpenBotOrders($user), 422, 'این اکانت سفارش در جریان در ربات دارد؛ ابتدا آن سفارش را تعیین تکلیف کنید.');
                $update['email'] = $email;
            }
            if (!empty($data['password'])) {
                $update['password'] = Hash::make($data['password']);
                $update['password_algo'] = null;
                $update['password_salt'] = null;
            }
            if (isset($data['panel_plan_id'])) {
                $tariff = DB::table('panel_plan')->where('id', $data['panel_plan_id'])->lockForUpdate()->first();
                $current = $this->currentTariff($user);
                abort_if(!$tariff || (!$tariff->enable && (int) ($current?->id ?? 0) !== (int) $tariff->id), 422, 'پلن انتخابی فعال نیست.');
                $plan = DB::table('v2_plan')->where('id', $tariff->v2_plan_id)->first();
                \App\Services\SubscriptionServiceType::same($user,(int)$tariff->v2_plan_id);
                $group = DB::table('panel_tariff_groups')->where('id', $tariff->tariff_group_id)->first();
                abort_unless($plan && $group, 422, 'تعرفه به پلن معتبر متصل نیست.');
                $update += [
                    'plan_id' => $plan->id, 'group_id' => $plan->group_id,
                    'account_plan_name' => $group->name,
                    'account_group_type' => $tariff->plan_key === 'volume_gig' ? 'volume' : ($group->group_type ?? 'time'),
                    'account_tariff_group_id' => $group->id, 'account_plan_key' => $tariff->plan_key,
                    'speed_limit' => $plan->speed_limit,
                    'account_reset_traffic_method' => $tariff->plan_key === 'volume_gig' ? 2 : (int) ($plan->reset_traffic_method ?? \App\Services\RuntimeSetting::get('reset_traffic_method', 2)),
                    'device_limit' => app(TariffConnectionPolicy::class)->resolve((int) $plan->id, (int) $group->id, $tariff->plan_key),
                ];
            }
            if (array_key_exists('expired_at', $update) && $update['expired_at'] !== null) $update['expired_at'] = (int) $update['expired_at'];
            if (array_key_exists('banned', $update)) $update['banned'] = (int) $update['banned'];
            if (isset($data['panel_plan_id']) || array_key_exists('expired_at', $update)) {
                $update['next_reset_at'] = app(SubscriptionTrafficReset::class)->next((object) array_merge((array) $user, $update));
            }
            if (array_intersect(['u', 'd', 'transfer_enable', 'expired_at', 'plan_id'], array_keys($update))) {
                $update['traffic_warning_version'] = (int) ($user->traffic_warning_version ?? 0) + 1;
            }
            if (!Schema::hasColumn('v2_user', 'account_reset_traffic_method')) unset($update['account_reset_traffic_method']);
            if(($user->service_type??'v2ray')==='wireguard') {
                abort_if(isset($update['device_limit']) && ((int)$update['device_limit']<1 || (int)$update['device_limit']>16),422,'تعداد پروفایل وایرگارد بین ۱ و ۱۶ باشد.');
                abort_if((int)($update['speed_limit']??0)>10000,422,'سقف سرعت وایرگارد ۱۰۰۰۰ مگابیت است.');
            }
            $update['updated_at'] = time();
            DB::table('v2_user')->where('id', $id)->update($update);
            if(($user->service_type??'v2ray')==='wireguard')\App\Jobs\SyncWireGuard::dispatch((int)$user->wireguard_instance_id)->afterCommit();
            \App\Services\AnyConnectService::queue($user);
            $after = $this->account($id);
            $changes = [];
            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $update) && $field !== 'updated_at') $changes[$field] = ['before' => $user->$field ?? null, 'after' => $after->$field ?? null];
            }
            AccountIdentityOperations::audit($user, $request->user(), 'edit', ['changes' => $changes, 'password_changed' => !empty($data['password'])]);
            AccountIdentityOperations::syncBotSessions($user, $after);
            return AccountIdentityOperations::publicAccount($after);
        }, 3);
        return response()->json(['message' => 'اطلاعات اکانت ذخیره شد.', 'data' => $result]);
    }

    public function resetSecret(Request $request, int $id)
    {
        $this->confirm($request);
        $result = DB::transaction(function () use ($id, $request) {
            $user = $this->account($id, true);
            $after = AccountIdentityOperations::rotate($user, $request->user());
            return AccountIdentityOperations::publicAccount($after);
        }, 3);
        return response()->json(['message' => 'UUID و لینک بازنشانی شد؛ لینک جدید را از مشاهده دریافت کنید.', 'data' => $result]);
    }

    public function resetHistory(Request $request, int $id)
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $user = $this->account($id);
        $logs = DB::table('v2_traffic_reset_logs')->where('user_id', $id)->orderByDesc('id')->paginate(10);
        $items = collect($logs->items())->map(function ($log) {
            $log->metadata = json_decode($log->metadata ?? '{}', true) ?: [];
            $log->reason = is_array($log->metadata) && is_string($log->metadata['reason'] ?? null) ? $log->metadata['reason'] : '';
            return $log;
        });
        return response()->json(['data' => $items, 'user' => AccountIdentityOperations::publicAccount($user),
            'can_reset' => $this->canReset($user), 'meta' => [
                'current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total(),
            ]])->header('Cache-Control', 'no-store');
    }

    public function resetTraffic(Request $request, int $id)
    {
        $this->confirm($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000'], 'acknowledge_extra' => ['nullable', 'boolean']]);
        $result = DB::transaction(function () use ($id, $data, $request) {
            $user = $this->account($id, true);
            abort_unless($this->canReset($user), 422, 'بازنشانی دستی برای اکانت فعال، دارای پلن و منقضی‌نشده امکان‌پذیر است.');
            abort_if((int) $user->d < 0 && !($data['acknowledge_extra'] ?? false), 422, 'بازنشانی مصرف، اعتبار ترافیک اضافه را هم صفر می‌کند؛ ابتدا اثر آن را تأیید کنید.');
            $update = [
                'u' => 0, 'd' => 0, 'last_reset_at' => time(), 'reset_count' => (int) ($user->reset_count ?? 0) + 1,
                'next_reset_at' => app(SubscriptionTrafficReset::class)->next($user),
                'traffic_warning_version' => (int) ($user->traffic_warning_version ?? 0) + 1, 'updated_at' => time(),
            ];
            if ((int) ($user->subscription_generation ?? 0) > 0) $update['subscription_generation'] = (int) ($user->subscription_generation ?? 0) + 1;
            DB::table('v2_user')->where('id', $id)->update($update);
            \App\Services\AnyConnectService::queue($user);
            app(TrafficResetHistory::class)->record($user, $update, 'manual', 'manual', [
                'reason' => $data['reason'] ?? null, 'admin_id' => $request->user()?->id,
                'operator_email' => $request->user()?->email,
            ]);
            AccountIdentityOperations::audit($user, $request->user(), 'reset_traffic', ['reason' => $data['reason'] ?? null]);
            return AccountIdentityOperations::publicAccount($this->account($id));
        }, 3);
        return response()->json(['message' => 'مصرف ترافیک بازنشانی و در تاریخچه ثبت شد.', 'data' => $result]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->confirm($request);
        DB::transaction(function () use ($id, $request) {
            $user = $this->account($id, true);
            abort_if(app(\App\Services\SubscriptionReservationService::class)->current($id), 422, 'این اکانت طرح رزرو دارد؛ ابتدا رزرو را تعیین تکلیف کنید.');
            abort_if(AccountIdentityOperations::hasOpenBotOrders($user), 422, 'این اکانت سفارش در جریان در ربات دارد؛ ابتدا آن سفارش را تعیین تکلیف کنید.');
            AccountIdentityOperations::audit($user, $request->user(), 'delete', ['account' => AccountIdentityOperations::publicAccount($user)]);
            AccountIdentityOperations::syncBotSessions($user, null);
            DB::table('speedbox_connection_leases')->where('user_id', $id)->delete();
            DB::table('v2_user')->where('id', $id)->delete();
            // Financial transactions, usage reports and audit/reset history are retained.
        }, 3);
        return response()->json(['message' => 'اکانت حذف شد؛ سوابق مالی حفظ شدند.']);
    }

    private function account(int $id, bool $lock = false): object
    {
        $query = DB::table('v2_user')->where('id', $id);
        if ($lock) $query->lockForUpdate();
        $user = $query->first();
        abort_unless($user, 404, 'اکانت یافت نشد.');
        return $user;
    }

    private function currentTariff(object $user): ?object
    {
        if (!$user->plan_id) return null;
        $query = DB::table('panel_plan')->where('v2_plan_id', $user->plan_id);
        if ($user->account_tariff_group_id) $query->where('tariff_group_id', $user->account_tariff_group_id);
        if ($user->account_plan_key) $query->where('plan_key', $user->account_plan_key);
        return $query->orderBy('id')->first();
    }

    private function canReset(object $user): bool
    {
        return !$user->banned && \App\Services\SubscriptionValidity::unexpired($user->expired_at)
            && $user->plan_id && DB::table('v2_plan')->where('id', $user->plan_id)->exists();
    }

    private function confirm(Request $request): void
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
    }

}
