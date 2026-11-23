#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
php_bin=${PHP_BIN:-php}
"$php_bin" -r 'if(!extension_loaded("sodium"))throw new RuntimeException("ext-sodium is required");'
"$php_bin" scripts/tests/wireguard-service.test.php
python3 scripts/tests/wireguard-host.test.py
node --check public/speedbox/wireguard.js
node --check public/speedbox/infrastructure.js
printf 'WIREGUARD_EXPERIMENT_CHECKS_PASS\n'
