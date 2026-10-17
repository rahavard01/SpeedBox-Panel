<?php

namespace App\Http\Middleware;

use App\Models\ProxyServer;
use App\Models\ServerMachine;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateNode
{
    public function handle(Request $request, Closure $next): Response
    {
        $machineId = (int) $request->input('machine_id', 0);
        $nodeId = (int) $request->input('node_id', 0);
        $token = (string) $request->input('token', '');

        if ($machineId > 0) {
            $machine = ServerMachine::query()->whereKey($machineId)->where('is_active', true)->first();
            if (!$machine || $token === '' || !hash_equals((string) $machine->token, $token)) {
                return response()->json(['message' => 'Machine authentication failed.'], 401);
            }
            $machine->forceFill(['last_seen_at' => time()])->saveQuietly();
            $request->attributes->set('speedbox_machine', $machine);

            if ($nodeId > 0) {
                $node = ProxyServer::query()->whereKey($nodeId)->where('machine_id', $machineId)->where('enabled', true)->first();
                if (!$node) {
                    return response()->json(['message' => 'Node is not assigned to this machine.'], 404);
                }
                $request->attributes->set('speedbox_node', $node);
            }
        } else {
            $expected = (string) \App\Services\RuntimeSetting::get('server_token', config('speedbox.server_token'));
            if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
                return response()->json(['message' => 'Node authentication failed.'], 401);
            }
            if ($nodeId > 0) {
                $node = ProxyServer::query()->whereKey($nodeId)->where('enabled', true)->first();
                if (!$node) {
                    return response()->json(['message' => 'Node was not found.'], 404);
                }
                $request->attributes->set('speedbox_node', $node);
            }
        }

        return $next($request);
    }
}

