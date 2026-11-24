<?php

namespace App\Services;

use App\Jobs\SyncAnyConnect;
use App\Models\{AnyConnectAccount, AnyConnectInstance, VpnOperation};
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AnyConnectService
{
    public function __construct(private readonly AnyConnectRemote $remote)
    {
    }

    public function provision(object $user): void
    {
        $password = Str::random(4);
        AnyConnectAccount::create([
            'user_id' => $user->id, 'instance_id' => $user->anyconnect_instance_id,
            'password' => $password, 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);
        self::queue($user);
    }

    public function rotate(object $user, string $password): void
    {
        AnyConnectAccount::whereKey($user->id)->firstOrFail()->update([
            'password' => $password, 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);
        self::queue($user);
    }

    public static function queue(object $user): void
    {
        if (SubscriptionServiceType::of($user) === 'anyconnect' && $user->anyconnect_instance_id) {
            SyncAnyConnect::dispatch((int) $user->anyconnect_instance_id)->afterCommit();
        }
    }

    public function credentials(object $user): array
    {
        $account = AnyConnectAccount::whereKey($user->id)->where('instance_id', $user->anyconnect_instance_id)->firstOrFail();
        $instance = AnyConnectInstance::findOrFail($account->instance_id);
        return [
            'address' => $instance->address(),
            'username' => $this->loginAliases((int) $account->instance_id)[(int) $user->id],
            'password' => $account->password,
        ];
    }

    public function tick(): void
    {
        AnyConnectInstance::query()->where(fn ($query) => $query->where('enabled', true)
            ->orWhereIn('status', ['stopping', 'deleting']))->orderBy('id')->pluck('id')
            ->each(fn ($id) => SyncAnyConnect::dispatch((int) $id));
    }

    public function assertUnused(int $id): void
    {
        foreach (['v2_plan', 'v2_user'] as $table) {
            abort_if(DB::table($table)->where('anyconnect_instance_id', $id)->exists(), 422, 'این سرور به طرح یا اکانت متصل است.');
        }
    }

    public function sync(int $id): void
    {
        $lock = Cache::lock('anyconnect:sync:'.$id, 1230);
        if (!$lock->get()) return;

        $instance = null;
        try {
            $instance = AnyConnectInstance::with(['server', 'ingress'])->find($id);
            if (!$instance || (!$instance->enabled && !in_array($instance->status, ['stopping', 'deleting'], true))) return;
            $operation = VpnOperation::start('anyconnect', $id);
            if (!$instance->server) throw new RuntimeException('سرور AnyConnect یافت نشد.');

            $policy = $this->policy($instance);
            if (!$instance->enabled) {
                $delete = $instance->status === 'deleting';
                if ($delete) $this->assertUnused($id);
                $operation?->recordProgress(25, $delete ? 'حذف سرویس از سرور خروجی.' : 'توقف سرویس روی سرور خروجی.');
                $this->remote->request($instance->server, $delete ? 'remove' : 'stop', $policy);
                $operation?->recordProgress(60, 'اعمال تغییر روی مسیر اتصال.');
                $this->link($instance, $delete ? 'remove_link' : 'stop_link');
                if ($delete) {
                    DB::transaction(function () use ($instance) {
                        $row = AnyConnectInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
                        $this->assertUnused($row->id);
                        AnyConnectAccount::where('instance_id', $row->id)->delete();
                        $row->delete();
                    });
                } else {
                    $instance->update(['status' => 'stopped', 'last_error' => null]);
                }
                $operation?->recordProgress(100, 'عملیات با موفقیت تکمیل شد.', 'succeeded');
                return;
            }

            $install = $instance->status !== 'ready';
            $operation?->recordProgress(15, 'بررسی و آماده‌سازی گواهی دامنه.');
            $certificateError = app(AnyConnectCertificate::class)->prepare($instance, $policy);
            if ($certificateError && $install) throw new RuntimeException($certificateError);
            if ($install) {
                $operation?->recordProgress(35, 'آماده‌سازی مسیر اتصال.');
                $this->link($instance, 'install_link');
                $operation?->recordProgress(50, 'نصب AnyConnect روی سرور خروجی.');
                $this->remote->request($instance->server, 'install', $policy, $this->accountPolicies($id));
            }
            $operation?->recordProgress(65, 'دریافت وضعیت و اطلاعات مصرف اکانت‌ها.');
            $report = $this->remote->request($instance->server, 'report', $policy);
            $this->ingest($instance, $report['accounts'] ?? []);
            $operation?->recordProgress(80, 'همگام‌سازی تنظیمات و اکانت‌ها.');
            $this->remote->request($instance->server, 'sync', $policy, $this->accountPolicies($id));
            $operation?->recordProgress(90, 'بررسی مسیر اتصال.');
            $this->link($instance, 'check_link');
            AnyConnectInstance::whereKey($id)->where('enabled', true)
                ->update(['status' => 'ready', 'last_synced_at' => now(), 'last_error' => $certificateError]);
            $operation?->recordProgress(100, $certificateError
                ? 'همگام‌سازی تکمیل شد. هشدار گواهی: '.mb_substr($certificateError, 0, 800)
                : 'عملیات با موفقیت تکمیل شد.', 'succeeded');
        } catch (Throwable $error) {
            if (isset($operation)) $operation->fail($error->getMessage());
            if ($instance) {
                $changes = ['last_error' => mb_substr($error->getMessage(), 0, 800)];
                if ($instance->enabled) $changes['status'] = 'failed';
                AnyConnectInstance::whereKey($id)->where('enabled', $instance->enabled)->update($changes);
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }

    private function policy(AnyConnectInstance $instance): array
    {
        return [
            'id' => $instance->id, 'owner' => $instance->owner_id,
            'endpoint' => $instance->endpoint_host, 'listen_port' => (int) $instance->listen_port,
            'endpoint_port' => (int) $instance->endpoint_port, 'dns' => $instance->dns,
            'network' => $instance->network, 'mtu' => (int) $instance->mtu,
            'tunneled' => (bool) $instance->ingress_server_id, 'link_network' => $instance->link_network,
            'radius_secret' => $instance->radius_secret,
        ];
    }

    private function link(AnyConnectInstance $instance, string $action): void
    {
        if (!$instance->ingress_server_id) return;
        if (!$instance->ingress || !$instance->link_keys) throw new RuntimeException('مسیر تونل AnyConnect کامل نیست.');
        $servers = $action === 'check_link' ? ['iran' => $instance->ingress]
            : ['foreign' => $instance->server, 'iran' => $instance->ingress];
        foreach ($servers as $side => $server) {
            $peer = $side === 'iran' ? 'foreign' : 'iran';
            $policy = $this->policy($instance);
            unset($policy['radius_secret']);
            $this->remote->request($server, $action, $policy + [
                'side' => $side, 'foreign_host' => $instance->server->host,
                'link_port' => (int) $instance->link_port, 'link_private' => $instance->link_keys[$side]['private'],
                'link_public' => $instance->link_keys[$side]['public'], 'link_peer' => $instance->link_keys[$peer]['public'],
            ]);
        }
    }

    public static function displayUsername(string $email): string
    {
        return str_replace('@', '-', preg_replace('/\.com$/i', '', $email));
    }

    private function loginAliases(int $instanceId): array
    {
        $emails = DB::table('v2_user as u')->join('speedbox_anyconnect_accounts as ac', 'ac.user_id', '=', 'u.id')
            ->where('ac.instance_id', $instanceId)->where('u.service_type', 'anyconnect')
            ->orderBy('u.id')->pluck('u.email', 'u.id');
        $aliases = $emails->map(fn ($email) => self::displayUsername($email))->all();
        $counts = array_count_values($aliases);
        $reserved = array_fill_keys($aliases, true);
        foreach ($aliases as $id => $name) {
            if ($counts[$name] === 1) continue;
            $candidate = $name.'-'.$id;
            while (isset($reserved[$candidate])) $candidate .= '-'.$id;
            $aliases[$id] = $candidate;
            $reserved[$candidate] = true;
        }
        return $aliases;
    }

    public function pollOnline(): void
    {
        AnyConnectInstance::where('enabled', true)->where('status', 'ready')->pluck('id')
            ->each(fn ($id) => \App\Jobs\PollAnyConnectOnline::dispatch((int) $id));
    }

    public function refreshOnline(int $id): void
    {
        $lock = Cache::lock('anyconnect:sync:'.$id, 60);
        if (!$lock->get()) return;
        try {
            $instance = AnyConnectInstance::with('server')->whereKey($id)->where('enabled', true)->where('status', 'ready')->first();
            if (!$instance?->server) return;
            $counts = $this->remote->request($instance->server, 'report_online', $this->policy($instance))['online'] ?? null;
            if (!is_array($counts)) throw new RuntimeException('گزارش نشست‌های AnyConnect معتبر نیست.');
            DB::transaction(function () use ($id, $counts) {
                $accounts = AnyConnectAccount::where('instance_id', $id);
                $groups = [];
                foreach ($counts as $userId => $count) {
                    if (is_int($count) && $count > 0) $groups[$count][] = (int) $userId;
                }
                $online = array_merge([], ...array_values($groups));
                (clone $accounts)->where('online', '>', 0)->whereNotIn('user_id', $online)->update(['online' => 0]);
                foreach ($groups as $count => $ids) {
                    (clone $accounts)->whereIn('user_id', $ids)->where('online', '!=', $count)->update(['online' => $count]);
                }
                AnyConnectInstance::whereKey($id)->update(['last_synced_at' => now()]);
            });
        } finally {
            $lock->release();
        }
    }

    private function accountPolicies(int $instanceId): array
    {
        $aliases = $this->loginAliases($instanceId);
        return DB::table('speedbox_anyconnect_accounts as ac')->join('v2_user as u', 'u.id', '=', 'ac.user_id')
            ->where('ac.instance_id', $instanceId)->where('u.service_type', 'anyconnect')
            ->orderBy('ac.user_id')->get(['ac.user_id', 'ac.password_hash', 'ac.uploaded', 'ac.downloaded',
                'u.email', 'u.banned', 'u.expired_at', 'u.u', 'u.d', 'u.transfer_enable', 'u.device_limit', 'u.speed_limit'])
            ->map(fn ($row) => [
                'id' => (int) $row->user_id, 'username' => $row->email, 'login' => $aliases[$row->user_id], 'hash' => $row->password_hash,
                'uploaded' => (int) $row->uploaded, 'downloaded' => (int) $row->downloaded,
                'quota' => (int) $row->transfer_enable, 'used' => (int) $row->u + (int) $row->d,
                'expires' => $row->expired_at === null ? null : (int) $row->expired_at,
                'banned' => (bool) $row->banned, 'connections' => (int) $row->device_limit, 'speed' => (int) $row->speed_limit,
            ])->all();
    }

    public function ingest(AnyConnectInstance $instance, array $report): void
    {
        foreach (AnyConnectAccount::where('instance_id', $instance->id)->orderBy('user_id')->pluck('user_id') as $id) {
            DB::transaction(function () use ($instance, $report, $id) {
                $user = DB::table('v2_user')->where('id', $id)->where('service_type', 'anyconnect')
                    ->where('anyconnect_instance_id', $instance->id)->lockForUpdate()->first();
                if (!$user) return;
                $account = AnyConnectAccount::whereKey($id)->lockForUpdate()->firstOrFail();
                $entry = $report[$id] ?? null;
                if (!$entry) {
                    $account->update(['online' => 0]);
                    return;
                }
                $uploaded = (int) $entry['uploaded'];
                $downloaded = (int) $entry['downloaded'];
                if ($uploaded < $account->uploaded || $downloaded < $account->downloaded) {
                    throw new RuntimeException('شمارندهٔ مصرف AnyConnect کاهش یافته است؛ پایگاه دادهٔ حسابداری سرور را بررسی کنید.');
                }
                $in = $uploaded - (int) $account->uploaded;
                $out = $downloaded - (int) $account->downloaded;
                $account->update(compact('uploaded', 'downloaded') + ['online' => max(0, (int) $entry['online'])]);
                if ($in || $out) {
                    DB::table('v2_user')->where('id', $id)->update([
                        'u' => (int) $user->u + $in, 'd' => (int) $user->d + $out, 't' => time(), 'updated_at' => time(),
                    ]);
                }
                app(SubscriptionReservationService::class)->activateIfDue((int) $id);
            }, 3);
        }
    }
}
