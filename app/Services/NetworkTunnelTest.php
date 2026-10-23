<?php
namespace App\Services;

use App\Models\{ManagedServer,TunnelConnectionTest,TunnelOperation};
use App\Support\NetworkTunnelRules;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Tests use existing durable test/operation records and the shared server leases. */
class NetworkTunnelTest
{
    public function fingerprint(ManagedServer $server): string
    {
        return hash('sha256',json_encode([(int)$server->id,trim($server->host),(int)$server->machine_id]));
    }

    public function candidates(string $engine,ManagedServer $iran,ManagedServer $foreign,NetworkTunnelManager $network,array $options=[]): array
    {
        $rows=[];
        foreach(['ipv4','ipv6'] as $family) {
            try {
                $peer=$network->serverAddress($foreign,$family,'foreign_server_id');
                $local=in_array($engine,['gre','wattunnel'],true)?$network->serverAddress($iran,$family,'iran_server_id'):'';
            } catch(ValidationException) {continue;}
            if($engine==='wattunnel') {
                $direction=$options['direction']??'normal';
                foreach($options['carriers']??\App\Support\WatTunnelRules::CARRIERS as $carrier)foreach($direction==='both'?[false,true]:[$direction==='reverse'] as $reverse) {
                    if(($reverse&&$carrier==='udp')||($family==='ipv6'&&$carrier==='icmp'))continue;
                    $rows[]=['id'=>'wattunnel:'.$carrier.':'.($reverse?'reverse':'normal').':'.$family,'engine'=>$engine,
                        'version'=>\App\Support\WatTunnelRules::VERSION,'transport'=>$carrier,'reverse'=>$reverse,'ip_family'=>$family,
                        'peer_host'=>$peer,'local_endpoint'=>$local,'server_fingerprints'=>[$this->fingerprint($iran),$this->fingerprint($foreign)],
                        'label'=>'WatTunnel · '.strtoupper($carrier).' · '.($reverse?'Reverse':'Direct').' · '.($family==='ipv6'?'IPv6':'IPv4'),
                        'status'=>'testing','selectable'=>false,'protocols'=>[],'recommended'=>false];
                }
                continue;
            }
            foreach($engine==='gost'?NetworkTunnelRules::VERSIONS:($family==='ipv6'?['ip6gre','ip6gretap']:['gre','gretap']) as $method) {
                $version=$engine==='gost'?$method:'3.0.0';$transport=$engine==='gost'?'tcp':$method;
                $rows[]=['id'=>$engine.':'.$method.':'.$family,'engine'=>$engine,'version'=>$version,'transport'=>$transport,'ip_family'=>$family,
                    'peer_host'=>$peer,'local_endpoint'=>$local,'server_fingerprints'=>[$this->fingerprint($iran),$this->fingerprint($foreign)],
                    'label'=>$engine==='gost'?'GOST '.($version==='2.11.5'?'2':'3').' · '.($family==='ipv6'?'IPv6':'IPv4'):strtoupper($transport).' · '.($family==='ipv6'?'IPv6':'IPv4'),
                    'status'=>'testing','selectable'=>false,'protocols'=>[],'recommended'=>false];
            }
        }
        if(!$rows)NetworkTunnelRules::fail('foreign_server_id','برای این نوع تونل، آدرس سازگار روی دو سرور در دسترس نیست.');
        return $rows;
    }

    public function testOperation(TunnelConnectionTest $test): TunnelOperation
    {
        return $test->operations()->where('action','network-test')->latest()->firstOrFail();
    }

    public function publicTest(TunnelConnectionTest $test): array
    {
        $op=$this->testOperation($test);
        $data=$test->toArray();$data['engine']=$op->payload['engine'];
        $data['results']=array_map(function($row){unset($row['server_fingerprints'],$row['local_endpoint'],$row['peer_host']);return $row;},$data['results']??[]);
        return $data;
    }

    /** Bind creation to a fresh, completed measurement of these exact endpoints. */
    public function assertSelection(Request $request,array $settings): void
    {
        if(($settings['address_source']??null)!=='servers')NetworkTunnelRules::fail('address_source','برای تونل جدید از آدرس سرورهای انتخاب‌شده استفاده کنید.');
        foreach($settings['mappings'] as $mapping)if(!empty($mapping['destinations']))NetworkTunnelRules::fail('mappings','مقصد اختصاصی جدید خارج از مسیر تست‌شده است؛ برای آن سرور، تونل جدا بسازید.');
        $ids=$request->validate(['test_id'=>'required|integer|exists:speedbox_tunnel_tests,id','result_id'=>'required|string|max:100','accept_unstable'=>'sometimes|boolean']);
        $test=TunnelConnectionTest::whereKey($ids['test_id'])->lockForUpdate()->firstOrFail();
        $op=$this->testOperation($test);
        abort_unless($op->status==='succeeded'&&$test->status==='completed'&&$test->updated_at->greaterThan(now()->subHours(24)),422,'ابتدا تست اتصال را کامل کنید؛ نتیجه تا ۲۴ ساعت معتبر است.');
        abort_unless((int)$test->iran_server_id===(int)$settings['iran_server_id']&&(int)$test->foreign_server_id===(int)$settings['foreign_server_id']&&$op->payload['engine']===$settings['engine'],422,'نتیجهٔ تست متعلق به این نوع تونل و این دو سرور نیست.');
        $row=collect($test->results??[])->firstWhere('id',$ids['result_id']);
        abort_unless($row&&($row['selectable']??false),422,'یک نتیجهٔ قابل استفاده انتخاب کنید.');
        foreach(['transport','ip_family'] as $key)abort_unless(($row[$key]??null)===($settings[$key]??null),422,'مدل یا نوع IP تغییر کرده است؛ نتیجهٔ سازگار را انتخاب کنید.');
        if($settings['engine']==='wattunnel')abort_unless((bool)($row['reverse']??false)===$settings['reverse'],422,'جهت اتصال با نتیجهٔ تست سازگار نیست.');
        if(in_array($settings['engine'],['gost','wattunnel'],true))abort_unless($row['version']===$settings['version'],422,'برای نسخهٔ انتخاب‌شده، نتیجهٔ تست سازگار را انتخاب کنید.');
        $iran=ManagedServer::findOrFail($test->iran_server_id);$foreign=ManagedServer::findOrFail($test->foreign_server_id);
        abort_unless(($row['server_fingerprints']??[])===[$this->fingerprint($iran),$this->fingerprint($foreign)]&&$row['peer_host']===$settings['peer_host']&&(!in_array($settings['engine'],['gre','wattunnel'],true)||$row['local_endpoint']===$settings['local_endpoint']),422,'آدرس سرورها پس از تست تغییر کرده است؛ دوباره تست بگیرید.');
        foreach($settings['protocol']==='both'?['tcp','udp']:[$settings['protocol']] as $proto) {
            $result=$row['protocols'][$proto]??[];
            abort_unless(in_array($result['status']??'',['ok','unstable'],true),422,'پروتکل انتخاب‌شده در این مسیر پاسخ نداده است.');
            abort_if($result['status']==='unstable'&&!$request->boolean('accept_unstable'),422,'این مسیر ناپایدار است؛ استفاده از نتیجهٔ ناپایدار را تأیید کنید.');
        }
    }
}
