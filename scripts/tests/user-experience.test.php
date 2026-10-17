<?php

// No project .env, migrations against live data, or live services. Default: SQLite
// :memory:. --mysql: CREATE/DROP only a random, dedicated test database using
// explicitly supplied SPEEDBOX_TEST_MYSQL_SOCKET or SPEEDBOX_TEST_MYSQL_PORT.
// --worker is private to the MySQL concurrency regressions.
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

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
$worker = ($argv[1] ?? '') === '--worker';
$mysql = in_array('--mysql', $argv, true) || $worker;
$temporary = $worker ? ($argv[2] ?? '') : sys_get_temp_dir().'/speedbox-user-experience-'.bin2hex(random_bytes(10));
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
    } elseif (!preg_match('/^speedbox-user-experience-[a-f0-9]{20}$/D', basename($temporary)) || !is_file($temporary.'/worker.json')) {
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
            $check(preg_match('/^speedbox_user_experience_test_[a-f0-9]{20}$/D', $connection['database'] ?? '') === 1, 'Dedicated worker database');
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
            $database = 'speedbox_user_experience_test_'.bin2hex(random_bytes(10));
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
        $label=$argv[3]??'';if(!in_array($label,['a','b'],true))throw new RuntimeException('Invalid worker label');
        $job=json_decode(file_get_contents($temporary.'/job-'.$label.'.json'),true,512,JSON_THROW_ON_ERROR);
        $owner=App\Models\User::findOrFail($job['owner']);auth()->setUser($owner);
        $request=Request::create('/api/panel/users/create','POST',['panel_code'=>$owner->code,'plan_key'=>'1m','tariff_group_id'=>$job['group'],'custom_username'=>'race'],[],[],['HTTP_ACCEPT'=>'application/json']);$request->setUserResolver(fn()=>$owner);
        file_put_contents($temporary.'/ready-'.$label,'ready');$deadline=microtime(true)+20;while(!is_file($temporary.'/go')&&microtime(true)<$deadline)usleep(10000);if(!is_file($temporary.'/go'))throw new RuntimeException('Barrier timeout');
        $response=app(App\Http\Controllers\Api\V2UserController::class)->create($request,app(App\Services\V2UserCreator::class),app(App\Services\PanelPricingService::class));
        file_put_contents($temporary.'/result-'.$label.'.json',json_encode($response->getData(true)));echo $response->getStatusCode();exit(0);
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
    $makeOwner = function (string $code, int $role = 2) {
        return App\Models\User::forceCreate(['name' => $code, 'email' => $code.'@fixture.test', 'password' => Illuminate\Support\Facades\Hash::make('fixture-password'), 'code' => $code, 'role' => $role, 'credit' => 1000000, 'partner_plan_type' => 'time']);
    };
    $owner = $makeOwner('uxrep'); $other = $makeOwner('otherrep'); $manager = $makeOwner('uxadmin', 1);
    $plan = DB::table('v2_plan')->insertGetId(['name'=>'UX plan','group_id'=>1,'transfer_enable'=>10,'reset_traffic_method'=>2,'created_at'=>time(),'updated_at'=>time()]);
    $group = (int)DB::table('panel_tariff_groups')->where('group_type','time')->where('is_default',1)->value('id');
    DB::table('panel_plan')->updateOrInsert(['tariff_group_id'=>$group,'plan_key'=>'1m'],['name'=>'UX tariff','default_price'=>100,'enable'=>1,'v2_plan_id'=>$plan]);
    $creator = app(App\Services\V2UserCreator::class);
    $request = function ($user, array $data = [], string $method = 'POST') {
        $request=Request::create('/api/fixture', $method, $data, [], [], ['REMOTE_ADDR'=>'192.0.2.100','HTTP_ACCEPT'=>'application/json']);
        $request->setUserResolver(fn()=>$user); auth()->setUser($user); return $request;
    };
    $create = function ($owner, string $name) use ($request, $group, $creator) {
        return app(App\Http\Controllers\Api\V2UserController::class)->create($request($owner,['panel_code'=>$owner->code,'plan_key'=>'1m','tariff_group_id'=>$group,'custom_username'=>$name]), $creator, app(App\Services\PanelPricingService::class));
    };
    $reject = function (callable $operation) use ($check) { try { $operation(); } catch (Illuminate\Validation\ValidationException $error) { $check(true,'Invalid custom username rejected'); return; } throw new RuntimeException('Expected username rejection'); };
    $check((new ReflectionMethod($creator, 'createUsers'))->getNumberOfParameters()===8,'Existing creation contract remains compatible with extensions');
    $response=$create($owner,' Alice ');$data=$response->getData(true);
    $check($response->getStatusCode()===200 && ($data['created'][0]['email']??'')==='alice@uxrep.com','Partner API creates a normalized custom name with its own panel suffix: '.json_encode($data,JSON_UNESCAPED_UNICODE));
    $id=(int)$data['created'][0]['id'];
    $fresh = DB::table('v2_user')->where('id', $id)->first();
    $check((int) $fresh->reset_count === 0 && $fresh->last_reset_at === null, 'New account has no fictitious reset');
    $check(DB::table('v2_traffic_reset_logs')->where('user_id', $id)->count() === 0, 'Creation writes no reset log');
    $repair = app(App\Services\TrafficResetBaselineRepair::class);
    DB::table('v2_user')->where('id', $id)->update(['reset_count'=>1,'last_reset_at'=>$fresh->created_at]);
    $preview = $repair->run();
    $check(in_array($id, array_column($preview['accounts'], 'id'), true) && (int) DB::table('v2_user')->where('id',$id)->value('reset_count') === 1, 'Repair preview detects proven creation seed without writing');
    $repair->run(true);
    $check((int) DB::table('v2_user')->where('id',$id)->value('reset_count') === 0 && DB::table('v2_user')->where('id',$id)->value('last_reset_at') === null, 'Repair clears only the proven creation seed');
    $check($repair->run(true)['count'] === 0, 'Baseline repair is idempotent');

    $credit=(float)DB::table('panel_users')->where('id',$owner->id)->value('credit');
    $check($credit===999900.0,'Exactly one charge for named account');
    $before=DB::table('v2_user')->count();$response=$create($owner,'ALICE');
    $check($response->getStatusCode()===422 && str_contains($response->getData(true)['message'],'قبلاً'),'Duplicate names return a clear validation response');
    $check(DB::table('v2_user')->count()===$before && (float)DB::table('panel_users')->where('id',$owner->id)->value('credit')===$credit,'Duplicate failure creates no account and deducts no credit');
    $check(($create($other,'alice')->getData(true)['created'][0]['email']??'')==='alice@otherrep.com','Another panel may use the same prefix');
    foreach (['bad@name','<script>','نام',str_repeat('a',33),'-starts-with-hyphen'] as $name) $reject(fn()=>$creator->createUsersWithUsername($owner->code,$plan,1,'1m',customUsername:$name));
    $reject(fn()=>$creator->createUsersWithUsername($owner->code,$plan,2,'1m',customUsername:'bulk'));
    $random=$creator->createUsers($owner->code,$plan,2,'1m')['created'];
    $check(count($random)===2 && $random[0]['email']!==$random[1]['email'],'Automatic batch creation stays available');
    $adminResponse=app(App\Http\Controllers\Api\Admin\UserAdminController::class)->create($request($manager,['panel_code'=>'custom-code','plan_key'=>'1m','count'=>1,'tariff_group_id'=>$group,'custom_username'=>'admin-name']),$creator);
    $check(($adminResponse->getData(true)['created'][0]['email']??'')==='admin-name@custom-code.com','Admin custom name and custom panel code work together');

    // Login time comes from the real rate limiter and decreases rather than restarting.
    App\Services\RuntimeSetting::set('password_limit_enable',true,'safe');
    App\Services\RuntimeSetting::set('password_limit_count',2,'safe');
    App\Services\RuntimeSetting::set('password_limit_expire',2,'safe');
    $login = fn($password)=>app(App\Http\Controllers\Api\LoginController::class)->login($request($owner,['email'=>$owner->email,'password'=>$password]));
    $check($login('wrong')->getStatusCode()===401 && $login('wrong')->getStatusCode()===401,'Configured failed login attempts are counted');
    $limited=$login('fixture-password');$wait=$limited->getData(true)['retry_after']??0;
    $check($limited->getStatusCode()===429 && $wait>0 && $wait<=120 && (int)$limited->headers->get('Retry-After')===$wait,'Lockout returns actual remaining seconds in body and header');
    $check(str_contains($limited->getData(true)['message'],'دقیقه') && preg_match('/[۰-۹]/u',$limited->getData(true)['message']),'Persian lockout message includes duration');
    Illuminate\Support\Carbon::setTestNow(now()->addSeconds(61));
    $later=$login('fixture-password');
    $check(($later->getData(true)['retry_after']??0)<$wait && str_contains($later->getData(true)['message'],'ثانیه') && !str_contains($later->getData(true)['message'],'دقیقه'),'Sub-minute lockout uses remaining seconds');
    Illuminate\Support\Carbon::setTestNow(now()->addSeconds(61));
    $check($login('fixture-password')->getStatusCode()===200,'Login succeeds after lockout expires');
    Illuminate\Support\Carbon::setTestNow();

    // Online information is an opt-in snapshot, using the existing account/IP count.
    foreach ([[1,'192.0.2.1',time()+60],[2,'192.0.2.1',time()+60],[3,'192.0.2.2',time()+60],[4,'192.0.2.3',time()-1]] as [$node,$ip,$expiry]) DB::table('speedbox_connection_leases')->insert(['user_id'=>$id,'node_id'=>$node,'source_ip'=>$ip,'lease_id'=>bin2hex(random_bytes(16)),'expires_at'=>$expiry]);
    DB::table('v2_user')->where('id',$id)->update(['device_limit'=>3]);
    $server=['name'=>'🇹🇷 Fixture','type'=>'vmess','port'=>443];
    $decorate=fn()=> (new ReflectionMethod(App\Http\Controllers\Api\SubscriptionController::class,'decorateServers'))->invoke(app(App\Http\Controllers\Api\SubscriptionController::class),[$server],App\Models\SubscriptionAccount::findOrFail($id));
    App\Services\RuntimeSetting::set('show_info_to_server_enable',false,'subscribe');App\Services\RuntimeSetting::set('show_online_to_server_enable',false,'subscribe');
    $check(count($decorate())===1,'Online information is disabled by default');
    App\Services\RuntimeSetting::set('show_online_to_server_enable',true,'subscribe');$decorated=$decorate();
    $check(count($decorated)===2 && str_contains($decorated[0]['name'],'🔌 Online : 2 / 3'),'Online row counts duplicate IPs once and excludes expired leases');
    DB::table('v2_user')->where('id',$id)->update(['device_limit'=>0]);
    $check(str_contains($decorate()[0]['name'],'2 / ∞'),'Unlimited connection limit displays infinity');
    App\Services\RuntimeSetting::set('show_info_to_server_enable',true,'subscribe');
    $check(count(array_filter($decorate(),fn($item)=>str_contains($item['name'],'Online :')))===1,'Online row is not duplicated when other subscription information is enabled');
    $field=array_values(array_filter(App\Services\SystemConfiguration::fields('subscribe'),fn($field)=>$field['name']==='show_online_to_server_enable'));
    $check(count($field)===1 && $field[0]['type']==='boolean','Online visibility is an editable subscription setting');

    // Reset reasons remain escaped text; other panels cannot read them.
    $reason='درخواست کاربر <script>alert(1)</script>';
    $operations=app(App\Http\Controllers\Api\Admin\AccountOperationsController::class);
    $operations->resetTraffic($request($manager,['confirmed'=>true,'reason'=>$reason]),$id);
    $adminHistory=$operations->resetHistory($request($manager,[],'GET'),$id)->getData(true);
    $check(($adminHistory['data'][0]['reason']??null)===$reason,'Admin reset history includes the stored reason');
    $real = DB::table('v2_user')->where('id',$id)->first();
    $check((int) $real->reset_count === 1 && $real->last_reset_at !== null, 'First real reset is number one');
    DB::table('v2_user')->where('id',$id)->update(['reset_count'=>2]);
    $repair->run(true);
    $check((int) DB::table('v2_user')->where('id',$id)->value('reset_count') === 1 && (int) DB::table('v2_user')->where('id',$id)->value('last_reset_at') === (int) $real->last_reset_at, 'Legacy seed correction preserves the real last reset');
    $check(DB::table('v2_traffic_reset_logs')->where('user_id',$id)->count() === 1 && $operations->resetHistory($request($manager,[],'GET'),$id)->getData(true)['data'][0]['reason'] === $reason, 'Repair preserves real log and reason');
    DB::table('v2_user')->where('id',$id)->update(['reset_count'=>7]);
    $check($repair->run(true)['count'] === 0 && (int) DB::table('v2_user')->where('id',$id)->value('reset_count') === 7, 'Incomplete historical evidence is never guessed away');
    DB::table('v2_user')->where('id',$id)->update(['reset_count'=>1]);
    $unproved = (int) $random[0]['id'];
    $unprovedUser = DB::table('v2_user')->where('id',$unproved)->first();
    DB::table('v2_user')->where('id',$unproved)->update(['reset_count'=>1,'last_reset_at'=>$unprovedUser->created_at]);
    $check($repair->run(true)['count'] === 0 && (int) DB::table('v2_user')->where('id',$unproved)->value('reset_count') === 1, 'An account without the original purchase ledger is preserved');

    $partnerHistory=app(App\Http\Controllers\Api\PartnerAccountReadController::class)->resetHistory($request($owner,[],'GET'),$id)->getData(true);
    $check(($partnerHistory['data'][0]['reason']??null)===$reason && !array_key_exists('metadata',$partnerHistory['data'][0]),'Partner receives reason without internal metadata or operator identifiers');
    try { app(App\Http\Controllers\Api\PartnerAccountReadController::class)->resetHistory($request($other,[],'GET'),$id); throw new RuntimeException('Cross-panel history exposed'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { $check($error->getStatusCode()===404,'Reset history remains scoped to its owner'); }

    if ($mysql) {
        $jobs=[];
        foreach (['a','b'] as $label) {
            file_put_contents($temporary.'/job-'.$label.'.json',json_encode(['owner'=>$owner->id,'group'=>$group]));
            $workerProcess=new Process([getenv('PHP_AUDIT_BIN')?:PHP_BINARY,__FILE__,'--worker',$temporary,$label]);$workerProcess->setTimeout(35);$workerProcess->start();$processes[]=$workerProcess;$jobs[]=$workerProcess;
        }
        $deadline=microtime(true)+20;while((!is_file($temporary.'/ready-a')||!is_file($temporary.'/ready-b'))&&microtime(true)<$deadline)usleep(10000);
        $check(is_file($temporary.'/ready-a')&&is_file($temporary.'/ready-b'),'Both concurrent named requests reached the barrier');
        $beforeCredit=(float)DB::table('panel_users')->where('id',$owner->id)->value('credit');file_put_contents($temporary.'/go','go');
        $statuses=[];foreach($jobs as $job){$job->wait();$check($job->isSuccessful(),'Concurrent request exited normally: '.$job->getErrorOutput());$statuses[]=(int)trim($job->getOutput());}sort($statuses);
        $check($statuses===[200,422] && DB::table('v2_user')->where('email','race@uxrep.com')->count()===1,'Concurrent duplicate names create one account and one validation response: '.json_encode($statuses).' '.file_get_contents($temporary.'/result-a.json').' '.file_get_contents($temporary.'/result-b.json'));
        $check((float)DB::table('panel_users')->where('id',$owner->id)->value('credit')===$beforeCredit-100,'Concurrent duplicate requests deduct exactly one purchase');
    }
    echo 'USER EXPERIENCE OK: '.$checks.' checks; '.($mysql?'MySQL; concurrent API requests and temporary database only':'SQLite :memory: fixture; no live database').PHP_EOL;

} catch (Throwable $error) {
    fwrite(STDERR, 'USER EXPERIENCE FAILED: '.$error->getMessage()."\n");
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
