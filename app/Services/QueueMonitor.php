<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Dashboard telemetry never stores queue payloads or SSH credentials. */
class QueueMonitor
{
    private array $started = [];
    private ?bool $available = null;
    private int $lastPruned = 0;

    private function available(): bool
    {
        return $this->available ??= Schema::hasTable('speedbox_queue_activity') && Schema::hasTable('speedbox_queue_workers');
    }

    private function safely(callable $action): void
    {
        try {
            if ($this->available()) {
                $action();
            }
        } catch (Throwable $e) {
            report($e); // Monitoring must never interrupt the queue's actual work.
        }
    }

    private function workerId(): string
    {
        return hash('sha256', (gethostname() ?: 'worker').'|'.getmypid());
    }

    private function touchWorker(string $connection, string $queues, ?string $busy = null): void
    {
        DB::table('speedbox_queue_workers')->updateOrInsert(['id' => $this->workerId()], [
            'connection' => $connection, 'queues' => substr($queues, 0, 255),
            'last_seen_at' => now(), 'busy_job' => $busy,
        ]);
    }

    public function looping(object $event): void
    {
        $this->safely(function () use ($event) {
            $this->touchWorker($event->connectionName, (string) $event->queue);
            if (time() - $this->lastPruned >= 3600) {
                $this->lastPruned = time();
                DB::table('speedbox_queue_activity')->where('started_at', '<', now()->subDays(7))->delete();
                DB::table('speedbox_queue_workers')->where('last_seen_at', '<', now()->subDay())->delete();
            }
        });
    }

    public function starting(object $event): void
    {
        $this->safely(function () use ($event) {
            $job = $event->job;
            $id = (string) ($job->getJobId() ?: uniqid('inline-', true));
            $key = hash('sha256', $event->connectionName.'|'.$job->getQueue().'|'.$id);
            $this->started[spl_object_id($job)] = ['key' => $key, 'time' => microtime(true)];
            DB::table('speedbox_queue_activity')->updateOrInsert(['id' => $key], [
                'job_id' => substr($id, 0, 190), 'connection' => $event->connectionName,
                'queue' => substr((string) $job->getQueue(), 0, 100),
                'name' => substr($job->resolveName(), 0, 255), 'status' => 'running',
                'attempts' => $job->attempts(), 'started_at' => now(), 'finished_at' => null,
                'duration' => null, 'exception_class' => null,
            ]);
            $this->touchWorker($event->connectionName, (string) $job->getQueue(), $key);
        });
    }

    public function finished(object $event, bool $failed = false): void
    {
        $this->safely(function () use ($event, $failed) {
            $entry = $this->started[spl_object_id($event->job)] ?? null;
            if ($entry) {
                $status = $failed ? 'failed' : ($event->job->isReleased() ? 'released' : 'succeeded');
                DB::table('speedbox_queue_activity')->where('id', $entry['key'])->update([
                    'status' => $status, 'finished_at' => now(),
                    'duration' => round(microtime(true) - $entry['time'], 3),
                    'exception_class' => $failed ? get_class($event->exception) : null,
                ]);
                unset($this->started[spl_object_id($event->job)]);
            }
            $this->touchWorker($event->connectionName, (string) $event->job->getQueue());
        });
    }

    public function stopping(): void
    {
        $this->safely(fn () => DB::table('speedbox_queue_workers')->where('id', $this->workerId())->delete());
    }

    public function snapshot(): array
    {
        $connection = (string) config('queue.default');
        $options = config('queue.connections.'.$connection, []);
        $driver = $options['driver'] ?? $connection;
        $ready = $this->available();
        $activity = DB::table('speedbox_queue_activity');
        $workers = $ready ? DB::table('speedbox_queue_workers')->get()->filter(function ($worker) {
            if (config('queue.connections.'.$worker->connection.'.driver') === 'sync') {
                return false;
            }
            if (strtotime($worker->last_seen_at) >= time() - 120) {
                return true;
            }
            return $worker->busy_job && DB::table('speedbox_queue_activity')->where('id', $worker->busy_job)
                ->where('status', 'running')->where('started_at', '>=', now()->subMinutes(25))->exists();
        })->values() : collect();
        $names = array_unique(['default', 'deployments', (string) ($options['queue'] ?? 'default')]);
        $workload = [];
        if ($driver === 'database') {
            $db = DB::connection($options['connection'] ?? null);
            $table = $options['table'] ?? 'jobs';
            if (Schema::connection($options['connection'] ?? null)->hasTable($table)) {
                $names = array_unique([...$names, ...$db->table($table)->distinct()->pluck('queue')->all()]);
                foreach ($names as $name) {
                    $base = $db->table($table)->where('queue', $name);
                    $waiting = (clone $base)->whereNull('reserved_at')->where('available_at', '<=', time());
                    $oldest = (clone $waiting)->min('created_at');
                    $workload[] = ['name' => $name, 'pending' => (clone $waiting)->count(),
                        'running' => (clone $base)->whereNotNull('reserved_at')->count(),
                        'delayed' => (clone $base)->whereNull('reserved_at')->where('available_at', '>', time())->count(),
                        'wait' => $oldest ? max(0, time() - $oldest) : 0];
                }
            }
        } elseif (!in_array($driver, ['sync', 'null'], true)) {
            foreach ($names as $name) {
                $workload[] = ['name' => $name, 'pending' => Queue::connection($connection)->size($name),
                    'running' => null, 'delayed' => null, 'wait' => null];
            }
        }
        $failedDb = config('queue.failed.database');
        $failedTable = config('queue.failed.table', 'failed_jobs');
        $failed = Schema::connection($failedDb)->hasTable($failedTable)
            ? DB::connection($failedDb)->table($failedTable)->where('failed_at', '>=', now()->subDays(7))->count()
            : ($ready ? (clone $activity)->where('status', 'failed')->where('finished_at', '>=', now()->subDays(7))->count() : null);
        $longest = $ready ? (clone $activity)->where('finished_at', '>=', now()->subDay())->orderByDesc('duration')->first() : null;
        $peak = $ready ? (clone $activity)->where('status', 'succeeded')->where('finished_at', '>=', now()->subHour())->pluck('finished_at')->groupBy(fn ($timestamp) => substr($timestamp, 0, 16))->map->count()->max() : null;
        $activeQueues = $workers->flatMap(fn ($worker) => explode(',', $worker->queues))->unique()->values();
        return [
            'driver' => $driver, 'monitoring' => $ready,
            'status' => $driver === 'sync' ? 'inline' : ($workers->isNotEmpty() ? 'running' : 'not_seen'),
            'processes' => $workers->count(), 'active_queues' => $activeQueues,
            'recentJobs' => $ready ? (clone $activity)->where('finished_at', '>=', now()->subHour())->whereIn('status', ['succeeded', 'failed'])->count() : null,
            'jobsPerMinute' => $ready ? (clone $activity)->where('finished_at', '>=', now()->subMinute())->where('status', 'succeeded')->count() : null,
            'peakJobsPerMinute' => $peak, 'failedJobs' => $failed, 'periods' => ['recentJobs' => 1, 'failedJobs' => 168],
            'queueWithMaxRuntime' => $longest ? ['name' => $longest->queue, 'runtime' => (float) $longest->duration] : null,
            'wait' => collect($workload)->max('wait'), 'workload' => $workload,
            'recent' => $ready ? (clone $activity)->orderByDesc('started_at')->limit(20)->get(['id', 'job_id', 'queue', 'name', 'status', 'attempts', 'started_at', 'finished_at', 'duration', 'exception_class']) : [],
        ];
    }

    public function failedJobs(): array
    {
        $database = config('queue.failed.database');
        $table = config('queue.failed.table', 'failed_jobs');
        if (Schema::connection($database)->hasTable($table)) {
            return DB::connection($database)->table($table)->where('failed_at', '>=', now()->subDays(7))
                ->orderByDesc('failed_at')->limit(50)->get()->map(function ($job) {
                    $payload = json_decode($job->payload, true) ?: [];
                    preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)[: ]/', $job->exception, $exception);
                    return ['id' => (string) $job->id, 'job_id' => $job->uuid ?? (string) $job->id,
                        'name' => $payload['displayName'] ?? 'عملیات صف', 'queue' => $job->queue, 'status' => 'failed',
                        'started_at' => null, 'finished_at' => $job->failed_at, 'attempts' => null,
                        'duration' => null, 'exception_class' => $exception[1] ?? null];
                })->all();
        }
        if (!$this->available()) {
            return [];
        }
        return DB::table('speedbox_queue_activity')->where('status', 'failed')->where('finished_at', '>=', now()->subDays(7))
            ->orderByDesc('finished_at')->limit(50)->get(['id', 'job_id', 'queue', 'name', 'status', 'attempts', 'started_at', 'finished_at', 'duration', 'exception_class'])->all();
    }
}
