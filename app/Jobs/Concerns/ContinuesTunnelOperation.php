<?php

namespace App\Jobs\Concerns;

/** Publish a checkpoint's next turn only after its operation lock is released. */
trait ContinuesTunnelOperation
{
    private bool $deferContinuation = false;
    private ?int $continuationDelay = null;

    private function continueOperation(int $delay = 0): void
    {
        if ($this->deferContinuation) {
            $this->continuationDelay = $delay;
            return;
        }
        self::dispatch($this->operationId)->delay(now()->addSeconds($delay));
    }

    private function flushContinuation(): void
    {
        $this->deferContinuation = false;
        $delay = $this->continuationDelay;
        $this->continuationDelay = null;
        if ($delay !== null) $this->continueOperation($delay);
    }
}
