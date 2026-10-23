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
            if(empty($test->results))$test->update(['results'=>$s['cases']]);
            $beforePhase=$s['phase'];$beforeSide=$s['side'];$beforeIndex=$s['index'];
            $s['phase_started_at']??=microtime(true);
            try {
                if($s['phase']==='ensure') {
                    $op->update(['message'=>'آماده‌سازی تست موقت روی دو سرور…','progress'=>max((int)$op->progress,5)]);
                    $remote->ensureProbe($servers[$s['side']],($s['engine']==='wattunnel'||$s['side']===1)?$s['engine']:'gre');
                    if($s['side']===0)$s['side']=1;else{$s['side']=0;$s['phase']='start';}
                } elseif($s['phase']==='start') {
                    $this->publishRow($test,[...$row,'status'=>'testing']);
                    if(!isset($s['probe'])) {
                        $n=random_int(0,16383)*4;$s['probe']=['id'=>bin2hex(random_bytes(16)),'token'=>bin2hex(random_bytes(32)),
                            'inner_iran'=>'10.253.'.intdiv($n,256).'.'.($n%256+1),'inner_foreign'=>'10.253.'.intdiv($n,256).'.'.($n%256+2),'backend_port'=>random_int(33000,60000),'gre_key'=>random_int(1,2147483647)];
                    }
                    if($s['engine']==='wattunnel'&&empty($s['backend_reserved_checked'])) {
                        $blocked=[...$manager->reservedPorts($servers[0]),...$manager->reservedPorts($servers[1])];
                        for($attempt=0;in_array($s['probe']['backend_port'],$blocked,true)&&$attempt<200;$attempt++)$s['probe']['backend_port']=random_int(33000,60000);
                        if(in_array($s['probe']['backend_port'],$blocked,true))throw new \RuntimeException('پورت آزاد برای سرویس موقت تست پیدا نشد.');
                        $s['backend_reserved_checked']=true;
                    }
                    // Persist ownership before either remote start, including interrupted SSH replies.
                    $op->update(['payload'=>$s,'message'=>'در حال تست '.$row['label'].'…','progress'=>max((int)$op->progress,10+(int)(75*$s['index']/count($s['cases'])))]);
                    $startSide=($row['engine']==='wattunnel'&&!empty($row['reverse']))?1-$s['side']:$s['side'];
                    $config=[...$row,...$s['probe'],'side'=>$startSide===0?'foreign':'iran','peer_port'=>$s['peer_port']??null,'reserved_ports'=>$manager->reservedPorts($servers[$startSide])];
                    $answer=$remote->probe($servers[$startSide],['action'=>'start','id'=>$s['probe']['id'],'config'=>$config]);
                    if($s['side']===0){$s['peer_port']=$answer['peer_port']??$answer['port'];$s['side']=1;}else{$s['side']=0;$s['phase']='measure';}
                } elseif($s['phase']==='measure') {
                    $remote->probe($servers[1],['action'=>'measure-start','id'=>$s['probe']['id']]);
                    $s['phase']='measuring';
                } elseif($s['phase']==='measuring') {
                    $answer=$remote->probe($servers[1],['action'=>'measure-state','id'=>$s['probe']['id']]);
                    if(($answer['state']??'')==='failed')throw new \RuntimeException($answer['error']??'measurement failed');
                    $complete=($answer['state']??'')==='completed';
                    $measured=$this->measuredRow($row,$answer['protocols']??[],$complete);
                    $this->publishRow($test,$measured);
                    $s['sample_progress']=$this->sampleProgress($answer['protocols']??[]);
                    if($complete){$s['measured']=$measured;$s['phase']='cleanup';$s['side']=0;}
                } elseif($s['phase']==='cleanup') {
                    if(isset($s['probe']))$remote->probe($servers[$s['side']],['action'=>'stop','id'=>$s['probe']['id'],'engine'=>$s['engine']]);
                    $s['attempts']=0;
                    if($s['side']===0)$s['side']=1;
                    else {
                        unset($s['probe'],$s['peer_port'],$s['backend_reserved_checked']);$s['side']=0;
                        if(!empty($s['cancelled'])||!empty($s['fatal']))$s['phase']='done';
                        else {
                            $this->publishRow($test,$s['measured']??[...$row,'status'=>'down','selectable'=>false,'reason'=>'این مدل روی مسیر انتخاب‌شده برقرار نشد.','error'=>$s['case_error']??null]);unset($s['measured'],$s['case_error'],$s['sample_progress']);$s['index']++;
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
            if($s['phase']!==$beforePhase||$s['side']!==$beforeSide||$s['index']!==$beforeIndex){
                $s['timings'][]=['phase'=>$beforePhase,'side'=>$beforeSide,'case'=>$beforeIndex,'seconds'=>round(microtime(true)-$s['phase_started_at'],3)];
                $s['timings']=array_slice($s['timings'],-80);$s['phase_started_at']=microtime(true);
            }
            $weights=['ensure'=>2,'start'=>10,'measure'=>20,'measuring'=>20+60*($s['sample_progress']??0),'cleanup'=>85,'done'=>100];
            $count=max(1,count($s['cases']??[]));
            $percent=5+(int)(90*(($s['index']??0)+($weights[$s['phase']]??0)/100)/$count);
            $op->update(['payload'=>$s,'progress'=>min(99,max((int)$op->progress,$percent))]);
            if($s['phase']==='done') {
                $status=!empty($s['fatal'])?'failed':(!empty($s['cancelled'])?'cancelled':'completed');
                $rows=array_map(fn($r)=>($r['status']??'')==='testing'?[...$r,'status'=>'skipped','selectable'=>false]:$r,$test->results??[]);$eligible=array_values(array_filter($rows,fn($r)=>($r['selectable']??false)&&$r['status']==='ok'));
                usort($eligible,fn($a,$b)=>($a['rtt']?:PHP_INT_MAX)<=>($b['rtt']?:PHP_INT_MAX));$best=$eligible[0]['id']??null;
                $rows=array_map(fn($r)=>[...$r,'recommended'=>$r['id']===$best],$rows);
                $message=$s['fatal']??(!empty($s['cancelled'])?'تست متوقف و تنظیمات موقت جمع‌آوری شد.':'تست تمام شد؛ نتیجهٔ قابل استفاده را انتخاب کنید.');
                $test->update(['status'=>$status,'message'=>$message,'results'=>$rows]);
                $manager->finish($op,$status==='completed'?'succeeded':$status,$message,['cleanup_error'=>$s['cleanup_error']??null,'timings'=>$s['timings']??[]]);
            } else self::dispatch($op->id)->delay(now()->addSeconds(1));
        } finally {$lock->release();}
    }

    private function publishRow(TunnelConnectionTest $test,array $row): void
    {
        $rows=$test->results??[];$found=false;
        foreach($rows as &$existing)if($existing['id']===$row['id']){$existing=$row;$found=true;break;}
        unset($existing);if(!$found)$rows[]=$row;
        $test->update(['results'=>$rows]);
    }

    private function measuredRow(array $row,array $protocols,bool $complete): array
    {
        $row['protocols']=$protocols;
        $usable=array_filter($protocols,fn($p)=>in_array($p['status']??'',['ok','unstable'],true));
        $row['selectable']=$complete&&(bool)$usable;
        $row['status']=$complete?(collect($usable)->contains(fn($p)=>$p['status']==='ok')?'ok':($usable?'unstable':'down')):'testing';
        if($complete&&!$usable)$row['reason']='سرویس موقت تست پاسخ نداد؛ مسیر و فایروال دو سرور را بررسی کنید.';
        $best=$protocols['tcp']??$protocols['udp']??[];
        if(($best['status']??'')==='down')$best=$protocols['udp']??$best;
        foreach(['ok','total','rtt','jitter','download','upload'] as $key)$row[$key]=is_numeric($best[$key]??null)?max(0,(float)$best[$key]):null;
        return $row;
    }

    private function sampleProgress(array $protocols): float
    {
        $done=0;
        foreach($protocols as $p){
            if(in_array($p['status']??'',['ok','unstable','down'],true)){$done++;continue;}
            $done+=match($p['phase']??'echo'){'download'=>.7,'upload'=>.85,default=>.7*min(1,($p['tried']??0)/max(1,$p['total']??20))};
        }
        return min(1,$done/2);
    }

    public function failed(?Throwable $error): void
    {
        $op=TunnelOperation::find($this->operationId);
        if(!$op||!in_array($op->status,['queued','running'],true))return;
        $s=$op->payload;$s['phase']='cleanup';$s['side']=0;$s['fatal']='اجرای تست قطع شد؛ پاک‌سازی درخواست شد.';
        $op->update(['payload'=>$s]);self::dispatch($op->id)->delay(now()->addSeconds(5));
    }
}
