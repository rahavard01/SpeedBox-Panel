<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('method', 24)->unique(); // card | crypto
            $table->boolean('enabled')->default(true);
            $table->string('access_mode', 24)->default('all'); // all | selected
            $table->json('partner_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('panel_crypto_wallet_settings', function (Blueprint $table) {
            $table->id();
            $table->string('asset', 16)->default('USDT'); // USDT | TRX
            $table->string('network', 16)->default('TRC20'); // TRC20 | BEP20
            $table->string('wallet_address', 255)->nullable();
            $table->timestamps();
        });

        DB::table('panel_payment_methods')->insert([
            [
                'method' => 'card',
                'enabled' => true,
                'access_mode' => 'all',
                'partner_ids' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'method' => 'crypto',
                'enabled' => false,
                'access_mode' => 'all',
                'partner_ids' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('panel_crypto_wallet_settings')->insert([
            'asset' => 'USDT',
            'network' => 'TRC20',
            'wallet_address' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_crypto_wallet_settings');
        Schema::dropIfExists('panel_payment_methods');
    }
};