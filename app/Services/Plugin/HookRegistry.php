<?php

namespace App\Services\Plugin;

/** One registry per request/job scope, including under Octane. */
final class HookRegistry
{
    public array $actions = [];
    public array $filters = [];
}
