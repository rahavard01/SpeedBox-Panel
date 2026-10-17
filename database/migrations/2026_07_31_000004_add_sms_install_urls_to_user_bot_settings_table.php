<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->text('card_sms_shortcut_url')
                ->nullable()
                ->after('card_sms_auto_approve_enabled');

            $table->text('card_sms_android_app_url')
                ->nullable()
                ->after('card_sms_shortcut_url');
        });
    }

    public function down(): void
    {
        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'card_sms_shortcut_url',
                'card_sms_android_app_url',
            ]);
        });
    }
};