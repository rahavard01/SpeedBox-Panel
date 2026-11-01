<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('panel_wallet_low_balance_warnings', function (Blueprint $table) {
            $table->unsignedBigInteger('panel_user_id')->primary();
            $table->unsignedBigInteger('transaction_id')->default(0);
            $table->text('pending_credit_ids')->nullable();
            $table->string('claim_token', 32)->nullable();
            $table->dateTime('retry_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_wallet_low_balance_warnings');
    }
};
