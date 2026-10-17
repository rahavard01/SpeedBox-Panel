<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_payment_methods', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_receipt_check_fee')->nullable()->after('gemini_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('panel_payment_methods', function (Blueprint $table) {
            $table->dropColumn('ai_receipt_check_fee');
        });
    }
};