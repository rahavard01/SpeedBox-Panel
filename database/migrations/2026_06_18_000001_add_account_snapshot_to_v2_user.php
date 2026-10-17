<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'account_plan_name')) {
                $table->string('account_plan_name')->nullable()->after('plan_id');
            }

            if (!Schema::hasColumn('v2_user', 'account_group_type')) {
                $table->string('account_group_type', 20)->nullable()->after('account_plan_name');
            }

            if (!Schema::hasColumn('v2_user', 'account_tariff_group_id')) {
                $table->unsignedBigInteger('account_tariff_group_id')->nullable()->after('account_group_type');
            }

            if (!Schema::hasColumn('v2_user', 'account_plan_key')) {
                $table->string('account_plan_key', 50)->nullable()->after('account_tariff_group_id');
            }
        });

        DB::statement("
            UPDATE v2_user vu
            LEFT JOIN (
                SELECT
                    pp.v2_plan_id,
                    MIN(tg.name) AS account_plan_name,
                    MIN(tg.group_type) AS account_group_type,
                    MIN(pp.tariff_group_id) AS account_tariff_group_id,
                    MIN(pp.plan_key) AS account_plan_key
                FROM panel_plan pp
                INNER JOIN panel_tariff_groups tg ON tg.id = pp.tariff_group_id
                WHERE pp.v2_plan_id IS NOT NULL
                GROUP BY pp.v2_plan_id
            ) x ON x.v2_plan_id = vu.plan_id
            SET
                vu.account_plan_name = COALESCE(vu.account_plan_name, x.account_plan_name),
                vu.account_group_type = COALESCE(vu.account_group_type, x.account_group_type),
                vu.account_tariff_group_id = COALESCE(vu.account_tariff_group_id, x.account_tariff_group_id),
                vu.account_plan_key = COALESCE(vu.account_plan_key, x.account_plan_key)
            WHERE x.v2_plan_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (Schema::hasColumn('v2_user', 'account_plan_key')) {
                $table->dropColumn('account_plan_key');
            }

            if (Schema::hasColumn('v2_user', 'account_tariff_group_id')) {
                $table->dropColumn('account_tariff_group_id');
            }

            if (Schema::hasColumn('v2_user', 'account_group_type')) {
                $table->dropColumn('account_group_type');
            }

            if (Schema::hasColumn('v2_user', 'account_plan_name')) {
                $table->dropColumn('account_plan_name');
            }
        });
    }
};