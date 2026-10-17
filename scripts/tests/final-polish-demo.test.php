<?php

// Isolated real-MySQL integration test; never loads the project .env or live DB.
$root = dirname(__DIR__, 2);
$fallback = getenv('COMPATIBILITY_TEST_PROJECT_ROOT') ?: $root;
require getenv('PARTNER_TEST_AUTOLOAD') ?: $root . '/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root, $fallback) {
    if (!str_starts_with($class, 'App\\')) return;
    $relative = '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    foreach (array_unique([$root, $fallback]) as $directory) {
        if (is_file($directory . $relative)) { require_once $directory . $relative; return; }
    }
});

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

$port = getenv('LOOKUP_TEST_MYSQL_PORT');
if (!$port) throw new RuntimeException('LOOKUP_TEST_MYSQL_PORT must point to an isolated MySQL test instance');
$app = new Illuminate\Foundation\Application($root);
$testStorage = sys_get_temp_dir().'/speedbox-polish-tests/'.bin2hex(random_bytes(6));
mkdir($testStorage.'/views', 0700, true);
$app->instance('config', new Illuminate\Config\Repository([
    'view' => ['paths'=>[$root.'/resources/views'], 'compiled'=>$testStorage.'/views'],
    'filesystems' => ['default'=>'local', 'disks'=>['local'=>['driver'=>'local','root'=>$testStorage.'/local'], 'public'=>['driver'=>'local','root'=>$testStorage.'/public']]],
    'queue'=>['default'=>'database','connections'=>['database'=>['driver'=>'database','table'=>'jobs']]],
    'app' => ['url' => 'https://new.test', 'locale' => 'en', 'fallback_locale' => 'en'],
    'database' => ['default' => 'mysql', 'connections' => ['mysql' => [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port, 'database' => 'mysql',
        'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '', 'strict' => true, 'options' => [PDO::ATTR_EMULATE_PREPARES => false],
    ]]],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
    'auth' => ['defaults' => ['guard' => 'test'], 'guards' => ['test' => ['driver' => 'test']]],
]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
foreach ([Illuminate\Events\EventServiceProvider::class, Illuminate\Database\DatabaseServiceProvider::class,
    Illuminate\Cache\CacheServiceProvider::class, Illuminate\Hashing\HashServiceProvider::class,
    Illuminate\Auth\AuthServiceProvider::class, Illuminate\Filesystem\FilesystemServiceProvider::class,
    Illuminate\Translation\TranslationServiceProvider::class, Illuminate\Validation\ValidationServiceProvider::class,
    Illuminate\View\ViewServiceProvider::class] as $provider) $app->register($provider);
$app->instance('env','testing');
$app->boot();
$app->instance('request', Request::create('http://test/'));
$app['auth']->viaRequest('test', fn () => null);
Request::macro('validate', function (array $rules) use ($app) { return $app['validator']->make($this->all(), $rules)->validate(); });
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory {
    public function __construct() {}
});
$files = [];
foreach (array_unique([$fallback, $root]) as $directory) {
    foreach (glob($directory . '/database/migrations/*.php') as $file) $files[basename($file)] = $file;
}
ksort($files);
$runMigration = function (string $file): void {
    $class = Illuminate\Support\Str::studly(implode('_', array_slice(explode('_', basename($file, '.php')), 4)));
    if (class_exists($class, false)) { (new $class)->up(); return; }
    $migration = require $file;
    if (is_object($migration)) $migration->up();
    else (new $class)->up();
};

$checks = 0;
$check = function ($value, $message) use (&$checks) { if (!$value) throw new RuntimeException($message); $checks++; };
$db = DB::connection();
$name = 'final_polish_test_'.bin2hex(random_bytes(6));
$db->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->statement("USE `{$name}`"); $db->setDatabaseName($name);
try {
    foreach ($files as $file) $runMigration($file);
    $id = DB::table('panel_users')->insertGetId(['name'=>'Existing representative','email'=>'existing@test.com','password'=>'untouched','code'=>'testrep','role'=>2,'credit'=>12345]);
    $oldIds = [];
    for ($i=0;$i<2;$i++) $oldIds[] = DB::table('v2_user')->insertGetId(['email'=>'old'.$i.'@testrep.com','password'=>'untouched','uuid'=>(string) Illuminate\Support\Str::uuid(),'token'=>Illuminate\Support\Str::random(32),'u'=>123,'d'=>456,'transfer_enable'=>789,'reset_count'=>2,'created_at'=>123,'updated_at'=>456]);
    $beforeUsers = DB::table('v2_user')->get()->toArray();
    $beforeOwner = DB::table('panel_users')->where('id',$id)->first();
    $base = [];
    foreach (Schema::getTables() as $info) $base[$info['name']] = DB::table($info['name'])->get()->toArray();
    $service = new App\Services\LocalDemoData;
    $result = $service->run('testrep');
    $check(!$result['existing'], 'First run creates tracked samples');
    $check(count($result['accounts']) === 7, 'Seven accounts cover limits and account status cases');
    $check(DB::table('panel_users')->where('id',$id)->first() == $beforeOwner, 'Existing representative code, pricing and credit remain unchanged');
    $check(DB::table('v2_user')->whereIn('id',$oldIds)->get()->toArray() == $beforeUsers, 'Existing two accounts remain unchanged');
    foreach ($oldIds as $userId) $check(DB::table('v2_traffic_reset_logs')->where('user_id',$userId)->count()===6, 'Existing accounts get six reset previews');
    $demoUsers = DB::table('v2_user')->whereNotIn('id',$oldIds)->orderBy('id')->get();
    $counts = (new App\Services\AccountOnlineStatus)->counts($demoUsers->pluck('id')->all());
    $check(array_map(fn($user)=>(int)($counts[$user->id]??0), $demoUsers->all()) === [0,1,2,3,2,0,0], 'Real online service reports zero / below / equal / above / unlimited');
    $check(DB::table('jobs')->count()===0, 'No runnable jobs are seeded');
    $check(DB::table('speedbox_deployments')->whereIn('status',['queued','running'])->count()===0, 'All deployment samples are historical');
    $check(DB::table('user_bot_settings')->where('is_enabled',true)->count()===0, 'New bot settings stay disabled');
    $check(DB::table('panel_transactions')->whereIn('type',['credit_adjustment','referral_commission'])->count()===0, 'Financial samples use actual Panel transaction types');
    $check(DB::table('panel_transactions')->where('type','wallet_adjust')->where('direction','debit')->exists(), 'Adjustment sample is a debit');
    $check(DB::table('panel_announcements')->where('title','اطلاعیه')->count()===1, 'One short announcement preview is available');
    $adminRequest = Illuminate\Http\Request::create('/', 'GET', ['status'=>'all']);
    $adminRequest->setUserResolver(fn () => DB::table('panel_users')->where('role',1)->first());
    $ownerRequest = Illuminate\Http\Request::create('/');
    $ownerRequest->setUserResolver(fn () => DB::table('panel_users')->where('id',$id)->first());
    $billing = json_decode((new App\Http\Controllers\Api\BillingController)->all($adminRequest)->getContent(),true);
    $fee = collect($billing)->firstWhere('type','ai_receipt_check_fee');
    $check($fee && $fee['user_code']==='testrep' && $fee['affected_users']===[] && $fee['signed_amount']===-4000, 'AI fee exposes its panel owner without inventing a VPN account');
    $adjustId = DB::table('panel_wallet_receipts')->insertGetId(['user_id'=>$id,'amount'=>100,'method'=>'adjust','status'=>'verified','path'=>'adjust://admin','disk'=>'public','original_name'=>'ADMIN-ADJUST','mime'=>'text/plain','size'=>0,'meta'=>'{}','created_at'=>now(),'updated_at'=>now()]);
    $wallet = new App\Http\Controllers\Api\Admin\WalletAdminController;
    $receipts = json_decode($wallet->index($adminRequest)->getContent(),true);
    $check(!in_array($adjustId,array_column($receipts,'id')), 'A wallet correction is excluded from actual deposits');
    $adjustNotices = json_decode((new App\Http\Controllers\Api\WalletController)->pendingAdjustToasts($ownerRequest)->getContent(),true);
    $check(in_array($adjustId,array_column($adjustNotices,'id')), 'Adjustment notification remains available to its owner');
    DB::table('panel_wallet_receipts')->where('id',$adjustId)->delete();
    foreach ([$wallet->countPending($adminRequest),(new App\Http\Controllers\Api\ReferralController)->adminCount($adminRequest),(new App\Http\Controllers\Api\Admin\SupportTicketAdminController)->count($adminRequest)] as $response) {
        $payload=json_decode($response->getContent(),true);
        $check($payload['count']===count($payload['notification_ids']), 'Admin unread counter retains separate event IDs');
    }
    $noticeService = new App\Services\PartnerNotificationState;
    $noticeBefore = $noticeService->snapshot($id);
    $ticketId = DB::table('support_tickets')->insertGetId(['user_id'=>$id,'ticket_number'=>98765,'subject'=>'Reply notice test','status'=>'closed']);
    $foreignTicketId = DB::table('support_tickets')->insertGetId(['user_id'=>DB::table('panel_users')->where('role',1)->value('id'),'ticket_number'=>98766,'subject'=>'Other owner']);
    $replyId = DB::table('support_ticket_messages')->insertGetId(['ticket_id'=>$ticketId,'sender_id'=>DB::table('panel_users')->where('role',1)->value('id'),'sender_role'=>'admin','message'=>'New reply','is_read_by_user'=>false,'is_read_by_admin'=>true]);
    $foreignReplyId = DB::table('support_ticket_messages')->insertGetId(['ticket_id'=>$foreignTicketId,'sender_id'=>DB::table('panel_users')->where('role',1)->value('id'),'sender_role'=>'admin','message'=>'Private other reply','is_read_by_user'=>false,'is_read_by_admin'=>true]);
    $readReplyId = DB::table('support_ticket_messages')->insertGetId(['ticket_id'=>$ticketId,'sender_id'=>DB::table('panel_users')->where('role',1)->value('id'),'sender_role'=>'admin','message'=>'Already read','is_read_by_user'=>true,'is_read_by_admin'=>true]);
    $notice = json_decode((new App\Http\Controllers\Api\SupportTicketController)->unreadCount($ownerRequest)->getContent(),true);
    $check($notice['count']===$noticeBefore['count']+1, 'Closed tickets still expose their unread reply');
    $check(in_array($replyId,$notice['notification_ids']) && !in_array($foreignReplyId,$notice['notification_ids']) && !in_array($readReplyId,$notice['notification_ids']), 'Reply identities exclude other owners and read messages');
    $check(collect($notice['notifications'])->firstWhere('id',$replyId)['ticket_number']===98765, 'Reply notification carries its actual ticket number');
    $check($notice['announcement_count']===$noticeBefore['announcement_count'], 'One response includes the current independent announcement count');
    $check(!DB::table('support_ticket_messages')->where('id',$replyId)->value('is_read_by_user'), 'Fetching toast state does not mark a reply read');
    DB::table('support_ticket_messages')->where('id',$replyId)->update(['is_read_by_user'=>true]);
    $nextReplyId = DB::table('support_ticket_messages')->insertGetId(['ticket_id'=>$ticketId,'sender_id'=>DB::table('panel_users')->where('role',1)->value('id'),'sender_role'=>'admin','message'=>'Another new reply','is_read_by_user'=>false,'is_read_by_admin'=>true]);
    $nextNotice = $noticeService->snapshot($id);
    $check($nextNotice['count']===$notice['count'] && in_array($nextReplyId,$nextNotice['notification_ids']) && !in_array($replyId,$nextNotice['notification_ids']), 'A new reply remains identifiable when unread total stays unchanged');
    $profile = json_decode((new App\Http\Controllers\Api\PanelController)->getProfile($ownerRequest)->getContent(),true);
    $check($profile['pending_counts']===['announcements'=>$nextNotice['announcement_count'],'tickets'=>$nextNotice['count']], 'Profile carries both badge counts together');
    $app['config']->set('view.paths',[$root.'/resources/views',$fallback.'/resources/views']);
    $app['config']->set('view.compiled',$testStorage.'/views');
    $app->register(Illuminate\View\ViewServiceProvider::class);
    $app['auth']->guard()->setUser(new Illuminate\Auth\GenericUser((array) DB::table('panel_users')->where('id',$id)->first()));
    $bootstrap = (new App\Http\Controllers\AppController)->index($ownerRequest)->getData()['bootstrap'];
    $check($bootstrap['pending_counts']['tickets']===$nextNotice['count'] && $bootstrap['pending_counts']['announcements']===$nextNotice['announcement_count'], 'Initial server-rendered bootstrap has the complete badge total');
    $app['auth']->forgetGuards();
    DB::table('support_ticket_messages')->whereIn('ticket_id',[$ticketId,$foreignTicketId])->delete();
    DB::table('support_tickets')->whereIn('id',[$ticketId,$foreignTicketId])->delete();
    $tariffSnapshot = json_decode((new App\Http\Controllers\Api\Admin\TariffsAdminController)->index()->getContent(),true);
    foreach ($tariffSnapshot['v2_options'] as $option) {
        $defaultLimit = DB::table('v2_plan')->where('id',$option['id'])->value('device_limit');
        $check(array_key_exists('device_limit',$option) && $option['device_limit']===($defaultLimit===null?null:(int)$defaultLimit), 'Tariff UI receives the real plan default without changing it');
    }
    $coverage = array_column($result['coverage'],1,0);
    foreach (['panel_transactions','panel_wallet_receipts','panel_crypto_topups','panel_wallet_card_topups','panel_referrals','support_tickets','support_ticket_messages','support_ticket_attachments','panel_announcements','panel_announcement_reads','panel_tutorials','panel_tutorial_toasts','user_bot_settings','user_bot_plans','user_bot_sessions','user_bot_orders','user_bot_sms_devices','user_bot_sms_events','v2_server','v2_server_machine','v2_server_machine_load_history','v2_server_group','v2_server_route','v2_plan','panel_tariff_groups','panel_plan','panel_user_tariff_prices','v2_stat_user','v2_stat_server','v2_traffic_reset_logs','speedbox_connection_leases','speedbox_managed_servers','speedbox_deployments','speedbox_queue_activity','failed_jobs'] as $table) $check(($coverage[$table]??0)>0, 'Demo coverage: '.$table);
    $saved = [];
    foreach (Schema::getTables() as $info) $saved[$info['name']] = DB::table($info['name'])->count();
    $again = $service->run('testrep'); $check($again['existing'], 'Repeat run reuses manifest');
    $manifestDisk=Illuminate\Support\Facades\Storage::disk('local');
    $oldManifest=json_decode($manifestDisk->get('speedbox-demo-all-v1.json'),true);
    $legacyId=DB::table('panel_transactions')->where('type','wallet_adjust')->orderBy('id')->value('id');
    DB::table('panel_transactions')->where('id',$legacyId)->update(['type'=>'credit_adjustment','direction'=>'credit','balance_after'=>2250000]);
    foreach ($oldManifest['rows'] as &$row) if($row['table']==='panel_transactions' && $row['id']===$legacyId) $row['snapshot']=(array)DB::table('panel_transactions')->where('id',$legacyId)->first();
    unset($row);
    $shortId=$oldManifest['short_announcement_id'];
    DB::table('panel_announcements')->where('id',$shortId)->delete();
    $oldManifest['rows']=array_values(array_filter($oldManifest['rows'],fn($row)=>!($row['table']==='panel_announcements' && $row['id']===$shortId)));
    unset($oldManifest['short_announcement_id']);
    $manifestDisk->put('speedbox-demo-all-v1.json',json_encode($oldManifest,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $service->refreshReviewPreviews();
    $upgraded=DB::table('panel_transactions')->where('id',$legacyId)->first();
    $check($upgraded->type==='wallet_adjust' && $upgraded->direction==='debit', 'Existing untouched samples are upgraded to actual adjustment semantics');
    $check(DB::table('panel_announcements')->where('title','اطلاعیه')->count()===1, 'Review command restores one missing short sample without reseeding');
    $check(DB::table('panel_users')->where('id',$id)->first()==$beforeOwner, 'Preview refresh never changes the real wallet balance');
    foreach ($saved as $table=>$count) $check(DB::table($table)->count()===$count, 'Repeat has no duplicate rows: '.$table);
    $queue = (new App\Services\QueueMonitor)->snapshot();
    $check($queue['recentJobs']===2 && $queue['failedJobs']===1, 'Real queue monitor reads sample successes and failures');
    $check($queue['processes']===1, 'Preview worker telemetry populates queue statistics without runnable jobs');
    $infra = new App\Http\Controllers\Api\Admin\InfrastructureController(new App\Services\NodeControlService);
    $overview = $infra->overview()->getData(true)['data'];
    $check($overview['online_machines']===1 && $overview['online_nodes']===2, 'Real server overview sees preview machine and node telemetry');
    // Render included partials through Blade's real view environment.
    $render = static fn (array $variables) => $app['view']->make('client.subscribe', $variables)->render();
    foreach ([[0,2,'is-offline'],[1,2,'is-online'],[2,2,'is-at-limit'],[3,2,'is-at-limit'],[2,null,'is-online']] as [$count,$limit,$state]) {
        $html = $render(['username'=>'preview','status'=>'active','data_used'=>'2 GB','data_limit'=>'20 GB','data_remaining'=>'18 GB','usage_percent'=>10,'expired_date'=>'1405/07/12','remaining_days'=>3,'online_count'=>$count,'device_limit'=>$limit,'subscription_url'=>'http://test/s/token','qr_code'=>'']);
        $check(substr_count($html,'class="stat-box"')===6, 'Browser portal renders six statistics');
        $check(str_contains($html,'دستگاه‌های آنلاین') && str_contains($html,'speedbox-online-count '.$state), 'Portal shows real count / limit badge state');
        $badge=App\Support\JalaliDateFormatter::persianDigits((string)$count).' / '.($limit === null ? '∞' : App\Support\JalaliDateFormatter::persianDigits((string)$limit));
        $check(str_contains($html,'<bdi>'.$badge.'</bdi>'), 'Portal preserves above-limit count and unlimited badge with Persian digits');
    }
    $formatter = App\Support\JalaliDateFormatter::class;
    $check($formatter::format(Carbon\CarbonImmutable::create(2026,10,4,23,15,0,'Asia/Tehran'))==='23:15 12-07-1405', 'Machine date contract stays Latin');
    $check($formatter::forTelegram(Carbon\CarbonImmutable::create(2026,10,4,23,15,0,'Asia/Tehran'))==="۲۳:۱۵ ۱۲-\u{2060}۰۷-\u{2060}۱۴۰۵", 'Telegram dates have Persian digits with separator guards');
    $check($formatter::persianDigits('12 GB / :443')==='۱۲ GB / :۴۴۳', 'Digit utility does not change punctuation or units');
    $demo = $demoUsers[0];
    DB::table('v2_user')->where('id',$demo->id)->update(['remarks'=>'Edited by tester']);
    $blocked = false;
    try { $service->run('testrep',true); } catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(),'ویرایش'); }
    $check($blocked && DB::table('v2_user')->where('id',$demo->id)->exists(), 'Cleanup refuses to erase edited sample data');
    DB::table('v2_user')->where('id',$demo->id)->update(['remarks'=>$demo->remarks]);
    $newTicket = DB::table('support_tickets')->insertGetId(['user_id'=>DB::table('panel_users')->where('role',1)->value('id'),'subject'=>'Untracked dependent record','ticket_number'=>123999]);
    $blocked = false;
    try { $service->run('testrep',true); } catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(),'وابسته'); if (!$blocked) throw $e; }
    $check($blocked && DB::table('support_tickets')->where('id',$newTicket)->exists(), 'Cleanup refuses cascades on untracked child records');
    DB::table('support_tickets')->where('id',$newTicket)->delete();
    $service->run('testrep',true);
    foreach ($base as $table=>$rows) $check(DB::table($table)->get()->toArray()==$rows, 'Cleanup restores original data: '.$table);
    $app->instance('env','production');
    $blocked=false; try { $service->run('testrep'); } catch (RuntimeException $e) { $blocked=true; }
    $check($blocked, 'Production cannot seed demo data');
    echo "Final polish MySQL samples: {$checks} checks passed.\n";
} finally {
    $db->statement('USE mysql'); $db->setDatabaseName('mysql'); $db->statement("DROP DATABASE `{$name}`");
    foreach (['local','public'] as $disk) { Illuminate\Support\Facades\Storage::disk($disk)->deleteDirectory('speedbox-demo'); }
    Illuminate\Support\Facades\Storage::disk('local')->delete(['speedbox-demo-all-v1.json','speedbox-demo-all-v1.json.lock']);
}
