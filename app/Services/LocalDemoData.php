<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Local-only, tracked inserts. Never dispatches jobs or updates existing records. */
final class LocalDemoData
{
    private const MANIFEST = 'speedbox-demo-all-v1.json';
    private const GB = 1073741824;
    // Two-second local preview clip; no external downloads are needed.
    private const VIDEO = 'AAAAIGZ0eXBpc29tAAACAGlzb21pc28yYXZjMW1wNDEAAARUbW9vdgAAAGxtdmhkAAAAAAAAAAAAAAAAAAAD6AAAB9AAAQAAAQAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAA350cmFrAAAAXHRraGQAAAADAAAAAAAAAAAAAAABAAAAAAAAB9AAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAAUAAAAC0AAAAAAAkZWR0cwAAABxlbHN0AAAAAAAAAAEAAAfQAAAIAAABAAAAAAL2bWRpYQAAACBtZGhkAAAAAAAAAAAAAAAAAAAwAAAAYABVxAAAAAAALWhkbHIAAAAAAAAAAHZpZGUAAAAAAAAAAAAAAABWaWRlb0hhbmRsZXIAAAACoW1pbmYAAAAUdm1oZAAAAAEAAAAAAAAAAAAAACRkaW5mAAAAHGRyZWYAAAAAAAAAAQAAAAx1cmwgAAAAAQAAAmFzdGJsAAAAwXN0c2QAAAAAAAAAAQAAALFhdmMxAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAUAAtABIAAAASAAAAAAAAAABFUxhdmM2MC4zMS4xMDIgbGlieDI2NAAAAAAAAAAAAAAAGP//AAAAN2F2Y0MBZAAM/+EAGmdkAAys2UFBn58BEAAAAwAQAAADAYDxQplgAQAGaOvjyyLA/fj4AAAAABBwYXNwAAAAAQAAAAEAAAAUYnRydAAAAAAAADVAAAA1QAAAABhzdHRzAAAAAAAAAAEAAAAYAAAEAAAAABRzdHNzAAAAAAAAAAEAAAABAAAAyGN0dHMAAAAAAAAAFwAAAAEAAAgAAAAAAQAAFAAAAAABAAAIAAAAAAEAAAAAAAAAAQAABAAAAAABAAAUAAAAAAEAAAgAAAAAAQAAAAAAAAABAAAEAAAAAAEAABQAAAAAAQAACAAAAAABAAAAAAAAAAEAAAQAAAAAAQAAFAAAAAABAAAIAAAAAAEAAAAAAAAAAQAABAAAAAABAAAUAAAAAAEAAAgAAAAAAQAAAAAAAAABAAAEAAAAAAEAABAAAAAAAgAABAAAAAAcc3RzYwAAAAAAAAABAAAAAQAAABgAAAABAAAAdHN0c3oAAAAAAAAAAAAAABgAAAvDAAAAKgAAABIAAAAQAAAADQAAABoAAAAPAAAADQAAAA0AAAAXAAAADwAAAA0AAAANAAAAFwAAAA8AAAANAAAADQAAABYAAAAPAAAADQAAAA0AAAAWAAAADwAAAA0AAAAUc3RjbwAAAAAAAAABAAAEhAAAAGJ1ZHRhAAAAWm1ldGEAAAAAAAAAIWhkbHIAAAAAAAAAAG1kaXJhcHBsAAAAAAAAAAAAAAAALWlsc3QAAAAlqXRvbwAAAB1kYXRhAAAAAQAAAABMYXZmNjAuMTYuMTAwAAAACGZyZWUAAA1YbWRhdAAAAq4GBf//qtxF6b3m2Ui3lizYINkj7u94MjY0IC0gY29yZSAxNjQgcjMxMDggMzFlMTlmOSAtIEguMjY0L01QRUctNCBBVkMgY29kZWMgLSBDb3B5bGVmdCAyMDAzLTIwMjMgLSBodHRwOi8vd3d3LnZpZGVvbGFuLm9yZy94MjY0Lmh0bWwgLSBvcHRpb25zOiBjYWJhYz0xIHJlZj0zIGRlYmxvY2s9MTowOjAgYW5hbHlzZT0weDM6MHgxMTMgbWU9aGV4IHN1Ym1lPTcgcHN5PTEgcHN5X3JkPTEuMDA6MC4wMCBtaXhlZF9yZWY9MSBtZV9yYW5nZT0xNiBjaHJvbWFfbWU9MSB0cmVsbGlzPTEgOHg4ZGN0PTEgY3FtPTAgZGVhZHpvbmU9MjEsMTEgZmFzdF9wc2tpcD0xIGNocm9tYV9xcF9vZmZzZXQ9LTIgdGhyZWFkcz02IGxvb2thaGVhZF90aHJlYWRzPTEgc2xpY2VkX3RocmVhZHM9MCBucj0wIGRlY2ltYXRlPTEgaW50ZXJsYWNlZD0wIGJsdXJheV9jb21wYXQ9MCBjb25zdHJhaW5lZF9pbnRyYT0wIGJmcmFtZXM9MyBiX3B5cmFtaWQ9MiBiX2FkYXB0PTEgYl9iaWFzPTAgZGlyZWN0PTEgd2VpZ2h0Yj0xIG9wZW5fZ29wPTAgd2VpZ2h0cD0yIGtleWludD0yNTAga2V5aW50X21pbj0xMiBzY2VuZWN1dD00MCBpbnRyYV9yZWZyZXNoPTAgcmNfbG9va2FoZWFkPTQwIHJjPWNyZiBtYnRyZWU9MSBjcmY9MjMuMCBxY29tcD0wLjYwIHFwbWlu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';
    private array $manifest = [];
    private array $columns = [];
    private string $prefix;

    public function run(string $partner, bool $clear = false): array
    {
        if (!app()->environment('local', 'testing')) throw new \RuntimeException('داده نمونه فقط در local یا testing مجاز است.');
        $disk = Storage::disk('local');
        $lockPath = $disk->path(self::MANIFEST.'.lock');
        if (!is_dir(dirname($lockPath))) mkdir(dirname($lockPath), 0755, true);
        $lock = fopen($lockPath, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('دستور نمونه‌سازی دیگری در حال اجراست.');
        try {
            if ($disk->exists(self::MANIFEST)) {
                $this->manifest = json_decode($disk->get(self::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
                if ($this->manifest['database'] !== DB::connection()->getDatabaseName()) throw new \RuntimeException('فهرست نمونه متعلق به دیتابیس دیگری است؛ پاک‌سازی انجام نشد.');
                if ($clear) {
                    DB::transaction(fn () => $this->clear());
                    $disk->delete(self::MANIFEST);
                    return [];
                }
                $owner = DB::table('panel_users')->where('id', $this->manifest['partner_id'])->first();
                if (!$owner || (!ctype_digit($partner) && strtolower($owner->code) !== strtolower($partner)) || (ctype_digit($partner) && (int) $partner !== $owner->id)) {
                    throw new \RuntimeException('نمونه‌ها برای نماینده دیگری ساخته شده‌اند؛ ابتدا همان نمونه‌ها را با --clear پاک کنید.');
                }
                DB::transaction(function () use ($disk) {
                    $this->refreshPresence();
                    $this->prefix = $this->manifest['prefix'];
                    $this->ensureReviewPreviews();
                    $this->manifest['result']['coverage'] = collect($this->manifest['rows'])->countBy('table')->map(fn ($count, $table) => [$table, $count])->values()->all();
                    if (!$disk->put(self::MANIFEST,json_encode($this->manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) throw new \RuntimeException('ذخیره فهرست نمونه‌ها ناموفق بود.');
                });
                return $this->manifest['result'] + ['existing' => true];
            }
            if ($clear) return [];
            $required = ['panel_users', 'panel_tariff_groups', 'panel_plan', 'v2_user', 'v2_plan', 'v2_server', 'v2_traffic_reset_logs', 'speedbox_connection_leases', 'user_bot_orders', 'speedbox_queue_activity'];
            foreach ($required as $table) if (!Schema::hasTable($table)) throw new \RuntimeException('ابتدا php artisan migrate را اجرا کنید؛ جدول موجود نیست: '.$table);
            $this->prefix = 'sb-demo-'.strtolower(Str::random(8));
            $this->manifest = ['database' => DB::connection()->getDatabaseName(), 'rows' => [], 'files' => [], 'nodes' => [], 'prefix' => $this->prefix];
            try {
                return DB::transaction(function () use ($partner, $disk) {
                    $result = $this->seed($partner);
                    $this->manifest['result'] = $result;
                    if (!$disk->put(self::MANIFEST, json_encode($this->manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) throw new \RuntimeException('ذخیره فهرست نمونه‌ها ناموفق بود.');
                    return $result + ['existing' => false];
                });
            } catch (\Throwable $error) {
                foreach ($this->manifest['files'] as $file) Storage::disk($file['disk'])->delete($file['path']);
                foreach ($this->manifest['nodes'] as $node) app(NodeControlService::class)->clearNodeCache($node['id']);
                $disk->delete(self::MANIFEST);
                throw $error;
            }
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function refreshReviewPreviews(): array
    {
        if (!app()->environment('local', 'testing')) throw new \RuntimeException('نمونه فقط در محیط local یا testing مجاز است.');
        $disk = Storage::disk('local');
        if (!$disk->exists(self::MANIFEST)) throw new \RuntimeException('ابتدا نمونه‌های قبلی را با speedbox:demo-all بسازید.');
        $manifest = json_decode($disk->get(self::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
        return $this->run((string) $manifest['partner_id']);
    }

    private function add(string $table, array $data): int|string
    {
        if (!Schema::hasTable($table)) throw new \RuntimeException('جدول نمونه موجود نیست؛ migrate را اجرا کنید: '.$table);
        $columns = $this->columns[$table] ??= Schema::getColumnListing($table);
        foreach (['created_at', 'updated_at'] as $column) if (in_array($column, $columns, true) && !array_key_exists($column, $data)) $data[$column] = now()->format('Y-m-d H:i:s');
        // Legacy XBoard can have fewer optional fields; required fixture fields still fail visibly.
        $data = array_intersect_key($data, array_flip($columns));
        foreach ($data as &$value) if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        unset($value);
        if (isset($data['id'])) { DB::table($table)->insert($data); $id = $data['id']; }
        else $id = DB::table($table)->insertGetId($data);
        $snapshot = (array) DB::table($table)->where('id', $id)->first();
        $this->manifest['rows'][] = ['table' => $table, 'id' => $id, 'snapshot' => $snapshot];
        return $id;
    }

    private function file(string $name, string $contents, string $disk = 'public'): string
    {
        $path = 'speedbox-demo/'.$this->prefix.'/'.$name;
        if (!Storage::disk($disk)->put($path, $contents)) throw new \RuntimeException('ذخیره فایل نمونه ناموفق بود.');
        $this->manifest['files'][] = ['disk' => $disk, 'path' => $path, 'hash' => hash('sha256', $contents)];
        return $path;
    }

    private function seed(string $partner): array
    {
        $credentials = [];
        $owner = ctype_digit($partner) ? DB::table('panel_users')->where('id', (int) $partner)->first() : DB::table('panel_users')->where('code', $partner)->where('role', 2)->first();
        if (!$owner) {
            if ($partner !== 'testrep') throw new \RuntimeException('نماینده پیدا نشد: '.$partner);
            $password = Str::random(16);
            $id = $this->add('panel_users', ['name' => 'نماینده تست جامع', 'email' => $this->prefix.'@example.invalid', 'password' => Hash::make($password), 'code' => 'testrep', 'role' => 2, 'credit' => 2000000, 'partner_plan_type' => 'both', 'partner_code_locked' => true]);
            $owner = DB::table('panel_users')->where('id', $id)->first();
            $credentials[] = 'ورود نماینده ساخته‌شده: '.$owner->email.' / '.$password;
        }
        if ((int) $owner->role !== 2 || trim((string) $owner->code) === '') throw new \RuntimeException('نماینده باید role=2 و کد معتبر داشته باشد.');
        $this->manifest['partner_id'] = $owner->id;
        $admin = DB::table('panel_users')->where('role', 1)->orderBy('id')->first();
        if (!$admin) {
            $password = Str::random(16);
            $id = $this->add('panel_users', ['name' => 'مدیر تست جامع', 'email' => $this->prefix.'-admin@example.invalid', 'password' => Hash::make($password), 'code' => $this->prefix.'admin', 'role' => 1, 'credit' => 2000000]);
            $admin = DB::table('panel_users')->where('id', $id)->first();
            $credentials[] = 'ورود مدیر ساخته‌شده: '.$admin->email.' / '.$password;
        }
        $this->media = $this->file('receipt.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="400"><rect width="640" height="400" fill="#f4f7fb"/><rect x="30" y="30" width="580" height="340" rx="16" fill="white"/><g font-family="sans-serif" text-anchor="middle" fill="#172a42"><text x="320" y="100" font-size="30">SpeedBox — DEMO RECEIPT</text><text x="320" y="160" font-size="22">Sample only / Not a payment</text><text x="320" y="225" font-size="34">250,000 IRT</text><text x="320" y="290" font-size="20">Tracking: LOCAL-PREVIEW</text></g></svg>');
        $video = $this->file('tutorial-preview.mp4', base64_decode(self::VIDEO, true));
        $attachment = $this->file('support-sample.txt', "SpeedBox local preview attachment\nNo payment or remote connection was performed.\n", 'local');
        $group = $this->add('v2_server_group', ['name' => 'نمایشی — دسترسی تست جامع', 'created_at' => time(), 'updated_at' => time()]);
        $route = $this->add('v2_server_route', ['remarks' => 'نمایشی — مسدودسازی دامنه آزمایشی', 'match' => ['domain:example.invalid'], 'action' => 'block', 'created_at' => time(), 'updated_at' => time()]);
        $plan = $this->add('v2_plan', ['name' => 'نمایشی — پلن ۱۰۰ گیگ', 'group_id' => $group, 'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 50, 'reset_traffic_method' => 0, 'show' => false, 'renew' => false, 'created_at' => time(), 'updated_at' => time()]);
        $nodes = $this->infrastructure($group, $route, $admin->id);
        $tariffs = [];
        foreach (['time', 'volume'] as $type) {
            $tariff = $this->add('panel_tariff_groups', ['name' => 'نمایشی — تعرفه '.($type === 'time' ? 'زمانی' : 'حجمی'), 'group_type' => $type, 'is_default' => false, 'enable' => true]);
            $tariffs[$type] = $tariff;
            foreach (($type === 'time' ? ['test', '1m', '3m', '6m', '12m', 'gig'] : ['volume_gig']) as $key) $this->add('panel_plan', ['name' => 'نمایشی — '.$key, 'plan_key' => $key, 'tariff_group_id' => $tariff, 'default_price' => $key === 'gig' || $key === 'volume_gig' ? 12000 : 250000, 'v2_plan_id' => $plan, 'enable' => true, 'details' => 'تعرفه محلی برای بررسی رابط کاربری', 'device_limit' => 2]);
            $this->add('panel_user_tariff_prices', ['panel_user_id' => $owner->id, 'tariff_group_id' => $tariff, 'plan_key' => $type === 'time' ? '1m' : 'volume_gig', 'price' => 180000]);
        }
        $existing = PanelAccountOwnership::scope(DB::table('v2_user'), $owner->code)->orderBy('id')->limit(20)->get();
        $accounts = []; $accountRows = [];
        $suffix = substr($this->prefix, -8);
        foreach ([[0,2,'time'],[1,2,'time'],[2,2,'time'],[3,2,'volume'],[2,null,'volume'],[0,2,'expired'],[0,2,'blocked']] as $i => [$online,$limit,$mode]) {
            $email = str_contains($owner->code, '.') ? $owner->code.'@preview'.$i.'-'.$suffix.'.invalid' : 'preview'.$i.'-'.$suffix.'@'.$owner->code.'.com';
            if (strlen($email) > 64) throw new \RuntimeException('کد نماینده برای ایمیل نمونه بیش از حد بلند است.');
            $type = $mode === 'volume' ? 'volume' : 'time';
            $id = $this->add('v2_user', ['email' => $email, 'password' => Hash::make(Str::random(32)), 'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'u' => ($i + 1) * self::GB, 'd' => ($i + 1) * 4 * self::GB, 'transfer_enable' => 100 * self::GB, 'expired_at' => time() + ($mode === 'expired' ? -86400 : 21 * 86400), 'banned' => $mode === 'blocked', 'group_id' => $group, 'plan_id' => $plan, 'device_limit' => $limit, 'speed_limit' => 50, 'account_plan_name' => 'نمایشی — طرح '.($type === 'time' ? 'زمانی' : 'حجمی'), 'account_group_type' => $type, 'account_tariff_group_id' => $tariffs[$type], 'account_plan_key' => $type === 'volume' ? 'volume_gig' : '1m', 'reset_count' => 6, 'last_reset_at' => time() - 86400, 'next_reset_at' => $type === 'time' && $mode !== 'expired' ? now()->addMonthNoOverflow()->startOfMonth()->timestamp : null, 'remarks' => 'نمایشی — تست جامع / '.$mode, 'created_at' => time() - ($i + 1) * 86400, 'updated_at' => time()]);
            $account = DB::table('v2_user')->where('id', $id)->first(); $accountRows[] = $account;
            for ($j = 0; $j < $online; $j++) $this->add('speedbox_connection_leases', ['user_id' => $id, 'node_id' => $nodes[0], 'source_ip' => '192.0.2.'.(50 + $i * 5 + $j), 'lease_id' => hash('sha256', $this->prefix.$i.$j), 'expires_at' => time() + 86400]);
            $accounts[] = [$email, $online.' / '.($limit ?? '∞'), \App\Utils\Helper::getSubscribeUrl($account->token)];
        }
        foreach ($accountRows as $account) $this->usage($account);
        foreach ($existing as $account) $this->usage($account);
        $this->cacheNodeStatus();
        $this->finance($owner->id, $admin->id, $accountRows);
        $this->content($owner->id, $admin->id, $attachment, $video);
        $this->ensureReviewPreviews();
        $this->bots($owner->id, $admin->id, $accountRows[0], $tariffs['time']);
        $coverage = [];
        foreach (collect($this->manifest['rows'])->countBy('table')->all() as $table => $count) $coverage[] = [$table, $count];
        return ['partner' => $owner->code.' (ID '.$owner->id.')', 'credentials' => $credentials, 'accounts' => $accounts, 'coverage' => $coverage];
    }

    private string $media;

    private function infrastructure(int $group, int $route, int $admin): array
    {
        $nodes = [];
        foreach ([true, false] as $i => $online) {
            $load = ['cpu' => 28.5 + $i * 40, 'mem_total' => 8*self::GB, 'mem_used' => (3+$i)*self::GB, 'disk_total' => 80*self::GB, 'disk_used' => 24*self::GB];
            $machine = $this->add('v2_server_machine', ['name' => 'نمایشی — ماشین '.($online?'آنلاین':'آفلاین'), 'token' => Str::random(64), 'notes' => $this->prefix, 'is_active' => $online, 'last_seen_at' => time()-($online?0:7200), 'load_status' => $load]);
            $server = $this->add('speedbox_managed_servers', ['name' => 'نمایشی — سرور '.($online?'فعال':'ناموفق'), 'machine_id' => $machine, 'host' => '192.0.2.'.(200+$i), 'ssh_port' => random_int(20000,60000), 'status' => $online?'online':'failed', 'operating_system' => 'Ubuntu 24.04', 'architecture' => 'amd64', 'agent_version' => '1.0.0', 'last_checked_at' => time()-180, 'last_error' => $online?null:'خطای نمایشی دسترسی به سرور']);
            foreach (['vless','trojan'] as $j => $protocol) {
                $node = $this->add('v2_server', ['type' => $protocol, 'code' => $this->prefix.'-'.$i.'-'.$j, 'name' => 'نمایشی — '.$protocol.' '.($i+1), 'machine_id' => $machine, 'host' => $this->prefix.'-'.$i.'-'.$j.'.invalid', 'port' => '443', 'server_port' => 10443+$j, 'group_ids' => [$group], 'route_ids' => [$route], 'tags' => ['نمایشی'], 'protocol_settings' => ['tls'=>0,'network'=>'tcp','network_settings'=>[]], 'kernel' => 'singbox', 'show' => false, 'enabled' => $online, 'u' => 12*self::GB, 'd' => 45*self::GB, 'sort' => 100+$i*2+$j]);
                $nodes[] = $node;
                $this->manifest['nodes'][] = ['id'=>$node,'online'=>$online];
                for ($day=0;$day<8;$day++) $this->add('v2_stat_server', ['server_id' => $node, 'server_type' => $protocol, 'record_type' => 'd', 'record_at' => now()->startOfDay()->subDays($day)->timestamp, 'u' => ($day+1)*self::GB, 'd' => ($day%2?8:4)*self::GB, 'created_at' => time(), 'updated_at' => time()]);
            }
            for ($hour=0;$hour<12;$hour++) $this->add('v2_server_machine_load_history', ['machine_id' => $machine, 'cpu' => 12+$hour*3, 'mem_total' => 8*self::GB, 'mem_used' => (2+$hour/10)*self::GB, 'disk_total' => 80*self::GB, 'disk_used' => 24*self::GB, 'net_in_speed' => 250000+$hour*12000, 'net_out_speed' => 900000+$hour*15000, 'recorded_at' => time()-$hour*3600]);
            foreach (['install','restart','repair','check'] as $j => $action) $this->add('speedbox_deployments', ['managed_server_id' => $server, 'requested_by' => $admin, 'action' => $action, 'status' => $action==='repair'?'failed':'succeeded', 'progress' => $action==='repair'?55:100, 'log' => "گزارش نمایشی {$action}\nسیستم: Ubuntu 24.04 / amd64\n".($action==='repair'?'خطای نمایشی دسترسی':'عملیات با موفقیت پایان یافت.'), 'started_at' => now()->subHours(4-$j)->format('Y-m-d H:i:s'), 'finished_at' => now()->subHours(4-$j)->addMinutes(2)->format('Y-m-d H:i:s'), 'created_at' => now()->subHours(4-$j)->format('Y-m-d H:i:s')]);
        }
        foreach (['succeeded','failed','released'] as $i => $status) $this->add('speedbox_queue_activity', ['id' => hash('sha256',$this->prefix.'job'.$i), 'job_id' => $this->prefix.'-'.$i, 'connection' => config('queue.default','database'), 'queue' => 'default', 'name' => 'SpeedboxDemoPreview', 'status' => $status, 'attempts' => $i+1, 'started_at' => now()->subSeconds(45+$i*20)->format('Y-m-d H:i:s'), 'finished_at' => now()->subSeconds(30+$i*20)->format('Y-m-d H:i:s'), 'duration' => 15.4, 'exception_class' => $status==='failed'?'LocalPreviewException':null]);
        $this->add('speedbox_queue_workers', ['id'=>hash('sha256',$this->prefix.'worker'),'connection'=>config('queue.default','database'),'queues'=>'default,deployments','last_seen_at'=>now()->format('Y-m-d H:i:s')]);
        $this->add('failed_jobs', ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => ['displayName'=>'SpeedboxDemoPreview','job'=>'SpeedboxDemoPreview','data'=>[]], 'exception' => 'خطای نمایشی صف — هیچ کار واقعی اجرا نشده است.', 'failed_at' => now()->subMinutes(3)->format('Y-m-d H:i:s')]);
        return $nodes;
    }

    private function refreshPresence(): void
    {
        foreach ($this->manifest['rows'] as &$row) {
            $changes = match ($row['table']) {
                'speedbox_connection_leases' => ['expires_at'=>time()+86400],
                'speedbox_queue_workers' => ['last_seen_at'=>now()->format('Y-m-d H:i:s')],
                'v2_server_machine' => $row['snapshot']['is_active'] ? ['last_seen_at'=>time()] : [],
                default => [],
            };
            if (!$changes) continue;
            $current = DB::table($row['table'])->where('id',$row['id'])->lockForUpdate()->first();
            if (!$current || json_encode((array)$current,JSON_THROW_ON_ERROR) !== json_encode($row['snapshot'],JSON_THROW_ON_ERROR)) continue;
            DB::table($row['table'])->where('id',$row['id'])->update($changes);
            $row['snapshot'] = (array)DB::table($row['table'])->where('id',$row['id'])->first();
        }
        unset($row);
        $this->cacheNodeStatus();
    }

    private function cacheNodeStatus(): void
    {
        foreach ($this->manifest['nodes'] as $node) {
            if (!$node['online']) continue;
            // Only new preview nodes with no reachable server agent use these telemetry keys.
            Cache::put('speedbox:node:'.$node['id'].':last_seen',time(),86400);
            Cache::put('speedbox:node:'.$node['id'].':metrics',['kernel_status'=>true],86400);
            Cache::put('speedbox:node:'.$node['id'].':online',[['ip'=>'192.0.2.50'],['ip'=>'192.0.2.51']],86400);
        }
    }

    private function usage(object $account): void
    {
        foreach (['manual','renewal','periodic','monthly','manual','renewal'] as $i => $type) {
            $time = now()->subDays(6-$i)->format('Y-m-d H:i:s');
            $this->add('v2_traffic_reset_logs', ['user_id' => $account->id, 'reset_type' => $type, 'trigger_source' => ['manual','order','cron','auto','api','order'][$i], 'reset_time' => $time, 'old_upload' => ($i+1)*self::GB, 'old_download' => ($i+1)*3*self::GB, 'old_total' => ($i+1)*4*self::GB, 'new_upload'=>0,'new_download'=>0,'new_total'=>0, 'metadata' => ['speedbox_demo'=>$this->prefix,'account_email'=>$account->email,'reason'=>'نمونه نمایشی تاریخچه'], 'created_at' => $time, 'updated_at' => $time]);
        }
        // Existing accounts get only added histories, never replaced usage or summary counters.
        for ($day=0;$day<8;$day++) {
            $at = now()->startOfDay()->subDays($day)->timestamp;
            if (DB::table('v2_stat_user')->where('user_id',$account->id)->where('record_type','d')->where('record_at',$at)->exists()) continue;
            $this->add('v2_stat_user', ['user_id'=>$account->id,'server_rate'=>1,'record_type'=>'d','record_at'=>$at,'u'=>($day+1)*self::GB/4,'d'=>($day%2?3:5)*self::GB,'created_at'=>time(),'updated_at'=>time()]);
        }
    }

    private function finance(int $owner, int $admin, array $accounts): void
    {
        foreach (['account_purchase','account_extend','traffic_purchase','wallet_topup_card','referrer_commission','wallet_adjust','ai_receipt_check_fee'] as $i => $type) {
            foreach ([0,1,7,30] as $day) {
                $account = $accounts[$i % count($accounts)];
                $debit = $i < 3 || in_array($type, ['wallet_adjust', 'ai_receipt_check_fee'], true);
                $amount = $type === 'ai_receipt_check_fee' ? 4000 : 250000;
                $meta = ['speedbox_demo'=>$this->prefix,'description'=>'تراکنش نمایشی؛ موجودی واقعی تغییر نکرده است'];
                if ($i < 3) $meta += ['user_ids'=>[$account->id],'emails'=>[$account->email],'email'=>$account->email,'gb'=>10,'period'=>'1m'];
                if ($type === 'referrer_commission') $meta['source_user_code'] = $this->prefix.'ref0';
                $this->add('panel_transactions', ['panel_user_id'=>$owner,'type'=>$type,'direction'=>$debit?'debit':'credit','amount'=>$amount,'balance_before'=>2000000,'balance_after'=>2000000+($debit?-$amount:$amount),'status'=>'success','performed_by_id'=>$type==='ai_receipt_check_fee'?null:$admin,'performed_by_role'=>$type==='ai_receipt_check_fee'?'system':'admin','plan_key_after'=>$i<2?'1m':null,'quantity'=>1,'reference_type'=>$i<3?'v2_user':null,'reference_id'=>$i<3?$account->id:null,'idempotency_key'=>$this->prefix.'-tx-'.$i.'-'.$day,'meta'=>$meta,'created_at'=>now()->subDays($day)->subHours($i)->format('Y-m-d H:i:s')]);
            }
        }
        foreach (['submitted','verified','rejected'] as $i => $status) $this->add('panel_wallet_receipts', ['user_id'=>$owner,'amount'=>250000,'path'=>$this->media,'original_name'=>'demo-receipt.svg','mime'=>'image/svg+xml','size'=>Storage::disk('public')->size($this->media),'status'=>$status,'notified_at'=>now()->format('Y-m-d H:i:s'),'meta'=>['speedbox_demo'=>$this->prefix,'note'=>'رسید آزمایشی','ai_receipt'=>['failed_final'=>true]]]);
        foreach (['paid','expired','failed'] as $status) $this->add('panel_crypto_topups', ['user_id'=>$owner,'amount_irt'=>250000,'asset'=>'USDT','network'=>'TRC20','wallet_address'=>'DEMO-NOT-A-WALLET','crypto_amount'=>2.5,'base_crypto_amount'=>2.5,'status'=>$status,'expires_at'=>now()->subDays(1)->format('Y-m-d H:i:s'),'paid_at'=>$status==='paid'?now()->subHours(2)->format('Y-m-d H:i:s'):null,'notified_at'=>now()->format('Y-m-d H:i:s'),'telegram_notified_at'=>now()->format('Y-m-d H:i:s'),'meta'=>['speedbox_demo'=>$this->prefix]]);
        foreach (['paid','expired','cancelled'] as $status) $this->add('panel_wallet_card_topups', ['user_id'=>$owner,'amount_irt'=>250000,'payable_amount_irt'=>250001,'unique_extra'=>1,'status'=>$status,'expires_at'=>now()->subDay()->format('Y-m-d H:i:s'),'card_number'=>'0000000000000000','card_holder'=>'نمونه نمایشی','notified_at'=>now()->format('Y-m-d H:i:s'),'meta'=>['speedbox_demo'=>$this->prefix]]);
        foreach (['pending','approved','rejected'] as $i => $status) $this->add('panel_referrals', ['referrer_id'=>$owner,'referee_name'=>'نماینده معرفی‌شده نمونه '.($i+1),'referee_email'=>$this->prefix.'-ref'.$i.'@example.invalid','referee_code'=>$this->prefix.'ref'.$i,'status'=>$status,'note'=>'درخواست نمایشی برای بررسی جزئیات','pricing_strategy'=>'default','decided_by_id'=>$status==='pending'?null:$admin,'decided_at'=>$status==='pending'?null:now()->format('Y-m-d H:i:s'),'decide_reason'=>$status==='rejected'?'رد آزمایشی':null,'admin_notified_at'=>now()->format('Y-m-d H:i:s'),'referrer_notified_at'=>now()->format('Y-m-d H:i:s'),'meta'=>['speedbox_demo'=>$this->prefix]]);
    }

    private function ensureReviewPreviews(): void
    {
        foreach ($this->manifest['rows'] as &$tracked) {
            if ($tracked['table'] !== 'panel_transactions') continue;
            $current = DB::table($tracked['table'])->where('id', $tracked['id'])->first();
            if (!$current || json_encode((array) $current, JSON_THROW_ON_ERROR) !== json_encode($tracked['snapshot'], JSON_THROW_ON_ERROR)) continue;
            $changes = match ($current->type) {
                'credit_adjustment' => ['type'=>'wallet_adjust','direction'=>'debit','balance_after'=>(int) $current->balance_before-(int) $current->amount],
                'referral_commission' => ['type'=>'referrer_commission'],
                default => [],
            };
            if ($changes) {
                $meta = json_decode((string) ($current->meta ?? '{}'), true) ?: [];
                unset($meta['user_ids'], $meta['emails'], $meta['email'], $meta['gb'], $meta['period']);
                if ($changes['type'] === 'referrer_commission') $meta['source_user_code'] = $this->prefix.'ref0';
                $changes += ['meta'=>json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),'reference_type'=>null,'reference_id'=>null,'plan_key_after'=>null];
                DB::table($tracked['table'])->where('id', $tracked['id'])->update($changes);
                $tracked['snapshot'] = (array) DB::table($tracked['table'])->where('id', $tracked['id'])->first();
            }
        }
        unset($tracked);
        $owner = (int) $this->manifest['partner_id'];
        $hasFee = collect($this->manifest['rows'])->contains(fn ($row) => $row['table']==='panel_transactions' && ($row['snapshot']['type'] ?? '')==='ai_receipt_check_fee');
        if (!$hasFee) $this->add('panel_transactions', ['panel_user_id'=>$owner,'type'=>'ai_receipt_check_fee','direction'=>'debit','amount'=>4000,'balance_before'=>2000000,'balance_after'=>1996000,'status'=>'success','quantity'=>1,'currency'=>'IRT','performed_by_role'=>'system','meta'=>['speedbox_demo'=>$this->prefix,'description'=>'هزینه نمونه؛ موجودی واقعی تغییر نکرده است']]);
        if (!isset($this->manifest['short_announcement_id'])) {
            $this->manifest['short_announcement_id'] = $this->add('panel_announcements', ['title'=>'اطلاعیه','body'=>'<p>نمونه کوتاه برای بررسی جای عنوان، نقطه وضعیت و تاریخ انتشار در موبایل.</p>','audience'=>'all','is_published'=>true,'publish_at'=>now()->subMinute()->format('Y-m-d H:i:s'),'tg_broadcasted_at'=>now()->format('Y-m-d H:i:s'),'created_by'=>DB::table('panel_users')->where('role',1)->orderBy('id')->value('id')]);
        }
    }

    private function content(int $owner, int $admin, string $attachment, string $video): void
    {
        foreach (['open','answered','pending','closed'] as $i => $status) {
            $ticket = $this->add('support_tickets', ['user_id'=>$owner,'ticket_number'=>900000+random_int(1,99999),'subject'=>'نمایشی — پشتیبانی '.$status,'status'=>$status,'priority'=>$i%2?'important':'normal','last_message_at'=>now()->subHours($i)->format('Y-m-d H:i:s'),'closed_at'=>$status==='closed'?now()->format('Y-m-d H:i:s'):null]);
            $message = $this->add('support_ticket_messages', ['ticket_id'=>$ticket,'sender_id'=>$owner,'sender_role'=>'user','message'=>'پیام نمونه نماینده با فایل پیوست؛ لطفاً وضعیت اتصال را بررسی کنید.','is_read_by_admin'=>$status!=='open']);
            $this->add('support_ticket_attachments', ['message_id'=>$message,'original_name'=>'support-sample.txt','path'=>$attachment,'mime_type'=>'text/plain','size'=>Storage::disk('local')->size($attachment)]);
            $this->add('support_ticket_messages', ['ticket_id'=>$ticket,'sender_id'=>$admin,'sender_role'=>'admin','message'=>'پاسخ نمونه مدیر؛ اطلاعات حساب و راهنما بررسی شد.','is_read_by_user'=>$status==='closed']);
        }
        foreach (['published','scheduled','expired','draft'] as $i => $status) {
            $ann = $this->add('panel_announcements', ['title'=>'نمایشی — اطلاعیه '.$status,'body'=>'<p>متن نمونه اطلاعیه برای بررسی ظاهر، تاریخ و جزئیات نمایش.</p>','audience'=>'all','is_published'=>$status!=='draft','publish_at'=>now()->addDays($status==='scheduled'?2:-2)->format('Y-m-d H:i:s'),'expires_at'=>$status==='expired'?now()->subDay()->format('Y-m-d H:i:s'):null,'tg_broadcasted_at'=>now()->format('Y-m-d H:i:s'),'created_by'=>$admin]);
            if ($status==='published') $this->add('panel_announcement_reads', ['announcement_id'=>$ann,'user_id'=>$owner,'read_at'=>now()->format('Y-m-d H:i:s')]);
        }
        foreach (['ios','android','system'] as $category) {
            $tutorial = $this->add('panel_tutorials', ['category'=>$category,'app_name'=>'نمایشی — راهنمای '.$category,'icon_path'=>$this->media,'video_path'=>$video,'download_url'=>rtrim(config('app.url'),'/').'/storage/'.$video,'desc'=>'راهنمای نمونه: لینک سابسکرایب را کپی کنید، وارد برنامه شوید و اتصال را بررسی کنید.','published_at'=>now()->subDay()->format('Y-m-d H:i:s'),'created_by'=>$admin]);
            $this->add('panel_tutorial_toasts', ['user_id'=>$owner,'tutorial_id'=>$tutorial,'kind'=>'published']);
        }
    }

    private function bots(int $owner, int $admin, object $account, int $tariff): void
    {
        foreach ([['partner',$owner],['admin',$admin]] as [$type,$id]) {
            $setting = DB::table('user_bot_settings')->where('owner_type',$type)->where('owner_id',$id)->first();
            if (!$setting) {
                $settingId = $this->add('user_bot_settings', ['owner_type'=>$type,'owner_id'=>$id,'is_enabled'=>false,'card_payment_enabled'=>false,'card_number'=>'0000000000000000','card_holder'=>'نمایشی — کارت آزمایشی','help_ios'=>'راهنمای نمونه iOS','help_android'=>'راهنمای نمونه Android','help_windows'=>'راهنمای نمونه Windows']);
                $setting = DB::table('user_bot_settings')->where('id',$settingId)->first();
            }
            foreach (['1m','traffic_gig'] as $i => $key) {
                $planKey = $tariff.'_'.$key;
                if (!DB::table('user_bot_plans')->where('setting_id',$setting->id)->where('plan_key',$planKey)->exists()) $this->add('user_bot_plans', ['setting_id'=>$setting->id,'plan_key'=>$planKey,'title'=>'نمایشی — '.($i?'ترافیک':'یک ماهه'),'price'=>250000,'description'=>'تعرفه نمونه','is_enabled'=>false]);
            }
            // No routable Telegram chat IDs: prevents scheduled delivery to real people.
            $session = $this->add('user_bot_sessions', ['setting_id'=>$setting->id,'telegram_chat_id'=>$this->prefix.'-'.$type,'telegram_username'=>'demo_preview','is_logged_in'=>false,'account_email'=>$account->email,'account_snapshot'=>['email'=>$account->email,'expired_at'=>$account->expired_at]]);
            foreach (['pending','done','rejected','failed'] as $i => $status) {
                $effective = $setting->is_enabled && $status==='pending' ? 'failed' : $status;
                $this->add('user_bot_orders', ['setting_id'=>$setting->id,'session_id'=>$session,'order_code'=>strtoupper(substr($this->prefix,-8)).($type==='admin'?'A':'P').$i,'order_type'=>['buy_account','renew_account','buy_traffic','buy_traffic'][$i],'plan_key'=>$tariff.'_'.($i<2?'1m':'traffic_gig'),'account_email'=>$account->email,'traffic_gb'=>$i>=2?10:null,'amount'=>250000,'payment_method'=>'card','status'=>$effective,'receipt_submitted_at'=>now()->format('Y-m-d H:i:s'),'admin_note'=>'سفارش نمایشی؛ هیچ عملیات واقعی انجام نشده است.','payload'=>['speedbox_demo'=>$this->prefix],'approved_at'=>$status==='done'?now()->format('Y-m-d H:i:s'):null,'rejected_at'=>$status==='rejected'?now()->format('Y-m-d H:i:s'):null]);
            }
            $device = $this->add('user_bot_sms_devices', ['setting_id'=>$setting->id,'name'=>'نمایشی — دستگاه پیامک','platform'=>'android','token_hash'=>hash('sha256',Str::random(64)),'is_enabled'=>false,'last_seen_at'=>now()->subMinutes(5)->format('Y-m-d H:i:s'),'last_ip'=>'192.0.2.250','metadata'=>['speedbox_demo'=>$this->prefix]]);
            foreach (['matched','unmatched','duplicate','failed'] as $i => $status) $this->add('user_bot_sms_events', ['setting_id'=>$setting->id,'device_id'=>$device,'event_id'=>$this->prefix.'-'.$type.'-'.$i,'platform'=>'android','status'=>$status,'candidate_count'=>1,'matched_amount_toman'=>$status==='matched'?250000:null,'failure_reason'=>$status==='failed'?'خطای نمونه پردازش':null,'received_at_device'=>now()->subMinutes($i)->format('Y-m-d H:i:s'),'received_at_server'=>now()->subMinutes($i)->format('Y-m-d H:i:s'),'request_ip'=>'192.0.2.250']);
        }
    }

    private function clear(): void
    {
        $tracked = [];
        foreach ($this->manifest['rows'] as $row) $tracked[$row['table']][] = $row['id'];
        foreach ($this->manifest['rows'] as $row) {
            $current = DB::table($row['table'])->where('id',$row['id'])->lockForUpdate()->first();
            if ($current && json_encode((array) $current, JSON_THROW_ON_ERROR) !== json_encode($row['snapshot'], JSON_THROW_ON_ERROR)) throw new \RuntimeException('نمونه ویرایش شده است؛ برای حفظ تغییرات هیچ چیز حذف نشد: '.$row['table'].' #'.$row['id']);
        }
        // Block cascades / set-null on records created after seeding, including imported schemas.
        foreach (Schema::getTables() as $tableInfo) {
            $table = $tableInfo['name'];
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $parent = $foreign['foreign_table'];
                if (!isset($tracked[$parent]) || count($foreign['columns']) !== 1 || $foreign['foreign_columns'] !== ['id']) continue;
                $query = DB::table($table)->whereIn($foreign['columns'][0],$tracked[$parent]);
                if (isset($tracked[$table])) $query->whereNotIn('id',$tracked[$table]);
                if ($query->exists()) throw new \RuntimeException('رکورد جدید به نمونه وابسته است؛ پاک‌سازی انجام نشد: '.$table);
            }
        }
        // XBoard's logical references often lack foreign-key constraints.
        foreach (['v2_user'=>['plan_id'=>'v2_plan','group_id'=>'v2_server_group'], 'v2_stat_user'=>['user_id'=>'v2_user'], 'v2_stat_server'=>['server_id'=>'v2_server'], 'v2_traffic_reset_logs'=>['user_id'=>'v2_user'], 'speedbox_subscription_cycles'=>['user_id'=>'v2_user'], 'speedbox_connection_leases'=>['user_id'=>'v2_user','node_id'=>'v2_server'], 'panel_plan'=>['v2_plan_id'=>'v2_plan','tariff_group_id'=>'panel_tariff_groups']] as $table=>$links) {
            if (!Schema::hasTable($table)) continue;
            foreach ($links as $column=>$parent) if (isset($tracked[$parent]) && Schema::hasColumn($table,$column)) {
                $query = DB::table($table)->whereIn($column,$tracked[$parent]);
                if (isset($tracked[$table])) $query->whereNotIn('id',$tracked[$table]);
                if ($query->exists()) throw new \RuntimeException('داده جدید از نمونه استفاده می‌کند؛ پاک‌سازی انجام نشد: '.$table);
            }
        }
        if (isset($tracked['v2_user'])) {
            $tokens = DB::table('v2_user')->whereIn('id',$tracked['v2_user'])->pluck('token')->all();
            $transactions = DB::table('panel_transactions')->where('reference_type','v2_user')->whereIn('reference_id',$tracked['v2_user'])->whereNotIn('id',$tracked['panel_transactions'] ?? []);
            if ($transactions->exists()) throw new \RuntimeException('تراکنش جدید به اکانت نمونه وابسته است؛ پاک‌سازی انجام نشد.');
        }
        foreach ($this->manifest['files'] as $file) {
            $disk = Storage::disk($file['disk']);
            if ($disk->exists($file['path']) && hash('sha256',$disk->get($file['path'])) !== $file['hash']) throw new \RuntimeException('فایل نمونه ویرایش شده است؛ پاک‌سازی انجام نشد.');
        }
        foreach (array_reverse($this->manifest['rows']) as $row) DB::table($row['table'])->where('id',$row['id'])->delete();
        foreach ($this->manifest['nodes'] as $node) app(NodeControlService::class)->clearNodeCache($node['id']);
        foreach ($this->manifest['files'] as $file) Storage::disk($file['disk'])->delete($file['path']);
    }
}
