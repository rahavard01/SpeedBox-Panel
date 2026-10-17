<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

use App\Http\Kernel as HttpKernel;
use App\Console\Kernel as ConsoleKernel;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Preserve Laravel 12's maintenance, sessions, CSRF and binding defaults.
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustProxies::class);
        $middleware->append(\App\Http\Middleware\ApplyRuntimeSettings::class);
        $middleware->throttleApi('api');
        $middleware->redirectGuestsTo('/login'); // SPA login route has no Laravel 'login' name.
        $middleware->alias([
            'token.expires' => \App\Http\Middleware\CheckTokenExpiration::class,
            'user.banned' => \App\Http\Middleware\CheckUserBanned::class,
            'speedbox.node' => \App\Http\Middleware\AuthenticateNode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();

// ✅ فقط این دو بایند لازمند
$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    HttpKernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    ConsoleKernel::class
);

return $app;
