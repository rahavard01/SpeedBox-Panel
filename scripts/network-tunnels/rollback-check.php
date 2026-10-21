<?php
/** Read-only guard for restoring the older source, which cannot manage new engines. */
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(Illuminate\Support\Facades\Schema::hasColumn('speedbox_tunnels','engine')) {
 $active=App\Models\ServerTunnel::whereIn('engine',['gost','gre'])->where('status','!=','deleted')->exists();
 $pending=App\Models\TunnelOperation::whereIn('status',['queued','running'])->exists();
 if($active||$pending){fwrite(STDERR,"Remove new GOST/GRE connections through the panel and let queued operations finish before restoring old source.\n");exit(2);}
}
echo "ROLLBACK SOURCE GUARD PASSED\n";
