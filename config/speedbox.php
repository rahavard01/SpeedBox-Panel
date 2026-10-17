<?php

return [
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('SPEEDBOX_TRUSTED_PROXIES', '127.0.0.1,::1'))))),
    'api_rate_limit' => max(1, (int) env('SPEEDBOX_API_RATE_LIMIT', 60)),
    'node_rate_limit' => max(1, (int) env('SPEEDBOX_NODE_RATE_LIMIT', 1200)),
    'node_ip_rate_limit' => max(1, (int) env('SPEEDBOX_NODE_IP_RATE_LIMIT', 12000)),
    'webhook_rate_limit' => max(1, (int) env('SPEEDBOX_WEBHOOK_RATE_LIMIT', 600)),
    'server_token' => env('SPEEDBOX_SERVER_TOKEN', ''),
    'agent_repository' => env('SPEEDBOX_AGENT_REPOSITORY', 'rahavard01/Panel-SpeedBox'),
    'agent_release' => env('SPEEDBOX_AGENT_RELEASE', 'latest'),
    'deploy_timeout' => (int) env('SPEEDBOX_DEPLOY_TIMEOUT', 900),
    'strict_host_key_checking' => (bool) env('SPEEDBOX_STRICT_HOST_KEY_CHECKING', true),
    'octane' => [
        'enabled' => (bool) env('SPEEDBOX_OCTANE_ENABLED', true),
        'port' => (int) env('OCTANE_PORT', 7010),
        'workers' => (int) env('OCTANE_WORKERS', 2),
        'max_requests' => (int) env('OCTANE_MAX_REQUESTS', 500),
    ],
];

