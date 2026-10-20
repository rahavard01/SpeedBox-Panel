<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('speedbox_tunnel_tests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('iran_server_id')->constrained('speedbox_managed_servers')->restrictOnDelete();
            $t->foreignId('foreign_server_id')->constrained('speedbox_managed_servers')->restrictOnDelete();
            $t->boolean('compare_both')->default(false);
            $t->string('status')->default('queued');
            $t->json('results')->nullable();
            $t->text('message')->nullable();
            $t->timestamps();
        });
        Schema::create('speedbox_tunnels', function (Blueprint $t) {
            $t->id(); $t->string('name', 100); $t->string('remote_name', 40)->unique();
            $t->foreignId('iran_server_id')->constrained('speedbox_managed_servers')->restrictOnDelete();
            $t->foreignId('foreign_server_id')->constrained('speedbox_managed_servers')->restrictOnDelete();
            $t->string('kind', 20); $t->string('transport', 30); $t->string('orientation', 20)->default('normal');
            $t->text('secret');
            $t->json('settings'); $t->json('remote_status')->nullable();
            $t->string('status')->default('queued'); $t->text('message')->nullable();
            $t->unsignedInteger('subnet')->unique(); $t->timestamps();
        });
        Schema::create('speedbox_tunnel_operations', function (Blueprint $t) {
            $t->id(); $t->foreignId('tunnel_id')->nullable()->constrained('speedbox_tunnels')->restrictOnDelete();
            $t->foreignId('test_id')->nullable()->constrained('speedbox_tunnel_tests')->restrictOnDelete();
            $t->string('action', 30); $t->string('status')->default('queued');
            $t->unsignedInteger('progress')->default(0); $t->boolean('cancel_requested')->default(false);
            $t->text('payload')->nullable(); $t->json('result')->nullable(); $t->text('message')->nullable();
            $t->timestamp('deadline'); $t->timestamps();
        });
        Schema::create('speedbox_tunnel_leases', function (Blueprint $t) {
            $t->foreignId('managed_server_id')->primary()->constrained('speedbox_managed_servers')->restrictOnDelete();
            $t->foreignId('operation_id')->constrained('speedbox_tunnel_operations')->cascadeOnDelete();
        });
    }
    public function down(): void
    {
        foreach (['speedbox_tunnel_leases', 'speedbox_tunnel_operations', 'speedbox_tunnels', 'speedbox_tunnel_tests'] as $table) Schema::dropIfExists($table);
    }
};
