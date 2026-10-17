<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('speedbox_crypto_payment_claims')) return;
        Schema::create('speedbox_crypto_payment_claims', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->string('network', 16);
            $table->string('tx_hash', 255);
            $table->string('owner_type', 32)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Consumed payment identities must survive a code rollback.
    }
};
