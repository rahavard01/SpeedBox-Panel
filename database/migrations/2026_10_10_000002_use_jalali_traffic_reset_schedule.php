<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Only reschedule. Never change consumption, quota, expiry or reset history.
        DB::table('v2_user')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                DB::table('v2_user')->where('id', $user->id)->update([
                    'next_reset_at' => app(\App\Services\SubscriptionTrafficReset::class)->next($user),
                ]);
            }
        });
    }
    public function down(): void {}
};
