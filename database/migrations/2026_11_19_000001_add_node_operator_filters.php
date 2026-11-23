<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->json('operator_filters')->nullable();
            $table->string('operator_filter_status', 32)->nullable();
        });
        Schema::table('speedbox_managed_servers', function (Blueprint $table) {
            $table->json('operator_firewall')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn(['operator_filters', 'operator_filter_status']));
        Schema::table('speedbox_managed_servers', fn (Blueprint $table) => $table->dropColumn('operator_firewall'));
    }
};
