<?php
/** Real SQLite, validation, queued operations and fake Telegram HTTP; no .env or SSH. */
require __DIR__.'/network-maintenance.test.php';
use App\Models\{EmergencyConfig,PanelBotSetting,ServerTunnel,TunnelOperation};
use App\Services\{EmergencyConfigurations,TelegramConfig,TunnelAlertSettings,TunnelOutageMonitor};
use App\Jobs\SendTunnelOutageAlert;
use Illuminate\Support\Facades\{Bus,DB,Http,Schema};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

if(!class_exists('DB'))class_alias(DB::class,'DB');
$checks=0;
$assert=function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$app->instance('log',Mockery::mock(Psr\Log\LoggerInterface::class)->shouldIgnoreMissing());
$app['config']->set('app.url','https://panel.test');
Schema::create('panel_users',function(Blueprint $t){$t->id();$t->integer('role');$t->string('telegram_user_id')->nullable();});
Schema::create('panel_bot_settings',function(Blueprint $t){$t->id();$t->boolean('enabled')->default(false);$t->text('bot_token')->nullable();$t->text('webhook_secret')->nullable();$t->text('message_template')->nullable();$t->timestamps();});
(require $root.'/database/migrations/2026_10_13_000001_create_emergency_configurations.php')->up();
$migration=require $root.'/database/migrations/2026_10_21_000001_add_emergency_tags_and_tunnel_alerts.php';
$migration->up();$migration->up();
$assert(Schema::hasColumn('speedbox_emergency_configs','tags'),'additive migration can resume without dropping data');
$cap=new TunnelAlertSettings();$monitor=new TunnelOutageMonitor();
$assert(!$cap->capability()['available'],'inactive bot is unavailable');
$bot=PanelBotSetting::getSingleton();$bot->update(['enabled'=>true,'bot_token'=>'123456:fake-token']);
$assert(!$cap->capability()['available'],'token alone cannot enable alerts without a linked admin');
DB::table('panel_users')->insert([['role'=>1,'telegram_user_id'=>'1001'],['role'=>1,'telegram_user_id'=>'1002'],['role'=>2,'telegram_user_id'=>'9999']]);
$assert($cap->capability()['available'],'active panel bot and linked admins enable the capability');
$assert($cap->resolve(Request::create('/','POST'))===true,'new tunnels default on with a ready bot');
$assert($cap->resolve(Request::create('/','POST',['telegram_alerts'=>false]))===false,'explicit per-tunnel opt-out is respected');
$base=$t->fresh()->settings;
$t->update(['settings'=>[...$base,'telegram_alerts'=>false]]);
$assert(!$cap->resolve(Request::create('/','POST'),$t->fresh()),'editing legacy/disabled tunnels does not turn alerts on');
$bot->update(['enabled'=>false]);
$assert(!$cap->resolve(Request::create('/','POST')),'new tunnels default off with an inactive bot');
try{$cap->resolve(Request::create('/','POST',['telegram_alerts'=>true]),$t->fresh());$assert(false,'server must reject bypassing the checkbox gate');}
catch(Illuminate\Validation\ValidationException){$assert(true,'server rejects enabling without a working bot configuration');}
$bot->update(['enabled'=>true]);

// Two real status operations, not two renders of one cached response.
$now=Carbon::now()->startOfMinute();Carbon::setTestNow($now);
TunnelOperation::query()->update(['updated_at'=>now()->subHour()]);
App\Models\TunnelConnectionTest::query()->update(['updated_at'=>now()->subHour()]);
$t->update(['status'=>'connected','settings'=>[...$base,'telegram_alerts'=>true,'enabled'=>true]]);
$observe=function(?bool $health=true,bool $success=true)use($t,$manager){
    $tunnel=$t->fresh();
    $tunnel->update(['remote_status'=>['iran'=>['active'=>true,'connected'=>$health,'checked_at'=>now()->timestamp],'foreign'=>['active'=>true,'connected'=>$health,'checked_at'=>now()->timestamp]]]);
    $op=$manager->queue('status',$tunnel,null,['outage_probe'=>true]);
    $manager->finish($op,$success?'succeeded':'failed','fixture status');
    return $op->fresh();
};
$state=fn()=>DB::table('speedbox_tunnel_alerts')->where('tunnel_id',$t->id)->first();
$observe(false);$assert($state()->failures===1 && !$state()->incident,'one down result cannot trigger an alert');
$observe(false);$assert($state()->failures===1,'rapid duplicate observations cannot satisfy consecutive scheduled checks');
Carbon::setTestNow(now()->addMinute());$op=$observe(false);
$assert($state()->failures===2 && $state()->incident,'two spaced confirmed failures open one incident');
$incident=$state()->incident;$monitor->record($op);$assert($state()->incident===$incident,'reprocessing the same operation is idempotent');
$assert(Bus::dispatched(SendTunnelOutageAlert::class)->count()>0,'confirmed incident dispatches delivery independently of an open page');
Http::preventStrayRequests();Http::swap(new Illuminate\Http\Client\Factory());Http::preventStrayRequests();Http::fake(['api.telegram.org/*'=>Http::response(['ok'=>true],200)]);
(new SendTunnelOutageAlert($t->id,$incident))->handle($monitor);
$assert(Http::recorded()->count()===2,'only linked admins receive the alert; representatives do not');
$assert(count(json_decode($state()->delivered_to,true))===2,'successful recipients are persisted');
Http::assertSent(fn($request)=>str_contains($request['text'],'قطع ارتباط تونل') && str_contains($request['text'],$t->name) && isset($request['reply_markup']));
$assert(true,'message includes tunnel identity and a panel link');
(new SendTunnelOutageAlert($t->id,$incident))->handle($monitor);
$assert(Http::recorded()->count()===2,'repeated delivery cannot resend the same incident');
Carbon::setTestNow(now()->addMinute());$observe(true);
$assert(!$state()->incident && $state()->failures===0,'recovery rearms future outage alerts without sending recovery spam');
(new SendTunnelOutageAlert($t->id,$incident))->handle($monitor);
$assert(Http::recorded()->count()===2,'a queued stale alert is cancelled after recovery');
Carbon::setTestNow(now()->addMinute());$observe(false);
Carbon::setTestNow(now()->addMinute());$observe(null);
$assert($state()->failures===0 && !$state()->incident,'unknown connection state breaks the failure streak');
Carbon::setTestNow(now()->addMinute());$observe(false,false);
$assert(!$state()->incident,'SSH status failure is not reported as a confirmed tunnel outage');
Carbon::setTestNow(now()->addMinute());$observe(false);
Carbon::setTestNow(now()->addMinute());$observe(false);
$newIncident=$state()->incident;$assert($newIncident && $newIncident!==$incident,'a later outage has a distinct durable incident');

// A partial Telegram failure retries only the recipient that failed.
$attempts=[];
Http::swap(new Illuminate\Http\Client\Factory());Http::preventStrayRequests();Http::fake(['api.telegram.org/*'=>function($request)use(&$attempts){$id=$request['chat_id'];$attempts[]=$id;return Http::response(['ok'=>$id!=='1002'],200);}]);
(new SendTunnelOutageAlert($t->id,$newIncident))->handle($monitor);
$assert(json_decode($state()->delivered_to,true)===['1001'],'Telegram API rejection is not marked as delivered');
Carbon::setTestNow(now()->addMinutes(5));$observe(false);
Http::swap(new Illuminate\Http\Client\Factory());Http::preventStrayRequests();Http::fake(['api.telegram.org/*'=>function($request)use(&$attempts){$attempts[]=$request['chat_id'];return Http::response(['ok'=>true],200);}]);
(new SendTunnelOutageAlert($t->id,$newIncident))->handle($monitor);
$assert($attempts===['1001','1002','1002'],'retry targets only undelivered admins');

$t->refresh();$t->update(['status'=>'stopped','settings'=>[...$t->settings,'enabled'=>false]]);
$assert(!$monitor->eligible($t->fresh()),'intentionally stopped tunnel is ineligible');
$op=$observe(false);$assert(!$state()->incident,'stopped tunnel clears a pending incident');
$t->refresh();$t->update(['status'=>'connected','settings'=>[...$t->settings,'enabled'=>true]]);
Carbon::setTestNow(now()->addMinute());$observe(false);
$busy=TunnelOperation::create(['action'=>'restart','tunnel_id'=>$t->id,'status'=>'succeeded','deadline'=>now()->addMinute()]);
Carbon::setTestNow(now()->addMinute());$observe(false);
$assert(!$state()->incident,'maintenance grace prevents planned restarts from causing outage alerts');
$busy->update(['updated_at'=>now()->subHour()]);
DB::table('speedbox_deployments')->insert(['managed_server_id'=>$t->iran_server_id,'status'=>'running']);
$assert($monitor->quiet($t->fresh(),true),'server deployment suppresses alerts on the shared host');
DB::table('speedbox_deployments')->delete();
$sample=$t->fresh();$sample->settings=[...$sample->settings,'restart_hours'=>1];$sample->remote_status=['iran'=>['active'=>false,'connected'=>false,'last_restart'=>now()->timestamp]];
$assert($monitor->health($sample)===null,'automatic periodic restart receives a grace period');
foreach(['backpack','gost','gre'] as $engine){
    $sample=$t->fresh();$sample->engine=$engine;
    $sample->remote_status=['iran'=>['active'=>true,'connected'=>false,'metrics'=>['connected'=>false]],'foreign'=>['active'=>true,'connected'=>false,'metrics'=>['connected'=>false]]];
    $assert($monitor->health($sample)===false,$engine.' confirmed down state is recognized');
    $sample->remote_status=['iran'=>['active'=>true,'connected'=>true,'origin_endpoint'=>true,'path_verified'=>true,'metrics'=>['connected'=>true]],'foreign'=>['active'=>true,'connected'=>true,'origin_endpoint'=>true,'path_verified'=>true,'metrics'=>['connected'=>true]]];
    $assert($monitor->health($sample)===true,$engine.' confirmed recovery state is recognized');
}
$sample=$t->fresh();$sample->remote_status=['iran'=>['active'=>true,'connected'=>null],'foreign'=>['active'=>true,'connected'=>null,'origin_endpoint'=>true]];
$assert($monitor->health($sample)===null,'unprobed UDP is never called disconnected');
$sample->remote_status=['iran'=>['active'=>true,'connected'=>true,'origin_endpoint'=>true,'path_verified'=>true],'foreign'=>['active'=>true,'connected'=>null,'origin_endpoint'=>true]];
$assert($monitor->health($sample)===true,'GOST recovers when Iran confirms its destinations; foreign has no duplicate probe');
$sample->transport='grpc';$sample->remote_status=['iran'=>['active'=>true,'connected'=>true,'origin_endpoint'=>true,'path_verified'=>true],'foreign'=>['active'=>false,'connected'=>null]];
$assert($monitor->health($sample)===false,'legacy GOST gRPC still requires its foreign service');
$sample->engine='gre';$sample->settings=[...$sample->settings,'quota_mode'=>'enforce'];$sample->remote_status=['iran'=>['active'=>false,'connected'=>false,'quota_exhausted'=>true]];
$assert($monitor->health($sample)===null,'a configured quota stop is not mislabeled as an accidental outage');

// Scheduler creates real status jobs and skips unavailable bots/disabled tunnel flags.
foreach(ServerTunnel::whereKeyNot($t->id)->get() as $other)$other->update(['settings'=>[...$other->settings,'enabled'=>false]]);
$t->refresh();$monitor->reset($t->id);DB::table('speedbox_tunnel_alerts')->where('tunnel_id',$t->id)->update(['next_probe_at'=>now()->subMinute()]);
Bus::fake();$monitor->tick();
$pending=TunnelOperation::where('tunnel_id',$t->id)->whereIn('status',['queued','running'])->latest('id')->first();
$assert($pending?->action==='status' && DB::table('speedbox_tunnel_leases')->where('operation_id',$pending->id)->count()===2,'scheduler obtains the shared host leases for a fresh probe');
foreach(TunnelOperation::whereIn('status',['queued','running'])->get() as $pending)$manager->finish($pending,'failed','fixture unavailable');
$bot->update(['enabled'=>false]);$count=TunnelOperation::count();$monitor->tick();
$assert(TunnelOperation::count()===$count,'inactive bot disables autonomous probes');$bot->update(['enabled'=>true]);

// Polling remains active with Telegram disabled, and inaccessible endpoints get a distinct alert.
foreach(TunnelOperation::whereIn('status',['queued','running'])->get() as $pending)$manager->finish($pending,'failed','fixture finish');
TunnelOperation::query()->update(['updated_at'=>now()->subHour()]);
App\Models\TunnelConnectionTest::query()->update(['updated_at'=>now()->subHour()]);
$t->refresh();$t->update(['status'=>'connected','settings'=>[...$base,'enabled'=>true,'telegram_alerts'=>false]]);
DB::table('speedbox_tunnel_alerts')->where('tunnel_id',$t->id)->update(['next_probe_at'=>now()->subMinute()]);
$bot->update(['enabled'=>false]);$before=TunnelOperation::count();$monitor->tick();
$assert(TunnelOperation::count()>$before,'health probes do not require Telegram capability or opt-in');
foreach(TunnelOperation::whereIn('status',['queued','running'])->get() as $pending)$manager->finish($pending,'failed','fixture finish');
$bot->update(['enabled'=>true]);$t->refresh();$t->update(['settings'=>[...$t->settings,'telegram_alerts'=>true]]);
$monitor->reset($t->id);TunnelOperation::query()->update(['updated_at'=>now()->subHour()]);
$unreachable=function()use($t,$manager){
 $t->update(['status'=>'needs_review','remote_status'=>['_checked_at'=>now()->timestamp,'iran'=>['error'=>'unreachable'],'foreign'=>['active'=>true,'connected'=>true,'origin_endpoint'=>true,'path_verified'=>true]]]);
 $op=$manager->queue('status',$t->fresh(),null,['outage_probe'=>true]);$manager->finish($op,'failed','unreachable');
};
Carbon::setTestNow(now()->addMinute());$unreachable();$assert($state()->failures===1,'one inaccessible observation alone is insufficient');
Carbon::setTestNow(now()->addMinute());$unreachable();$assert($state()->incident!==null,'two inaccessible observations open their own incident');
Http::swap(new Illuminate\Http\Client\Factory());Http::preventStrayRequests();Http::fake(['api.telegram.org/*'=>Http::response(['ok'=>true],200)]);
(new SendTunnelOutageAlert($t->id,$state()->incident))->handle($monitor);
Http::assertSent(fn($r)=>str_contains($r['text'],'عدم دسترسی به سرور تونل')&&!str_contains($r['text'],'🔴 قطع ارتباط تونل'));
$assert(true,'inaccessible-host notification is distinct from confirmed tunnel loss');

// Emergency tags survive real CRUD and share the subscription's existing client gate.
Schema::create('v2_settings',function(Blueprint $t){$t->id();$t->string('name')->unique();$t->text('value')->nullable();$t->timestamps();});
$emergency=new App\Http\Controllers\Api\Admin\EmergencyConfigController();
$parser=new App\Services\EmergencyConfigParser();
$uri='trojan://outside-secret@external.test:443?sni=cert.test';
$response=$emergency->store(Request::create('/','POST',['name'=>'Only SpeedBox','config_uri'=>$uri,'show'=>true,'tags'=>[' SBONLY ','route-a']]),$parser);
$row=EmergencyConfig::findOrFail($response->getData()->data->id);
$assert($row->tags===['SBONLY','route-a'],'tags are trimmed and stored in create');
$assert(!str_contains(json_encode($row->toArray()),'outside-secret'),'adding tags does not expose encrypted config credentials in lists');
$emergency->update(Request::create('/','PUT',['name'=>'Only SpeedBox','config_uri'=>$uri,'show'=>true]),$row,$parser);
$assert($row->fresh()->tags===['SBONLY','route-a'],'older clients omitting tags do not erase an existing restriction');
EmergencyConfig::create(['name'=>'Public emergency','config_uri'=>$uri,'show'=>true,'protocol'=>'trojan','host'=>'external.test','port'=>443,'sort'=>2]);
$servers=app(EmergencyConfigurations::class)->merge([['name'=>'Normal','tags'=>[],'type'=>'trojan']]);
$filter=new ReflectionMethod(App\Http\Controllers\Api\SubscriptionController::class,'speedBoxServers');
$sub=new App\Http\Controllers\Api\SubscriptionController();
$app['config']->set('services.speedbox.sub_key','correct-key');
$normal=$filter->invoke($sub,$servers,Request::create('/','GET'));
$assert(count($normal)===2 && !in_array('Only SpeedBox',array_column($normal,'name')),'ordinary subscriptions exclude SBONLY emergency configs');
$request=Request::create('/','GET');$request->headers->set('X-SpeedBox-Key','wrong-key');
$assert(count($filter->invoke($sub,$servers,$request))===2,'a wrong client key cannot bypass emergency filtering');
$request->headers->set('X-SpeedBox-Key','correct-key');
$assert(count($filter->invoke($sub,$servers,$request))===3,'valid SpeedBox client receives the restricted emergency config');
$row->update(['tags'=>['sbonly']]);$servers=app(EmergencyConfigurations::class)->merge([]);
$assert(count($filter->invoke($sub,$servers,Request::create('/','GET')))===1,'restriction is case-insensitive like managed nodes');
$emergency->update(Request::create('/','PUT',['name'=>'Only SpeedBox','config_uri'=>$uri,'show'=>true,'tags'=>[]]),$row->fresh(),$parser);
$assert($row->fresh()->tags===[],'explicitly removing tags restores ordinary visibility');
try{$emergency->store(Request::create('/','POST',['name'=>'Bad tag','config_uri'=>$uri,'show'=>true,'tags'=>["bad\nline"]]),$parser);$assert(false,'control character tag accepted');}
catch(Illuminate\Validation\ValidationException){$assert(true,'tag control characters are rejected');}
$migration->down();
$assert(!Schema::hasTable('speedbox_tunnel_alerts') && !Schema::hasColumn('speedbox_emergency_configs','tags'),'schema rollback removes only the added schema');
$assert(EmergencyConfig::count()===2 && ServerTunnel::count()>0,'schema rollback preserves existing emergency links and tunnels');
Carbon::setTestNow();Mockery::close();
echo "PASS: $checks tunnel alert and emergency tag assertions\n";
