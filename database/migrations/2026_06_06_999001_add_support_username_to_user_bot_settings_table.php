<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->string('support_username', 160)->nullable()->after('card_description');
        });
    }

    public function down(): void
    {
        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->dropColumn('support_username');
        });
    }
};