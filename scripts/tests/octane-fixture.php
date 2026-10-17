<?php
// Used only by octane-runtime.test.py with an isolated temporary SQLite database.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || !str_starts_with(basename(dirname((string) getenv('DB_DATABASE'))), 'speedbox-octane-test-')) {
    throw new RuntimeException('This fixture requires the isolated Octane test environment.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (($argv[1] ?? '') === 'settings') {
    App\Services\RuntimeSetting::set('subscribe_path', 'custom');
    echo "Settings updated\n";
    exit;
}
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
Schema::create('panel_users', function (Blueprint $t) {
    $t->id(); $t->string('name'); $t->string('email'); $t->string('password');
    $t->integer('role'); $t->boolean('banned')->default(false); $t->timestamps();
});
foreach (['2025_01_01_000000_create_speedbox_core_tables.php',
    '2026_10_08_000001_create_speedbox_infrastructure_tables.php',
    '2026_10_09_000001_create_speedbox_queue_monitor_tables.php',
    '2026_10_10_000003_create_connection_admission_leases.php'] as $file) {
    (require database_path('migrations/'.$file))->up();
}
$admin = new App\Models\User;
$admin->forceFill(['name'=>'QA Admin','email'=>'admin@example.test','password'=>bcrypt('test-password'),'role'=>1,'banned'=>false])->save();
$partner = new App\Models\User;
$partner->forceFill(['name'=>'QA Partner','email'=>'partner@example.test','password'=>bcrypt('test-password'),'role'=>2,'banned'=>false])->save();
$adminToken=$admin->createToken('test',['*'],now()->addHour())->plainTextToken;
$partnerToken=$partner->createToken('test',['*'],now()->addHour())->plainTextToken;
$machine=App\Models\ServerMachine::create(['name'=>'QA Server','token'=>str_repeat('t',48),'is_active'=>true]);
$group=App\Models\ServerGroup::create(['name'=>'QA Group']);
$node=App\Models\ProxyServer::create(['name'=>'QA Node','type'=>'vless','kernel'=>'xray','machine_id'=>$machine->id,
    'host'=>'vpn.example.test','port'=>'443','server_port'=>443,'rate'=>2,'group_ids'=>[$group->id],'route_ids'=>[],
    'show'=>true,'enabled'=>true,'protocol_settings'=>['tls'=>0,'network'=>'tcp','flow'=>'','network_settings'=>[]]]);
foreach (['a','b'] as $i=>$letter) {
    DB::table('v2_user')->insert(['email'=>$letter.'@example.test','password'=>'test',
        'uuid'=>str_repeat($letter,8).'-1234-1234-1234-123456789012','token'=>str_repeat($letter,32),
        'group_id'=>$group->id,'transfer_enable'=>1000000,'expired_at'=>time()+3600,'created_at'=>time(),'updated_at'=>time()]);
}
DB::table('speedbox_queue_workers')->insert(['id'=>'qa-worker','connection'=>'database','queues'=>'default,deployments','last_seen_at'=>now()]);
DB::table('jobs')->insert(['queue'=>'default','payload'=>'{}','attempts'=>0,'reserved_at'=>null,'available_at'=>time(),'created_at'=>time()]);
echo json_encode(['admin'=>$adminToken,'partner'=>$partnerToken,'machine_id'=>$machine->id,'node_id'=>$node->id,'machine_token'=>$machine->token],JSON_THROW_ON_ERROR);
