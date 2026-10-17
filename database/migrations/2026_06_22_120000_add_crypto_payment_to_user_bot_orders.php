<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->boolean('card_payment_enabled')->default(true)->after('is_enabled');
            $table->boolean('crypto_payment_enabled')->default(false)->after('card_payment_enabled');
            $table->string('crypto_asset', 20)->default('USDT')->after('crypto_payment_enabled');
            $table->string('crypto_network', 20)->default('TRC20')->after('crypto_asset');
            $table->string('crypto_wallet_address')->nullable()->after('crypto_network');
            $table->string('crypto_trongrid_api_key', 500)->nullable()->after('crypto_wallet_address');
            $table->string('crypto_bscscan_api_key', 500)->nullable()->after('crypto_trongrid_api_key');
        });

        Schema::table('user_bot_orders', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('amount');
            $table->string('crypto_asset', 20)->nullable()->after('payment_method');
            $table->string('crypto_network', 20)->nullable()->after('crypto_asset');
            $table->string('crypto_wallet_address')->nullable()->after('crypto_network');
            $table->decimal('crypto_amount', 18, 6)->nullable()->after('crypto_wallet_address');
            $table->decimal('crypto_base_amount', 18, 6)->nullable()->after('crypto_amount');
            $table->decimal('crypto_unique_extra', 18, 8)->nullable()->after('crypto_base_amount');
            $table->string('crypto_tx_hash')->nullable()->after('crypto_unique_extra');
            $table->timestamp('crypto_expires_at')->nullable()->after('crypto_tx_hash');
            $table->timestamp('crypto_paid_at')->nullable()->after('crypto_expires_at');
            $table->json('crypto_meta')->nullable()->after('crypto_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_bot_orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'crypto_asset',
                'crypto_network',
                'crypto_wallet_address',
                'crypto_amount',
                'crypto_base_amount',
                'crypto_unique_extra',
                'crypto_tx_hash',
                'crypto_expires_at',
                'crypto_paid_at',
                'crypto_meta',
            ]);
        });

        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'card_payment_enabled',
                'crypto_payment_enabled',
                'crypto_asset',
                'crypto_network',
                'crypto_wallet_address',
                'crypto_trongrid_api_key',
                'crypto_bscscan_api_key',
            ]);
        });
    }
};