<?php

namespace App\Console\Commands;

use App\Services\LocalDemoData;
use Illuminate\Console\Command;

final class RefreshSpeedboxReviewDemo extends Command
{
    protected $signature = 'speedbox:demo-review';
    protected $description = 'Refresh existing local financial previews and add the short announcement sample';

    public function handle(LocalDemoData $demo): int
    {
        try {
            $demo->refreshReviewPreviews();
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
        $this->info('نمونهٔ «اطلاعیه» و پیش‌نمایش تراکنش‌های این مرحله آماده‌اند.');
        return self::SUCCESS;
    }
}
