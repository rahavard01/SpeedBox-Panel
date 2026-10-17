<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(\App\Services\Plugin\HookRegistry::class);
        $this->app->singleton(\App\Services\QueueMonitor::class);
        $this->app->scoped('protocols.manager', fn ($app) => (new \App\Support\ProtocolManager($app))->registerAllProtocols());
    }

    public function boot(): void
    {
        $monitor = fn () => app(\App\Services\QueueMonitor::class);
        \Illuminate\Support\Facades\Queue::looping(fn ($event) => $monitor()->looping($event));
        \Illuminate\Support\Facades\Queue::before(fn ($event) => $monitor()->starting($event));
        \Illuminate\Support\Facades\Queue::after(fn ($event) => $monitor()->finished($event));
        \Illuminate\Support\Facades\Queue::failing(fn ($event) => $monitor()->finished($event, true));
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\WorkerStopping::class, fn () => $monitor()->stopping());

    }
}


