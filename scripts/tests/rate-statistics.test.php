<?php

// No project .env, migrations against live data, or live services. Default: SQLite
// :memory:. --mysql: CREATE/DROP only a random, dedicated test database using
// explicitly supplied SPEEDBOX_TEST_MYSQL_SOCKET or SPEEDBOX_TEST_MYSQL_PORT.
// --worker is private to the concurrent statistics regression test.
use App\Models\ProxyServer;
use App\Models\ServerMachine;
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
$temporary = $worker ? ($argv[2] ?? '') : sys_get_temp_dir().'/speedbox-rate-statistics-'.bin2hex(random_bytes(10));
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
    } elseif (!preg_match('/^speedbox-rate-statistics-[a-f0-9]{20}$/D', basename($temporary)) || !is_file($temporary.'/worker.json')) {
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
            $check(preg_match('/^speedbox_rate_stat_test_[a-f0-9]{20}$/D', $connection['database'] ?? '') === 1, 'Dedicated worker database');
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
            $database = 'speedbox_rate_stat_test_'.bin2hex(random_bytes(10));
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
        [$userId,$rate,$label] = array_slice($argv,3);
        file_put_contents($temporary.'/ready-'.$label,'ready');
        $until=microtime(true)+15;
        while (!is_file($temporary.'/go') && microtime(true)<$until) usleep(10000);
        if (!is_file($temporary.'/go')) throw new RuntimeException('Race barrier timed out.');
        $method=new ReflectionMethod(App\Services\NodeControlService::class,'upsertUserStat');
        $service=new App\Services\NodeControlService;
        for($i=0;$i<50;$i++) $method->invoke($service,(int)$userId,(float)$rate,0,(int)round(1000*(float)$rate),1700000000,time());
        echo 'STAT WORKER OK';
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
    $service=new App\Services\NodeControlService;
    foreach ([0,1,1.25,1.234,1.235,0.001] as $rate) {
        $validated=App\Support\InfrastructureNodeRules::validate(Request::create('/fixture','POST',[
            'name'=>'Statistics fixture','type'=>'vless','kernel'=>'xray','machine_id'=>null,
            'host'=>'vpn.example.test','port'=>'443','server_port'=>443,'rate'=>1,
            'rate_time_enable'=>true,'rate_time_ranges'=>[['start'=>'00:00','end'=>'23:59','rate'=>$rate]],
            'protocol_settings'=>['network'=>'tcp','tls'=>0],'show'=>true,'enabled'=>true
        ]));
        $check((float)$validated['rate_time_ranges'][0]['rate']===(float)$rate,'Accepted precision is preserved');
        $node->rate_time_enable=true;
        $node->rate_time_ranges=$validated['rate_time_ranges'];$node->save();$node->refresh();
        DB::table('v2_stat_user')->delete();DB::table('v2_stat_server')->delete();
        DB::table('v2_user')->where('id',$userId)->update(['u'=>0,'d'=>0]);
        DB::table('v2_server')->where('id',$node->id)->update(['u'=>0,'d'=>0]);
        $before=time();
        $service->report($node,['traffic'=>[$userId=>[1000,2000]]]);
        DB::table('v2_stat_user')->update(['created_at'=>123]);
        $service->report($node,['traffic'=>[$userId=>[1000,2000]]]);
        $account=DB::table('v2_user')->where('id',$userId)->first();
        $check((int)$account->u===2*(int)round(1000*$rate),'Upload charge is unchanged for rate '.$rate);
        $check((int)$account->d===2*(int)round(2000*$rate),'Download charge is unchanged for rate '.$rate);
        $check((int)DB::table('v2_stat_user')->sum('u')===(int)$account->u,'Upload history matches charge for rate '.$rate);
        $check((int)DB::table('v2_stat_user')->sum('d')===(int)$account->d,'Download history matches charge for rate '.$rate);
        $check(DB::table('v2_stat_user')->count()===1,'One daily statistics row for repeated reports');
        $check((int)DB::table('v2_stat_server')->sum('u')===2000 && (int)DB::table('v2_stat_server')->sum('d')===4000,'Node statistics preserve raw traffic');
        $server=DB::table('v2_server')->where('id',$node->id)->first();
        $check((int)$server->u===2000 && (int)$server->d===4000,'Node counters preserve raw traffic');
        $stat=DB::table('v2_stat_user')->first();
        $check((int)$stat->created_at===123,'Updating daily counters preserves their original creation time');
        $check($stat->record_type==='d' && (int)$stat->updated_at >= $before,'Daily metadata remains valid');
    }
    DB::table('v2_stat_user')->delete();
    DB::table('v2_user')->where('id',$userId)->update(['u'=>0,'d'=>0]);
    foreach([1.231,1.234] as $rate) {
        $node->rate_time_ranges=[['start'=>'00:00','end'=>'23:59','rate'=>$rate]];$node->save();
        $service->report($node,['traffic'=>[$userId=>[0,1000]]]);
    }
    $check((int)DB::table('v2_stat_user')->sum('d')===2465,'Rates sharing stored precision never lose history');
    $check((int)DB::table('v2_user')->where('id',$userId)->value('d')===2465,'Stored precision never changes the actual billing rate');
    if($mysql) {
        $check(DB::table('v2_stat_user')->count()===1,'MySQL stored precision uses its existing unique key');
        DB::table('v2_stat_user')->delete();
        $php=getenv('PHP_AUDIT_BIN') ?: PHP_BINARY;
        foreach(['a'=>1.231,'b'=>1.234] as $label=>$rate) {
            $process=new Process([$php,__FILE__,'--worker',$temporary,(string)$userId,(string)$rate,$label]);
            $process->setTimeout(45);$process->start();$processes[]=$process;
        }
        $until=microtime(true)+15;
        while((!is_file($temporary.'/ready-a') || !is_file($temporary.'/ready-b')) && microtime(true)<$until)usleep(10000);
        $check(is_file($temporary.'/ready-a') && is_file($temporary.'/ready-b'),'Both independent MySQL writers reach the barrier');
        file_put_contents($temporary.'/go','go');
        foreach($processes as $process) {
            $process->wait();
            $check($process->isSuccessful() && trim($process->getOutput())==='STAT WORKER OK','Concurrent writer completes: '.$process->getErrorOutput());
        }
        $check(DB::table('v2_stat_user')->count()===1,'Concurrent rounded keys share one row');
        $check((int)DB::table('v2_stat_user')->sum('d')===123250,'All 100 concurrent deltas are retained');
        echo 'Concurrent MySQL writers: 2; updates: 100; expected/actual bytes: 123250/123250.'.PHP_EOL;
    }
    echo 'RATE STATISTICS OK: '.$checks.' checks; '.($mysql ? 'MySQL' : 'SQLite :memory: fixture (not MySQL)')."; temporary data only.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'RATE STATISTICS FAILED: '.$error->getMessage()."\n");
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
