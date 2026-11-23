<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountIdentityOperations;
use App\Services\PanelAccountOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PartnerAccountOperationsController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        [$account, $owner] = $this->account($request, $id);

        return response()->json(['data' => $this->identity($account, $owner)])
            ->header('Cache-Control', 'no-store');
    }

    public function rename(Request $request, int $id): JsonResponse
    {
        $request->merge(['username' => is_string($request->input('username'))
            ? strtolower(trim($request->input('username'))) : $request->input('username')]);
        $name = $request->validate(['username' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9][a-z0-9_-]*$/D']])['username'];
        $changed = DB::transaction(function () use ($request, $id, $name) {
            [$account, $owner] = $this->account($request, $id, true);
            $identity = $this->identity($account, $owner);
            abort_unless($identity['can_rename'], 422, 'بخش قابل ویرایش برای این نام کاربری در دسترس نیست.');
            [$oldName, $domain] = explode('@', $account->email, 2);
            if (strtolower($oldName) === $name) return $identity;
            abort_if(AccountIdentityOperations::hasOpenBotOrders($account), 422, 'این اکانت سفارش در جریان در ربات دارد؛ ابتدا آن سفارش را تعیین تکلیف کنید.');

            $email = $name.'@'.$domain;
            $conflicts = DB::table('panel_users')->whereRaw('LOWER(TRIM(code)) = ?', [$name])->exists()
                || DB::table('v2_user')->whereRaw('LOWER(email) = ?', [strtolower($email)])->exists();
            if (strlen($email) > 64 || $conflicts) {
                throw ValidationException::withMessages(['username' => 'این نام کاربری قابل استفاده نیست.']);
            }
            try {
                DB::table('v2_user')->where('id', $id)->update(['email' => $email, 'updated_at' => time()]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['username' => 'این نام کاربری قبلاً استفاده شده است.']);
            }
            $after = DB::table('v2_user')->where('id', $id)->first();
            AccountIdentityOperations::syncBotSessions($account, $after);
            AccountIdentityOperations::audit($account, $request->user(), 'edit', [
                'changes' => ['email' => ['before' => $account->email, 'after' => $after->email]],
            ]);

            return $this->identity($after, $owner);
        }, 3);

        return response()->json(['data' => $changed, 'message' => 'نام کاربری ذخیره شد.']);
    }

    public function resetSecret(Request $request, int $id): JsonResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($id, $request) {
            [$account] = $this->account($request, $id, true);
            AccountIdentityOperations::rotate($account, $request->user());
        }, 3);

        return response()->json(['message' => 'UUID و لینک بازنشانی شد. لینک جدید را از مشاهده دریافت کنید.']);
    }

    private function account(Request $request, int $id, bool $lock = false): array
    {
        $actor = $request->user();
        abort_unless($actor && (int) $actor->role === 2, 403);
        $ownerQuery = DB::table('panel_users')->where('id', $actor->id);
        $owner = ($lock ? $ownerQuery->lockForUpdate() : $ownerQuery)->first(['id', 'role', 'code']);
        $code = strtolower(trim((string) ($owner->code ?? '')));
        abort_unless($owner && (int) $owner->role === 2 && $code !== '', 403);
        $query = DB::table('v2_user')->where('id', $id);
        $account = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($account && PanelAccountOwnership::matches($account->email, $code), 404);

        [$local, $domain] = explode('@', strtolower(trim($account->email)), 2);
        $domainCode = explode('.', $domain)[0];
        $otherOwner = DB::table('panel_users')->where('id', '!=', $owner->id)
            ->whereIn(DB::raw('LOWER(TRIM(code))'), [$local, $domainCode])->exists();
        abort_if($otherOwner, 404);

        return [$account, $owner];
    }

    private function identity(object $account, object $owner): array
    {
        [$local, $domain] = explode('@', $account->email, 2);
        $code = strtolower(trim($owner->code));
        $canRename = strtolower($domain) === $code.'.com' && strtolower($local) !== $code;

        return ['id' => $account->id, 'email' => $account->email,
            'username' => $local, 'suffix' => '-'.explode('.', $domain)[0],
            'can_rename' => $canRename, 'service_type' => $account->service_type ?? 'v2ray'];
    }
}
