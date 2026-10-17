<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            || !Schema::hasTable('v2_user')) {
            return;
        }

        $changes = [];
        foreach (['u', 'd'] as $name) {
            $column = DB::selectOne("SHOW COLUMNS FROM `v2_user` WHERE Field = '{$name}'");
            if (!$column || !str_contains(strtolower($column->Type), 'unsigned')) {
                continue;
            }

            // Validate every column before ALTER, so conversion cannot truncate data.
            if (DB::table('v2_user')->where($name, '>', '9223372036854775807')->exists()) {
                throw new RuntimeException("Cannot convert v2_user.{$name} to signed BIGINT: an existing value exceeds its range.");
            }
            $changes[] = "MODIFY COLUMN `{$name}` BIGINT NOT NULL DEFAULT 0";
        }

        // MySQL unsigned arithmetic would otherwise reject a negative u + d.
        if ($changes !== []) {
            DB::statement('ALTER TABLE `v2_user` ' . implode(', ', $changes));
        }
    }

    public function down(): void
    {
        // Preserve signed values: restoring unsigned could lose purchased traffic.
    }
};
