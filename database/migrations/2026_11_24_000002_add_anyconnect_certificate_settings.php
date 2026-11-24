<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('speedbox_anyconnect_instances', 'certificate_config')) {
            Schema::table('speedbox_anyconnect_instances', fn (Blueprint $table) => $table->longText('certificate_config')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('speedbox_anyconnect_instances', fn (Blueprint $table) => $table->dropColumn('certificate_config'));
    }
};
