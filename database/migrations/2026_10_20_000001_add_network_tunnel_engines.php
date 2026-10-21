<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('speedbox_tunnels', function (Blueprint $t) {
            $t->string('engine', 20)->default('backpack')->index();
        });
        Schema::create('speedbox_network_samples', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tunnel_id')->constrained('speedbox_tunnels')->cascadeOnDelete();
            $t->unsignedBigInteger('taken_at');
            $t->json('snapshot');
            $t->index(['tunnel_id', 'taken_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('speedbox_network_samples');
        Schema::table('speedbox_tunnels', fn (Blueprint $t) => $t->dropColumn('engine'));
    }
};
