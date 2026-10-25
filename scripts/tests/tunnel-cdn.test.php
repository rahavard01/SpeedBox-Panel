<?php
/** Isolated receipt, redaction and durable CDN probe checks; no SSH or ACME traffic. */
require __DIR__.'/network-tunnels.test.php';

use App\Services\{TunnelCdn,TunnelCdnRemote};
use App\Models\{ManagedServer,ServerTunnel,TunnelConnectionTest,TunnelOperation};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$checks=0;
$assert=function($value,$label)use(&$checks){if(!$value)throw new RuntimeException($label);$checks++;};
$invalid=function($fn,$label)use($assert){try{$fn();}catch(ValidationException){$assert(true,$label);return;}throw new RuntimeException($label);};
$normalize=fn($data,$engine='backpack',$kind='reverse',$transport='wss',$old=null)=>TunnelCdn::normalize(Request::create('/','POST',$data),$engine,$kind,$transport,$old);
$dns=['tunnel_port'=>8443,'connection_mode'=>'cdn','cdn'=>['domain'=>'Tunnel.Example.com.','port'=>8443,'cert_config'=>['cert_mode'=>'dns','dns_provider'=>'arvan','dns_env'=>['ARVANCLOUD_API_KEY'=>'fixture-private-api-key']]]];
$settings=$normalize($dns);
$assert($settings['cdn']['port']===8443,'shared port is used for the edge');
$onePort=$dns;unset($onePort['cdn']['port']);$assert($normalize($onePort)['cdn']['port']===8443,'separate CDN port is not required');
$onePort['cdn']['port']=443;$assert($normalize($onePort)['cdn']['port']===8443,'stale client edge port cannot override shared port');
$assert($settings['cdn']['domain']==='tunnel.example.com','canonical hostname');
$assert(!str_contains(json_encode($settings),'fixture-private-api-key'),'DNS credentials sealed before persistence');
$assert(TunnelCdn::reveal($settings)['cdn']['cert_config']['dns_env']['ARVANCLOUD_API_KEY']==='fixture-private-api-key','only worker reveals credentials');
$public=TunnelCdn::publicSettings($settings);
$assert($public['cdn']['cert_config']['saved_dns_fields']===['ARVANCLOUD_API_KEY'],'saved credential field names are public without their values');
$assert($public['cdn']['cert_config']['saved_dns_previews']['ARVANCLOUD_API_KEY']==='fixt********-key','API exposes only the masked DNS key preview');
foreach(['x','12345678','123456789012'] as $short){$shortData=$dns;$shortData['cdn']['cert_config']['dns_env']['ARVANCLOUD_API_KEY']=$short;$assert(TunnelCdn::publicSettings($normalize($shortData))['cdn']['cert_config']['saved_dns_previews']['ARVANCLOUD_API_KEY']==='********','short keys are fully masked');}
$masked=$dns;$masked['cdn']['cert_config']['dns_env']['ARVANCLOUD_API_KEY']='fixt********-key';$invalid(fn()=>$normalize($masked),'masked preview cannot be stored as a new credential');
$cf=$dns;$cf['cdn']['cert_config']=['cert_mode'=>'dns','dns_provider'=>'cloudflare','dns_env'=>['CF_DNS_API_TOKEN'=>'old-cf-token','CLOUDFLARE_ZONE_API_TOKEN'=>'old-zone-token']];
$cfSettings=$normalize($cf);$cfOld=new ServerTunnel(['settings'=>$cfSettings]);
$cf['cdn']['cert_config']['dns_env']=['CLOUDFLARE_DNS_API_TOKEN'=>'new-cf-token'];
$cfChanged=TunnelCdn::reveal($normalize($cf,old:$cfOld))['cdn']['cert_config']['dns_env'];
$assert($cfChanged['CLOUDFLARE_DNS_API_TOKEN']==='new-cf-token'&&!isset($cfChanged['CF_DNS_API_TOKEN']),'replacement token supersedes its old alias');
$assert($cfChanged['CLOUDFLARE_ZONE_API_TOKEN']==='old-zone-token','editing one field preserves the saved optional zone token');

$assert($public['cdn']['cert_config']['has_saved_secret']&&!isset($public['cdn']['cert_config']['sealed']),'API hides encrypted and plaintext secrets');
$old=new ServerTunnel(['engine'=>'backpack','kind'=>'reverse','transport'=>'wss','orientation'=>'normal','settings'=>$settings]);
$blank=$dns;$blank['cdn']['cert_config']['dns_env']=[];
$assert(TunnelCdn::reveal($normalize($blank,old:$old))['cdn']['cert_config']['dns_env']['ARVANCLOUD_API_KEY']==='fixture-private-api-key','editing blank secret preserves stored value');
$blank['cdn']['cert_config']['saved_dns_previews']=['ARVANCLOUD_API_KEY'=>'forged-preview'];$blank['cdn']['cert_config']['saved_dns_fields']=['FORGED_KEY'];$assert(TunnelCdn::publicSettings($normalize($blank,old:$old))['cdn']['cert_config']['saved_dns_previews']['ARVANCLOUD_API_KEY']==='fixt********-key','client preview metadata never overwrites persisted secret or computed preview');
$invalid(fn()=>$normalize($masked,old:$old),'masked preview cannot replace an existing credential');
$replacement=$dns;$replacement['cdn']['cert_config']['dns_env']['ARVANCLOUD_API_KEY']='replacement-real-api-key';$assert(TunnelCdn::reveal($normalize($replacement,old:$old))['cdn']['cert_config']['dns_env']['ARVANCLOUD_API_KEY']==='replacement-real-api-key','complete replacement key is persisted');
$assert(!str_contains(json_encode($old),'fixture-private-api-key')&&!str_contains(json_encode($old),'sealed'),'model serialization redacts nested secrets');
foreach([['backpack','reverse','ws'],['backpack','reverse','wss'],['backpack','reverse','wsmux'],['backpack','reverse','wssmux'],['wattunnel','direct','ws'],['wattunnel','reverse','wss'],['wattunnel','direct','grpc']] as [$engine,$kind,$transport])$assert(TunnelCdn::supported($engine,$kind,$transport),'supported method '.$engine.'/'.$transport);
foreach([['backpack','direct','wss'],['backpack','reverse','tcp'],['wattunnel','direct','h2'],['wattunnel','direct','h2c'],['gost','direct','ws'],['gre','direct','gre']] as [$engine,$kind,$transport])$invalid(fn()=>$normalize($dns,$engine,$kind,$transport),'reject unsupported '.$engine.'/'.$transport);
$invalid(fn()=>$normalize([...$dns,'tls_pin'=>str_repeat('a',64)],'wattunnel'),'direct certificate pin rejected on CDN');
$bad=$dns;$bad['cdn']['domain']='192.0.2.1';$invalid(fn()=>$normalize($bad),'raw origin cannot be CDN hostname');
$bad=$dns;$bad['cdn']['edge_ip']='127.0.0.1';$invalid(fn()=>$normalize($bad),'private edge rejected');
$bad=$dns;$bad['tunnel_port']=80;$invalid(fn()=>$normalize($bad),'secure edge port validated');
$bad=$dns;$bad['cdn']['cert_config']['dns_env']['PATH']='unsafe';$invalid(fn()=>$normalize($bad),'unrelated environment names rejected');
$ws=$normalize(['tunnel_port'=>80,'connection_mode'=>'cdn','cdn'=>['domain'=>'tunnel.example.com']],transport:'ws');
$assert($ws['cdn']['cert_config']['cert_mode']==='none','plain WS requires no origin TLS certificate');
$assert($normalize(['connection_mode'=>'direct'],'gre','direct','gre')===['connection_mode'=>'direct'],'direct path unchanged for all engines');

$method=['engine'=>'backpack','kind'=>'reverse','transport'=>'wss','orientation'=>'normal'];
$route=[...$settings,'tunnel_port'=>18443,'accept_udp'=>false,'mappings'=>[['public_port'=>25443,'target_port'=>443]]];
$fingerprint=TunnelCdn::fingerprint($route,$method,1,2);
$invalid(fn()=>TunnelCdn::assertReceipt($route,$method,1,2),'baseline or absent receipt does not authorize CDN');
$test=TunnelConnectionTest::create(['iran_server_id'=>1,'foreign_server_id'=>2,'status'=>'completed']);
$receipt=TunnelOperation::create(['test_id'=>$test->id,'action'=>'cdn-test','status'=>'succeeded','payload'=>['fingerprint'=>$fingerprint],'result'=>['cdn_verified'=>true],'deadline'=>now()->addMinutes(5)]);
$route['cdn']['test_id']=$test->id;TunnelCdn::assertReceipt($route,$method,1,2);$assert(true,'matching measured route accepted');
$changed=$route;$changed['cdn']['domain']='other.example.com';$invalid(fn()=>TunnelCdn::assertReceipt($changed,$method,1,2),'domain change invalidates receipt');
$changed=$route;$changed['tunnel_port']++;$invalid(fn()=>TunnelCdn::assertReceipt($changed,$method,1,2),'origin port change invalidates receipt');
$changed=$route;$changed['cdn']['port']=443;$invalid(fn()=>TunnelCdn::assertReceipt($changed,$method,1,2),'edge port change invalidates receipt');
$invalid(fn()=>TunnelCdn::assertReceipt($route,[...$method,'orientation'=>'swapped'],1,2),'direction change invalidates receipt');
$invalid(fn()=>TunnelCdn::assertReceipt($route,$method,2,1),'server order change invalidates receipt');
$server=ManagedServer::findOrFail(1);$before=$server->host;$server->update(['host'=>'203.0.113.100']);$invalid(fn()=>TunnelCdn::assertReceipt($route,$method,1,2),'server host change invalidates receipt');$server->update(['host'=>$before]);
$receipt->update(['result'=>['cdn_verified'=>false]]);$invalid(fn()=>TunnelCdn::assertReceipt($route,$method,1,2),'failed verification cannot authorize route');
$receipt->update(['result'=>['cdn_verified'=>true]]);$test->timestamps=false;$test->update(['updated_at'=>now()->subDays(2)]);$invalid(fn()=>TunnelCdn::assertReceipt($route,$method,1,2),'expired receipt rejected');

// A real job state machine against explicit fakes: receipt requires both data and cleanup.
$cdn=Mockery::mock(TunnelCdnRemote::class);$bp=Mockery::mock(App\Services\BackpackRemote::class);$wat=Mockery::mock(App\Services\NetworkTunnelRemote::class);
$cdn->shouldReceive('ensure')->andReturnNull();$cdn->shouldReceive('prepare')->andReturn(['expires_at'=>time()+86400]);
$bp->shouldReceive('ensure')->andReturnNull();$bp->shouldReceive('call')->andReturn(['active'=>true,'deleted'=>true]);
$cdn->shouldReceive('status')->andReturn(['cdn_verified'=>true,'peer_addresses'=>['185.143.234.132']]);
$clean=true;$dataOk=true;$calls=[];
$cdn->shouldReceive('call')->andReturnUsing(function($server,$r)use(&$clean,&$dataOk,&$calls){$calls[]=$r['action'];return match($r['action']){'free-port'=>['port'=>45000+count($calls)],'probe'=>['data_verified'=>$dataOk,'protocols'=>['tcp'=>['status'=>$dataOk?'ok':'down']]],'echo-stop'=>['stopped'=>$clean],default=>[]};});
$probe=function()use($manager,$method,$route,$fingerprint,$cdn,$bp,$wat,$network,$assert){
 $test=TunnelConnectionTest::create(['iran_server_id'=>1,'foreign_server_id'=>2,'status'=>'queued']);
 $op=$manager->queue('cdn-test',null,$test,['method'=>$method,'settings'=>$route,'fingerprint'=>$fingerprint,'remote_name'=>'sb-'.bin2hex(random_bytes(16)),'token'=>str_repeat('a',64),'subnet'=>1024,'phase'=>'ensure','index'=>0]);
 for($i=0;$i<30;$i++){(new App\Jobs\RunTunnelCdnTest($op->id))->handle($cdn,$bp,$wat,$network,$manager);$op->refresh();if(!in_array($op->status,['queued','running']))return $op;}
 throw new RuntimeException('CDN probe did not terminate');
};
// Earlier suites may leave deliberate leases; release only this in-memory fixture database.
DB::table('speedbox_tunnel_leases')->delete();
$op=$probe();$assert($op->status==='succeeded'&&$op->result['cdn_verified'],'native route plus data plus cleanup authorizes receipt');
$assert(in_array('probe',$calls)&&in_array('delete',$calls),'data checked before temporary certificates removed');
$dataOk=false;$op=$probe();$assert($op->status==='failed'&&!$op->result['cdn_verified'],'edge socket alone is insufficient');
$dataOk=true;$clean=false;$op=$probe();$assert($op->status==='failed'&&$op->result['cleanup_pending'],'unconfirmed cleanup cannot authorize receipt');
$assert(DB::table('speedbox_tunnel_leases')->count()===0,'finished CDN operations release host leases');

// Real controller round trip: failed direct WSS -> CDN test -> verified creation.
$clean=true;$dataOk=true;
$wat->shouldReceive('ensure')->andReturnNull();$wat->shouldReceive('call')->andReturn(['active'=>true,'deleted'=>true]);
$body=[...App\Support\WatTunnelRules::defaults(),...$dns,'engine'=>'wattunnel','name'=>'CDN WSS API fixture','iran_server_id'=>1,'foreign_server_id'=>2,'address_source'=>'servers','ip_family'=>'ipv4','transport'=>'wss','reverse'=>false,'protocol'=>'both','public_host'=>'192.0.2.1','tunnel_port'=>2053,'mappings'=>[['public_port'=>28444,'target_port'=>443]]];
$baseSettings=$network->prepare(Request::create('/','POST',$body));$tester=new App\Services\NetworkTunnelTest();
$baseline=TunnelConnectionTest::create(['iran_server_id'=>1,'foreign_server_id'=>2,'status'=>'completed','results'=>[['id'=>'failed-wss','engine'=>'wattunnel','transport'=>'wss','version'=>$baseSettings['version'],'ip_family'=>'ipv4','reverse'=>false,'selectable'=>false,'status'=>'down','protocols'=>['tcp'=>['status'=>'down'],'udp'=>['status'=>'down']],'peer_host'=>$baseSettings['peer_host'],'local_endpoint'=>$baseSettings['local_endpoint'],'server_fingerprints'=>[$tester->fingerprint(ManagedServer::find(1)),$tester->fingerprint(ManagedServer::find(2))]]]]);
TunnelOperation::create(['test_id'=>$baseline->id,'action'=>'network-test','status'=>'succeeded','payload'=>['engine'=>'wattunnel'],'deadline'=>now()->addMinutes(5)]);
$body=[...$body,'test_id'=>$baseline->id,'result_id'=>'failed-wss'];
$answer=(new App\Http\Controllers\Api\Admin\TunnelCdnController())->store(Request::create('/','POST',$body),$manager,$network)->getData(true);
$op=TunnelOperation::findOrFail($answer['operation']['id']);
for($i=0;$i<30&&in_array($op->status,['queued','running']);$i++){(new App\Jobs\RunTunnelCdnTest($op->id))->handle($cdn,$bp,$wat,$network,$manager);$op->refresh();}
$assert($op->status==='succeeded','CDN test accepts failed direct WSS method');
$body['cdn']['test_id']=$answer['data']['id'];
$response=(new App\Http\Controllers\Api\Admin\NetworkTunnelController())->store(Request::create('/','POST',$body),$network,$manager)->getData(true);
$assert($response['data']['settings']['connection_mode']==='cdn','matching CDN receipt authorizes actual WatTunnel create');
$assert(!str_contains(json_encode($response),'fixture-private-api-key')&&!isset($response['data']['settings']['cdn']['cert_config']['sealed']),'creation response redacts persisted credentials');
DB::table('speedbox_tunnel_leases')->delete();
echo "PASS: $checks isolated CDN checks\n";
