<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_tariff_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('panel_tariff_groups', 'partner_access_mode')) {
                $table->string('partner_access_mode', 30)
                    ->default('all_partners')
                    ->after('group_type');
            }
        });

        Schema::create('panel_tariff_group_partner_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tariff_group_id');
            $table->unsignedBigInteger('partner_id');
            $table->timestamps();

            $table->unique(['tariff_group_id', 'partner_id'], 'ptgpa_group_partner_unique');

            $table->foreign('tariff_group_id')
                ->references('id')
                ->on('panel_tariff_groups')
                ->cascadeOnDelete();

            $table->foreign('partner_id')
                ->references('id')
                ->on('panel_users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_tariff_group_partner_access');

        Schema::table('panel_tariff_groups', function (Blueprint $table) {
            if (Schema::hasColumn('panel_tariff_groups', 'partner_access_mode')) {
                $table->dropColumn('partner_access_mode');
            }
        });
    }
};