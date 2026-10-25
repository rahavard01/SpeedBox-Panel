<?php
namespace App\Services;

use App\Models\{ServerTunnel,TunnelOperation};
use Illuminate\Support\Facades\{DB,Log};
use Throwable;

/** Deletion is independent per endpoint and never rolls a successful removal back. */
class TunnelRemoval
{
    public function run(TunnelOperation $operation, BackpackRemote|NetworkTunnelRemote $remote, TunnelManager $manager): void
    {
        $tunnel=ServerTunnel::with(['iran','foreign'])->findOrFail($operation->tunnel_id);
        $operation->update(['status'=>'running','message'=>'در حال حذف تونل…']);
        $results=$operation->result['cleanup']??[];
        foreach (['iran','foreign'] as $side) {
            if (isset($results[$side])) continue; // A completed attempt is not replayed after recovery.
            $server=$tunnel->$side;
            try {
                $request=$tunnel->engine==='backpack'
                    ? ['action'=>'delete','name'=>$tunnel->remote_name]
                    : ['action'=>'delete','config'=>app(NetworkTunnelManager::class)->config($tunnel,$side)];
                if (!$server) throw new \RuntimeException('missing_server');
                $answer=$remote->call($server,$request);
                if (empty($answer['deleted'])) throw new \RuntimeException('unconfirmed_cleanup');
                $results[$side]=['cleaned'=>true,'checked_at'=>time()];
            } catch (Throwable $error) {
                $results[$side]=['cleaned'=>false,'checked_at'=>time(),'reason'=>$error->getCode()===1001?'unreachable':'cleanup_not_confirmed'];
            }
            $operation->update(['result'=>['cleanup'=>$results],'progress'=>$side==='iran'?45:90]);
        }
        $incomplete=array_keys(array_filter($results,fn($r)=>!$r['cleaned']));
        $names=['iran'=>'ایران','foreign'=>'خارج'];
        $message='تونل حذف شد.'.($incomplete?' پاک‌سازی سرور '.implode(' و ',array_map(fn($s)=>$names[$s],$incomplete)).' تأیید نشد.':'');
        DB::transaction(function () use ($tunnel,$operation,$manager,$results,$message) {
            $tunnel->update(['status'=>'deleted','message'=>$message]);
            $manager->finish($operation,'succeeded',$message,['cleanup'=>$results]);
        });
        // Remains available after a retired server and its dependent history are removed.
        try { Log::notice('SpeedBox tunnel removal',['operation_id'=>$operation->id,'tunnel_id'=>$tunnel->id,'name'=>$tunnel->name,'cleanup'=>$results]); } catch (Throwable) {}
    }
}
