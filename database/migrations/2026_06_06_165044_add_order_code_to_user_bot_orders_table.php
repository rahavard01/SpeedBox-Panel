<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOrderCodeToUserBotOrdersTable extends Migration
{
    public function up(): void
    {
        Schema::table('user_bot_orders', function (Blueprint $table) {
            $table->string('order_code', 20)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('user_bot_orders', function (Blueprint $table) {
            $table->dropColumn('order_code');
        });
    }
}