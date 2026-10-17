<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AnnouncementDueRunner
{
    /** هر چند ثانیه یک‌بار اجازه اجرا بدهیم (برای سبک نگه داشتن اجراها) */
    protected int $throttleSeconds = 30;

    /** حداکثر تعداد اعلانی که در هر تیک بررسی/ارسال می‌کنیم */
    protected int $batchLimit = 20;

    /** کلیدهای کمکی */
    protected string $throttleKey = 'announcements:due:last_run';
    protected string $lockName    = 'announcements_broadcast_due_lock';

    public function tick(): void
    {
        // لاک سراسری (DB-level) تا اجراهای همزمان با هم تداخل نکنند
        if (!$this->acquireDbLock($this->lockName)) {
            return;
        }

        try {
            // Throttle سبک؛ برای Scheduler هم بی‌ضرر است
            $now = now();
            $lastRun = cache()->get($this->throttleKey);
            if ($lastRun && $now->diffInSeconds(Carbon::parse($lastRun)) < $this->throttleSeconds) {
                return;
            }

            // اعلان‌های موعدرسیده و ارسال‌نشده را بگیر
            $dueIds = $this->findDueAnnouncementIds($this->batchLimit);

            foreach ($dueIds as $aid) {
                $this->broadcastOne((int) $aid);
                cache()->put('announcements:due:cursor', (int) $aid, 3600);
            }

            cache()->put($this->throttleKey, $now->toDateTimeString(), $this->throttleSeconds);
        } finally {
            $this->releaseDbLock($this->lockName);
        }
    }

    /** شناسهٔ اعلان‌های موعدرسیده و آمادهٔ ارسال */
    protected function findDueAnnouncementIds(int $limit): array
    {
        $now = now();

        $query = DB::table('panel_announcements as a')
            // باید منتشر باشد
            ->where('a.is_published', true)
            // اگر زمان انتشار دارد باید رسیده باشد، یا اصلاً زمان انتشار ندارد (منتشر فوری)
            ->where(function ($q) use ($now) {
                $q->whereNull('a.publish_at')
                  ->orWhere('a.publish_at', '<=', $now);
            })
            // هنوز منقضی نشده
            ->where(function ($q) use ($now) {
                $q->whereNull('a.expires_at')
                  ->orWhere('a.expires_at', '>', $now);
            })
            // هنوز به تلگرام ارسال نشده
            ->whereNull('a.tg_broadcasted_at');
        // Retry failed recipients without starving newer notices behind old failures.
        $cursor = (int) cache()->get('announcements:due:cursor', 0);
        $ids = (clone $query)->where('a.id', '>', $cursor)->orderBy('a.id')->limit($limit)->pluck('a.id')->all();
        if (count($ids) < $limit && $cursor > 0) {
            $ids = array_merge($ids, (clone $query)->where('a.id', '<=', $cursor)->orderBy('a.id')
                ->limit($limit - count($ids))->pluck('a.id')->all());
        }
        return $ids;
    }

    /** ارسال اعلان با ثبت موفقیت هر گیرنده و امکان تلاش مجدد */
    protected function broadcastOne(int $announcementId): void
    {
        // The notifier records success per recipient and only completes the
        // broadcast after every required delivery succeeds.
        try {
            app(TelegramNotifier::class)->broadcastAnnouncement($announcementId);
        } catch (\Throwable $error) {
            Log::warning('Announcement broadcast interrupted.', ['announcement_id' => $announcementId]);
        }
    }

    /** گرفتن لاک سراسری با GET_LOCK (بدون نیاز به Redis/Memcached) */
    protected function acquireDbLock(string $name): bool
    {
        try {
            $row = DB::select("SELECT GET_LOCK(?, 0) AS l", [$name]);
            $got = $row[0]->l ?? 0;
            return (int) $got === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** آزاد کردن لاک */
    protected function releaseDbLock(string $name): void
    {
        try {
            DB::select("SELECT RELEASE_LOCK(?)", [$name]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
