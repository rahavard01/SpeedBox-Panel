<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeedTrafficRankingDemo extends Command
{
    protected $signature = 'speedbox:demo-traffic {type : node or user} {--demo-users= : Create 1-10 disabled local demo accounts} {--clear : Remove unchanged samples inserted by this command}';
    protected $description = 'Insert local daily ranking samples without changing account usage or replacing real reports.';

    public function handle(): int
    {
        $type = $this->argument('type');
        if (!app()->environment('local') || !in_array($type, ['node', 'user'], true)) {
            $this->error('فقط در محیط local، با نوع node یا user اجرا کنید.');
            return self::FAILURE;
        }
        $node = $type === 'node';
        $source = $node ? 'v2_server' : 'v2_user';
        $table = $node ? 'v2_stat_server' : 'v2_stat_user';
        if (!Schema::hasTable($table) || !Schema::hasTable($source)) {
            $this->error('ابتدا php artisan migrate را اجرا کنید.');
            return self::FAILURE;
        }
        // Keep a manifest for precise cleanup; never delete rows that an agent has since updated.
        $disk = Storage::disk('local');
        $manifest = 'speedbox-demo-traffic-'.$type.'.json';
        $samples = $disk->exists($manifest) ? json_decode($disk->get($manifest), true, 512, JSON_THROW_ON_ERROR) : [];
        if ($this->option('clear')) {
            $removed = DB::transaction(function () use ($table, $samples) {
                $count = 0;
                foreach ($samples as $row) $count += DB::table($table)->where($row)->delete();
                return $count;
            });
            $disk->delete($manifest);
            $this->info("{$removed} گزارش آزمایشیِ بدون تغییر حذف شد؛ گزارش‌های تغییرکرده حفظ شدند.");
            return self::SUCCESS;
        }
        $entities = DB::table($source)->orderBy('id')->limit(10)->get();
        $demoCount = $this->option('demo-users');
        if ($demoCount !== null && ($node || !ctype_digit((string) $demoCount) || (int) $demoCount < 1 || (int) $demoCount > 10)) {
            $this->error('گزینه --demo-users فقط برای user و با تعداد 1 تا 10 قابل استفاده است.');
            return self::FAILURE;
        }
        if (!$node && ($demoCount !== null || $entities->isEmpty())) {
            $entities = $this->demoUsers($demoCount !== null ? (int) $demoCount : 6);
            $this->info('اکانت‌های نمایشی غیرفعال آماده شدند؛ مصرف واقعی آن‌ها صفر است.');
        }
        if ($entities->isEmpty()) {
            $this->error($node ? 'ابتدا حداقل یک نود ایجاد کنید.' : 'ابتدا حداقل یک اکانت آزمایشی ایجاد کنید.');
            return self::FAILURE;
        }
        $today = CarbonImmutable::today(config('app.timezone'));
        $inserted = 0;
        DB::transaction(function () use ($entities, $node, $table, $today, &$samples, &$inserted, $disk, $manifest) {
            foreach ($entities as $index => $entity) {
                foreach ([0, 1] as $daysAgo) {
                    $gb = (10 - $index) * ($daysAgo ? ($index % 2 ? 4 : 2) : 3);
                    $key = $node
                        ? ['server_id' => $entity->id, 'server_type' => $entity->type]
                        : ['user_id' => $entity->id, 'server_rate' => 1];
                    $row = $key + ['record_type' => 'd', 'record_at' => $today->subDays($daysAgo)->timestamp,
                        'u' => $gb * 1073741824, 'd' => $gb * 2 * 1073741824,
                        'created_at' => time(), 'updated_at' => time()];
                    if (DB::table($table)->insertOrIgnore($row)) {
                        $id = DB::table($table)->where($key)->where('record_type', 'd')->where('record_at', $row['record_at'])->value('id');
                        $samples[] = ['id' => $id] + $row;
                        $inserted++;
                    }
                }
            }
            // If the manifest cannot be saved, roll back rather than leave untracked sample rows.
            if (!$disk->put($manifest, json_encode($samples, JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('ذخیره فهرست گزارش‌های آزمایشی ناموفق بود.');
            }
        });
        $this->info("{$inserted} گزارش برای امروز و دیروز اضافه شد. گزارش‌های موجود و مصرف واقعی اکانت‌ها حفظ شدند.");
        $this->line("پاک‌سازی: php artisan speedbox:demo-traffic {$type} --clear");
        return self::SUCCESS;
    }

    private function demoUsers(int $count): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($count) {
            $ids = [];
            for ($index = 1; $index <= $count; $index++) {
                $email = sprintf('demo%02d@speedbox-demo.com', $index);
                $account = DB::table('v2_user')->where('email', $email)->lockForUpdate()->first();
                if ($account) {
                    if (($account->remarks ?? '') !== 'speedbox-traffic-ranking-demo' || !(bool) $account->banned) {
                        throw new \RuntimeException('نام اکانت نمایشی قبلاً استفاده شده یا اکانت فعال شده است: '.$email);
                    }
                    $ids[] = $account->id;
                    continue;
                }
                $ids[] = DB::table('v2_user')->insertGetId([
                    'email' => $email, 'password' => Hash::make(Str::random(64)),
                    'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
                    'banned' => 1, 'u' => 0, 'd' => 0, 'transfer_enable' => 0,
                    'expired_at' => time() - 86400, 'plan_id' => null, 'group_id' => null,
                    'remarks' => 'speedbox-traffic-ranking-demo',
                    'created_at' => time(), 'updated_at' => time(),
                ]);
            }
            return DB::table('v2_user')->whereIn('id', $ids)->orderBy('id')->get();
        });
    }
}
