<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Services\AnnouncementDueRunner;
use App\Services\V2UserExpiryRunner;  // ← ایمپورت درست است
use App\Services\BotPaymentMessageCleanupRunner;
use App\Services\ReceiptAiAutoApproveRunner;
use App\Services\UserBot\UserBotCardProcessingRecoveryRunner;
use App\Services\UserBot\UserBotExpiryWarningRunner;
use App\Services\UserBot\UserBotTrafficWarningRunner;
use App\Services\UserBot\UserBotTelegramDeliveryRetryRunner;
use App\Services\UserBot\UserBotSmsEventCleanupRunner;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => \Illuminate\Support\Facades\DB::table('speedbox_connection_leases')->where('expires_at', '<=', time())->delete())
            ->everyMinute()->name('connections:expired-leases')->withoutOverlapping();
        $schedule->call(fn () => app(\App\Services\SubscriptionTrafficReset::class)->tick())
            ->everyMinute()->name('subscription:traffic-reset')->withoutOverlapping();
        // هر دقیقه اعلان‌هایی که موعدشان رسیده را چک و ارسال کن
        $schedule->call(function () {
            app(AnnouncementDueRunner::class)->tick();
        })
        ->everyMinute()
        ->name('announcements:due-tick')
        ->evenInMaintenanceMode();

        // ←← جاب جدید: ست‌کردن expired_at برای v2_user وقتی t مقدار گرفت
        $schedule->call(function () {
            app(V2UserExpiryRunner::class)->tick();
        })
        ->everyMinute()                 // هم‌راستا با بالا؛ throttle داخل سرویس فعال است
        ->name('v2user:expiry-tick')
        ->evenInMaintenanceMode();
        // ->onOneServer();  // اگر چند سرور داری و cache/lock توزیع‌شده داری، می‌تونی این را هم اضافه کنی

        $schedule->call(function () {
            app(BotPaymentMessageCleanupRunner::class)->tick();
        })
        ->everyMinute()
        ->name('bot-payment-message-cleanup')
        ->evenInMaintenanceMode();

        $schedule->call(function () {
            app(
                UserBotCardProcessingRecoveryRunner::class
            )->tick();
        })
        ->everyMinute()
        ->name('userbot-card-processing-recovery')
        ->withoutOverlapping()
        ->evenInMaintenanceMode();

        $schedule->call(function () {
            app(
                UserBotTelegramDeliveryRetryRunner::class
            )->tick();
        })
        ->everyMinute()
        ->name(
            'userbot-telegram-delivery-retry'
        )
        ->withoutOverlapping()
        ->evenInMaintenanceMode();

        $schedule->call(function () {
            app(
                UserBotExpiryWarningRunner::class
            )->tick();
        })
        ->everyFiveMinutes()
        ->name('userbot-expiry-warning')
        ->withoutOverlapping()
        ->evenInMaintenanceMode();

        $schedule->call(fn () => app(UserBotTrafficWarningRunner::class)->tick())
            ->everyFiveMinutes()->name('userbot-traffic-warning')
            ->withoutOverlapping()->evenInMaintenanceMode();

        $schedule->call(function () {
            app(
                UserBotSmsEventCleanupRunner::class
            )->tick();
        })
        ->dailyAt('03:20')
        ->name(
            'userbot-sms-event-cleanup'
        )
        ->withoutOverlapping()
        ->evenInMaintenanceMode();

        $schedule->command('crypto:verify-topups')->everyFiveMinutes()->withoutOverlapping();

        $schedule->command('userbot:verify-crypto-orders')->everyFiveMinutes()->withoutOverlapping();

        $schedule->call(function () {
            app(ReceiptAiAutoApproveRunner::class)->tick();
        })
        ->everyMinute()
        ->name('receipt-ai-auto-approve')
        ->withoutOverlapping()
        ->evenInMaintenanceMode();

    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}


