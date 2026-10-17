<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('speedbox_connection_leases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('node_id');
            $table->string('source_ip', 45);
            $table->string('lease_id', 64);
            $table->unsignedBigInteger('expires_at');
            $table->unique(['user_id', 'node_id', 'source_ip'], 'connection_lease_owner');
            $table->index(['user_id', 'expires_at'], 'connection_lease_expiry');
        });
    }
    public function down(): void { Schema::dropIfExists('speedbox_connection_leases'); }
};
