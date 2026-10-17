<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_payment_methods', function (Blueprint $table) {
            $table->boolean('ai_auto_approve_enabled')->default(false);
            $table->text('gemini_api_key')->nullable();
        });

        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->boolean('card_ai_auto_approve_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('panel_payment_methods', function (Blueprint $table) {
            $table->dropColumn([
                'ai_auto_approve_enabled',
                'gemini_api_key',
            ]);
        });

        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'card_ai_auto_approve_enabled',
            ]);
        });
    }
};