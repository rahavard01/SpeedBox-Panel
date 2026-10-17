<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
use Closure;

class TrustProxies extends Middleware
{
    /**
     * Only trust the local reverse proxy by default, not arbitrary Internet peers.
     */
    protected $proxies = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next)
    {
        // Resolve after configuration is loaded, including on Octane requests.
        $configured = config('speedbox.trusted_proxies', ['127.0.0.1', '::1']);
        $this->proxies = is_array($configured) ? $configured : array_values(array_filter(array_map('trim', explode(',', (string) $configured))));
        return parent::handle($request, $next);
    }

    /**
     * Use all X-Forwarded-* headers (proto/host/port/for)
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO;
}
