<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_crypto_topups', function (Blueprint $table) {
            $table->unsignedBigInteger('bsc_start_block')->nullable()->after('tx_hash');
            $table->unsignedBigInteger('bsc_last_checked_block')->nullable()->after('bsc_start_block');
        });
    }

    public function down(): void
    {
        Schema::table('panel_crypto_topups', function (Blueprint $table) {
            $table->dropColumn(['bsc_start_block', 'bsc_last_checked_block']);
        });
    }
};