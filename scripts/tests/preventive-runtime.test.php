<?php

// No project .env, migrations against live data, or live services. Default: SQLite
// :memory:. --mysql: CREATE/DROP only a random, dedicated test database using
// explicitly supplied SPEEDBOX_TEST_MYSQL_SOCKET or SPEEDBOX_TEST_MYSQL_PORT.
// --timed adds 70 seconds of real renewals. --worker is private to the race test.
use App\Models\ProxyServer;
use App\Models\ServerMachine;
use App\Models\SubscriptionAccount;
use App\Services\ConnectionAdmission;
use App\Services\RuntimeSetting;
use App\Services\SubscriptionResetDay;
use App\Services\SubscriptionTrafficReset;
use App\Services\SubscriptionValidity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
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
$worker = ($argv[1] ?? '') === '--worker';
$mysql = in_array('--mysql', $argv, true) || $worker;
$timed = in_array('--timed', $argv, true);
$temporary = $worker ? ($argv[2] ?? '') : sys_get_temp_dir().'/speedbox-preventive-'.bin2hex(random_bytes(10));
$database = $admin = null;
$processes = [];
$checks = 0;
$check = static function ($ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
try {
    if (!$worker) {
        mkdir($temporary, 0700, true);
        foreach (['framework/cache/data', 'framework/views', 'framework/sessions', 'logs'] as $part) mkdir($temporary.'/storage/'.$part, 0700, true);
    } elseif (!preg_match('/^speedbox-preventive-[a-f0-9]{20}$/D', basename($temporary)) || !is_file($temporary.'/worker.json')) {
        throw new RuntimeException('Invalid worker fixture.');
    }
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
        if ($worker) {
            $connection = json_decode(file_get_contents($temporary.'/worker.json'), true, 512, JSON_THROW_ON_ERROR);
            $check(preg_match('/^speedbox_preventive_test_[a-f0-9]{20}$/D', $connection['database'] ?? '') === 1, 'Dedicated worker database');
        } else {
            $socket = getenv('SPEEDBOX_TEST_MYSQL_SOCKET') ?: '';
            $port = getenv('SPEEDBOX_TEST_MYSQL_PORT') ?: '';
            $check($socket !== '' || (ctype_digit($port) && (int) $port > 0 && (int) $port <= 65535), 'Explicit isolated MySQL socket/port required');
            $connection = ['driver'=>'mysql', 'host'=>'127.0.0.1', 'port'=>$port ?: 3306, 'unix_socket'=>$socket,
                'username'=>getenv('SPEEDBOX_TEST_MYSQL_USER') ?: 'root', 'password'=>getenv('SPEEDBOX_TEST_MYSQL_PASSWORD') ?: '',
                'database'=>'mysql', 'charset'=>'utf8mb4', 'collation'=>'utf8mb4_unicode_ci', 'prefix'=>'', 'strict'=>true,
                'options'=>[PDO::ATTR_EMULATE_PREPARES=>false]];
            config(['database.connections.preventive_admin'=>$connection]);
            $admin = DB::connection('preventive_admin');
            $database = 'speedbox_preventive_test_'.bin2hex(random_bytes(10));
            $admin->statement("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $connection['database'] = $database;
            file_put_contents($temporary.'/worker.json', json_encode($connection, JSON_THROW_ON_ERROR));
            chmod($temporary.'/worker.json', 0600);
        }
        config(['database.default'=>'preventive', 'database.connections.preventive'=>$connection]);
        DB::setDefaultConnection('preventive');
        $check(DB::connection()->getDriverName() === 'mysql', 'Actual MySQL PDO driver');
        if (!$worker) echo 'Test engine: MySQL '.DB::selectOne('SELECT VERSION() AS v')->v."; random disposable database only.\n";
    }
    if ($worker) {
        [$userId, $nodeId, $ip] = array_slice($argv, 3);
        file_put_contents($temporary.'/ready-'.$userId.'-'.$ip, 'ready');
        $until = microtime(true) + 15;
        while (!is_file($temporary.'/go') && microtime(true) < $until) usleep(10000);
        if (!is_file($temporary.'/go')) throw new RuntimeException('Race barrier timed out.');
        $result = (new ConnectionAdmission)->handle(ProxyServer::findOrFail($nodeId), ['user_id'=>(int) $userId, 'source_ip'=>$ip, 'lease_id'=>hash('sha256', $ip), 'operation'=>'acquire']);
        echo json_encode($result, JSON_THROW_ON_ERROR);
        exit(0);
    }
    // Infrastructure has a real MySQL foreign key to panel_users.
    Schema::create('panel_users', function ($table) { $table->id(); $table->string('code'); });
    DB::table('panel_users')->insert(['code'=>'fixture']);
    foreach (['2025_01_01_000000_create_speedbox_core_tables.php', '2026_10_08_000001_create_speedbox_infrastructure_tables.php',
        '2026_10_09_000001_create_speedbox_queue_monitor_tables.php', '2026_10_10_000003_create_connection_admission_leases.php'] as $migration) {
        (require $root.'/database/migrations/'.$migration)->up();
    }
    Schema::table('v2_user', function ($table) {
        $table->string('account_group_type')->nullable();
        foreach (['next_reset_at','last_reset_at'] as $column) $table->unsignedBigInteger($column)->nullable();
        $table->unsignedInteger('reset_count')->default(0);
        $table->unsignedInteger('traffic_warning_version')->default(0);
    });
    $machine = ServerMachine::create(['name'=>'Fixture', 'token'=>str_repeat('t', 48), 'is_active'=>true]);
    $node = ProxyServer::create(['name'=>'Fixture', 'type'=>'vless', 'kernel'=>'xray', 'machine_id'=>$machine->id,
        'host'=>'vpn.example.test', 'port'=>'443', 'server_port'=>443, 'group_ids'=>[1], 'rate'=>1, 'enabled'=>true, 'show'=>true]);
    $otherNode = $node->replicate(); $otherNode->save();
    $plan = DB::table('v2_plan')->insertGetId(['group_id'=>1, 'name'=>'Fixture', 'reset_traffic_method'=>null, 'created_at'=>time(), 'updated_at'=>time()]);
    $userId = DB::table('v2_user')->insertGetId(['email'=>'renew@fixture.test', 'password'=>'fixture', 'uuid'=>Illuminate\Support\Str::uuid()->toString(),
        'token'=>str_repeat('a',32), 'group_id'=>1, 'plan_id'=>$plan, 'account_group_type'=>'time', 'device_limit'=>0,
        'transfer_enable'=>1000000, 'expired_at'=>time()+366*86400, 'created_at'=>time(), 'updated_at'=>time()]);
    $auth = ['machine_id'=>$machine->id, 'node_id'=>$node->id, 'token'=>$machine->token];
    $http = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $send = static function (array $data, string $ip='198.51.100.20', string $path='connections/lease', string $method='POST') use ($http) {
        $request = Request::create('https://preventive.example.test/api/v2/server/'.$path, $method, $data, [], [], ['REMOTE_ADDR'=>$ip, 'HTTP_ACCEPT'=>'application/json']);
        $response = $http->handle($request); $http->terminate($request, $response);
        return $response;
    };
    $lease = ['user_id'=>$userId, 'source_ip'=>'192.0.2.1', 'lease_id'=>hash('sha256','lease'), 'operation'=>'acquire'];
    $allowed = static fn ($response) => $response->getStatusCode() === 200 && json_decode($response->getContent(), true)['allowed'] === true;
    $check($allowed($send($lease+$auth)), 'Authenticated lease is admitted');
    config(['speedbox.node_rate_limit'=>2, 'speedbox.node_ip_rate_limit'=>3]);
    // Invalid traffic exhausts both buckets; rotating fake IDs cannot evade the IP bucket.
    $bad = $auth; $bad['token']='bad';
    $check($send($lease+$bad)->getStatusCode() === 401, 'Bad token rejected');
    $check($send($lease+$bad)->getStatusCode() === 401, 'Bad token consumes node budget');
    $check($send($lease+$bad)->getStatusCode() === 429, 'Invalid node requests are throttled');
    $bad['machine_id']=999999;
    $check($send($lease+$bad)->getStatusCode() === 401, 'Rotating node ID still consumes shared IP budget');
    $bad['machine_id']=999998;
    $check($send($lease+$bad)->getStatusCode() === 429, 'Shared IP budget throttles rotating fake IDs');
    $check($allowed($send(array_replace($lease,['operation'=>'renew'])+$auth)), 'Exhausted invalid buckets do not block a valid renewal');
    $app['cache']->flush();
    $check($send(['operation'=>'acquire']+$auth)->getStatusCode() === 422, 'Malformed authenticated request rejected');
    $send(['operation'=>'acquire']+$auth);
    $check($send(['operation'=>'acquire']+$auth)->getStatusCode() === 429, 'Malformed authenticated requests remain limited');
    $check($allowed($send(array_replace($lease,['operation'=>'renew'])+$auth)), 'Malformed traffic cannot starve a valid renewal');
    $app['cache']->flush();
    $wrongMachine = ServerMachine::create(['name'=>'Other', 'token'=>'other-token', 'is_active'=>true]);
    $check($send($lease+['machine_id'=>$wrongMachine->id,'token'=>$wrongMachine->token,'node_id'=>$node->id])->getStatusCode() === 404, 'A valid machine cannot borrow another machine node');
    $machine->is_active=false; $machine->save();
    $check($send($lease+$auth, '198.51.100.21')->getStatusCode() === 401, 'Disabled machine rejected');
    $machine->is_active=true; $machine->save();
    $node->enabled=false; $node->save();
    $check($send($lease+$auth, '198.51.100.22')->getStatusCode() === 404, 'Disabled node rejected');
    $node->enabled=true; $node->save();
    RuntimeSetting::set('server_token', str_repeat('s',32));
    $check($allowed($send($lease+['node_id'=>$node->id,'token'=>str_repeat('s',32)], '198.51.100.23')), 'Existing legacy server-token authentication preserved');
    $app['cache']->flush();
    for ($i=0; $i<3; $i++) $response=$send($auth, '198.51.100.24', 'user', 'GET');
    $check($response->getStatusCode() === 429, 'Normal node polling retains its existing ceiling');
    config(['speedbox.api_rate_limit'=>1]);
    foreach ([200,429] as $status) {
        $request=Request::create('https://preventive.example.test/api/ping','GET',[],[],[],['REMOTE_ADDR'=>'198.51.100.25','HTTP_ACCEPT'=>'application/json']);
        $response=$http->handle($request);$http->terminate($request,$response);
        $check($response->getStatusCode() === $status, 'Public API throttling remains active');
    }
    config(['speedbox.api_rate_limit'=>60]);
    config(['speedbox.node_rate_limit'=>1200, 'speedbox.node_ip_rate_limit'=>12000]);
    $app['cache']->flush();
    DB::table('speedbox_connection_leases')->delete();
    $start = microtime(true);
    for ($cycle=0; $cycle<($timed ? 8 : 7); $cycle++) {
        if ($timed) while (microtime(true) < $start+$cycle*10) usleep(50000);
        for ($i=1; $i<=201; $i++) {
            $data = ['user_id'=>$userId,'source_ip'=>'192.0.2.'.$i,'lease_id'=>hash('sha256',(string)$i),'operation'=>$cycle === 0 ? 'acquire' : 'renew'];
            $check($allowed($send($data+$auth)), 'Every valid lease survives beyond the former 1200/minute ceiling');
        }
        $check(DB::table('speedbox_connection_leases')->where('user_id',$userId)->where('expires_at','>',time())->count() === 201, 'All leases remain live through renewal cycle');
    }
    echo 'Lease cycles: '.($timed ? 8 : 7).'; 201 account/IP pairs; '.round(microtime(true)-$start,2)." real seconds.\n";
    DB::table('speedbox_connection_leases')->delete();
    DB::table('v2_user')->where('id',$userId)->update(['device_limit'=>2]);
    $admission = new ConnectionAdmission;
    $first=$lease; $second=array_replace($lease,['source_ip'=>'192.0.2.2','lease_id'=>hash('sha256','second')]);
    $third=array_replace($lease,['source_ip'=>'192.0.2.3','lease_id'=>hash('sha256','third')]);
    $check($admission->handle($node,$first)['allowed'], 'First IP admitted');
    $check($admission->handle($otherNode,$first)['allowed'], 'Same IP across nodes counts once');
    $check($admission->handle($otherNode,$second)['allowed'], 'Second distinct IP admitted');
    $check(!$admission->handle($node,$third)['allowed'], 'Third distinct IP rejected across nodes');
    $check(!$admission->handle($node,array_replace($first,['operation'=>'renew','lease_id'=>str_repeat('f',64)]))['allowed'], 'Lease ownership cannot be stolen by renew');
    $admission->handle($node,array_replace($first,['operation'=>'release']));
    $check(!$admission->handle($node,$third)['allowed'], 'Releasing one node preserves the same IP reservation on another node');
    $admission->handle($otherNode,array_replace($first,['operation'=>'release']));
    $check($admission->handle($node,$third)['allowed'], 'Slot becomes available after the IP leaves every node');
    DB::table('speedbox_connection_leases')->where('user_id',$userId)->update(['expires_at'=>time()-1]);
    $check(!$admission->handle($node,array_replace($third,['operation'=>'renew']))['allowed'], 'Expired lease cannot be revived by renew');
    $check($admission->handle($node,$first)['allowed'], 'Fresh acquisition after expired leases');
    // Display must not change traffic or stored scheduling state.
    DB::table('v2_user')->where('id',$userId)->update(['u'=>123,'d'=>-50,'next_reset_at'=>time()+3*86400]);
    $display = new SubscriptionResetDay; $scheduler = new SubscriptionTrafficReset;
    $account = static fn () => DB::table('v2_user')->where('id',$userId)->first();
    $before=(array)$account();
    $check($display->forUser($account()) === null, 'Missing setting defaults to no reset even with a stale stored date');
    foreach ([0,1,3,4] as $method) {
        DB::table('v2_plan')->where('id',$plan)->update(['reset_traffic_method'=>$method]);
        $user=$account(); $user->next_reset_at=null;
        $check($display->next($user)?->timestamp === $scheduler->next($user), 'Display delegates every enabled Jalali policy');
        $check($display->forUser($user) === max(0,(int)ceil(($scheduler->next($user)-time())/86400)), 'Day count follows actual scheduled date');
        $check($display->forUser($account()) === 3, 'Stored pending date is honored for an enabled policy');
    }
    DB::table('v2_plan')->where('id',$plan)->update(['reset_traffic_method'=>2]);
    $check($display->forUser($account()) === null, 'Explicit never-reset ignores stale pending date');
    DB::table('v2_plan')->where('id',$plan)->update(['reset_traffic_method'=>null]);
    RuntimeSetting::set('reset_traffic_method',1);
    $check($display->forUser($account()) === 3, 'Plan inherits current system policy');
    $user=$account();$user->account_group_type='volume';
    $check($display->forUser($user) === null, 'Volume account does not advertise a reset');
    $user=$account();$user->expired_at=null;
    $check($display->forUser($user) === null, 'No expiry means no periodic scheduler date');
    $user=$account();$user->banned=true;
    $check($display->forUser($user) === null, 'Banned account does not advertise an executable reset');
    $check((array)$account() === $before, 'Display never writes quota, usage, expiry or reset fields');
    $check(SubscriptionValidity::unexpired(null,100), 'NULL remains no expiry');
    $check(SubscriptionValidity::unexpired(0,100) && SubscriptionValidity::unexpired('0',100), 'Integer and database-string zero are unlimited');
    $check(!SubscriptionValidity::unexpired(100,100) && SubscriptionValidity::unexpired(101,100), 'Real expiry boundary remains strict');
    $availability=new ReflectionMethod(App\Http\Controllers\Api\SubscriptionController::class,'isAvailable');
    foreach ([null,0,time(),time()-1,time()+86400] as $expiry) {
        DB::table('v2_user')->where('id',$userId)->update(['expired_at'=>$expiry,'banned'=>0,'u'=>0,'d'=>0]);
        DB::table('speedbox_connection_leases')->delete();
        $expected=SubscriptionValidity::unexpired($expiry);
        $check((new App\Services\NodeControlService)->usersFor($node)->contains('id',$userId) === $expected, 'Node list agrees with expiry policy');
        $check($admission->handle($node,$first)['allowed'] === $expected, 'Lease admission agrees with expiry policy');
        $check($availability->invoke(new App\Http\Controllers\Api\SubscriptionController,SubscriptionAccount::find($userId)) === $expected, 'Subscription agrees with expiry policy');
        $link=(new App\Http\Controllers\AuthLinkController)->validateLink(Request::create('/auth/validate-link','GET',['token'=>str_repeat('a',32)]))->getData(true);
        $check($link['user']['account_expired'] === !$expected, 'App link validation agrees with expiry policy');
        $botText=(new App\Services\UserBot\UserBotAccountResolver)->expiryText(['email'=>'fixture','expired_at'=>$expiry]);
        $check(str_contains($botText,'تاریخ انقضا ندارد') === ($expiry === null || $expiry === 0), 'Bot renders both zero and NULL without an expiry date');
        $request=Request::create('/statistics','GET',['panel_code'=>'fixture']);
        $stats=(new App\Http\Controllers\Api\Admin\PartnerAccountStatisticsController)($request)->getData(true)['data'];
        $check($stats['active'] === (int)$expected && $stats['expired'] === (int)!$expected, 'Partner active/expired counters agree with expiry policy');
    }
    foreach ([['banned'=>1,'u'=>0],['banned'=>0,'u'=>1000000]] as $state) {
        DB::table('v2_user')->where('id',$userId)->update($state+['expired_at'=>time()+86400]);
        $check(!(new App\Services\NodeControlService)->usersFor($node)->contains('id',$userId), 'Banned/exhausted account excluded from node list');
        $check(!$admission->handle($node,$first)['allowed'], 'Banned/exhausted account denied a lease');
    }
    // Ordinary users, administrators and staff can all own subscriptions.
    // Use unrelated IDs so no account-specific deployment exception can pass.
    foreach ([[101,0,0],[202,1,0],[303,0,1]] as [$id,$adminFlag,$staffFlag]) {
        $seed=(array)$account();
        $seed=array_replace($seed,['id'=>$id,'email'=>'role'.$id.'@fixture.test','uuid'=>Illuminate\Support\Str::uuid()->toString(),
            'token'=>md5('role'.$id),'is_admin'=>$adminFlag,'is_staff'=>$staffFlag,'expired_at'=>0,'banned'=>0,'u'=>0,'d'=>0,'device_limit'=>2]);
        DB::table('v2_user')->insert($seed);
        $data=['user_id'=>$id,'source_ip'=>'198.18.0.1','lease_id'=>hash('sha256','role'.$id),'operation'=>'acquire'];
        $check((new App\Services\NodeControlService)->usersFor($node)->contains('id',$id), 'Zero-expiry account is listed regardless of admin/staff flag or ID');
        $check($admission->handle($node,$data)['allowed'], 'Zero-expiry account acquires a lease regardless of role');
        $check($admission->handle($node,array_replace($data,['operation'=>'renew']))['allowed'], 'Zero-expiry account renews regardless of role');
        $secondIp=array_replace($data,['source_ip'=>'198.18.0.2','lease_id'=>hash('sha256','role-second'.$id)]);
        $thirdIp=array_replace($data,['source_ip'=>'198.18.0.3','lease_id'=>hash('sha256','role-third'.$id)]);
        $check($admission->handle($otherNode,$secondIp)['allowed'], 'Second IP allowed on unlimited-time account');
        $check(!$admission->handle($node,$thirdIp)['allowed'], 'Unlimited time never removes per-account IP limit');
        $request=Request::create('https://preventive.example.test/s/'.md5('role'.$id),'GET',['flag'=>'general','raw'=>1]);
        $raw=(new App\Http\Controllers\Api\SubscriptionController)->show($request,md5('role'.$id));
        $check(str_contains($raw->getContent(),$seed['uuid']) && !str_contains($raw->getContent(),'00000000-0000-0000-0000-000000000001'), 'Real subscription exports the zero-expiry account UUID for every role');
        foreach (['shadowrocket','surge','surfboard'] as $flag) {
            $response=(new App\Http\Controllers\Api\SubscriptionController)->show(Request::create('/subscribe','GET',['flag'=>$flag]),md5('role'.$id));
            $body=$flag === 'shadowrocket' ? base64_decode($response->getContent()) : $response->getContent();
            $check(!str_contains($body,'1970-01-01') && !str_contains($body,'1969-12-31'), 'Exporter does not show the epoch as zero expiry');
        }
        $portal=(new App\Http\Controllers\Api\SubscriptionController)->show(Request::create('/subscribe','GET',[],[],[],['HTTP_ACCEPT'=>'text/html','HTTP_SEC_FETCH_DEST'=>'document']),md5('role'.$id));
        $check($portal->getOriginalContent()->getData()['status'] === 'active' && $portal->getOriginalContent()->getData()['remaining_days'] === null, 'Browser portal shows zero-expiry subscription active and unlimited');
        $check($portal->getOriginalContent()->getData()['expired_date'] === 'بدون تاریخ انقضا', 'Portal never formats zero as an expired calendar date');
        foreach ([['banned'=>1],['banned'=>0,'u'=>1000000],['banned'=>0,'u'=>0,'transfer_enable'=>0]] as $blocked) {
            DB::table('v2_user')->where('id',$id)->update($blocked);
            $check(!$admission->handle($node,array_replace($data,['operation'=>'renew']))['allowed'], 'Zero expiry does not bypass ban or quota, including admin accounts');
            $check(!(new App\Services\NodeControlService)->usersFor($node)->contains('id',$id), 'Ineligible unlimited-time account is omitted from node users');
        }
        $check((int)DB::table('v2_user')->where('id',$id)->value('expired_at') === 0, 'No role/account-specific zero conversion');
        DB::table('v2_user')->where('id',$id)->update(['banned'=>0,'u'=>0,'transfer_enable'=>1000000]);
    }
    DB::table('v2_plan')->where('id',$plan)->update(['capacity_limit'=>4]);
    try {
        (new App\Services\V2UserCreator)->createUsers('fixture',$plan,1,'1m');
        throw new RuntimeException('Plan capacity ignored unlimited-time accounts.');
    } catch (Illuminate\Validation\ValidationException $error) {
        $check(isset($error->errors()['plan_id']), 'Unlimited-time accounts consume the same plan capacity as dated accounts');
    }
    DB::table('v2_plan')->where('id',$plan)->update(['capacity_limit'=>null]);
    if ($mysql) {
        // Preserve the existing first-use policy for NULL only. A stored zero is
        // permanently undated, even after use and even on a one-month plan.
        Schema::create('panel_plan', function ($table) { $table->id(); $table->integer('enable'); $table->string('plan_key'); $table->unsignedInteger('v2_plan_id'); });
        DB::table('panel_plan')->insert(['enable'=>1,'plan_key'=>'1m','v2_plan_id'=>$plan]);
        $firstUse=time()-10;
        DB::table('v2_user')->whereIn('id',[$userId,101,202])->update(['t'=>$firstUse]);
        DB::table('v2_user')->where('id',$userId)->update(['expired_at'=>null]);
        $check((new App\Services\V2UserExpiryRunner)->tick() === 1, 'Only NULL expiry starts the existing first-use period');
        $check((int)DB::table('v2_user')->where('id',$userId)->value('expired_at') === $firstUse+30*86400, 'Existing one-month activation policy is preserved');
        $check(DB::table('v2_user')->whereIn('id',[101,202])->where('expired_at',0)->count() === 2, 'Normal and admin subscriptions with zero expiry stay undated after first use');
        // Exercise the real deployment gate against only this disposable schema.
        $preflightConfig=$temporary.'/preflight-config.php';
        file_put_contents($preflightConfig,'<?php return '.var_export($app['config']->all(),true).';');
        $php=getenv('PHP_AUDIT_BIN') ?: PHP_BINARY;
        foreach ([null,0] as $expiry) {
            DB::table('v2_user')->where('id',$userId)->update(['expired_at'=>$expiry]);
            $snapshot=DB::table('v2_user')->orderBy('id')->get()->toJson();
            $gate=new Process([$php,$root.'/scripts/preventive-preflight.php'], $root, ['APP_CONFIG_CACHE'=>$preflightConfig,'SPEEDBOX_PREFLIGHT_ROOT'=>$root]);
            $gate->setTimeout(20);$gate->run();
            $check($gate->getExitCode() === 0, 'Read-only preflight accepts NULL and zero without an account exception: '.$gate->getErrorOutput());
            $check(DB::table('v2_user')->orderBy('id')->get()->toJson() === $snapshot, 'Preflight never changes any account data');
        }
        unlink($preflightConfig);
        DB::table('v2_user')->where('id',$userId)->update(['banned'=>0,'u'=>0,'expired_at'=>time()+86400,'device_limit'=>1]);
        DB::table('speedbox_connection_leases')->delete();
        $php=getenv('PHP_AUDIT_BIN') ?: PHP_BINARY;
        foreach (['203.0.113.1','203.0.113.2'] as $ip) {
            $process=new Process([$php,__FILE__,'--worker',$temporary,(string)$userId,(string)$node->id,$ip]);
            $process->setTimeout(30);$process->start();$processes[]=$process;
        }
        $until=microtime(true)+15;
        while (count(glob($temporary.'/ready-*')) < 2 && microtime(true)<$until) usleep(10000);
        $check(count(glob($temporary.'/ready-*')) === 2, 'Both MySQL processes ready before barrier');
        file_put_contents($temporary.'/go','go');
        $admitted=0;
        foreach ($processes as $process) {
            $process->wait();
            $check($process->isSuccessful(), 'Concurrent admission worker: '.$process->getErrorOutput());
            $admitted+=(int)(json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR)['allowed']);
        }
        $check($admitted === 1 && DB::table('speedbox_connection_leases')->count() === 1, 'MySQL row lock admits exactly one IP under a simultaneous race');
    }
    echo 'PREVENTIVE RUNTIME OK: '.$checks.' checks; '.($mysql ? 'MySQL' : 'SQLite :memory: fixture (not MySQL)')."; temporary data only.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'PREVENTIVE RUNTIME FAILED: '.$error->getMessage()."\n");
    $failed=true;
} finally {
    if (!$worker) {
        foreach ($processes as $process) if ($process->isRunning()) $process->stop();
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
}
exit(isset($failed) ? 1 : 0);
