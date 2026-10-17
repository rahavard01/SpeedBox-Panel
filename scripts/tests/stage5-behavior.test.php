<?php

// Run with: php scripts/tests/partner-accounts.test.php
// Uses a separate in-memory SQLite database; it never boots the project .env.
$root = dirname(__DIR__, 2);
require getenv('PARTNER_TEST_AUTOLOAD') ?: $root . '/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root) {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require_once $path;
    }
});

use App\Http\Controllers\Api\Admin\PartnersAdminController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\Api\PartnerAccountReadController;
use App\Services\AccountOnlineStatus;
use App\Services\PanelAccountOwnership;
use App\Services\V2UserCreator;
use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$app = new Application($root);
$app->instance('config', new Repository([
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
    'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
    'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
]));
Facade::setFacadeApplication($app);
foreach ([Illuminate\View\ViewServiceProvider::class, Illuminate\Filesystem\FilesystemServiceProvider::class, Illuminate\Events\EventServiceProvider::class, Illuminate\Database\DatabaseServiceProvider::class,
    Illuminate\Translation\TranslationServiceProvider::class, Illuminate\Validation\ValidationServiceProvider::class,
    Illuminate\Hashing\HashServiceProvider::class, Illuminate\Cache\CacheServiceProvider::class] as $provider) $app->register($provider);
$app->instance('request', Request::create('http://partner.test/'));
Request::macro('validate', function (array $rules) use ($app) { return $app['validator']->make($this->all(), $rules)->validate(); });

$app->boot();
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory { public function __construct() {} });
$app->instance(Illuminate\Http\Client\Factory::class,new Illuminate\Http\Client\Factory);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(['api.telegram.org/*'=>Illuminate\Support\Facades\Http::response(['ok'=>true,'result'=>['message_id'=>1]])]);
$checks=0;$check=function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$request=function($role,$items=null){$r=Request::create('/test','PUT',$items===null?[]:['items'=>$items]);$r->setUserResolver(fn()=>(object)['id'=>1,'role'=>$role]);return $r;};
$reject=function($call,$class)use($check){try{$call();}catch(Throwable $e){$check($e instanceof $class,'Expected '.$class.', got '.get_class($e));return;}throw new RuntimeException('Expected rejection');};
Schema::create('panel_users',function(Blueprint $t){$t->id();$t->string('partner_plan_type')->default('time');});
foreach(['2025_01_01_000000_create_speedbox_core_tables.php','2026_10_08_000001_create_speedbox_infrastructure_tables.php','2026_10_10_000003_create_connection_admission_leases.php','2026_06_06_000001_create_user_bot_tables.php','2026_10_12_000001_create_user_bot_faqs.php']as $migration)(require $root.'/database/migrations/'.$migration)->up();
$controller=new App\Http\Controllers\Api\Admin\UserBotFaqAdminController;
$faqs=new App\Services\UserBot\GlobalUserBotFaq;
$check($faqs->active()->isEmpty(),'No hardcoded defaults');
$check(!in_array(['❓ سوالات متداول'],App\Services\UserBot\UserBotKeyboard::guest(true)['keyboard'],true),'Empty FAQ hides guest button');
$reject(fn()=>$controller->index($request(2)),Symfony\Component\HttpKernel\Exception\HttpException::class);
$reject(fn()=>$controller->update($request(2,[])),Symfony\Component\HttpKernel\Exception\HttpException::class);
$reject(fn()=>$controller->update($request(1,[['question'=>' ','answer'=>'x','enabled'=>true]])),ValidationException::class);
$rows=$controller->update($request(1,[['question'=>'اول <سؤال>','answer'=>"پاسخ <b>متن</b>\nخط دوم & ادامه",'enabled'=>true],['question'=>'دوم','answer'=>'پاسخ دوم','enabled'=>false]]))->getData(true)['data'];
$id=$rows[0]['id'];$second=$rows[1]['id'];
$check(count($rows)===2&&$faqs->active()->count()===1,'Enabled questions only');
$check(str_contains($faqs->answer($id),'&lt;b&gt;متن&lt;/b&gt;')&&str_contains($faqs->answer($id),'خط دوم &amp; ادامه'),'Plain answers safely escaped, newline retained');
$check($faqs->answer($second)===null,'Inactive callbacks cannot expose answer');
$check(App\Services\UserBot\UserBotKeyboard::faqMenu()['inline_keyboard'][0][0]['callback_data']==='userbot_faq:item_'.$id,'Stable ID callback');
$controller->update($request(1,[['id'=>$second,'question'=>'دوم','answer'=>'پاسخ دوم','enabled'=>true],['id'=>$id,'question'=>'اول','answer'=>'پاسخ جدید','enabled'=>true]]));
$check($faqs->active()->pluck('id')->all()===[$second,$id],'Order changes keep callback IDs stable');
$reject(fn()=>$controller->update($request(1,[['id'=>999,'question'=>'bad','answer'=>'bad','enabled'=>true]])),ValidationException::class);
$check($faqs->active()->count()===2,'Invalid replacement rolls back');
$webhook=new App\Http\Controllers\Api\UserBotWebhookController;
foreach([['admin',1,'admin-test'],['partner',2,'partner-test']]as[$type,$owner,$token]){
 DB::table('panel_users')->insertOrIgnore(['id'=>$owner,'partner_plan_type'=>'time']);
 App\Models\UserBotSetting::create(['owner_type'=>$type,'owner_id'=>$owner,'bot_token'=>$token,'webhook_secret'=>'testsecret','is_enabled'=>true]);
 $r=Request::create('/webhook','POST',['message'=>['chat'=>['id'=>99],'from'=>['id'=>99],'text'=>'❓ سوالات متداول']]);
 $check($webhook->handle($r,$type,$owner,'testsecret')->getData(true)['ok'],'Actual '.$type.' FAQ menu webhook');
 $r=Request::create('/webhook','POST',['callback_query'=>['id'=>'callback','data'=>'userbot_faq:item_'.$id,'from'=>['id'=>99],'message'=>['chat'=>['id'=>99],'message_id'=>10]]]);
 $check($webhook->handle($r,$type,$owner,'testsecret')->getData(true)['ok'],'Actual '.$type.' answer webhook');
}
$sent=Illuminate\Support\Facades\Http::recorded()->map(fn($pair)=>$pair[0]->data())->all();
$answers=array_values(array_filter($sent,fn($v)=>str_contains($v['text']??'','پاسخ جدید')));
$check(count($answers)===2,'Admin and partner bots share exact same saved answer');
$controller->update($request(1,[]));
$check($faqs->answer($id)===null&&$faqs->active()->isEmpty(),'Deleting all questions is allowed');
$check(!in_array(['❓ سوالات متداول'],App\Services\UserBot\UserBotKeyboard::user()['keyboard'],true),'Empty FAQ hides logged-in menu button');
$policy=new App\Services\UserBot\AccountTrafficResetPolicy;
$summary=new ReflectionMethod($webhook,'buildUserBotOrderSummaryText');
$session=new App\Models\UserBotSession;
$session->account_email='x@rep.com';
foreach([0,1,2,3,4,null]as $method){
 $plan=DB::table('v2_plan')->insertGetId(['name'=>'VIP','group_id'=>1,'reset_traffic_method'=>$method,'created_at'=>time(),'updated_at'=>time()]);
 foreach([0,2]as $global){App\Services\RuntimeSetting::set('reset_traffic_method',$global);
  foreach(['VIP','معمولی']as $name){$snapshot=['plan_id'=>$plan,'account_plan_name'=>$name];$expected=in_array($method??$global,[0,1,3,4],true);
   $check($policy->hasPeriodicReset($snapshot)===$expected,'Effective reset independent of name');
   $session->account_snapshot=$snapshot;
   $text=$summary->invoke($webhook,$session,'buy_traffic',['plan_key'=>'gig','title'=>'ترافیک'],2,100,'مبلغ');
   $check(str_contains($text,'قابل انتقال به ماه بعد نیست')===$expected,'Original rollover wording follows effective reset');
  }
 }
}
$check(!$policy->hasPeriodicReset(['plan_id'=>999])&&!$policy->hasPeriodicReset([]),'Missing plan safe');
$ip=new App\Services\AccountOnlineStatus;
$uid=function($email){return DB::table('v2_user')->insertGetId(['email'=>$email,'password'=>'x','uuid'=>$email,'token'=>$email,'created_at'=>time(),'updated_at'=>time()]);};
$a=$uid('a@rep.com');$b=$uid('b@rep.net');$c=$uid('c@other.com');
foreach([[$a,1,'192.0.2.1',60],[$a,2,'192.0.2.1',60],[$a,2,'192.0.2.2',60],[$b,1,'192.0.2.1',60],[$c,1,'192.0.2.3',60],[$a,3,'192.0.2.4',-1],[999,1,'192.0.2.5',60]]as[$user,$node,$address,$ttl])DB::table('speedbox_connection_leases')->insert(['user_id'=>$user,'node_id'=>$node,'source_ip'=>$address,'lease_id'=>'test','expires_at'=>time()+$ttl]);
$check($ip->activeIpTotal()===4,'IP total deduplicates account/IP across nodes and ignores expired/deleted users');
$check($ip->activeIpTotal('rep')===3,'Representative only own accounts, including legacy domain');
$check($ip->activeIpTotal('')===0,'Empty partner code denies all');
$check($ip->counts([$a])[$a]===2,'Account badge uses same IP deduplication');
$never=DB::table('v2_plan')->insertGetId(['name'=>'VIP','group_id'=>1,'reset_traffic_method'=>2,'created_at'=>time(),'updated_at'=>time()]);
DB::table('v2_user')->where('id',$a)->update(['plan_id'=>$never]);
$check(!$policy->hasPeriodicReset(['id'=>$a,'plan_id'=>1]),'Old bot snapshot does not override current non-reset plan');
DB::table('v2_user')->where('id',$a)->update(['plan_id'=>1]);
$check($policy->hasPeriodicReset((object)['id'=>$a,'plan_id'=>$never]),'Current resetting plan overrides old snapshot');
echo "Stage 5: {$checks} backend checks passed (isolated SQLite; Telegram mocked).\n";
