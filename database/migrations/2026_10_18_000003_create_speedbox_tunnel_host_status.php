<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('speedbox_tunnel_host_status', function (Blueprint $table) {
            $table->foreignId('managed_server_id')->primary()->constrained('speedbox_managed_servers')->cascadeOnDelete();
            $table->json('snapshot');
            $table->timestamp('checked_at');
        });
    }
    public function down(): void { Schema::dropIfExists('speedbox_tunnel_host_status'); }
};
