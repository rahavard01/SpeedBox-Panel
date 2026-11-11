<?php
/** Exercise the real policy reload service with the existing model fixture. */
namespace App\Services {
    final class SshExecutor {
        public array $calls=[];
        public function run($server,$command,$timeout){$this->calls[]=$command;return 'READY';}
    }
}
namespace {
    require __DIR__.'/tunnel-origin-plan.test.php';
    $source=file_get_contents(dirname(__DIR__,2).'/app/Services/TunnelOriginRemote.php');
    $source=str_replace('final class TunnelOriginRemote','final class TogglePolicyRemote',$source);
    eval(substr($source,5));
    $t->engine='backpack';$t->foreign=\App\Models\ManagedServer::find(15);$t->foreign->ssh_user='root';
    $t->settings=$base;
    $on=\App\Services\TrustedTunnelOrigin::upgrade($t,[...$base,'preserve_client_ip'=>true]);
    $off=\App\Services\TrustedTunnelOrigin::upgrade($t,[...$base,'preserve_client_ip'=>false]);
    $ssh=new \App\Services\SshExecutor;$remote=new \App\Services\TogglePolicyRemote($ssh);
    $remote->refreshNodePolicy($t,$on,$on);check($ssh->calls===[],'ordinary reapply does not restart unchanged node policy');
    $remote->refreshNodePolicy($t,$on,$off);
    check(count($ssh->calls)===2 && $ssh->calls[0]==='systemctl restart xboard-node.service','turning off reloads the committed native node policy');
    check(str_ends_with($ssh->calls[1],' 0'),'native readiness does not require an active broker');
    check(str_contains($ssh->calls[1],'7798'),'native readiness still checks the enabled destination node');
    $ssh->calls=[];$remote->refreshNodePolicy($t,$off,$on);
    check(count($ssh->calls)===2 && str_ends_with($ssh->calls[1],' 1'),'turning on waits for broker readiness');
    $ssh->calls=[];$remote->refreshNodePolicy($t,$off,$off);check($ssh->calls===[],'reapplying an existing native route does not restart the node');
    $stopped=[...$off,'enabled'=>false];$remote->refreshNodePolicy($t,$stopped,$off);
    check(count($ssh->calls)===2,'starting a native route refreshes the shared node fallback policy');
    $ssh->calls=[];$remote->refreshNodePolicy($t,$off,$stopped);
    check(count($ssh->calls)===2,'stopping a native route restores the remaining shared identity policy');
    $ssh->calls=[];
    $newOff=[...$base,'preserve_client_ip'=>false];$remote->refreshNodePolicy($t,$newOff,$off);
    check(count($ssh->calls)===2,'a new native route refreshes any previously strict shared node policy');
    $ssh->calls=[];$remote->refreshNodePolicy($t,$on,$on,true);check(count($ssh->calls)===2,'rollback can force policy restoration');
    $ssh->calls=[];$t->engine='wattunnel';$remote->refreshNodePolicy($t,$on,$off,true);check($ssh->calls===[],'WatTunnel reload behavior is unchanged');
    echo "ORIGIN TOGGLE POLICY: reload, readiness, shared-node preparation and rollback passed\n";
}
