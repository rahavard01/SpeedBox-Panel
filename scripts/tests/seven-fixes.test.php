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
$temporary = $worker ? ($argv[2] ?? '') : sys_get_temp_dir().'/speedbox-seven-fixes-'.bin2hex(random_bytes(10));
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
    } elseif (!preg_match('/^speedbox-seven-fixes-[a-f0-9]{20}$/D', basename($temporary)) || !is_file($temporary.'/worker.json')) {
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
            $check(preg_match('/^speedbox_seven_fixes_test_[a-f0-9]{20}$/D', $connection['database'] ?? '') === 1, 'Dedicated worker database');
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
            $database = 'speedbox_seven_fixes_test_'.bin2hex(random_bytes(10));
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
        [$label,$mode,$id]=array_slice($argv,3,3);$hash=$argv[6]??'';
        file_put_contents($temporary.'/ready-'.$label,'ready');$until=microtime(true)+15;
        while(!is_file($temporary.'/go')&&microtime(true)<$until)usleep(10000);
        if(!is_file($temporary.'/go'))throw new RuntimeException('Barrier timed out');
        $app->instance(App\Services\UserBot\UserBotOrderProcessor::class,new class extends App\Services\UserBot\UserBotOrderProcessor{
            public function approve(App\Models\UserBotOrder $order):array{usleep(150000);return parent::approve($order);}
        });
        $tx=['tx_hash'=>$hash,'amount'=>10.001,'network'=>'BEP20'];
        if($mode==='retry')app(App\Services\UserBotCryptoOrderVerifier::class)->retryApprove((int)$id);
        elseif($mode==='verify')(new ReflectionMethod(App\Services\UserBotCryptoOrderVerifier::class,'markPaidAndApprove'))->invoke(new App\Services\UserBotCryptoOrderVerifier,App\Models\UserBotOrder::findOrFail($id),$tx);
        else(new ReflectionMethod(App\Services\CryptoTopupVerifier::class,'markPaid'))->invoke(new App\Services\CryptoTopupVerifier,App\Models\PanelCryptoTopup::findOrFail($id),$tx);
        echo 'WORKER OK';exit(0);
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
    if ($mysql) {
        // Test the actual read-only gate and pinned Artisan migration on a disposable DB.
        Schema::create('migrations', function ($table) { $table->increments('id');$table->string('migration');$table->integer('batch'); });
        foreach (glob($root.'/database/migrations/*.php') as $file) {
            if (basename($file)==='2026_10_16_000001_create_speedbox_crypto_payment_claims.php') continue;
            DB::table('migrations')->insert(['migration'=>$app->make('migrator')->getMigrationName($file),'batch'=>1]);
        }
        Schema::drop('speedbox_crypto_payment_claims');
        file_put_contents($temporary.'/config.php','<?php return '.var_export($app['config']->all(),true).';');
        $php=getenv('PHP_AUDIT_BIN')?:PHP_BINARY;
        $gate=new Process([$php,$root.'/scripts/seven-fixes-preflight.php']);$gate->setTimeout(30);$gate->run();
        $check($gate->isSuccessful()&&str_contains($gate->getOutput(),'pending_additive_migration'),'Preflight accepts the reviewed additive migration before installation: '.$gate->getErrorOutput());
        $check($app->make('migrator')->getMigrationName('2026_01_01_000001_legacy_fixture.php.php') === '2026_01_01_000001_legacy_fixture', 'Legacy double extension uses the actual Laravel migration name');
        $legacy=DB::table('migrations')->orderBy('id')->value('migration');
        $row=(array)DB::table('migrations')->where('migration',$legacy)->first();
        DB::table('migrations')->where('id',$row['id'])->delete();
        try {
            $failedGate=new Process([$php,$root.'/scripts/seven-fixes-preflight.php']);$failedGate->run();
            $check($failedGate->getExitCode()===1 && str_contains($failedGate->getErrorOutput(),'An earlier migration is pending') && !str_contains($failedGate->getOutput(),'SEVEN FIXES PREFLIGHT OK'), 'A genuinely pending old migration exits 1 without a success marker');
        } finally { DB::table('migrations')->insert($row); }
        $failedGate=new Process([$php,$root.'/scripts/seven-fixes-preflight.php'],null,['SPEEDBOX_REQUIRE_CLAIMS'=>'1']);$failedGate->run();
        $check($failedGate->getExitCode()===1 && str_contains($failedGate->getErrorOutput(),'Payment claim migration has not completed.'), 'A required missing claim table exits 1');
        $savedConfig=file_get_contents($temporary.'/config.php');
        $badConfig=$app['config']->all();$badConfig['database']['default']='sqlite';
        file_put_contents($temporary.'/config.php','<?php return '.var_export($badConfig,true).';');
        try {
            $failedGate=new Process([$php,$root.'/scripts/seven-fixes-preflight.php']);$failedGate->run();
            $check($failedGate->getExitCode()===1 && str_contains($failedGate->getErrorOutput(),'Expected configured MySQL connection.'), 'Wrong database driver exits 1');
        } finally { file_put_contents($temporary.'/config.php',$savedConfig); }
        $migration=new Process([$php,$root.'/artisan','migrate','--path=database/migrations/2026_10_16_000001_create_speedbox_crypto_payment_claims.php','--force']);$migration->setTimeout(30);$migration->run();
        $check($migration->isSuccessful()&&Schema::hasTable('speedbox_crypto_payment_claims'),'Pinned Artisan migration succeeds: '.$migration->getErrorOutput());
        $gate=new Process([$php,$root.'/scripts/seven-fixes-preflight.php'],null,['SPEEDBOX_REQUIRE_CLAIMS'=>'1']);$gate->setTimeout(30);$gate->run();
        $check($gate->isSuccessful()&&str_contains($gate->getOutput(),'payment_claims_table=ready'),'Preflight verifies the installed schema and primary key: '.$gate->getErrorOutput());
        unlink($temporary.'/config.php');
    }
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $ownerId=DB::table('panel_users')->insertGetId(['name'=>'Fixture partner','email'=>'owner@fixture.test','password'=>'fixture','code'=>'fixrep','role'=>2,'credit'=>1000000,'partner_plan_type'=>'time']);
    $owner=DB::table('panel_users')->where('id',$ownerId)->first();
    $group=(int)DB::table('panel_tariff_groups')->where('group_type','time')->where('is_default',1)->value('id');
    $volumeGroup=(int)DB::table('panel_tariff_groups')->where('group_type','volume')->where('is_default',1)->value('id');
    $plan=DB::table('v2_plan')->insertGetId(['name'=>'Fixture plan','group_id'=>1,'transfer_enable'=>10,'reset_traffic_method'=>2,'created_at'=>time(),'updated_at'=>time()]);
    foreach([[$group,'1m'],[$group,'gig'],[$group,'test'],[$volumeGroup,'volume_gig']] as [$g,$key])DB::table('panel_plan')->updateOrInsert(['tariff_group_id'=>$g,'plan_key'=>$key],['name'=>'Fixture','default_price'=>100,'enable'=>1,'v2_plan_id'=>$plan]);
    $created=app(App\Services\V2UserCreator::class)->createUsers('fixrep',$plan,1,'1m',false,null,['account_group_type'=>'time','account_tariff_group_id'=>$group,'account_plan_key'=>'1m'],$ownerId);
    $userId=$created['created'][0]['id'];$email=$created['created'][0]['email'];
    $setting=App\Models\UserBotSetting::create(['owner_type'=>'partner','owner_id'=>$ownerId,'bot_token'=>null,'is_enabled'=>true]);
    $newOrder=static function(string $status,?string $hash=null)use($setting,$group,$email){return App\Models\UserBotOrder::create([
        'setting_id'=>$setting->id,'order_type'=>'buy_traffic','plan_key'=>$group.'_traffic_gig','account_email'=>$email,'traffic_gb'=>1,
        'amount'=>100,'payment_method'=>'crypto','crypto_asset'=>'USDT','crypto_network'=>'BEP20','crypto_wallet_address'=>'0x'.str_repeat('1',40),
        'crypto_amount'=>'10.001','crypto_tx_hash'=>$hash,'status'=>$status,'payload'=>[],
    ]);};
    $newWallet=static function()use($ownerId){return App\Models\PanelCryptoTopup::create(['user_id'=>$ownerId,'amount_irt'=>50000,'asset'=>'USDT','network'=>'BEP20','wallet_address'=>'0x'.str_repeat('1',40),'crypto_amount'=>'10.001','base_crypto_amount'=>10,'status'=>'pending','expires_at'=>now()->addHour()]);};
    $balance=fn()=>(int)DB::table('panel_users')->where('id',$ownerId)->value('credit');
    $traffic=fn()=>(int)DB::table('v2_user')->where('id',$userId)->value('d');
    $tx=fn($c)=>['tx_hash'=>str_repeat($c,64),'amount'=>10.001,'network'=>'BEP20'];
    $bot=new App\Services\UserBotCryptoOrderVerifier;$markBot=new ReflectionMethod($bot,'markPaidAndApprove');
    $wallet=new App\Services\CryptoTopupVerifier;$markWallet=new ReflectionMethod($wallet,'markPaid');
    $done=$newOrder('done',$tx('a')['tx_hash']);$before=$balance();$dBefore=$traffic();
    $markBot->invoke($bot,$done,$tx('a'));
    $check($balance()===$before&&$traffic()===$dBefore,'Stale approval never reexecutes a done order');
    $legacyWallet=$newWallet();$markWallet->invoke($wallet,$legacyWallet,$tx('a'));
    $check($legacyWallet->fresh()->status==='failed'&&$balance()===$before,'Legacy done bot payment is protected before it has a claim row');
    $legacyPaid=$newWallet();$legacyPaid->update(['status'=>'paid','tx_hash'=>$tx('9')['tx_hash'],'paid_at'=>now()]);
    $legacyBot=$newOrder('crypto_pending');$markBot->invoke($bot,$legacyBot,$tx('9'));
    $check($legacyBot->fresh()->status==='failed'&&$balance()===$before,'Legacy paid wallet payment is protected before it has a claim row');
    $first=$newOrder('crypto_pending');$markBot->invoke($bot,$first,$tx('b'));$after=$balance();$dAfter=$traffic();
    $check($first->fresh()->status==='done'&&$before-$after===100&&$dBefore-$dAfter===1073741824,'Normal crypto fulfillment charges and delivers exactly once');
    $markBot->invoke($bot,$first,$tx('b'));
    $check($balance()===$after&&$traffic()===$dAfter,'Repeated verification is financially idempotent');
    $duplicateWallet=$newWallet();$markWallet->invoke($wallet,$duplicateWallet,$tx('b'));
    $check($duplicateWallet->fresh()->status==='failed'&&$balance()===$after,'Wallet cannot reuse a bot payment');
    $firstWallet=$newWallet();$markWallet->invoke($wallet,$firstWallet,$tx('c'));$after=$balance();
    $check($firstWallet->fresh()->status==='paid','Normal wallet payment completes');
    $duplicateBot=$newOrder('crypto_pending');$markBot->invoke($bot,$duplicateBot,$tx('c'));
    $check($duplicateBot->fresh()->status==='failed'&&$balance()===$after,'Bot cannot reuse a wallet payment');
    $markWallet->invoke($wallet,$firstWallet,$tx('c'));$check($balance()===$after,'Repeated wallet approval never credits again');
    $reserved=$newOrder('crypto_retry_pending',$tx('d')['tx_hash']);$another=$newOrder('crypto_pending');$before=$balance();
    $markBot->invoke($bot,$another,$tx('d'));
    $check($another->fresh()->status==='failed'&&$reserved->fresh()->status==='crypto_retry_pending'&&$balance()===$before,'A legacy paid retry reserves its payment');
    $retryResult=$bot->retryApprove($reserved->id);$after=$balance();$again=$bot->retryApprove($reserved->id);
    $check($retryResult['ok']&&!$again['ok']&&$before-$after===100&&$balance()===$after,'A paid retry executes only once');
    // A failure after actual business effects must roll them back while reserving payment.
    $fault=$newOrder('crypto_pending');$before=$balance();$dBefore=$traffic();
    $app->instance(App\Services\UserBot\UserBotOrderProcessor::class,new class extends App\Services\UserBot\UserBotOrderProcessor {
        public function approve(App\Models\UserBotOrder $order):array{parent::approve($order);throw new RuntimeException('Fixture failure after business effects');}
    });
    $markBot->invoke($bot,$fault,$tx('e'));
    $check($fault->fresh()->status==='crypto_retry_pending'&&$fault->fresh()->crypto_paid_at!==null,'Confirmed payment remains retryable after a fulfillment fault');
    $check($balance()===$before&&$traffic()===$dBefore,'Post-fulfillment exception rolls back credit and traffic together');
    $app->instance(App\Services\UserBot\UserBotOrderProcessor::class,new App\Services\UserBot\UserBotOrderProcessor);
    $retryResult=$bot->retryApprove($fault->id);$check($retryResult['ok']&&$before-$balance()===100&&$dBefore-$traffic()===1073741824,'Retry after fault performs one net purchase');
    if($mysql){
        $runRace=function(array $jobs)use($temporary,&$processes,$check){
            @unlink($temporary.'/go');foreach(['a','b']as$label)@unlink($temporary.'/ready-'.$label);
            $batch=[];foreach($jobs as$i=>$args){$label=$i===0?'a':'b';$proc=new Process(array_merge([getenv('PHP_AUDIT_BIN')?:PHP_BINARY,__FILE__,'--worker',$temporary,$label],$args));$proc->setTimeout(40);$proc->start();$batch[]=$proc;$processes[]=$proc;}
            $until=microtime(true)+15;while((!is_file($temporary.'/ready-a')||!is_file($temporary.'/ready-b'))&&microtime(true)<$until)usleep(10000);
            $check(is_file($temporary.'/ready-a')&&is_file($temporary.'/ready-b'),'Both independent writers are ready');file_put_contents($temporary.'/go','go');
            foreach($batch as$proc){$proc->wait();$check($proc->isSuccessful(),'Concurrent worker: '.$proc->getErrorOutput());}
        };
        $race=$newOrder('crypto_retry_pending',$tx('f')['tx_hash']);$before=$balance();$dBefore=$traffic();
        $runRace([['retry',(string)$race->id],['retry',(string)$race->id]]);
        $check($before-$balance()===100&&$dBefore-$traffic()===1073741824,'Two public retries deliver one purchase');
        $race=$newOrder('crypto_pending');$before=$balance();$dBefore=$traffic();
        $runRace([['verify',(string)$race->id,$tx('1')['tx_hash']],['verify',(string)$race->id,$tx('1')['tx_hash']]]);
        $check($before-$balance()===100&&$dBefore-$traffic()===1073741824,'Two first verifications deliver one purchase');
        $race=$newOrder('crypto_pending');$raceWallet=$newWallet();$before=$balance();$dBefore=$traffic();
        $runRace([['verify',(string)$race->id,$tx('2')['tx_hash']],['wallet',(string)$raceWallet->id,'0x'.$tx('2')['tx_hash']]]);
        $botWon=$race->fresh()->status==='done';$walletWon=$raceWallet->fresh()->status==='paid';
        $check($botWon!==$walletWon,'A chain transaction has exactly one owner across concurrent payment paths');
        $check($balance()-$before===($walletWon?50000:-100),'Only the winning payment path changes credit');
        $check($dBefore-$traffic()===($botWon?1073741824:0),'Only the winning bot purchase changes traffic');
    }
    // Expiry batches must not overwrite a payment completed after selection.
    $stale=$newOrder('crypto_pending');$stale->update(['crypto_expires_at'=>now()->subMinute()]);
    App\Models\UserBotOrder::retrieved(function($record)use($stale){if($record->id===$stale->id)DB::table('user_bot_orders')->where('id',$record->id)->update(['status'=>'done','crypto_paid_at'=>now()]);});
    $bot->run();$app['events']->forget('eloquent.retrieved: '.App\Models\UserBotOrder::class);
    $check($stale->fresh()->status==='done','Stale expiry selection cannot overwrite a completed bot payment');
    $staleWallet=$newWallet();$staleWallet->update(['expires_at'=>now()->subMinute()]);
    App\Models\PanelCryptoTopup::retrieved(function($record)use($staleWallet){if($record->id===$staleWallet->id)DB::table('panel_crypto_topups')->where('id',$record->id)->update(['status'=>'paid','paid_at'=>now()]);});
    $wallet->run();$app['events']->forget('eloquent.retrieved: '.App\Models\PanelCryptoTopup::class);
    $check($staleWallet->fresh()->status==='paid','Stale expiry selection cannot overwrite a completed wallet payment');
    // Stale and recent delivery locks are distinct.
    $deliveryOrder=$newOrder('done');$deliveryOrder->update(['payment_method'=>'card','payload'=>['telegram_delivery'=>['status'=>'sending','started_at'=>now()->subMinutes(10)->toIso8601String(),'attempts'=>1,'result'=>['type'=>'buy_traffic','email'=>$email,'traffic_gb'=>1]]]]);
    $delivery=new App\Services\UserBot\UserBotOrderDeliveryService;$delivery->retryApproved($setting,$deliveryOrder);
    $check(data_get($deliveryOrder->fresh()->payload,'telegram_delivery.attempts')===2&&data_get($deliveryOrder->fresh()->payload,'telegram_delivery.status')==='pending','Old sending lock is reclaimed (missing bot token leaves a retryable delivery)');
    $payload=$deliveryOrder->fresh()->payload;$payload['telegram_delivery']['status']='sending';$payload['telegram_delivery']['started_at']=now()->toIso8601String();$deliveryOrder->update(['payload'=>$payload]);$delivery->retryApproved($setting,$deliveryOrder);
    $check(data_get($deliveryOrder->fresh()->payload,'telegram_delivery.attempts')===2,'Recent sending attempt is not stolen');
    // API and service enforce new-account type without changing existing-account renewals.
    $request=Illuminate\Http\Request::create('/api/panel/users/create','POST',['panel_code'=>'fixrep','plan_key'=>'volume_gig','tariff_group_id'=>$volumeGroup,'volume_gb'=>10,'count'=>1]);$request->setUserResolver(fn()=>$owner);$before=$balance();$count=DB::table('v2_user')->count();
    $ctrl=new App\Http\Controllers\Api\V2UserController;$response=$ctrl->create($request,app(App\Services\V2UserCreator::class),app(App\Services\PanelPricingService::class));
    $check($response->getStatusCode()===403&&$balance()===$before&&DB::table('v2_user')->count()===$count,'Time-only owner cannot create volume via API');
    $result=app(App\Services\PanelAccountActionService::class)->createAccountForPanelUser($owner,'volume_gig',1,$volumeGroup,10);
    $check(!$result['ok']&&$balance()===$before,'The bot/service entry point enforces the same type');
    foreach(['time'=>['time'=>true,'volume'=>false],'volume'=>['time'=>false,'volume'=>true],'both'=>['time'=>true,'volume'=>true]]as$type=>$cases)foreach($cases as$kind=>$expected)$check(app(App\Services\PartnerTariffAccessService::class)->canCreateType((object)['role'=>2,'partner_plan_type'=>$type],$kind)===$expected,'Type policy '.$type.'/'.$kind);
    $check(app(App\Services\PartnerTariffAccessService::class)->canCreateType((object)['role'=>1,'partner_plan_type'=>'time'],'volume'),'Admin may create either type');
    $both=clone $owner;$both->partner_plan_type='both';DB::table('panel_users')->where('id',$ownerId)->update(['partner_plan_type'=>'both']);
    $result=app(App\Services\PanelAccountActionService::class)->createAccountForPanelUser($both,'volume_gig',1,$volumeGroup,10);
    $check($result['ok']&&count($result['created'])===1,'An allowed both-type owner still creates volume normally');
    // First scan after a delay includes the same historical payment.
    DB::table('panel_crypto_wallet_settings')->updateOrInsert(['id'=>1],['bscscan_api_key'=>'fixture-key']);
    $scan=$newWallet();$scan->forceFill(['crypto_amount'=>10,'created_at'=>now()->subMinutes(30)])->save();
    $ranges=[];Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(function($request)use(&$ranges){$data=$request->data();if($data['method']==='eth_blockNumber')return Illuminate\Support\Facades\Http::response(['result'=>'0x2713']);$from=hexdec($data['params'][0]['fromBlock']);$to=hexdec($data['params'][0]['toBlock']);$ranges[]=$from;return Illuminate\Support\Facades\Http::response(['result'=>$from<=9200&&$to>=9200?[['transactionHash'=>str_repeat('3',64),'data'=>'0x8ac7230489e80000']]:[]]);});
    $find=new ReflectionMethod($wallet,'findBep20Usdt');$found=$find->invoke($wallet,$scan);
    $check($found!==null&&min($ranges)<9200,'Delayed first scan includes the paid block');
    // A failed middle chunk keeps a contiguous checkpoint and is requested again.
    $scan->forceFill(['bsc_start_block'=>9700,'bsc_last_checked_block'=>null])->save();$fail=true;$ranges=[];
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(function($request)use(&$fail,&$ranges){$d=$request->data();if($d['method']==='eth_blockNumber')return Illuminate\Support\Facades\Http::response(['result'=>'0x2713']);$from=hexdec($d['params'][0]['fromBlock']);$ranges[]=$from;if($from===9800&&$fail){$fail=false;return Illuminate\Support\Facades\Http::response(['error'=>['message'=>'temporary fixture failure']]);}return Illuminate\Support\Facades\Http::response(['result'=>[]]);});
    $find->invoke($wallet,$scan);$check((int)$scan->fresh()->bsc_last_checked_block===9799&&$ranges===[9700,9800],'Failed chunk does not advance the checkpoint or process later chunks');$ranges=[];$find->invoke($wallet,$scan->fresh());$check($ranges[0]===9800&&$scan->fresh()->bsc_last_checked_block===10000,'Next scan retries the failed chunk and then advances');
    $setting->update(['crypto_bscscan_api_key'=>'fixture-key']);
    foreach (['wallet','bot'] as $path) {
        $record=$path==='wallet'?$newWallet():$newOrder('crypto_pending');
        $amountColumn=$path==='wallet'?'crypto_amount':'crypto_amount';
        $record->forceFill([$amountColumn=>10,'created_at'=>now()->subMinutes(30),'bsc_start_block'=>null,'bsc_last_checked_block'=>null])->save();
        $service=$path==='wallet'?$wallet:$bot;
        Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Http::fake(function($request){$d=$request->data();if($d['method']==='eth_blockNumber')return Illuminate\Support\Facades\Http::response(['result'=>'0x2713']);$from=hexdec($d['params'][0]['fromBlock']);$to=hexdec($d['params'][0]['toBlock']);return Illuminate\Support\Facades\Http::response(['result'=>$from<=9200&&$to>=9200?[['transactionHash'=>str_repeat('4',64),'data'=>'0x8ac7230489e80000']]:[]]);});
        $found=(new ReflectionMethod($service,'findBep20Usdt'))->invoke($service,$record);
        $check($found!==null,'Delayed token payment found in '.$path.' path');
        $record->forceFill(['bsc_start_block'=>9998,'bsc_last_checked_block'=>null])->save();$failReceipt=true;$blocks=[];
        Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Http::fake(function($request)use(&$failReceipt,&$blocks,$record,$path){$d=$request->data();if($d['method']==='eth_blockNumber')return Illuminate\Support\Facades\Http::response(['result'=>'0x2713']);if($d['method']==='eth_getTransactionReceipt'){if($failReceipt){$failReceipt=false;return Illuminate\Support\Facades\Http::response(['error'=>['message'=>'fixture receipt failure']]);}return Illuminate\Support\Facades\Http::response(['result'=>['status'=>'0x1']]);}$block=hexdec($d['params'][0]);$blocks[]=$block;return Illuminate\Support\Facades\Http::response(['result'=>['transactions'=>$block===9998?[['hash'=>str_repeat('5',64),'to'=>$path==='wallet'?$record->wallet_address:$record->crypto_wallet_address,'value'=>'0x8ac7230489e80000']]:[]]]);});
        $native=new ReflectionMethod($service,'findBep20Bnb');$found=$native->invoke($service,$record);
        $check($found===null&&$record->fresh()->bsc_last_checked_block===null&&$blocks===[9998],'Failed native receipt preserves checkpoint in '.$path);
        $found=$native->invoke($service,$record->fresh());$check($found!==null,'Native payment is retried after receipt recovery in '.$path);
    }
    // Successful recipients are retained while only a failed recipient is retried.
    config(['services.telegram.bot_token'=>'fixture-token']);DB::table('panel_users')->where('id',$ownerId)->update(['telegram_user_id'=>101]);
    DB::table('panel_users')->insert(['name'=>'Second','email'=>'second@fixture.test','password'=>'fixture','code'=>'second','role'=>2,'telegram_user_id'=>202]);
    $calls=[101=>0,202=>0];$firstFailure=true;
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(function($request)use(&$calls,&$firstFailure){$id=(int)$request['chat_id'];$calls[$id]++;if($id===202&&$firstFailure){$firstFailure=false;return Illuminate\Support\Facades\Http::response(['ok'=>false],500);}return Illuminate\Support\Facades\Http::response(['ok'=>true,'result'=>['message_id'=>$id]]);});
    $req=Illuminate\Http\Request::create('/api/admin/announcements','POST',['title'=>'Fixture notice','is_published'=>true,'publish_at'=>now()->subMinute()->toDateTimeString()]);$req->setUserResolver(fn()=>null);
    $reply=(new App\Http\Controllers\Api\Admin\AnnouncementAdminController)->store($req);$id=$reply->getData(true)['data']['id'];
    $check(DB::table('panel_announcements')->where('id',$id)->value('tg_broadcasted_at')===null,'Immediate publication never completes after a partial send failure');
    if ($mysql) app(App\Services\AnnouncementDueRunner::class)->tick();
    else app(App\Services\TelegramNotifier::class)->broadcastAnnouncement($id);
    $check($calls===[101=>1,202=>2],'Only the failed announcement recipient is retried');
    $check(DB::table('panel_announcements')->where('id',$id)->value('tg_broadcasted_at')!==null,'All-success broadcast is marked complete');cache()->forget('announcements:due:last_run');
    if ($mysql) app(App\Services\AnnouncementDueRunner::class)->tick();
    else app(App\Services\TelegramNotifier::class)->broadcastAnnouncement($id);
    $check($calls===[101=>1,202=>2],'Completed broadcasts are not resent');
    if ($mysql) {
        $seen=[];
        Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Http::fake(function($request)use(&$seen){$seen[]=(string)$request['text'];return Illuminate\Support\Facades\Http::response(['ok'=>false],500);});
        for($i=1;$i<=21;$i++)App\Models\PanelAnnouncement::create(['title'=>'Fair '.$i,'is_published'=>true,'publish_at'=>now()->subMinute()]);
        cache()->forget('announcements:due:last_run');app(App\Services\AnnouncementDueRunner::class)->tick();
        cache()->forget('announcements:due:last_run');app(App\Services\AnnouncementDueRunner::class)->tick();
        $check(count(array_filter($seen,fn($text)=>str_ends_with($text,'Fair 21')))>0,'Old failed announcements never starve newer notices beyond the batch limit');
    }
    // Schema addition is idempotent; code rollback must retain consumed identities.
    $migration=require $root.'/database/migrations/2026_10_16_000001_create_speedbox_crypto_payment_claims.php';$claims=DB::table('speedbox_crypto_payment_claims')->count();$migration->up();$migration->down();
    $check(DB::table('speedbox_crypto_payment_claims')->count()===$claims,'Rerun and rollback preserve consumed payment identities');
    if ($mysql && getenv('MYSQLDUMP_BIN')) {
        file_put_contents($temporary.'/config.php','<?php return '.var_export($app['config']->all(),true).';');
        $backup=new Process([getenv('PHP_AUDIT_BIN')?:PHP_BINARY,$root.'/scripts/update-database.php','backup',$temporary.'/database.sql']);$backup->setTimeout(45);$backup->run();
        $check($backup->isSuccessful()&&is_file($temporary.'/database.sql')&&filesize($temporary.'/database.sql')>0,'Real configured MySQL backup succeeds: '.$backup->getErrorOutput());
        $check(str_contains(file_get_contents($temporary.'/database.sql'),'speedbox_crypto_payment_claims'),'Database backup contains the payment ownership ledger');
        unlink($temporary.'/config.php');
    }
    echo 'SEVEN FIXES OK: '.$checks.' checks; '.($mysql?'MySQL; concurrent writers and temporary databases only':'SQLite :memory: fixture; no live database').PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'SEVEN FIXES FAILED: '.$error->getMessage()."\n");
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
