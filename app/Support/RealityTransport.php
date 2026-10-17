<?php

namespace App\Support;

final class RealityTransport
{
    public const ERROR = 'Reality فقط با انتقال TCP/RAW، XHTTP یا gRPC قابل استفاده است؛ برای WS از TLS استفاده کنید.';

    public static function isSupported(array $settings): bool
    {
        // Some protocols store TLS as an object instead of a numeric mode.
        $tls = $settings['tls'] ?? 0;
        return !is_scalar($tls)
            || (int) $tls !== 2
            || in_array($settings['network'] ?? 'tcp', ['tcp', 'raw', 'xhttp', 'grpc'], true);
    }
}
