<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('speedbox_anyconnect_instances')) Schema::create('speedbox_anyconnect_instances', function (Blueprint $table) {
            $table->id();
            $table->uuid('owner_id')->unique();
            $table->string('name', 100);
            $table->unsignedBigInteger('managed_server_id')->index();
            $table->unsignedBigInteger('ingress_server_id')->nullable()->index();
            $table->string('endpoint_host', 253);
            $table->unsignedSmallInteger('endpoint_port');
            $table->unsignedSmallInteger('listen_port');
            $table->string('network', 18);
            $table->json('dns');
            $table->unsignedSmallInteger('mtu');
            $table->string('link_network', 18)->nullable();
            $table->unsignedSmallInteger('link_port')->nullable();
            $table->text('link_keys')->nullable();
            $table->text('radius_secret');
            $table->boolean('enabled')->default(false);
            $table->string('status', 24)->default('stopped');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        if (!Schema::hasTable('speedbox_anyconnect_accounts')) Schema::create('speedbox_anyconnect_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedBigInteger('instance_id')->index();
            $table->text('password');
            $table->string('password_hash', 72);
            $table->unsignedBigInteger('uploaded')->default(0);
            $table->unsignedBigInteger('downloaded')->default(0);
            $table->unsignedInteger('online')->default(0);
        });

        foreach (['v2_plan', 'v2_user'] as $name) {
            if (!Schema::hasColumn($name, 'anyconnect_instance_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('anyconnect_instance_id')->nullable());
            }
            if (!Schema::hasIndex($name, ['anyconnect_instance_id'])) {
                Schema::table($name, fn (Blueprint $table) => $table->index('anyconnect_instance_id'));
            }
        }
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('v2_user')->where('service_type', 'anyconnect')->exists()) {
            throw new RuntimeException('ابتدا حساب‌های AnyConnect را خارج کنید.');
        }
        foreach (['v2_plan', 'v2_user'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['anyconnect_instance_id']);
                $table->dropColumn('anyconnect_instance_id');
            });
        }
        Schema::dropIfExists('speedbox_anyconnect_accounts');
        Schema::dropIfExists('speedbox_anyconnect_instances');
    }
};
