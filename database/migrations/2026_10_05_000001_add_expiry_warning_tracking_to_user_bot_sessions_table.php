<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bot_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('expiry_warning_for')
                ->nullable()
                ->after('account_snapshot');

            $table->timestamp('expiry_warning_sent_at')
                ->nullable()
                ->after('expiry_warning_for');

            $table->index(
                ['is_logged_in', 'expiry_warning_for'],
                'user_bot_sessions_expiry_warning_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_bot_sessions', function (Blueprint $table) {
            $table->dropIndex('user_bot_sessions_expiry_warning_index');
            $table->dropColumn([
                'expiry_warning_for',
                'expiry_warning_sent_at',
            ]);
        });
    }
};
