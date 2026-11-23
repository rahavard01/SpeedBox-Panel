<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        $fields=['ingress_server_id'=>fn(Blueprint $t)=>$t->unsignedBigInteger('ingress_server_id')->nullable(),
            'link_network'=>fn(Blueprint $t)=>$t->string('link_network',32)->nullable(),
            'tunnel_port'=>fn(Blueprint $t)=>$t->unsignedInteger('tunnel_port')->nullable(),
            'site_keys'=>fn(Blueprint $t)=>$t->text('site_keys')->nullable(),
            'auto_network'=>fn(Blueprint $t)=>$t->boolean('auto_network')->default(false),
            'auto_link_network'=>fn(Blueprint $t)=>$t->boolean('auto_link_network')->default(false)];
        foreach($fields as $column=>$add)if(!Schema::hasColumn('speedbox_wireguard_instances',$column))Schema::table('speedbox_wireguard_instances',$add);
    }
    public function down():void {
        if(\Illuminate\Support\Facades\DB::table('speedbox_wireguard_instances')->where('mode','tunnel')->exists())throw new RuntimeException('ابتدا سرویس‌های تونلی WireGuard را خارج کنید.');
        Schema::table('speedbox_wireguard_instances',fn(Blueprint $t)=>$t->dropColumn(['ingress_server_id','link_network','tunnel_port','site_keys','auto_network','auto_link_network']));
    }
};
