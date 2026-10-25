<?php
namespace App\Jobs;

use App\Models\{ManagedServer,ServerTunnel,TunnelConnectionTest,TunnelOperation};
use App\Services\{BackpackRemote,NetworkTunnelManager,NetworkTunnelRemote,TunnelCdnRemote,TunnelManager};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted,ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\Cache;
use Throwable;

/** A second, method-specific test; never authorizes CDN from a direct scan. */
class RunTunnelCdnTest implements ShouldQueue,ShouldBeEncrypted
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=1;
    public int $timeout=600;
    public function __construct(public readonly int $operationId){$this->onQueue('deployments');}

    public function handle(TunnelCdnRemote $cdn,BackpackRemote $bp,NetworkTunnelRemote $wat,NetworkTunnelManager $network,TunnelManager $manager): void
    {
        $lock=Cache::lock('speedbox-cdn-test-'.$this->operationId,630);if(!$lock->get())return;
        try {
            $op=TunnelOperation::findOrFail($this->operationId);if(!in_array($op->status,['queued','running']))return;
            $test=TunnelConnectionTest::findOrFail($op->test_id);$p=$op->payload;$method=$p['method'];
            $servers=['iran'=>ManagedServer::findOrFail($test->iran_server_id),'foreign'=>ManagedServer::findOrFail($test->foreign_server_id)];
            $t=new ServerTunnel(['remote_name'=>$p['remote_name'],'engine'=>$method['engine'],'kind'=>$method['kind'],'transport'=>$method['transport'],'orientation'=>$method['orientation'],
                'iran_server_id'=>$test->iran_server_id,'foreign_server_id'=>$test->foreign_server_id,'secret'=>$p['token'],'subnet'=>$p['subnet'],'settings'=>$p['settings']]);
            foreach($servers as $side=>$server)$t->setRelation($side,$server);
            $listener=TunnelCdnRemote::listenerIran($t,$p['settings'])?'iran':'foreign';$dialer=$listener==='iran'?'foreign':'iran';$side=['iran','foreign'][$p['index']??0];
            $op->update(['status'=>'running']);$test->update(['status'=>'running']);
            if(($op->cancel_requested||$op->deadline->isPast())&&!in_array($p['phase'],['cleanup','done'])){$p['error']=$op->cancel_requested?'آزمون متوقف شد.':'مهلت آزمون تمام شد.';$p['phase']='cleanup';}
            try {
                switch($p['phase']) {
                    case 'ensure':
                        $op->update(['message'=>'در حال آماده‌سازی آزمون CDN روی '.($side==='iran'?'ایران':'خارج').'…','progress'=>5+10*$p['index']]);
                        $cdn->ensure($servers[$side]);
                        if($method['engine']==='backpack')$bp->ensure($servers[$side]);else $wat->ensure($servers[$side],'wattunnel',$p['settings']['version']);
                        if(++$p['index']===2){$p['phase']='resolve';$p['index']=0;}break;
                    case 'resolve':
                        $cdn->call($servers[$dialer],[...TunnelCdnRemote::request($t,$p['settings']),'action'=>'resolve']);
                        $p['entry']=$cdn->call($servers['iran'],['action'=>'free-port'])['port'];
                        $p['echo']=$cdn->call($servers['foreign'],['action'=>'free-port'])['port'];
                        $p['metrics']=$cdn->call($servers['iran'],['action'=>'free-port'])['port'];
                        $p['phase']='certificate';break;
                    case 'certificate':
                        $op->update(['message'=>'در حال بررسی یا آماده‌سازی گواهی تونل…','progress'=>30]);
                        $p['certificate']=$cdn->prepare($t,$p['settings'],$listener,true);$p['phase']='echo';break;
                    case 'echo':
                        $cdn->call($servers['foreign'],['action'=>'echo-start','id'=>$t->remote_name,'port'=>$p['echo']]);$p['phase']='apply';$p['index']=0;break;
                    case 'apply':
                        $side=[$listener,$dialer][$p['index']];
                        $cdn->call($servers[$side],['action'=>'arm','id'=>$t->remote_name,'engine'=>$t->engine]);
                        if($t->engine==='backpack'&&$t->orientation==='swapped')$cdn->call($servers[$side],['action'=>'arm','id'=>'sb-'.substr(hash('sha256','speedbox-carrier:'.$t->remote_name),0,32),'engine'=>$t->engine]);
                        $settings=$this->settings($p,$t);
                        if($t->engine==='backpack')$bp->call($servers[$side],(new RunTunnelOperation($op->id))->applyRequest($t,$settings,$side));
                        else $wat->call($servers[$side],['action'=>'apply','config'=>$network->config($t,$side,$settings)]);
                        if(++$p['index']===2){$p['phase']='verify';$p['index']=0;}$op->update(['progress'=>55,'message'=>'در انتظار برقراری ارتباط موتور از CDN…']);break;
                    case 'verify':
                        $r=$cdn->status($t,$p['settings'],$dialer);
                        if(empty($r['cdn_verified'])) {
                            $p['attempts']=($p['attempts']??0)+1;
                            if($p['attempts']>=20)throw new \RuntimeException('اتصال موتور به لبهٔ CDN تأیید نشد.');
                            break;
                        }
                        $p['peers']=$r;$p['phase']='probe';break;
                    case 'probe':
                        $protocol=$t->engine==='backpack'?(!empty($p['settings']['accept_udp'])?'both':'tcp'):$p['settings']['protocol'];
                        $p['samples']=$cdn->call($servers['iran'],['action'=>'probe','port'=>$p['entry'],'protocols'=>$protocol==='both'?['tcp','udp']:[$protocol]]);
                        if(empty($p['samples']['data_verified']))throw new \RuntimeException('انتقال داده از مسیر CDN کامل تأیید نشد.');
                        $p['phase']='cleanup';$op->update(['progress'=>85,'message'=>'در حال جمع‌آوری آزمون موقت…']);break;
                    case 'cleanup':
                        $errors=[];
                        foreach($servers as $key=>$server) {
                            try {
                                if($t->engine==='backpack')$bp->call($server,['action'=>'delete','name'=>$t->remote_name]);
                                else $wat->call($server,['action'=>'delete','config'=>$network->config($t,$key,$this->settings($p,$t))]);
                                $cdn->call($server,['action'=>'disarm','id'=>$t->remote_name,'engine'=>$t->engine]);
                                if($t->engine==='backpack'&&$t->orientation==='swapped')$cdn->call($server,['action'=>'disarm','id'=>'sb-'.substr(hash('sha256','speedbox-carrier:'.$t->remote_name),0,32),'engine'=>$t->engine]);
                                $stopped=$cdn->call($server,['action'=>'echo-stop','id'=>$t->remote_name]);if(empty($stopped['stopped']))throw new \RuntimeException('echo remains active');
                                $cdn->call($server,['action'=>'delete','id'=>$t->remote_name]);
                            } catch(Throwable){$errors[]=$key;}
                        }
                        if($errors){$p['cleanup_attempts']=($p['cleanup_attempts']??0)+1;if($p['cleanup_attempts']<3)break;$p['error']='پاک‌سازی آزمون روی یکی از سرورها تأیید نشد.';$p['cleanup_pending']=$errors;}
                        $p['phase']='done';break;
                }
            } catch(Throwable){$p['error']='بررسی CDN کامل نشد؛ دامنه، تنظیم مبدأ، گواهی و آزادبودن پورت تونل را بررسی کنید.';$p['phase']='cleanup';}
            $op->update(['payload'=>$p]);
            if($p['phase']==='done') {
                $ok=empty($p['error'])&&!empty($p['peers']['cdn_verified'])&&!empty($p['samples']['data_verified']);
                $result=['cdn_verified'=>$ok,'peer_addresses'=>$p['peers']['peer_addresses']??[],'protocols'=>$p['samples']['protocols']??[],'certificate'=>$p['certificate']??[],'cleanup_pending'=>$p['cleanup_pending']??[]];
                unset($result['certificate']['cert_file'],$result['certificate']['key_file']);
                $message=$ok?'اتصال موتور و انتقال داده از مسیر CDN تأیید شد.':($p['error']??'آزمون CDN تأیید نشد.');
                $test->update(['status'=>$ok?'completed':'failed','results'=>[$result],'message'=>$message]);$manager->finish($op,$ok?'succeeded':'failed',$message,$result);
            } else self::dispatch($op->id)->delay(now()->addSeconds($p['phase']==='verify'?2:1));
        } finally {$lock->release();}
    }

    private function settings(array $p,ServerTunnel $t): array
    {
        $s=$p['settings'];$s['monitor']=false;$s['restart_hours']=0;$s['telegram_alerts']=false;$s['mappings']=[['public_port'=>$p['entry']??45000,'public_end'=>$p['entry']??45000,'target_port'=>$p['echo']??45001,'target_end'=>$p['echo']??45001]];
        if($t->engine==='wattunnel'){$s['metrics_port']=$t->subnet=($p['metrics']??45002);$s['source_identity']=false;}
        return $s;
    }

    public function failed(?Throwable $error): void
    {
        $op=TunnelOperation::find($this->operationId);if(!$op||!in_array($op->status,['queued','running']))return;
        $p=$op->payload;$p['phase']='cleanup';$p['error']='پردازش آزمون قطع شد؛ پاک‌سازی درخواست شد.';
        $op->update(['payload'=>$p,'deadline'=>now()->addMinutes(10)]);self::dispatch($op->id)->delay(now()->addSeconds(5));
    }
}
