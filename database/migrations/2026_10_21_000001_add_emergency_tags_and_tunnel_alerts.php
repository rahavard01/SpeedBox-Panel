<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('speedbox_emergency_configs','tags')) Schema::table('speedbox_emergency_configs', function (Blueprint $table) {
            $table->json('tags')->nullable();
        });
        if (!Schema::hasTable('speedbox_tunnel_alerts')) Schema::create('speedbox_tunnel_alerts', function (Blueprint $table) {
            $table->foreignId('tunnel_id')->primary()->constrained('speedbox_tunnels')->cascadeOnDelete();
            $table->unsignedInteger('failures')->default(0);
            $table->uuid('incident')->nullable();
            $table->json('delivered_to')->nullable();
            $table->unsignedBigInteger('last_operation_id')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('next_probe_at')->nullable()->index();
            $table->timestamp('next_delivery_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speedbox_tunnel_alerts');
        Schema::table('speedbox_emergency_configs', fn (Blueprint $table) => $table->dropColumn('tags'));
    }
};
