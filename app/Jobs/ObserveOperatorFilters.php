<?php

namespace App\Jobs;

use App\Services\OperatorFilterObservations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ObserveOperatorFilters implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 120;
    public int $timeout = 60;
    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('deployments');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('operator-firewall'))->shared()->dontRelease()->expireAfter(100)];
    }

    public function handle(OperatorFilterObservations $observations): void
    {
        $observations->refresh();
    }
}
