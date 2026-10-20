<?php

// No project .env, migrations against live data, or live services. Default: SQLite
// :memory:. --mysql: CREATE/DROP only a random, dedicated test database using
// explicitly supplied SPEEDBOX_TEST_MYSQL_SOCKET or SPEEDBOX_TEST_MYSQL_PORT.
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$root = dirname(__DIR__, 2);
if (!extension_loaded('bcmath')) { fwrite(STDERR, "Enable bcmath for isolated financial regressions.\n"); exit(1); }
$loader = require $root.'/vendor/autoload.php';
$map = [];
foreach ($loader->getClassMap() as $class => $file) {
    foreach (['App\\' => 'app/', 'Database\\Factories\\' => 'database/factories/'] as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) continue;
        $path = $root.'/'.$directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) $map[$class] = $path;
    }
}
$loader->addClassMap($map);
$loader->setPsr4('App\\', $root.'/app');
$mysql = in_array('--mysql', $argv, true);
$temporary = sys_get_temp_dir().'/speedbox-online-presence-'.bin2hex(random_bytes(10));
$database = $admin = null;
$checks = 0;
$check = static function ($ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
try {
    mkdir($temporary, 0700, true);
    foreach (['framework/cache/data', 'framework/views', 'framework/sessions', 'logs'] as $part) mkdir($temporary.'/storage/'.$part, 0700, true);
    foreach (['APP_ENV'=>'testing', 'APP_DEBUG'=>'false', 'APP_URL'=>'https://preventive.example.test',
        'APP_KEY'=>'base64:'.base64_encode(str_repeat('a', 32)), 'DB_CONNECTION'=>'sqlite', 'DB_DATABASE'=>':memory:', 'DB_URL'=>'',
        'CACHE_STORE'=>'file', 'SESSION_DRIVER'=>'array', 'QUEUE_CONNECTION'=>'sync', 'LOG_CHANNEL'=>'null',
        'APP_CONFIG_CACHE'=>$temporary.'/config.php', 'APP_ROUTES_CACHE'=>$temporary.'/routes.php', 'APP_EVENTS_CACHE'=>$temporary.'/events.php',
        'LARAVEL_STORAGE_PATH'=>$temporary.'/storage', 'SPEEDBOX_NODE_RATE_LIMIT'=>'1200', 'SPEEDBOX_NODE_IP_RATE_LIMIT'=>'12000'] as $key=>$value) {
        putenv("$key=$value"); $_ENV[$key]=$_SERVER[$key]=$value;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->addAbsoluteCachePathPrefix($temporary);
    $app->useEnvironmentPath($temporary);
    $app->useStoragePath($temporary.'/storage');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($mysql) {
            $socket = getenv('SPEEDBOX_TEST_MYSQL_SOCKET') ?: '';
            $port = getenv('SPEEDBOX_TEST_MYSQL_PORT') ?: '';
            $check($socket !== '' || (ctype_digit($port) && (int) $port > 0 && (int) $port <= 65535), 'Explicit isolated MySQL socket/port required');
            $connection = ['driver'=>'mysql', 'host'=>'127.0.0.1', 'port'=>$port ?: 3306, 'unix_socket'=>$socket,
                'username'=>getenv('SPEEDBOX_TEST_MYSQL_USER') ?: 'root', 'password'=>getenv('SPEEDBOX_TEST_MYSQL_PASSWORD') ?: '',
                'database'=>'mysql', 'charset'=>'utf8mb4', 'collation'=>'utf8mb4_unicode_ci', 'prefix'=>'', 'strict'=>true,
                'options'=>[PDO::ATTR_EMULATE_PREPARES=>false]];
            config(['database.connections.preventive_admin'=>$connection]);
            $admin = DB::connection('preventive_admin');
            $database = 'speedbox_online_presence_test_'.bin2hex(random_bytes(10));
            $admin->statement("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $connection['database'] = $database;
        config(['database.default'=>'preventive', 'database.connections.preventive'=>$connection]);
        DB::setDefaultConnection('preventive');
        $check(DB::connection()->getDriverName() === 'mysql', 'Actual MySQL PDO driver');
        echo 'Test engine: MySQL '.DB::selectOne('SELECT VERSION() AS v')->v."; random disposable database only.\n";
    }
    foreach (glob($root.'/database/migrations/*.php') as $file) {
        // SQLite fixtures do not claim to support a full production installation.
        if (!$mysql && basename($file)==='2026_06_07_000001_expand_panel_bot_sessions_for_actions.php') continue;
        $class=Illuminate\Support\Str::studly(implode('_',array_slice(explode('_',basename($file,'.php')),4)));
        if(class_exists($class,false)){(new $class)->up();continue;}
        $migration=require $file;
        if (!$mysql && basename($file)==='2026_06_18_000001_add_account_snapshot_to_v2_user.php') {
            // This fixture starts with no imported accounts: no MySQL-only data backfill.
            Schema::table('v2_user', function ($table) {
                $table->string('account_plan_name')->nullable();
                $table->string('account_group_type', 20)->nullable();
                $table->unsignedBigInteger('account_tariff_group_id')->nullable();
                $table->string('account_plan_key', 50)->nullable();
            });
        } elseif(is_object($migration))$migration->up();else(new $class)->up();
    }
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $check(!Schema::hasTable('speedbox_sessions') && !Schema::hasTable('speedbox_client_app_releases'), 'Public schema excludes app tables');
    $appRoutes=array_filter(app('router')->getRoutes()->getRoutes(),fn($route)=>preg_match('~client-app|app/heartbeat|speedbox-sessions~',$route->uri()));
    $check($appRoutes===[], 'Public routes exclude private app APIs');
    $status = app(App\Services\AccountOnlineStatus::class);
    $read = app(App\Http\Controllers\Api\PartnerAccountReadController::class);
    $control = app(App\Services\NodeControlService::class);
    $owner = (object) ['id'=>101,'role'=>2,'code'=>'alpha'];
    $other = (object) ['id'=>102,'role'=>2,'code'=>'beta'];
    $adminOwner = (object) ['id'=>103,'role'=>1,'code'=>'admin'];
    $request = function ($owner) { $q=Request::create('/api/panel/dashboard/online','GET');$q->setUserResolver(fn()=>$owner);return $q; };
    $json = fn($response)=>$response->getData(true);
    $makeUser = function($email,$t=0) { return DB::table('v2_user')->insertGetId(['email'=>$email,'password'=>'test','uuid'=>Illuminate\Support\Str::uuid(),'token'=>bin2hex(random_bytes(16)),'t'=>$t,'group_id'=>1,'device_limit'=>5,'transfer_enable'=>1099511627776,'expired_at'=>time()+86400,'created_at'=>time(),'updated_at'=>time()]); };
    $machine=App\Models\ServerMachine::forceCreate(['name'=>'Online presence fixture','token'=>bin2hex(random_bytes(24))]);
    $nodeA=App\Models\ProxyServer::forceCreate(['name'=>'A','machine_id'=>$machine->id,'type'=>'vmess','kernel'=>'xray','host'=>'a.test','port'=>'443','server_port'=>'443','enabled'=>true,'group_ids'=>[1]]);
    $nodeB=App\Models\ProxyServer::forceCreate(['name'=>'B','machine_id'=>$machine->id,'type'=>'vmess','kernel'=>'xray','host'=>'b.test','port'=>'443','server_port'=>'443','enabled'=>true,'group_ids'=>[1]]);
    $alias=App\Models\ProxyServer::forceCreate(['name'=>'Alias','machine_id'=>$machine->id,'type'=>'vmess','kernel'=>'xray','host'=>'alias.test','port'=>'443','server_port'=>'443','enabled'=>true,'parent_id'=>$nodeA->id,'group_ids'=>[1]]);
    $a=$makeUser('one@alpha.com');$b=$makeUser('alpha@custom.test');$c=$makeUser('three@beta.com');$stale=$makeUser('stale@alpha.com',time());
    $admission=app(App\Services\ConnectionAdmission::class);
    $leases=[];
    $acquire=function($node,$user,$ip)use($admission,&$leases,$check){$id=bin2hex(random_bytes(16));$data=['operation'=>'acquire','user_id'=>$user,'source_ip'=>$ip,'lease_id'=>$id];$check($admission->handle($node,$data)['allowed'],'Connection admitted');$leases[]=[$node,$data];};
    $check($status->summary()===['users'=>0,'ips'=>0],'Recent traffic alone does not make a user online');
    $acquire($nodeA,$a,'192.0.2.1');$acquire($nodeB,$a,'192.0.2.1');
    $check($status->summary()===['users'=>1,'ips'=>1],'Same account/IP across nodes counted once globally');
    $acquire($nodeA,$a,'192.0.2.2');$acquire($nodeA,$b,'192.0.2.1');$acquire($nodeB,$c,'192.0.2.3');
    $check($status->summary()===['users'=>3,'ips'=>4],'Users and account/IP pairs remain distinct');
    $check($status->summary('alpha')===['users'=>2,'ips'=>3],'Partner summary includes all supported ownership forms');
    $check($status->summary('beta')===['users'=>1,'ips'=>1],'Other partner isolated');
    $check($status->summary('a%')===['users'=>0,'ips'=>0],'SQL wildcards in owner code are escaped');
    $counts=$status->nodeUserCounts([$nodeA->id,$nodeB->id,$nodeA->id]);
    $check($counts[$nodeA->id]===2 && $counts[$nodeB->id]===2,'Per-node unique users, not IPs or global count');
    $check($status->nodeUserCounts([])===[],'Empty node request');
    // Live leases are counted only after a fresh report confirms a running kernel.
    $check($control->stateFor($nodeA)['online_users']===0, 'Live leases without a node report do not imply a running kernel');
    foreach ([$nodeA,$nodeB] as $node) $control->report($node,['metrics'=>['kernel_status'=>true,'runtime_state'=>'ready']]);
    Illuminate\Support\Facades\Cache::put('speedbox:node:'.$nodeA->id.':online',[$stale=>9],300);
    $check($control->stateFor($nodeA)['online_users']===2,'Node count ignores stale report cache');
    $check($control->stateFor($alias)['online_users']===2,'Alias uses parent runtime leases');
    $response=$read->onlineSummary($request($owner),$status);
    $check($json($response)['data']===['usersOnline'=>2,'onlineIps'=>3],'Authenticated lightweight dashboard endpoint');
    $check(str_contains($response->headers->get('Cache-Control'),'no-store'),'Live summary is not browser-cached');
    $check($json($read->onlineSummary($request($adminOwner),$status))['data']['usersOnline']===3,'Admin sees global count');
    foreach ([null,(object)['role'=>2,'code'=>'']] as $bad) {
        try{$read->onlineSummary($request($bad),$status);throw new RuntimeException('Unauthorized summary accepted');}
        catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===($bad?403:401),'Summary denies missing auth or partner code');}
    }
    DB::connection()->enableQueryLog();DB::connection()->flushQueryLog();
    $nodes=$json(app(App\Http\Controllers\Api\Admin\InfrastructureController::class)->nodes())['data'];
    $queries=array_filter(DB::getQueryLog(),fn($q)=>str_contains($q['query'],'speedbox_connection_leases'));
    $check(count($queries)===1,'Whole node list aggregates leases once, without N+1 queries');DB::connection()->disableQueryLog();
    $nodeMap=array_column($nodes,null,'id');$check($nodeMap[$alias->id]['runtime']['online_users']===2,'Node API preserves alias sharing');
    $control->report($nodeA,['metrics'=>['active_users'=>0,'kernel_status'=>true]]);
    $check(Illuminate\Support\Facades\Cache::get('speedbox:node:'.$nodeA->id.':online')===[],'Omitted online field with explicit zero metrics clears legacy cache');
    $check($control->stateFor($nodeA)['online_users']===2,'A delayed empty report cannot erase live leases');
    $control->report($nodeA,['online'=>[$a=>1],'metrics'=>['active_users'=>1,'kernel_status'=>true]]);
    $control->report($nodeA,['metrics'=>['kernel_status'=>true]]);
    $check(count(Illuminate\Support\Facades\Cache::get('speedbox:node:'.$nodeA->id.':online'))===1,'Missing snapshot is not treated as zero without evidence');
    $control->report($nodeA,['online'=>[]]);
    $check(Illuminate\Support\Facades\Cache::get('speedbox:node:'.$nodeA->id.':online')===[],'Explicit empty online array clears snapshot');
    if ($mysql) {
        Illuminate\Support\Facades\Cache::put('dash:panel',['usersTotal'=>4,'partnersTotal'=>0,'usersOnline'=>999,'usersExpired'=>0],10);
        $cards=$json(app(App\Http\Controllers\Api\Admin\DashboardAdminController::class)->cards($request($adminOwner)));
        $check($cards['panelStatus']['usersOnline']===3 && $cards['panelStatus']['onlineIps']===4,'Admin full cards override a warm stale online cache');
        $cards=$json(app(App\Http\Controllers\Api\DashboardPanelController::class)->cards($request($owner)));
        $check($cards['panelStatus']['usersOnline']===2 && $cards['panelStatus']['onlineIps']===3,'Partner full cards use the same live ownership-scoped summary');
    }
    // Releasing one of a user's two nodes must not make that user globally offline.
    [$node,$data]=$leases[0];$data['operation']='release';$admission->handle($node,$data);
    $check($status->summary()['users']===3 && $status->summary()['ips']===4,'Cross-node duplicate release preserves global pair');
    foreach(array_slice($leases,1) as [$node,$data]){$data['operation']='release';$admission->handle($node,$data);}
    $check($status->summary()===['users'=>0,'ips'=>0],'Disconnecting the last sessions becomes zero immediately');
    $check($control->stateFor($nodeA)['online_users']===0,'Node immediately follows final release');
    $acquire($nodeB,$a,'192.0.2.5');DB::table('speedbox_connection_leases')->update(['expires_at'=>time()-1]);
    $check($status->summary()===['users'=>0,'ips'=>0],'Expired leases do not remain online after a missed release');
    $check($status->nodeUserCounts([$nodeB->id])===[],'Expired node leases ignored');
    $acquire($nodeA,$a,'192.0.2.6');DB::table('v2_user')->where('id',$a)->delete();
    $check($status->summary()===['users'=>0,'ips'=>0] && $status->nodeUserCounts([$nodeA->id])===[],'Deleted accounts cannot leave ghost online counts');
    echo 'ONLINE PRESENCE OK: '.$checks.' checks; '.($mysql?'real MySQL disposable database':'SQLite :memory: fixture').PHP_EOL;

} catch (Throwable $error) {
    fwrite(STDERR, 'ONLINE PRESENCE FAILED: '.$error->getMessage()."\n");
    $failed=true;
} finally {
        if ($database !== null && $admin !== null) {
            try { DB::disconnect('preventive'); $admin->statement("DROP DATABASE `$database`"); }
            catch (Throwable $error) { fwrite(STDERR,"Test database cleanup failed: $database\n");$failed=true; }
        }
        if (is_dir($temporary)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($temporary);
        }
}
exit(isset($failed) ? 1 : 0);
