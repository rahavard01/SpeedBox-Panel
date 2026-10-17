<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->boolean('force_channel_join_enabled')->default(false)->after('support_username');
            $table->string('force_channel_join_chat', 160)->nullable()->after('force_channel_join_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->dropColumn(['force_channel_join_enabled', 'force_channel_join_chat']);
        });
    }
};