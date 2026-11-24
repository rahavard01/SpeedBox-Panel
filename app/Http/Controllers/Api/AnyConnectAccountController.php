<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{AccountIdentityOperations, AnyConnectService, PanelAccountOwnership};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

final class AnyConnectAccountController extends Controller
{
    public function show(Request $request, int $id, AnyConnectService $service): JsonResponse
    {
        return response()->json(['data' => $service->credentials($this->account($request, $id))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function rotate(Request $request, int $id, AnyConnectService $service): JsonResponse
    {
        $password = $request->validate(['password' => ['required', 'string', 'min:4',
            static function ($attribute, $value, $fail) {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('رمز عبور باید حداکثر ۷۲ بایت و بدون نویسهٔ تهی باشد.');
                }
            },
        ]])['password'];
        $credentials = DB::transaction(function () use ($request, $id, $service, $password) {
            $account = $this->account($request, $id, true);
            $service->rotate($account, $password);
            AccountIdentityOperations::audit($account, $request->user(), 'change_anyconnect_password', []);
            return $service->credentials($account);
        }, 3);
        return response()->json(['data' => $credentials])->header('Cache-Control', 'private, no-store');
    }

    private function account(Request $request, int $id, bool $lock = false): object
    {
        $query = DB::table('v2_user')->where('id', $id)->where('service_type', 'anyconnect');
        if ((int) $request->user()->role !== 1) {
            $code = trim((string) $request->user()->code);
            abort_if($code === '', 403);
            PanelAccountOwnership::scope($query, $code);
        }
        $account = $query->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($account, 404);
        return $account;
    }
}
