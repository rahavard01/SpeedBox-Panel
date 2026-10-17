<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('panel_users') && !Schema::hasColumn('panel_users', 'partner_plan_type')) {
            Schema::table('panel_users', function (Blueprint $table) {
                $table->string('partner_plan_type', 20)->default('time')->after('enable_personalized_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('panel_users') && Schema::hasColumn('panel_users', 'partner_plan_type')) {
            Schema::table('panel_users', function (Blueprint $table) {
                $table->dropColumn('partner_plan_type');
            });
        }
    }
};