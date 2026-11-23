<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        // MySQL commits DDL independently. A failed index creation may leave its
        // table behind without recording this migration. Resume without dropping data.
        if (!Schema::hasTable('speedbox_wireguard_instances')) {
            Schema::create('speedbox_wireguard_instances', function (Blueprint $t) {
                $t->id(); $t->string('owner_id',36); $t->string('name',100); $t->unsignedBigInteger('managed_server_id');
                $t->string('interface',15); $t->string('network',32); $t->unsignedInteger('listen_port');
                $t->string('mode',16); $t->unsignedBigInteger('tunnel_id')->nullable();
                $t->string('endpoint_host',253); $t->unsignedInteger('endpoint_port');
                $t->json('dns'); $t->unsignedInteger('mtu')->default(1380); $t->boolean('enabled')->default(false);
                $t->string('public_key',44)->nullable(); $t->string('status',20)->default('draft');
                $t->text('last_error')->nullable(); $t->timestamp('last_synced_at')->nullable(); $t->timestamps();
            });
        }
        if (!Schema::hasTable('speedbox_wireguard_peers')) {
            Schema::create('speedbox_wireguard_peers', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('instance_id'); $t->unsignedBigInteger('user_id');
                $t->string('name',80); $t->string('address',32); $t->text('private_key'); $t->string('public_key',44);
                $t->text('preshared_key'); $t->json('retired_keys')->nullable(); $t->boolean('enabled')->default(true);
                $t->unsignedBigInteger('rx_total')->default(0); $t->unsignedBigInteger('tx_total')->default(0);
                $t->unsignedBigInteger('last_handshake')->default(0); $t->timestamps();
            });
        }
        foreach (['v2_plan','v2_user','panel_tariff_groups'] as $table) {
            if (!Schema::hasColumn($table,'service_type')) {
                Schema::table($table, fn(Blueprint $t) => $t->string('service_type',24)->default('v2ray'));
            }
            if ($table!=='panel_tariff_groups' && !Schema::hasColumn($table,'wireguard_instance_id')) {
                Schema::table($table, fn(Blueprint $t) => $t->unsignedBigInteger('wireguard_instance_id')->nullable());
            }
            $this->index($table,['service_type'],'sb_'.$table.'_service_idx');
            if ($table!=='panel_tariff_groups') $this->index($table,['wireguard_instance_id'],'sb_'.$table.'_wgi_idx');
        }
        // Explicit short names fit MySQL's 64-character identifier limit. Match
        // by columns so successful indexes from an interrupted run are reused.
        $this->index('speedbox_wireguard_instances',['owner_id'],'sb_wgi_owner_uq',true);
        $this->index('speedbox_wireguard_instances',['interface'],'sb_wgi_interface_uq',true);
        $this->index('speedbox_wireguard_instances',['managed_server_id','listen_port'],'sb_wgi_server_port_uq',true);
        $this->index('speedbox_wireguard_instances',['managed_server_id','network'],'sb_wgi_server_network_uq',true);
        $this->index('speedbox_wireguard_peers',['instance_id'],'sb_wgp_instance_idx');
        $this->index('speedbox_wireguard_peers',['user_id'],'sb_wgp_user_idx');
        $this->index('speedbox_wireguard_peers',['public_key'],'sb_wgp_public_uq',true);
        $this->index('speedbox_wireguard_peers',['instance_id','address'],'sb_wgp_instance_address_uq',true);
    }
    private function index(string $table,array $columns,string $name,bool $unique=false): void {
        if (Schema::hasIndex($table,$columns,$unique?'unique':null)) return;
        Schema::table($table, function(Blueprint $t) use($columns,$name,$unique) {
            if ($unique) $t->unique($columns,$name); else $t->index($columns,$name);
        });
    }
    public function down(): void {
        // Rolling back billing identity while accounts exist would expose them to another engine.
        if (\Illuminate\Support\Facades\DB::table('v2_user')->where('service_type','wireguard')->exists()) throw new RuntimeException('ابتدا حساب‌های وایرگارد را خارج کنید.');
        Schema::table('panel_tariff_groups', fn(Blueprint $t) => $t->dropColumn('service_type'));
        foreach(['v2_user','v2_plan'] as $table) Schema::table($table, fn(Blueprint $t) => $t->dropColumn(['service_type','wireguard_instance_id']));
        Schema::dropIfExists('speedbox_wireguard_peers'); Schema::dropIfExists('speedbox_wireguard_instances');
    }
};
