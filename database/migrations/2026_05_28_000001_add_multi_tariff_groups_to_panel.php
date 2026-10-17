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
            Schema::create('panel_tariff_groups', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 255)->default('پلن پیشفرض');
                $table->boolean('is_default')->default(false);
                $table->boolean('enable')->default(true);
                $table->unsignedInteger('sort_order')->default(1);
                $table->timestamps();
            });
        }

        $defaultGroupId = DB::table('panel_tariff_groups')->where('is_default', 1)->value('id');

        if (!$defaultGroupId) {
            $defaultGroupId = DB::table('panel_tariff_groups')->insertGetId([
                'name' => 'پلن پیشفرض',
                'is_default' => 1,
                'enable' => 1,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('panel_plan') && !Schema::hasColumn('panel_plan', 'tariff_group_id')) {
            Schema::table('panel_plan', function (Blueprint $table) use ($defaultGroupId) {
                $table->unsignedBigInteger('tariff_group_id')->default($defaultGroupId)->after('id');
                $table->index('tariff_group_id', 'panel_plan_tariff_group_id_index');
            });
        }

        if (Schema::hasTable('panel_plan')) {
            DB::table('panel_plan')->whereNull('tariff_group_id')->update([
                'tariff_group_id' => $defaultGroupId,
            ]);

            DB::table('panel_plan')->where('tariff_group_id', 0)->update([
                'tariff_group_id' => $defaultGroupId,
            ]);

            try {
                Schema::table('panel_plan', function (Blueprint $table) {
                    $table->dropUnique('plan_key');
                });
            } catch (Throwable $e) {
            }

            try {
                Schema::table('panel_plan', function (Blueprint $table) {
                    $table->unique(['tariff_group_id', 'plan_key'], 'panel_plan_group_key_unique');
                });
            } catch (Throwable $e) {
            }
        }

        if (!Schema::hasTable('panel_user_tariff_prices')) {
            Schema::create('panel_user_tariff_prices', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('panel_user_id');
                $table->unsignedBigInteger('tariff_group_id');
                $table->string('plan_key', 16);
                $table->decimal('price', 20, 0)->nullable();
                $table->timestamps();

                $table->unique(
                    ['panel_user_id', 'tariff_group_id', 'plan_key'],
                    'panel_user_tariff_price_unique'
                );

                $table->index(
                    ['tariff_group_id', 'plan_key'],
                    'panel_user_tariff_price_group_key_index'
                );
            });
        }

        $this->migrateOldPersonalizedPrices((int) $defaultGroupId);
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_user_tariff_prices');

        if (Schema::hasTable('panel_plan')) {
            try {
                Schema::table('panel_plan', function (Blueprint $table) {
                    $table->dropUnique('panel_plan_group_key_unique');
                });
            } catch (Throwable $e) {
            }

            if (Schema::hasColumn('panel_plan', 'tariff_group_id')) {
                Schema::table('panel_plan', function (Blueprint $table) {
                    $table->dropIndex('panel_plan_tariff_group_id_index');
                    $table->dropColumn('tariff_group_id');
                });
            }

            try {
                Schema::table('panel_plan', function (Blueprint $table) {
                    $table->unique(['plan_key'], 'plan_key');
                });
            } catch (Throwable $e) {
            }
        }

        Schema::dropIfExists('panel_tariff_groups');
    }

    private function migrateOldPersonalizedPrices(int $defaultGroupId): void
    {
        if (!Schema::hasTable('panel_users') || !Schema::hasTable('panel_user_tariff_prices')) {
            return;
        }

        $columnMap = [
            'test' => 'personalized_price_test',
            '1m'   => 'personalized_price_1',
            '3m'   => 'personalized_price_3',
            '6m'   => 'personalized_price_6',
            '12m'  => 'personalized_price_12',
        ];

        DB::table('panel_users')
            ->select(array_merge(['id'], array_values($columnMap)))
            ->where('enable_personalized_price', 1)
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($columnMap, $defaultGroupId) {
                foreach ($users as $user) {
                    foreach ($columnMap as $planKey => $column) {
                        $value = $user->{$column} ?? null;

                        if ($value === null || $value === '') {
                            continue;
                        }

                        DB::table('panel_user_tariff_prices')->updateOrInsert(
                            [
                                'panel_user_id' => (int) $user->id,
                                'tariff_group_id' => $defaultGroupId,
                                'plan_key' => $planKey,
                            ],
                            [
                                'price' => (string) $value,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                }
            });
    }
};