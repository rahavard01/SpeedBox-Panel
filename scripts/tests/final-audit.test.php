<?php

// Real bootstrap/HTTP regression. SQLite :memory:, private temporary storage, no project .env.
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "Enable pdo_sqlite for this isolated test.\n"); exit(1); }
$child = ($argv[1] ?? '') === '--cache-probe';
$temporary = $child ? (string) ($argv[2] ?? '') : sys_get_temp_dir().'/speedbox-final-audit-'.bin2hex(random_bytes(10));
if ($child && (!preg_match('/^speedbox-final-audit-[a-f0-9]{20}$/D', basename($temporary)) || !is_file($temporary.'/config.php'))) exit(1);
if (!$child) {
    mkdir($temporary, 0700, true);
    foreach (['framework/cache/data','framework/views','framework/sessions','logs','app/private','app/public'] as $part) mkdir($temporary.'/storage/'.$part, 0700, true);
}
foreach (['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_URL'=>'https://audit.example.test','APP_KEY'=>'base64:'.base64_encode(str_repeat('a',32)),
    'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'database',
    'LOG_CHANNEL'=>'null','MAIL_MAILER'=>'array','LARAVEL_STORAGE_PATH'=>$temporary.'/storage',
    'APP_CONFIG_CACHE'=>$temporary.'/config.php','APP_ROUTES_CACHE'=>$temporary.'/routes.php','APP_EVENTS_CACHE'=>$temporary.'/events.php',
    'SPEEDBOX_TRUSTED_PROXIES'=>'127.0.0.1,::1','SPEEDBOX_API_RATE_LIMIT'=>'60','SPEEDBOX_NODE_RATE_LIMIT'=>'1200',
    'SPEEDBOX_NODE_IP_RATE_LIMIT'=>'12000','SPEEDBOX_WEBHOOK_RATE_LIMIT'=>'600','SANCTUM_STATEFUL_DOMAINS'=>'',
    'CORS_ALLOWED_ORIGINS'=>'https://allowed.example.test','CORS_ALLOWED_ORIGINS_PATTERNS'=>''] as $key=>$value) {
    putenv("$key=$value"); $_ENV[$key]=$_SERVER[$key]=$value;
}
$checks = 0;
$check = static function ($ok, string $label) use (&$checks) { if (!$ok) throw new RuntimeException($label); $checks++; };
$exitCode = 0;
try {
    // Also works when a local test workspace reuses a vendor directory via symlink.
    $loader = require $root.'/vendor/autoload.php';
    $map = [];
    foreach ($loader->getClassMap() as $class=>$file) {
        foreach (['App\\'=>'app/', 'Database\\Factories\\'=>'database/factories/', 'Database\\Seeders\\'=>'database/seeders/'] as $prefix=>$directory) {
            if (str_starts_with($class,$prefix)) {
                $candidate=$root.'/'.$directory.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
                if (is_file($candidate)) $map[$class]=$candidate;
            }
        }
    }
    $loader->addClassMap($map);
    $loader->setPsr4('App\\',$root.'/app');
    $loader->setPsr4('Database\\Factories\\',$root.'/database/factories');
    $loader->setPsr4('Database\\Seeders\\',$root.'/database/seeders');
    $app = require $root.'/bootstrap/app.php';
    // Laravel recognizes / and \\ by default; Windows temp paths start with a drive letter.
    // Register only this test's private directory before either process bootstraps.
    $app->addAbsoluteCachePathPrefix($temporary);
    $app->useEnvironmentPath($temporary);
    $app->useStoragePath($temporary.'/storage');
    $console=$app->make(Illuminate\Contracts\Console\Kernel::class);$console->bootstrap();
    $http=$app->make(Illuminate\Contracts\Http\Kernel::class);
    if ($child) {
        $cachedState = [
            'config' => $app->configurationIsCached(),
            'routes' => $app->routesAreCached(),
            'node_alias' => isset($http->getMiddlewareAliases()['speedbox.node']),
            'api_throttle' => in_array('throttle:api',$http->getMiddlewareGroups()['api'],true),
            'admin_gate' => Illuminate\Support\Facades\Gate::has('admin'),
        ];
        if (in_array(false,$cachedState,true)) {
            throw new RuntimeException('Cached bootstrap lost configuration: '.json_encode($cachedState));
        }
        echo "FINAL AUDIT CACHE OK\n";
        exit(0);
    }
    $initialConfig=$app['config']->all();
    $initialRoutes=clone $app['router']->getRoutes();
    $check(Illuminate\Support\Facades\DB::connection()->getDatabaseName()===':memory:','Memory database only');
    $normalize=static fn($p)=>PHP_OS_FAMILY==='Windows'?strtolower(str_replace('\\','/',$p)):str_replace('\\','/',$p);
    $check($normalize(realpath((new ReflectionClass(App\Http\Kernel::class))->getFileName()))===$normalize(realpath($root.'/app/Http/Kernel.php')),'This project source is loaded');
    $check(isset($console->all()['inspire']),'Console route registered');
    $events=$app->make(Illuminate\Console\Scheduling\Schedule::class)->events();
    $check(count($events)===14,'All 13 existing schedules and the tunnel monitor registered');
    $named=[];
    foreach($events as $event) if($event->description!==null) $named[$event->description]=$event;
    foreach(['connections:expired-leases','subscription:traffic-reset','announcements:due-tick',
        'v2user:expiry-tick','bot-payment-message-cleanup','userbot-card-processing-recovery',
        'userbot-telegram-delivery-retry','userbot-expiry-warning','userbot-traffic-warning',
        'userbot-sms-event-cleanup','receipt-ai-auto-approve'] as $name) {
        $check(isset($named[$name]),'Existing schedule preserved: '.$name);
    }
    foreach(['crypto:verify-topups','userbot:verify-crypto-orders'] as $command) {
        $check(count(array_filter($events,static fn($event)=>str_contains($event->command??'',$command)))===1,'Existing command schedule preserved: '.$command);
    }
    $monitor=$named['tunnels:outage-monitor']??null;
    $check($monitor!==null && $monitor->expression==='* * * * *' && $monitor->withoutOverlapping,'Tunnel monitor runs every minute without overlapping');
    $global=$http->getGlobalMiddleware();
    foreach ([App\Http\Middleware\ApplyRuntimeSettings::class,App\Http\Middleware\TrustProxies::class,
        Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class] as $class) $check(in_array($class,$global,true),'Required global '.$class);
    $check(in_array('throttle:api',$http->getMiddlewareGroups()['api'],true),'API limiter attached');
    $check(($http->getMiddlewareAliases()['speedbox.node']??null)===App\Http\Middleware\AuthenticateNode::class,'Explicit node alias');
    foreach($global as $class)$check(class_exists($class),'Global class exists');

    $schema=Illuminate\Support\Facades\Schema::class;
    $schema::create('panel_users',function($t){$t->id();$t->string('name');$t->string('email')->unique();$t->string('password');$t->integer('role')->default(2);$t->boolean('banned')->default(false);$t->string('remember_token')->nullable();$t->timestamps();});
    foreach (['2025_01_01_000000_create_speedbox_core_tables.php','2026_10_08_000001_create_speedbox_infrastructure_tables.php',
        '2026_10_09_000001_create_speedbox_queue_monitor_tables.php','2026_10_10_000003_create_connection_admission_leases.php'] as $file) (require $root.'/database/migrations/'.$file)->up();
    $db=Illuminate\Support\Facades\DB::class;$routes=Illuminate\Support\Facades\Route::class;
    $request=static function(string $path, array $parameters=[], array $server=[]) use($http,$app) {
        $app['auth']->forgetGuards();
        return $http->handle(Illuminate\Http\Request::create('http://panel.example.test'.$path,'GET',$parameters,[],[],
            array_replace(['HTTP_ACCEPT'=>'application/json','REMOTE_ADDR'=>'192.0.2.1'],$server)));
    };
    $check($request('/api/v2/server/config')->getStatusCode()===401,'Invalid node auth denied, not a 500');
    $machine=App\Models\ServerMachine::create(['name'=>'Audit','token'=>str_repeat('t',48),'is_active'=>true]);
    $node=App\Models\ProxyServer::create(['name'=>'Audit node','type'=>'vless','kernel'=>'xray','machine_id'=>$machine->id,'host'=>'vpn.example.test','port'=>'443','server_port'=>443,'group_ids'=>[1],'rate'=>1,'show'=>true,'enabled'=>true,'protocol_settings'=>['tls'=>0,'network'=>'tcp']]);
    $check($request('/api/v2/server/config',['machine_id'=>$machine->id,'node_id'=>$node->id,'token'=>$machine->token])->getStatusCode()===200,'Valid node reaches config');
    $routes::middleware('api')->get('/api/_final_audit/proxy',fn(Illuminate\Http\Request $r)=>response()->json(['ip'=>$r->ip(),'secure'=>$r->isSecure(),'url'=>url('/probe')]));
    App\Services\RuntimeSetting::set('force_https',false);
    $forward=['REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_FOR'=>'198.51.100.2','HTTP_X_FORWARDED_PROTO'=>'https'];
    $data=$request('/api/_final_audit/proxy',[],$forward)->getData(true);
    $check($data['ip']==='198.51.100.2'&&$data['secure'],'Local reverse proxy trusted');
    $data=$request('/api/_final_audit/proxy',[],array_replace($forward,['REMOTE_ADDR'=>'192.0.2.5']))->getData(true);
    $check($data['ip']==='192.0.2.5'&&!$data['secure'],'Public spoofed proxy headers ignored');
    foreach ([true,false,true] as $value) {
        App\Services\RuntimeSetting::set('force_https',$value);
        $data=$request('/api/_final_audit/proxy')->getData(true);
        $check(str_starts_with($data['url'],$value?'https:':'http:'),'HTTPS setting resets per request');
    }
    $routes::middleware('api')->get('/api/_final_audit/rate',fn()=>response('ok'));
    $statuses=[];for($i=0;$i<61;$i++)$statuses[]=$request('/api/_final_audit/rate',[],['REMOTE_ADDR'=>'192.0.2.77'])->getStatusCode();
    $check(count(array_filter($statuses,fn($s)=>$s===200))===60&&end($statuses)===429,'API request 61 is throttled');
    $limiter=Illuminate\Support\Facades\RateLimiter::limiter('api');
    $limits=[];foreach([1,2] as $id){$r=Illuminate\Http\Request::create('http://panel.example.test/api/v2/server/config','GET',['machine_id'=>1,'node_id'=>$id]);$limits[]=$limiter($r);}
    $check($limits[0][0]->key!==$limits[1][0]->key&&$limits[0][1]->key===$limits[1][1]->key,'Node buckets separate, shared IP ceiling retained');
    $check($limits[0][0]->maxAttempts===1200&&$limits[0][1]->maxAttempts===12000,'Node budgets preserved/configurable');
    $routes::middleware(['api','auth:sanctum','can:admin'])->get('/api/_final_audit/admin',fn()=>response('ok'));
    foreach([1=>200,2=>403] as $role=>$expected){$user=new App\Models\User;$user->forceFill(['name'=>'Audit','email'=>'role'.$role.'@example.test','password'=>'audit-password','role'=>$role])->save();$token=$user->createToken('audit')->plainTextToken;
        $check($request('/api/_final_audit/admin',[],['HTTP_AUTHORIZATION'=>'Bearer '.$token])->getStatusCode()===$expected,'Actual role gate '.$role);
        if($role===2){$user->forceFill(['banned'=>true])->save();$routes::middleware(['api','auth:sanctum',App\Http\Middleware\CheckUserBanned::class])->get('/api/_final_audit/banned',fn()=>response('ok'));
            $check($request('/api/_final_audit/banned',[],['HTTP_AUTHORIZATION'=>'Bearer '.$token])->getStatusCode()===403,'Banned bearer denied');}
    }
    $check($request('/api/_final_audit/admin')->getStatusCode()===401,'Guest denied admin route');
    $browser=$request('/api/_final_audit/admin',[],['HTTP_ACCEPT'=>'text/html']);
    $check($browser->getStatusCode()===302&&str_ends_with($browser->headers->get('Location'),'/login'),'Browser guest redirects to SPA login instead of missing named route');
    $before=App\Models\User::count();(new Database\Seeders\DatabaseSeeder)->run();$check(App\Models\User::count()===$before,'Seeder never creates sample accounts');
    // Faker is a dev dependency and is absent on --no-dev production installs.
    if(class_exists(Faker\Factory::class)){$made=App\Models\User::factory()->unverified()->create();$check($made->exists&&!array_key_exists('email_verified_at',$made->getAttributes()),'Factory matches panel schema');}
    else {$check(!str_contains(file_get_contents($root.'/database/factories/UserFactory.php'),"'email_verified_at'"),'No-dev factory schema source check (Faker unavailable)');}

    $portal=new ReflectionMethod(App\Http\Controllers\Api\SubscriptionController::class,'portal');$controller=new App\Http\Controllers\Api\SubscriptionController;
    $account=(new App\Models\SubscriptionAccount)->setRawAttributes(['id'=>999,'email'=>'<audit>@example.com','token'=>str_repeat('a',32),'banned'=>0,'u'=>0,'d'=>0,'transfer_enable'=>100000000,'plan_id'=>null,'expired_at'=>time()+86400*3,'device_limit'=>2]);
    foreach(['/api/v1/client/subscribe?token='.str_repeat('a',32),'/s/'.str_repeat('a',32),'/custom/deep/'.str_repeat('a',32)] as $path){
        $r=Illuminate\Http\Request::create('http://panel.example.test'.$path);$app->instance('request',$r);
        App\Services\RuntimeSetting::set('subscribe_url','https://sub.example.test');App\Services\RuntimeSetting::set('subscribe_path','custom/deep');
        $res=$portal->invoke($controller,$r,$account);$data=$res->getOriginalContent()->getData();
        $check($data['subscription_url']==='https://sub.example.test/custom/deep/'.str_repeat('a',32),'Canonical token URL for '.$path);
        $check($data['qr_code']!==''&&str_contains($res->getContent(),'&lt;audit&gt;'),'QR render and username escaping');
    }
    App\Services\Plugin\HookManager::registerFilter('subscribe.url',static fn($url)=>'https://gateway.example.test/import?url='.rawurlencode($url));
    $res=$portal->invoke($controller,$r,$account);$data=$res->getOriginalContent()->getData();
    $check($data['subscription_url']===App\Utils\Helper::getSubscribeUrl($account->token),'Portal preserves full URL hook/query');App\Services\Plugin\HookManager::reset();
    $days=new ReflectionMethod($controller,'remainingCalendarDays');
    foreach([-2,-1,0,2] as $offset){$account->expired_at=Carbon\CarbonImmutable::now('Asia/Tehran')->startOfDay()->addDays($offset)->addHours(12)->timestamp;$check($days->invoke($controller,$account)===$offset,'Signed calendar days '.$offset);}
    $account->expired_at=time()-86400*2;
    $res=$portal->invoke($controller,$r,$account);$data=$res->getOriginalContent()->getData();
    $check($data['remaining_days']<0&&str_contains($res->getContent(),'منقضی شده'),'Expired portal is not unlimited');
    $account->expired_at=null;$check($days->invoke($controller,$account)===null,'Unlimited remains distinct');

    $first=$app->make(App\Services\Plugin\HookRegistry::class);$app->forgetScopedInstances();$check($first!==$app->make(App\Services\Plugin\HookRegistry::class),'Scoped hooks reset');
    $check(config('queue.connections.database.retry_after')>1200,'Deployment retry safety retained');
    $check(!str_contains(file_get_contents($root.'/resources/views/client/subscribe.blade.php'),'Cafbb7Zc'),'Blade font not tied to build hash');
    $check(App\Support\SubscriptionAssets::fontUrl()!==null,'Current font manifest resolves');
    $app->usePublicPath($temporary.'/public');mkdir($temporary.'/public/build/assets',0700,true);
    file_put_contents($temporary.'/public/build/assets/font-next.woff2','fixture');
    $manifestKey='node_modules/@fontsource-variable/vazirmatn/files/vazirmatn-arabic-wght-normal.woff2';
    file_put_contents($temporary.'/public/build/manifest.json',json_encode([$manifestKey=>['file'=>'assets/font-next.woff2']]));
    $check(App\Support\SubscriptionAssets::fontUrl()==='/build/assets/font-next.woff2','Font follows changed build hash');
    file_put_contents($temporary.'/public/build/manifest.json',json_encode([$manifestKey=>['file'=>'../../secret.woff2']]));
    $check(App\Support\SubscriptionAssets::fontUrl()===null,'Manifest cannot escape public build');
    file_put_contents($temporary.'/public/build/manifest.json','invalid json');
    $check(App\Support\SubscriptionAssets::fontUrl()===null,'Invalid manifest degrades to fallback font');
    $app->usePublicPath($root.'/public');
    foreach(glob($root.'/resources/views/*.blade.php') as $view)$app['blade.compiler']->compile($view);
    $app['blade.compiler']->compile($root.'/resources/views/client/subscribe.blade.php');
    $check(true,'Views compile with changes');
    // Same format as Laravel's config/route cache commands, without booting a fresh app against .env.
    foreach($initialRoutes as $route)$route->prepareForSerialization();
    file_put_contents($temporary.'/routes.php','<?php app(\'router\')->setCompiledRoutes('.var_export($initialRoutes->compile(),true).');');
    file_put_contents($temporary.'/config.php','<?php return '.var_export($initialConfig,true).';');
    $process=new Symfony\Component\Process\Process([getenv('PHP_AUDIT_BIN')?:PHP_BINARY,__FILE__,'--cache-probe',$temporary]);
    $process->setTimeout(45);$process->run();
    if (!$process->isSuccessful() || !str_contains($process->getOutput(),'FINAL AUDIT CACHE OK')) {
        throw new RuntimeException('Fresh cached process failed (exit '.var_export($process->getExitCode(),true).'): '.trim($process->getOutput().' '.$process->getErrorOutput()));
    }
    $check(true,'Fresh cached process preserves middleware, routes and admin gate');
    echo "FINAL AUDIT OK: {$checks} checks passed; SQLite :memory: and temporary storage only.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'FINAL AUDIT FAILED: '.$error->getMessage().PHP_EOL);$exitCode=1;
} finally {
    if(!$child&&str_starts_with(basename($temporary),'speedbox-final-audit-')&&is_dir($temporary)){
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){
            $file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());
        }rmdir($temporary);
    }
}
exit($exitCode);
