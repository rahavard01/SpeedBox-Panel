<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('panel_users', 'partner_bot_has_logged_in')) {
            Schema::table('panel_users', function (Blueprint $table) {
                $table->boolean('partner_bot_has_logged_in')->default(false);
            });
        }

        DB::table('panel_users')
            ->where('role', 2)
            ->where(function ($users) {
                $users->whereNotNull('telegram_user_id')
                    ->orWhereIn('id', DB::table('panel_bot_sessions')->select('panel_user_id')->whereNotNull('panel_user_id'));
            })
            ->update(['partner_bot_has_logged_in' => true]);
    }

    public function down(): void
    {
        Schema::table('panel_users', function (Blueprint $table) {
            $table->dropColumn('partner_bot_has_logged_in');
        });
    }
};
