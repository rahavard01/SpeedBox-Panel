<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGroupNameToUserBotPlansTable extends Migration
{
    public function up(): void
    {
        Schema::table('user_bot_plans', function (Blueprint $table) {
            $table->string('group_name', 160)->nullable()->after('plan_key');
        });
    }

    public function down(): void
    {
        Schema::table('user_bot_plans', function (Blueprint $table) {
            $table->dropColumn('group_name');
        });
    }
}