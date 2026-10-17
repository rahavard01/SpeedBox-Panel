<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('panel_tariff_groups')) {
            return;
        }

        if (!Schema::hasColumn('panel_tariff_groups', 'group_type')) {
            Schema::table('panel_tariff_groups', function (Blueprint $table) {
                $table->string('group_type', 16)->default('time')->after('is_default');
                $table->index('group_type', 'panel_tariff_groups_group_type_index');
            });
        }

        DB::table('panel_tariff_groups')
            ->where(function ($q) {
                $q->whereNull('group_type')->orWhere('group_type', '');
            })
            ->update([
                'group_type' => 'time',
                'updated_at' => now(),
            ]);

        $timeDefaultId = DB::table('panel_tariff_groups')
            ->where('group_type', 'time')
            ->where('is_default', 1)
            ->value('id');

        if (!$timeDefaultId) {
            $timeDefaultId = DB::table('panel_tariff_groups')
                ->where('is_default', 1)
                ->orderBy('id')
                ->value('id');

            if ($timeDefaultId) {
                DB::table('panel_tariff_groups')->where('id', $timeDefaultId)->update([
                    'group_type' => 'time',
                    'updated_at' => now(),
                ]);
            }
        }

        if (!$timeDefaultId) {
            $timeDefaultId = DB::table('panel_tariff_groups')->insertGetId([
                'name' => 'پلن پیشفرض',
                'is_default' => 1,
                'group_type' => 'time',
                'enable' => 1,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $volumeDefaultId = DB::table('panel_tariff_groups')
            ->where('group_type', 'volume')
            ->where('is_default', 1)
            ->value('id');

        if (!$volumeDefaultId) {
            $volumeDefaultId = DB::table('panel_tariff_groups')->insertGetId([
                'name' => 'حجمی',
                'is_default' => 1,
                'group_type' => 'volume',
                'enable' => 1,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!Schema::hasTable('panel_plan')) {
            return;
        }

        $volumeRow = DB::table('panel_plan')
            ->where('plan_key', 'volume_gig')
            ->orderBy('id')
            ->first();

        if ($volumeRow) {
            DB::table('panel_plan')->where('id', $volumeRow->id)->update([
                'tariff_group_id' => $volumeDefaultId,
                'name' => $volumeRow->name ?: 'هر گیگ ترافیک - یک‌ماهه',
                'enable' => 1,
            ]);

            DB::table('panel_plan')
                ->where('plan_key', 'volume_gig')
                ->where('id', '<>', $volumeRow->id)
                ->delete();
        } else {
            DB::table('panel_plan')->insert([
                'tariff_group_id' => $volumeDefaultId,
                'plan_key' => 'volume_gig',
                'name' => 'هر گیگ ترافیک - یک‌ماهه',
                'enable' => 1,
                'default_price' => '',
                'details' => '',
                'v2_plan_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('panel_tariff_groups')) {
            return;
        }

        if (Schema::hasTable('panel_plan') && Schema::hasColumn('panel_tariff_groups', 'group_type')) {
            $timeDefaultId = DB::table('panel_tariff_groups')
                ->where('group_type', 'time')
                ->where('is_default', 1)
                ->value('id');

            if ($timeDefaultId) {
                DB::table('panel_plan')
                    ->where('plan_key', 'volume_gig')
                    ->update([
                        'tariff_group_id' => $timeDefaultId,
                    ]);
            }
        }

        if (Schema::hasColumn('panel_tariff_groups', 'group_type')) {
            try {
                Schema::table('panel_tariff_groups', function (Blueprint $table) {
                    $table->dropIndex('panel_tariff_groups_group_type_index');
                });
            } catch (\Throwable $e) {
            }

            Schema::table('panel_tariff_groups', function (Blueprint $table) {
                $table->dropColumn('group_type');
            });
        }
    }
};