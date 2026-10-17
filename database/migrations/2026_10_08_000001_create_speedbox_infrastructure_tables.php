<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createCoreXboardTables();
        $this->createMachineTables();
        $this->upgradeServerTable();
        $this->createManagedServerTables();
        $this->createQueueTables();
        $this->seedRuntimeSettings();
    }

    private function createCoreXboardTables(): void
    {
        if (!Schema::hasTable('v2_server_group')) {
            Schema::create('v2_server_group', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_server_route')) {
            Schema::create('v2_server_route', function (Blueprint $table) {
                $table->increments('id');
                $table->string('remarks');
                $table->text('match');
                $table->string('action', 32);
                $table->string('action_value')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_server')) {
            Schema::create('v2_server', function (Blueprint $table) {
                $table->id();
                $table->string('type', 32);
                $table->string('code')->nullable();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->json('group_ids')->nullable();
                $table->json('route_ids')->nullable();
                $table->string('name');
                $table->decimal('rate', 8, 2)->default(1);
                $table->unsignedBigInteger('transfer_enable')->nullable();
                $table->unsignedBigInteger('u')->default(0);
                $table->unsignedBigInteger('d')->default(0);
                $table->json('tags')->nullable();
                $table->string('host');
                $table->string('port');
                $table->unsignedInteger('server_port');
                $table->json('protocol_settings')->nullable();
                $table->json('custom_outbounds')->nullable();
                $table->json('custom_routes')->nullable();
                $table->json('cert_config')->nullable();
                $table->boolean('show')->default(false);
                $table->boolean('enabled')->default(true);
                $table->string('kernel', 16)->default('singbox');
                $table->boolean('rate_time_enable')->default(false);
                $table->json('rate_time_ranges')->nullable();
                $table->unsignedInteger('sort')->nullable()->index();
                $table->timestamps();
                $table->unique(['type', 'code']);
            });
        }

        if (!Schema::hasTable('v2_stat_server')) {
            Schema::create('v2_stat_server', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedBigInteger('server_id')->index();
                $table->string('server_type', 32);
                $table->unsignedBigInteger('u')->default(0);
                $table->unsignedBigInteger('d')->default(0);
                $table->char('record_type', 1)->default('d');
                $table->unsignedInteger('record_at')->index();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->unique(['server_id', 'server_type', 'record_at', 'record_type'], 'speedbox_server_stat_unique');
            });
        }

        if (!Schema::hasTable('v2_stat_user')) {
            Schema::create('v2_stat_user', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedBigInteger('user_id')->index();
                $table->decimal('server_rate', 10, 2)->default(1);
                $table->unsignedBigInteger('u')->default(0);
                $table->unsignedBigInteger('d')->default(0);
                $table->char('record_type', 1)->default('d');
                $table->unsignedInteger('record_at')->index();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->unique(['server_rate', 'user_id', 'record_at', 'record_type'], 'speedbox_user_stat_unique');
            });
        }

        if (!Schema::hasTable('v2_settings')) {
            Schema::create('v2_settings', function (Blueprint $table) {
                $table->id();
                $table->string('group')->default('server');
                $table->string('name');
                $table->longText('value')->nullable();
                $table->boolean('is_secret')->default(false);
                $table->timestamps();
                $table->unique(['group', 'name']);
            });
        }
    }

    private function createMachineTables(): void
    {
        if (!Schema::hasTable('v2_server_machine')) {
            Schema::create('v2_server_machine', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('last_seen_at')->nullable();
                $table->json('load_status')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('v2_server_machine_load_history')) {
            Schema::create('v2_server_machine_load_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('machine_id')->constrained('v2_server_machine')->cascadeOnDelete();
                $table->float('cpu')->default(0);
                $table->unsignedBigInteger('mem_total')->default(0);
                $table->unsignedBigInteger('mem_used')->default(0);
                $table->unsignedBigInteger('disk_total')->default(0);
                $table->unsignedBigInteger('disk_used')->default(0);
                $table->double('net_in_speed')->nullable();
                $table->double('net_out_speed')->nullable();
                $table->unsignedInteger('recorded_at');
                $table->timestamps();
                $table->index(['machine_id', 'recorded_at']);
            });
        }
    }

    private function upgradeServerTable(): void
    {
        $columns = [
            'machine_id' => fn (Blueprint $t) => $t->unsignedBigInteger('machine_id')->nullable()->index(),
            'enabled' => fn (Blueprint $t) => $t->boolean('enabled')->default(true),
            'kernel' => fn (Blueprint $t) => $t->string('kernel', 16)->default('singbox'),
            'transfer_enable' => fn (Blueprint $t) => $t->unsignedBigInteger('transfer_enable')->nullable(),
            'u' => fn (Blueprint $t) => $t->unsignedBigInteger('u')->default(0),
            'd' => fn (Blueprint $t) => $t->unsignedBigInteger('d')->default(0),
            'custom_outbounds' => fn (Blueprint $t) => $t->json('custom_outbounds')->nullable(),
            'custom_routes' => fn (Blueprint $t) => $t->json('custom_routes')->nullable(),
            'cert_config' => fn (Blueprint $t) => $t->json('cert_config')->nullable(),
            'rate_time_enable' => fn (Blueprint $t) => $t->boolean('rate_time_enable')->default(false),
            'rate_time_ranges' => fn (Blueprint $t) => $t->json('rate_time_ranges')->nullable(),
        ];

        foreach ($columns as $name => $add) {
            if (!Schema::hasColumn('v2_server', $name)) {
                Schema::table('v2_server', $add);
            }
        }
    }

    private function createManagedServerTables(): void
    {
        if (!Schema::hasTable('speedbox_managed_servers')) {
            Schema::create('speedbox_managed_servers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('machine_id')->nullable()->constrained('v2_server_machine')->nullOnDelete();
                $table->string('name');
                $table->string('host');
                $table->unsignedSmallInteger('ssh_port')->default(22);
                $table->string('ssh_user')->default('root');
                $table->string('auth_type', 16)->default('password');
                $table->longText('password')->nullable();
                $table->longText('private_key')->nullable();
                $table->longText('private_key_passphrase')->nullable();
                $table->string('host_fingerprint')->nullable();
                $table->string('status', 24)->default('draft');
                $table->string('agent_version')->nullable();
                $table->string('operating_system')->nullable();
                $table->string('architecture')->nullable();
                $table->unsignedInteger('last_checked_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->unique(['host', 'ssh_port']);
            });
        }

        if (!Schema::hasTable('speedbox_deployments')) {
            Schema::create('speedbox_deployments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('managed_server_id')->constrained('speedbox_managed_servers')->cascadeOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('panel_users')->nullOnDelete();
                $table->string('action', 24);
                $table->string('status', 24)->default('queued');
                $table->string('kernel', 16)->default('singbox');
                $table->unsignedTinyInteger('progress')->default(0);
                $table->longText('log')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function createQueueTables(): void
    {
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    private function seedRuntimeSettings(): void
    {
        foreach ([
            'server_push_interval' => '30',
            'server_pull_interval' => '60',
            'server_ws_enable' => '0',
            'subscribe_url' => rtrim((string) config('app.url'), '/'),
            'subscribe_path' => 's',
        ] as $name => $value) {
            if (!DB::table('v2_settings')->where('name', $name)->exists()) {
                DB::table('v2_settings')->insert(['name' => $name, 'group' => in_array($name, ['subscribe_url', 'subscribe_path']) ? 'subscribe' : 'server', 'value' => $value, 'updated_at' => now(), 'created_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('speedbox_deployments');
        Schema::dropIfExists('speedbox_managed_servers');
    }
};
