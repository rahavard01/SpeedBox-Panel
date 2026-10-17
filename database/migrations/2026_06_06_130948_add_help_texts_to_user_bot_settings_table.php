<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->longText('help_ios')->nullable()->after('card_description');
            $table->longText('help_android')->nullable()->after('help_ios');
            $table->longText('help_windows')->nullable()->after('help_android');
        });
    }

    public function down(): void
    {
        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->dropColumn(['help_ios', 'help_android', 'help_windows']);
        });
    }
};