<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/dashboard';

    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if ($request->is('api/v2/server/*')) {
                // Distinct nodes behind the same NAT do not consume one tiny bucket.
                // The second ceiling still bounds attempts with rotating fake IDs.
                $node = $request->integer('machine_id').':'.$request->integer('node_id');
                return [
                    Limit::perMinute(config('speedbox.node_rate_limit', 1200))->by('node:'.$request->ip().':'.$node),
                    Limit::perMinute(config('speedbox.node_ip_rate_limit', 12000))->by('node-ip:'.$request->ip()),
                ];
            }
            if ($request->is('api/telegram/webhook', 'api/user-bot/webhook/*')) {
                return Limit::perMinute(config('speedbox.webhook_rate_limit', 600))->by('webhook:'.$request->ip());
            }
            $key = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();
            return Limit::perMinute(config('speedbox.api_rate_limit', 60))->by('api:'.$key);
        });
    }
}
