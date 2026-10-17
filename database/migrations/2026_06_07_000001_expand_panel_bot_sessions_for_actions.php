<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE panel_bot_sessions MODIFY state VARCHAR(80) NOT NULL DEFAULT 'idle'");

        Schema::table('panel_bot_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('panel_bot_sessions', 'payload')) {
                $table->json('payload')->nullable()->after('temp_username');
            }
        });
    }

    public function down(): void
    {
        Schema::table('panel_bot_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('panel_bot_sessions', 'payload')) {
                $table->dropColumn('payload');
            }
        });

        DB::statement("ALTER TABLE panel_bot_sessions MODIFY state ENUM('idle','awaiting_username','awaiting_password','logged_in') NOT NULL DEFAULT 'idle'");
    }
};