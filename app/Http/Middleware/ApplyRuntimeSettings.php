<?php

namespace App\Http\Middleware;

use App\Services\RuntimeSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class ApplyRuntimeSettings
{
    public function handle(Request $request, Closure $next)
    {
        $https = (bool) RuntimeSetting::get('force_https', app()->environment('production', 'staging'));
        URL::forceScheme($https ? 'https' : null);
        return $next($request);
    }
}
