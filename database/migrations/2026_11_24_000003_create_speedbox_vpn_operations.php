<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speedbox_vpn_operations', function (Blueprint $table) {
            $table->id();
            $table->string('service_type', 16);
            $table->unsignedBigInteger('instance_id');
            $table->string('action', 16);
            $table->string('status', 16);
            $table->unsignedTinyInteger('progress');
            $table->text('log');
            $table->timestamps();
            $table->index(['service_type', 'instance_id', 'id'], 'speedbox_vpn_operation_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speedbox_vpn_operations');
    }
};
