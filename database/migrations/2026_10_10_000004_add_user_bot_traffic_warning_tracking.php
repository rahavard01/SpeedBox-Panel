<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'traffic_warning_version')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->unsignedBigInteger('traffic_warning_version')->default(0);
            });
        }
        if (!Schema::hasColumn('user_bot_sessions', 'traffic_warning_for')) {
            Schema::table('user_bot_sessions', function (Blueprint $table) {
                $table->char('traffic_warning_for', 64)->nullable();
                $table->timestamp('traffic_warning_sent_at')->nullable();
            });
        }
        if (!Schema::hasColumn('user_bot_sessions', 'expiry_warning_account_id')) {
            Schema::table('user_bot_sessions', function (Blueprint $table) {
                $table->unsignedBigInteger('expiry_warning_account_id')->nullable();
            });
        }
        // Preserve deduplication for expiry messages already delivered before this migration.
        DB::table('user_bot_sessions')->whereNotNull('expiry_warning_for')
            ->whereNull('expiry_warning_account_id')->orderBy('id')->chunkById(200, function ($sessions) {
                foreach ($sessions as $session) {
                    $id = DB::table('v2_user')->where('email', $session->account_email)
                        ->where('expired_at', $session->expiry_warning_for)->value('id');
                    if ($id) DB::table('user_bot_sessions')->where('id', $session->id)
                        ->update(['expiry_warning_account_id' => $id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('user_bot_sessions', function (Blueprint $table) {
            $table->dropColumn(['traffic_warning_for', 'traffic_warning_sent_at', 'expiry_warning_account_id']);
        });
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn('traffic_warning_version');
        });
    }
};
