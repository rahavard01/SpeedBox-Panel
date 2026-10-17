<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crypto_payment_options', function (Blueprint $table) {
            $table->id();
            $table->string('context', 40); // panel_wallet | user_bot
            $table->string('owner_type', 40)->default('admin'); // admin | panel_user
            $table->unsignedBigInteger('owner_id')->nullable();

            $table->string('asset', 20);
            $table->string('network', 20);
            $table->string('title', 100)->nullable();
            $table->string('wallet_address', 255)->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('sort_order')->default(1);

            $table->timestamps();

            $table->unique(['context', 'owner_type', 'owner_id', 'asset', 'network'], 'crypto_options_unique');
            $table->index(['context', 'owner_type', 'owner_id', 'enabled'], 'crypto_options_lookup');
        });

        DB::table('crypto_payment_options')->insert([
            [
                'context' => 'panel_wallet',
                'owner_type' => 'admin',
                'owner_id' => null,
                'asset' => 'USDT',
                'network' => 'BEP20',
                'title' => 'USDT - BEP20',
                'wallet_address' => null,
                'enabled' => false,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'context' => 'panel_wallet',
                'owner_type' => 'admin',
                'owner_id' => null,
                'asset' => 'TRX',
                'network' => 'TRC20',
                'title' => 'TRX - TRC20',
                'wallet_address' => null,
                'enabled' => false,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_payment_options');
    }
};