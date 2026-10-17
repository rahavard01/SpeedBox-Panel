<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('panel_plan', function (Blueprint $table) {
            $table->unsignedInteger('device_limit')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('panel_plan', fn (Blueprint $table) => $table->dropColumn('device_limit'));
    }
};
