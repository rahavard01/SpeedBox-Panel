<?php
/** Select exactly one managed tunnel; dry-run by default, normal paired queue on --apply. */
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ServerTunnel;
use App\Services\{TrustedTunnelOrigin,TunnelManager};
use Illuminate\Support\Facades\DB;

try {
    $args=array_slice($argv,1);
    if($args===['--list']) {
        $rows=[];
        foreach(ServerTunnel::where('status','!=','deleted')->orderBy('id')->get() as $t) {
            if(!in_array($t->engine,['backpack','gost','gre'],true))continue;
            $rows[]=['id'=>$t->id,'name'=>$t->name,'engine'=>$t->engine,'status'=>$t->status,
                'iran_server_id'=>$t->iran_server_id,'foreign_server_id'=>$t->foreign_server_id,
                'tcp_security'=>TrustedTunnelOrigin::tcpSecurity($t->settings),
                'source_identity'=>(bool)($t->settings['source_identity']??false)];
        }
        echo json_encode(['tunnels'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    if(count($args)<2||count($args)>3||!ctype_digit($args[0])||(int)$args[0]<1
        ||!in_array($args[1],['tls','hmac-stream-v1'],true)||isset($args[2])&&$args[2]!=='--apply') {
        throw new RuntimeException('Usage: php scripts/origin-tcp-mode.php --list | TUNNEL_ID tls|hmac-stream-v1 [--apply]');
    }
    $result=DB::transaction(function()use($args){
        $t=ServerTunnel::whereKey((int)$args[0])->lockForUpdate()->firstOrFail();
        if(!in_array($t->engine,['backpack','gost','gre'],true)||$t->status==='deleted'
            ||!($t->settings['enabled']??true)||empty($t->settings['source_identity'])||empty($t->settings['source_identity_port'])) {
            throw new RuntimeException('Select an enabled managed tunnel with an existing source-identity adapter.');
        }
        $before=TrustedTunnelOrigin::tcpSecurity($t->settings);
        $result=['tunnel_id'=>$t->id,'name'=>$t->name,'engine'=>$t->engine,
            'before'=>$before,'after'=>$args[1],'applied'=>false];
        if($before===$args[1])return [...$result,'already_selected'=>true];
        if(!isset($args[2]))return [...$result,'dry_run'=>true];
        $settings=[...$t->settings,'source_identity_tcp_security'=>$args[1]];
        // Do not edit committed settings early. Existing jobs snapshot both
        // hosts, stage ingress, probe the selected mode and roll back on failure.
        $op=app(TunnelManager::class)->queue('apply',$t,null,['settings'=>$settings]);
        return [...$result,'operation_id'=>$op->id,'queued'=>true];
    });
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}
