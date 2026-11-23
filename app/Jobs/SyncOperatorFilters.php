<?php

namespace App\Jobs;

use App\Services\OperatorFilters;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncOperatorFilters implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 300;
    public int $timeout = 80;
    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('deployments');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('operator-firewall'))->shared()->dontRelease()->expireAfter(100)];
    }

    public function handle(OperatorFilters $filters): void
    {
        $filters->sync();
    }
}
