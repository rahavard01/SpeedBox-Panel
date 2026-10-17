<?php

namespace App\Http\Middleware;

use App\Models\ProxyServer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/** Lease traffic scales with active account/IP pairs, rather than node polling. */
final class AuthenticateConnectionLease extends AuthenticateNode
{
    public function handle(Request $request, Closure $next): Response
    {
        // Authenticate the machine token AND its enabled, assigned node first.
        // Never exempt a request based on untrusted machine_id/node_id alone.
        $response = parent::handle($request, $next);
        if ($request->attributes->get('speedbox_node') instanceof ProxyServer && $response->isSuccessful()) {
            return $response;
        }

        // Invalid credentials, missing nodes and malformed payloads still consume
        // both existing node/IP buckets. Even an exhausted invalid-request bucket
        // must not prevent a subsequent authenticated lease from renewing.
        return app(ThrottleRequests::class)->handle($request, static fn () => $response, 'api');
    }
}
