<?php

namespace App\Jobs;

use App\Services\AnyConnectService;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Queue\Queueable;

final class PollAnyConnectOnline implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 25;
    public int $tries = 1;
    public int $uniqueFor = 60;

    public function __construct(public readonly int $instanceId) {}

    public function uniqueId(): string
    {
        return (string) $this->instanceId;
    }

    public function handle(AnyConnectService $service): void
    {
        $service->refreshOnline($this->instanceId);
    }
}
