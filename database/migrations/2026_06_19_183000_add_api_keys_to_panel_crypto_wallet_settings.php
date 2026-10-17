<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_crypto_wallet_settings', function (Blueprint $table) {
            $table->text('trongrid_api_key')->nullable()->after('wallet_address');
            $table->text('bscscan_api_key')->nullable()->after('trongrid_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('panel_crypto_wallet_settings', function (Blueprint $table) {
            $table->dropColumn(['trongrid_api_key', 'bscscan_api_key']);
        });
    }
};