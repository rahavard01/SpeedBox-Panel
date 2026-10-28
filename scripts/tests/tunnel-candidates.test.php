<?php
namespace Illuminate\Validation {class ValidationException extends \RuntimeException {}}
namespace App\Support {class NetworkTunnelRules {const VERSIONS=['2.11.5','3.0.0'];static function fail($key,$message){throw new \Illuminate\Validation\ValidationException($message);}}}
namespace App\Models {class ManagedServer {public string $name='fixture';function __construct(public int $id,public string $host,public int $machine_id){}}}
namespace {
 require dirname(__DIR__,2).'/app/Services/NetworkTunnelManager.php';require dirname(__DIR__,2).'/app/Services/NetworkTunnelTest.php';
 $rows=(new App\Services\NetworkTunnelTest)->candidates('gost',new App\Models\ManagedServer(1,'192.0.2.1',1),new App\Models\ManagedServer(15,'192.0.2.2',15),new App\Services\NetworkTunnelManager);
 if(array_column($rows,'transport')!==['tcp','tcp','grpc']||array_column($rows,'version')!==['2.11.5','3.0.0','3.0.0'])throw new RuntimeException('Missing GOST version/transport candidate');
 if(array_column($rows,'id')!==['gost:2.11.5:ipv4','gost:3.0.0:ipv4','gost:3.0.0:ipv4:grpc'])throw new RuntimeException('Existing candidate receipt IDs changed');
 if(array_filter($rows,fn($r)=>$r['selectable']))throw new RuntimeException('Untested candidate selectable');
 echo "TUNNEL CANDIDATES: both pinned versions and matching gRPC receipt passed\n";
}
