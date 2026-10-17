<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_crypto_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('panel_users')->cascadeOnDelete();

            $table->unsignedBigInteger('amount_irt');
            $table->string('asset', 16);
            $table->string('network', 16);
            $table->string('wallet_address', 255);

            $table->decimal('crypto_amount', 28, 8);
            $table->decimal('base_crypto_amount', 28, 8);
            $table->decimal('unique_extra', 28, 8)->default(0);

            $table->string('status', 24)->default('pending'); // pending | checking | paid | expired | failed
            $table->string('tx_hash', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['asset', 'network', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_crypto_topups');
    }
};