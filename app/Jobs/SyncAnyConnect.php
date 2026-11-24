<?php

namespace App\Jobs;

use App\Services\AnyConnectService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncAnyConnect implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 1200;
    public int $tries = 1;
    public int $uniqueFor = 1230;

    public function __construct(public readonly int $instanceId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->instanceId;
    }

    public function failed(\Throwable $error): void
    {
        \App\Models\VpnOperation::failUnfinished('anyconnect', $this->instanceId, '*', 'اجرای عملیات در صف متوقف شد.');
        $instance = \App\Models\AnyConnectInstance::find($this->instanceId);
        if ($instance && !$instance->last_error && in_array($instance->status, ['queued', 'stopping', 'deleting'], true)) {
            $instance->update([
                'status' => $instance->status === 'queued' ? 'failed' : $instance->status,
                'last_error' => 'اجرای عملیات در صف متوقف شد.',
            ]);
        }
    }

    public function handle(AnyConnectService $service): void
    {
        $service->sync($this->instanceId);
    }
}
