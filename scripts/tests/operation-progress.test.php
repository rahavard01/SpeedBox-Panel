<?php
/** No application bootstrap, database, SSH or external host. */
namespace App\Models {
    class ManagedServer { public string $ssh_user = 'root'; }
    class ServerDeployment {
        public int $progress = 5;
        public string $log = '';
        public array $updates = [];
        public function update(array $values): void { foreach ($values as $key=>$value) $this->$key=$value; $this->updates[]=$values; }
    }
}
namespace App\Services {
    class SshExecutor {
        public array $calls = [];
        public array $answers = [];
        public function run($server,$command,$timeout,$input=null): string {
            $this->calls[]=json_decode($input,true);
            $answer=array_shift($this->answers);
            if($answer instanceof \Throwable)throw $answer;
            return $answer;
        }
    }
}
namespace {
    $root=dirname(__DIR__,2);
    function base_path($path) { global $root; return $root.'/'.$path; }
    function now() { return new class { public function addSeconds($seconds) { return $seconds; } }; }
    require $root.'/app/Services/RemoteScriptRunner.php';
    require $root.'/app/Services/WarpProgressStream.php';
    require $root.'/app/Jobs/Concerns/ContinuesTunnelOperation.php';
    $checks=0;
    $check=function($value,$message)use(&$checks){if(!$value)throw new \RuntimeException($message);$checks++;};
    $server=new \App\Models\ManagedServer;
    $hash=hash_file('sha256',$root.'/scripts/backpack/host-maintenance.py');
    $ssh=new \App\Services\SshExecutor;
    $ssh->answers=[json_encode(['speedbox_helper_missing'=>$hash]),'{"ok":true,"data":{"verified":true}}'];
    $runner=new \App\Services\RemoteScriptRunner($ssh);
    $answer=$runner->run($server,'scripts/backpack/host-maintenance.py',['action'=>'status']);
    $check($answer['data']['verified']&&count($ssh->calls)===2,'cold cache installs only after explicit miss');
    $check(!isset($ssh->calls[0]['content'])&&hash('sha256',base64_decode($ssh->calls[1]['content'],true))===$hash,'only missing content is transferred and hash-pinned');
    $ssh->calls=[];$ssh->answers=['{"ok":true,"data":[]}'];
    $runner->run($server,'scripts/backpack/host-maintenance.py',['action'=>'status']);
    $check(count($ssh->calls)===1&&!isset($ssh->calls[0]['content']),'warm helper is one SSH command without file upload');
    foreach([new \RuntimeException('SSH disconnected after action'),'{"ok":false,"error":"action failed"}','not-json',json_encode(['speedbox_helper_missing'=>str_repeat('0',64)])] as $failure) {
        $ssh->calls=[];$ssh->answers=[$failure];
        try{$runner->run($server,'scripts/backpack/host-maintenance.py',['action'=>'optimize']);}catch(\Throwable){}
        $check(count($ssh->calls)===1,'a failed or ambiguous action is never replayed');
    }
    $deployment=new \App\Models\ServerDeployment;
    $stream=new \App\Services\WarpProgressStream($deployment);
    $deployment->progress=40;
    $stream->append('err',"SPEEDBOX_WARP_PHASE:prerequisites\n");
    $check($deployment->progress===40&&str_contains($deployment->log,'پیش‌نیاز'),'the first phase label updates without inventing more progress');
    $stream->append('err',"private-license\nSPEEDBOX_WARP_PHA");
    $stream->append('err',"SE:account\n");
    $check($deployment->progress===75,'split phase chunks update progress');
    $stream->append('out',"SPEEDBOX_WARP_PHASE:verify\n");
    $stream->append('err',"SPEEDBOX_WARP_PHASE:service\nSPEEDBOX_WARP_PHASE:unknown\nSPEEDBOX_WARP_PHASE:verify private-license\n");
    $check($deployment->progress===75,'stdout, unknown markers and regressing phases are ignored');
    $stream->append('err',str_repeat('private-license',100)."\nSPEEDBOX_WARP_PHASE:verify\n");
    $check($deployment->progress===95,'oversized private lines do not block later phases');
    $check(!str_contains(json_encode($deployment->updates),'private-license'),'only fixed translated phase messages reach storage');

    class ContinuationFixture {
        use \App\Jobs\Concerns\ContinuesTunnelOperation;
        public static array $dispatched=[];
        public static bool $locked=false;
        public int $operationId=7;
        public static function dispatch($id) {
            if(self::$locked)throw new \RuntimeException('continuation published while locked');
            return new class($id) {
                public function __construct(private int $id){}
                public function delay($delay) { ContinuationFixture::$dispatched[]=[$this->id,$delay]; }
            };
        }
        public function checkpoint($delay) {
            self::$locked=true;$this->deferContinuation=true;
            $this->continueOperation($delay);
            if(self::$dispatched)throw new \RuntimeException('early dispatch');
            self::$locked=false;$this->flushContinuation();
        }
    }
    $fixture=new ContinuationFixture;$fixture->checkpoint(0);
    $check(ContinuationFixture::$dispatched===[[7,0]],'ready checkpoints continue immediately after lock release');
    ContinuationFixture::$dispatched=[];$fixture->checkpoint(5);
    $check(ContinuationFixture::$dispatched===[[7,5]],'health and recovery waits remain intact');
    echo "Operation transport and progress: PASS ($checks checks)\n";
}
