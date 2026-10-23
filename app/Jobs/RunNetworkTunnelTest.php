<?php
namespace App\Jobs;

use App\Models\{ManagedServer,TunnelConnectionTest,TunnelOperation};
use App\Services\{NetworkTunnelRemote,NetworkTunnelTest,TunnelManager};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted,ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunNetworkTunnelTest implements ShouldQueue,ShouldBeEncrypted
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=1;
    public int $timeout=300;
    public function __construct(public readonly int $operationId){$this->onQueue('deployments');}

    public function handle(NetworkTunnelRemote $remote,TunnelManager $manager): void
    {
        $lock=Cache::lock('speedbox-network-test-'.$this->operationId,330);
        if(!$lock->get())return;
        try {
            $op=TunnelOperation::findOrFail($this->operationId);
            if(!in_array($op->status,['queued','running'],true))return;
            $test=TunnelConnectionTest::findOrFail($op->test_id);$s=$op->payload;
            $s+=['phase'=>'ensure','side'=>0,'index'=>0,'attempts'=>0];
            $servers=[ManagedServer::findOrFail($test->foreign_server_id),ManagedServer::findOrFail($test->iran_server_id)];
            if(($op->cancel_requested||$op->deadline->isPast())&&empty($s['cancelled'])){$s['cancelled']=true;$s['phase']='cleanup';$s['side']=0;}
            $op->update(['status'=>'running']);$test->update(['status'=>'running']);
            $row=$s['cases'][$s['index']]??null;
            try {
                if($s['phase']==='ensure') {
                    $op->update(['message'=>'آماده‌سازی تست موقت روی دو سرور…','progress'=>max((int)$op->progress,5)]);
                    $remote->ensureProbe($servers[$s['side']],$s['side']===1?$s['engine']:'gre');
                    if($s['side']===0)$s['side']=1;else{$s['side']=0;$s['phase']='start';}
                } elseif($s['phase']==='start') {
                    if(!isset($s['probe'])) {
                        $n=random_int(0,16383)*4;$s['probe']=['id'=>bin2hex(random_bytes(16)),'token'=>bin2hex(random_bytes(32)),
                            'inner_iran'=>'10.253.'.intdiv($n,256).'.'.($n%256+1),'inner_foreign'=>'10.253.'.intdiv($n,256).'.'.($n%256+2),'gre_key'=>random_int(1,2147483647)];
                    }
                    // Persist ownership before either remote start, including interrupted SSH replies.
                    $op->update(['payload'=>$s,'message'=>'در حال تست '.$row['label'].'…','progress'=>max((int)$op->progress,10+(int)(75*$s['index']/count($s['cases'])))]);
                    $config=[...$row,...$s['probe'],'side'=>$s['side']===0?'foreign':'iran','peer_port'=>$s['peer_port']??null,'reserved_ports'=>$manager->reservedPorts($servers[$s['side']])];
                    $answer=$remote->probe($servers[$s['side']],['action'=>'start','id'=>$s['probe']['id'],'config'=>$config]);
                    if($s['side']===0){$s['peer_port']=$answer['port'];$s['side']=1;}else{$s['side']=0;$s['phase']='measure';}
                } elseif($s['phase']==='measure') {
                    $answer=$remote->probe($servers[1],['action'=>'measure','id'=>$s['probe']['id']]);
                    $row['protocols']=$answer;
                    $usable=array_values(array_filter($answer,fn($v)=>in_array($v['status']??'',['ok','unstable'],true)));
                    $row['selectable']=(bool)$usable;
                    if(!$usable)$row['reason']='سرویس موقت تست پاسخ نداد؛ مسیر و فایروال دو سرور را بررسی کنید.';
                    $row['status']=collect($usable)->contains(fn($v)=>$v['status']==='ok')?'ok':($usable?'unstable':'down');
                    $best=$answer['tcp']??$answer['udp']??[];
                    if(($best['status']??'down')==='down')$best=$answer['udp']??[];
                    foreach(['ok','total','rtt','jitter','download','upload'] as $key)$row[$key]=is_numeric($best[$key]??null)?max(0,(float)$best[$key]):(in_array($key,['download','upload'],true)?null:0);
                    $s['measured']=$row;$s['phase']='cleanup';$s['side']=0;
                } elseif($s['phase']==='cleanup') {
                    if(isset($s['probe']))$remote->probe($servers[$s['side']],['action'=>'stop','id'=>$s['probe']['id'],'engine'=>$s['engine']]);
                    $s['attempts']=0;
                    if($s['side']===0)$s['side']=1;
                    else {
                        unset($s['probe'],$s['peer_port']);$s['side']=0;
                        if(!empty($s['cancelled'])||!empty($s['fatal']))$s['phase']='done';
                        else {
                            $rows=$test->results??[];$rows[]=$s['measured']??[...$row,'status'=>'down','reason'=>'این مدل روی مسیر انتخاب‌شده برقرار نشد.','error'=>$s['case_error']??null];
                            $test->update(['results'=>$rows]);unset($s['measured'],$s['case_error']);$s['index']++;
                            $s['phase']=$s['index']<count($s['cases'])?'start':'done';
                        }
                    }
                }
            } catch(Throwable $error) {
                if($s['phase']==='cleanup') {
                    $s['attempts']++;
                    if($s['attempts']>=3){$s['fatal']='پاک‌سازی تست روی یکی از سرورها تأیید نشد؛ رابط موقت برای جلوگیری از حذف اتصال دیگر حفظ شد.';$s['cleanup_error']=$error->getMessage();$s['cleanup_failures'][$s['side']]=$error->getMessage();$s['attempts']=0;if($s['side']===0)$s['side']=1;else $s['phase']='done';}
                } else {
                    if($s['phase']==='ensure')$s['fatal']='آماده‌سازی تست روی دو سرور کامل نشد.';
                    $s['case_error']=$error->getMessage();$s['phase']='cleanup';$s['side']=0;
                }
            }
            $weights=['ensure'=>2,'start'=>10,'measure'=>45,'cleanup'=>80,'done'=>100];
            $count=max(1,count($s['cases']??[]));
            $percent=5+(int)(90*(($s['index']??0)+($weights[$s['phase']]??0)/100)/$count);
            $op->update(['payload'=>$s,'progress'=>min(99,max((int)$op->progress,$percent))]);
            if($s['phase']==='done') {
                $status=!empty($s['fatal'])?'failed':(!empty($s['cancelled'])?'cancelled':'completed');
                $rows=$test->results??[];$eligible=array_values(array_filter($rows,fn($r)=>($r['selectable']??false)&&$r['status']==='ok'));
                usort($eligible,fn($a,$b)=>($a['rtt']?:PHP_INT_MAX)<=>($b['rtt']?:PHP_INT_MAX));$best=$eligible[0]['id']??null;
                $rows=array_map(fn($r)=>[...$r,'recommended'=>$r['id']===$best],$rows);
                $message=$s['fatal']??(!empty($s['cancelled'])?'تست متوقف و تنظیمات موقت جمع‌آوری شد.':'تست تمام شد؛ نتیجهٔ قابل استفاده را انتخاب کنید.');
                $test->update(['status'=>$status,'message'=>$message,'results'=>$rows]);
                $manager->finish($op,$status==='completed'?'succeeded':$status,$message,['cleanup_error'=>$s['cleanup_error']??null]);
            } else self::dispatch($op->id)->delay(now()->addSeconds(1));
        } finally {$lock->release();}
    }

    public function failed(?Throwable $error): void
    {
        $op=TunnelOperation::find($this->operationId);
        if(!$op||!in_array($op->status,['queued','running'],true))return;
        $s=$op->payload;$s['phase']='cleanup';$s['side']=0;$s['fatal']='اجرای تست قطع شد؛ پاک‌سازی درخواست شد.';
        $op->update(['payload'=>$s]);self::dispatch($op->id)->delay(now()->addSeconds(5));
    }
}
