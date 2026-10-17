<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_crypto_topups', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('paid_at');
            $table->timestamp('telegram_notified_at')->nullable()->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('panel_crypto_topups', function (Blueprint $table) {
            $table->dropColumn(['notified_at', 'telegram_notified_at']);
        });
    }
};