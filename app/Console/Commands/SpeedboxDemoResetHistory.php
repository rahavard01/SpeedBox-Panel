<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SpeedboxDemoResetHistory extends Command
{
    protected $signature = 'speedbox:demo-reset-history {user_id? : ID of the test account} {--clear : Remove only these demo records}';
    protected $description = 'Add removable demo reset-history records locally without changing account consumption';
    private const MARKER = 'speedbox-reset-history-preview-v1';

    public function handle(): int
    {
        if (!app()->environment('local', 'testing')) {
            $this->error('این دستور فقط در محیط local یا testing اجرا می‌شود.');
            return self::FAILURE;
        }
        if (!Schema::hasTable('v2_traffic_reset_logs')) {
            $this->error('جدول تاریخچه موجود نیست؛ ابتدا php artisan migrate را اجرا کنید.');
            return self::FAILURE;
        }
        $id = (string) ($this->argument('user_id') ?? '');
        if ($id === '') {
            $this->table(['ID', 'شناسه اکانت'], DB::table('v2_user')->orderByDesc('id')->limit(20)->get(['id', 'email'])->map(fn ($user) => [$user->id, $user->email])->all());
            $this->line('برای اکانت آزمایشی: php artisan speedbox:demo-reset-history ID');
            return self::SUCCESS;
        }
        if (!ctype_digit($id) || (int) $id < 1) {
            $this->error('ID اکانت باید عدد مثبت باشد.');
            return self::FAILURE;
        }
        $clear = (bool) $this->option('clear');
        $result = DB::transaction(function () use ($id, $clear) {
            $account = DB::table('v2_user')->where('id', (int) $id)->lockForUpdate()->first();
            if (!$account) return null;
            $removed = DB::table('v2_traffic_reset_logs')->where('user_id', $account->id)
                ->where('metadata->speedbox_demo', self::MARKER)->delete();
            if ($clear) return $removed;
            $types = [['manual', 'manual'], ['renewal', 'order'], ['periodic', 'cron'], ['monthly', 'auto'], ['manual', 'api'], ['renewal', 'order']];
            $rows = [];
            foreach ($types as $index => [$type, $source]) {
                $time = now()->subDays(6 - $index)->subMinutes($index * 17);
                $up = (int) round(($index + 1) * 0.45 * 1073741824);
                $down = (int) round(($index + 1) * 3.7 * 1073741824);
                $rows[] = [
                    'user_id' => $account->id, 'reset_type' => $type, 'reset_time' => $time,
                    'trigger_source' => $source, 'old_upload' => $up, 'old_download' => $down,
                    'old_total' => $up + $down, 'new_upload' => 0, 'new_download' => 0, 'new_total' => 0,
                    'metadata' => json_encode(['speedbox_demo' => self::MARKER, 'account_email' => $account->email, 'reason' => 'نمونه نمایشی برای تست تاریخچه'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => $time, 'updated_at' => $time,
                ];
            }
            DB::table('v2_traffic_reset_logs')->insert($rows);
            return count($rows);
        });
        if ($result === null) {
            $this->error('اکانت موردنظر پیدا نشد.');
            return self::FAILURE;
        }
        $this->info($clear ? "{$result} رکورد تستی پاک شد." : '۶ رکورد تستی اضافه شد؛ مصرف، انقضا، شمارنده بازنشانی و سوابق واقعی اکانت تغییر نکردند.');
        if (!$clear) $this->line("پاک‌سازی: php artisan speedbox:demo-reset-history {$id} --clear");
        return self::SUCCESS;
    }
}
