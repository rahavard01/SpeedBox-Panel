<?php

namespace App\Console\Commands;

use App\Services\LocalDemoData;
use Illuminate\Console\Command;

final class SeedSpeedboxAllDemo extends Command
{
    protected $signature = 'speedbox:demo-all {--partner=testrep : Representative code or panel user ID} {--clear : Remove unchanged demo data}';
    protected $description = 'Prepare repeatable local previews for the main, representative and infrastructure panels';

    public function handle(): int
    {
        if (!app()->environment('local', 'testing')) {
            $this->error('این دستور فقط در محیط local یا testing اجرا می‌شود.');
            return self::FAILURE;
        }
        try {
            $result = app(LocalDemoData::class)->run((string) $this->option('partner'), (bool) $this->option('clear'));
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
        if ($this->option('clear')) {
            $this->info('داده‌های آزمایشیِ بدون تغییر پاک شدند؛ اطلاعات قبلی حفظ شدند.');
            return self::SUCCESS;
        }
        $this->info($result['existing'] ? 'نمونه‌ها قبلاً ساخته شده‌اند؛ داده تکراری اضافه نشد.' : 'داده‌های نمایشی همه بخش‌ها آماده‌اند.');
        $this->table(['بخش', 'رکورد نمونه'], $result['coverage']);
        $this->line('نماینده: '.$result['partner']);
        foreach ($result['credentials'] as $credential) $this->line($credential);
        $this->table(['اکانت', 'دستگاه آنلاین / سقف', 'صفحه مرورگری سابسکرایب'], $result['accounts']);
        $this->line('برای نمایش فایل‌های نمونه: php artisan storage:link');
        $this->line('پاک‌سازی: php artisan speedbox:demo-all --clear');
        $this->line('نمونه‌ها محلی‌اند؛ هیچ اتصال SSH، پیام تلگرام، پرداخت یا کار اجرایی صف ارسال نشده است.');
        return self::SUCCESS;
    }
}
