#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"
fail() { printf '[X] %s\n' "$*" >&2; exit 1; }
PHP_PATH="$(command -v "${PHP_BIN:-php}" || true)"
[ -n "$PHP_PATH" ] || fail "PHP was not found."
[ -f vendor/autoload.php ] || fail "Install Composer dependencies before configuring Octane."

# Bootstrap the real application; never source .env as a shell script.
SETTINGS="$("$PHP_PATH" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$settings = config("speedbox.octane");
echo ($settings["enabled"] ? "1" : "0")." ".$settings["port"]." ".$settings["workers"]." ".$settings["max_requests"];
')"
read -r ENABLED PORT WORKERS MAX_REQUESTS <<< "$SETTINGS"
if [ "$ENABLED" != 1 ]; then
    if command -v systemctl >/dev/null 2>&1 && [ "$(id -u)" = 0 ]; then
        systemctl disable --now panel-speedbox-octane.service >/dev/null 2>&1 || true
    fi
    printf '[OK] Octane is disabled in configuration.\n'
    exit 0
fi
[[ "$PORT" =~ ^[0-9]+$ && "$WORKERS" =~ ^[0-9]+$ && "$MAX_REQUESTS" =~ ^[0-9]+$ ]] || fail "Invalid Octane service settings."
(( PORT > 0 && PORT < 65536 && WORKERS > 0 && WORKERS <= 64 && MAX_REQUESTS > 0 )) || fail "Octane port, workers or max requests is out of range."
"$PHP_PATH" -r 'exit(extension_loaded("swoole") || extension_loaded("openswoole") ? 0 : 1);' || fail "Install the Swoole PHP extension before enabling Octane."
command -v systemctl >/dev/null 2>&1 || fail "Octane installation requires systemd."
[ "$(id -u)" = 0 ] || fail "Run Octane service setup as root."

WEB_USER=www
id "$WEB_USER" >/dev/null 2>&1 || WEB_USER=www-data
id "$WEB_USER" >/dev/null 2>&1 || fail "The web user was not found."
chown -R "$WEB_USER":"$(id -gn "$WEB_USER")" storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

cat > /etc/systemd/system/panel-speedbox-octane.service <<EOF
[Unit]
Description=SpeedBox Panel Octane HTTP Server
After=network.target

[Service]
Type=simple
User=${WEB_USER}
WorkingDirectory=${PROJECT_DIR}
ExecStart="${PHP_PATH}" "${PROJECT_DIR}/artisan" octane:start --server=swoole --host=127.0.0.1 --port=${PORT} --workers=${WORKERS} --task-workers=0 --max-requests=${MAX_REQUESTS}
ExecStop="${PHP_PATH}" "${PROJECT_DIR}/artisan" octane:stop --server=swoole
Restart=always
RestartSec=4
KillSignal=SIGTERM
KillMode=mixed
TimeoutStopSec=60
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable panel-speedbox-octane.service
systemctl restart panel-speedbox-octane.service
for attempt in 1 2 3 4 5; do
    HTTP_STATUS="$(curl -sS --max-time 2 -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/up" || true)"
    if systemctl is-active --quiet panel-speedbox-octane.service && [[ "$HTTP_STATUS" = 200 || "$HTTP_STATUS" = 503 ]]; then
        printf '[OK] Octane is running on 127.0.0.1:%s.\n' "$PORT"
        exit 0
    fi
    sleep 1
done
fail "Octane did not start. Check journalctl -u panel-speedbox-octane -n 50."
