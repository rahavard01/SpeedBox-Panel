<?php

namespace App\Services\UserBot;

use App\Models\UserBotSmsEvent;

class UserBotSmsEventCleanupRunner
{
    private const RETENTION_DAYS = 30;
    private const BATCH_SIZE = 500;
    private const MAX_BATCHES_PER_RUN = 10;

    public function tick(): int
    {
        $cutoff = now()->subDays(
            self::RETENTION_DAYS
        );

        $deleted = 0;

        for (
            $batch = 0;
            $batch < self::MAX_BATCHES_PER_RUN;
            $batch++
        ) {
            $ids = UserBotSmsEvent::query()
                ->where(
                    'created_at',
                    '<',
                    $cutoff
                )
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += UserBotSmsEvent::query()
                ->whereIn(
                    'id',
                    $ids->all()
                )
                ->delete();

            if (
                $ids->count()
                < self::BATCH_SIZE
            ) {
                break;
            }
        }

        return $deleted;
    }
}