#!/usr/bin/env bash
# One-time deployment for the existing aaPanel/PHP-FPM test installation.
set -Eeuo pipefail
umask 077
project=/www/wwwroot/panel-speedbox-test
branch=experiment/independent-wireguard-v1
php_bin=${PHP_BIN:-/www/server/php/83/bin/php}
web_user=${SPEEDBOX_TEST_WEB_USER:-www}
queue_unit=speedbox-panel-test-queue.service
schedule_unit=speedbox-panel-test-schedule.service
timer_unit=speedbox-panel-test-schedule.timer
[[ $(id -u) == 0 ]] || { echo 'Run as root.' >&2; exit 1; }
cd "$project"
[[ $(pwd -P) == "$project" && $(git rev-parse --show-toplevel) == "$project" ]] || { echo 'Unexpected test project path.' >&2; exit 1; }
[[ -x "$php_bin" && -f .env && -f vendor/autoload.php && -f scripts/update-database.php ]]
id "$web_user" >/dev/null
[[ "$web_user" != root ]]
# Repair runtime files left by an earlier root CLI invocation before booting Laravel.
chown -R "$web_user:$(id -gn "$web_user")" storage bootstrap/cache
find storage/framework bootstrap/cache -type d -exec chmod u+rwx {} +
find storage/framework bootstrap/cache -type f -exec chmod u+rw {} +
[[ -z $(git status --porcelain --untracked-files=no) ]] || { echo 'Tracked local changes found.' >&2; exit 1; }
[[ ! -f storage/framework/down ]] || { echo 'Test application is already in maintenance.' >&2; exit 1; }
# Protect custom units if the operator already uses these names for something else.
for unit in "$queue_unit" "$schedule_unit" "$timer_unit"; do
  file=/etc/systemd/system/$unit
  if [[ -e "$file" ]] && ! grep -q '^# Speedbox isolated test deployment$' "$file"; then
    echo "Unmanaged service name collision: $unit" >&2; exit 1
  fi
done
runuser -u "$web_user" -- "$php_bin" <<'PHP'
<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=Illuminate\Support\Facades\DB::connection()->getConfig();
if(!in_array($c['driver'],['mysql','mariadb'],true) || !in_array($c['host'],['127.0.0.1','localhost'],true) || $c['database']!=='speedbox_test') throw new RuntimeException('This deployment is restricted to the confirmed local speedbox_test database.');
if(config('queue.default')!=='database' || (int)config('queue.connections.database.retry_after')<1300 || config('cache.default')!=='file') throw new RuntimeException('Unexpected test queue/cache configuration.');
$qc=config('queue.connections.database.connection')?:config('database.default');
$q=Illuminate\Support\Facades\DB::connection($qc)->getConfig();
foreach(['driver','host','port','database'] as $key)if(($q[$key]??null)!==($c[$key]??null))throw new RuntimeException('Queue database must be the confirmed test database.');
if(!extension_loaded('sodium'))throw new RuntimeException('PHP sodium is required.');
echo "TEST_CONFIGURATION_CONFIRMED\n";
PHP
# Reuse an existing test worker/scheduler if aaPanel/Supervisor already runs one.
read -r existing_queue existing_schedule existing_octane < <(python3 - <<'PY'
import pathlib, os
project='/www/wwwroot/panel-speedbox-test'; queue=False; scheduler=False; octane="none"
for entry in pathlib.Path('/proc').iterdir():
    if not entry.name.isdigit():continue
    try:
        args=(entry/'cmdline').read_bytes().split(b'\0'); text=b' '.join(args).decode(errors='replace')
        if b'artisan' not in b' '.join(args):continue
        if os.path.realpath(entry/'cwd')!=project and project+'/artisan' not in text:continue
        queue|=any(a in [b'queue:work',b'queue:listen',b'horizon'] for a in args)
        scheduler|=b'schedule:work' in args
        if b'octane:start' in args:
            octane=next((a.decode().split('=',1)[1] for a in args if a.startswith(b'--server=')), 'auto')
    except (OSError,PermissionError):pass
print(int(queue),int(scheduler),octane)
PY
)
# An existing cron scheduler also counts; inspect without displaying its contents.
for account in root "$web_user"; do
  if crontab -u "$account" -l 2>/dev/null | grep -vE '^[[:space:]]*(#|$)' | grep -F "$project" | grep -q 'schedule:run'; then existing_schedule=1; fi
done
for file in /etc/cron.d/*; do
  [[ -f "$file" ]] || continue
  if grep -vE '^[[:space:]]*(#|$)' "$file" | grep -F "$project" | grep -q 'schedule:run'; then existing_schedule=1; fi
done
# No pull/switch before the backup; migrations and code move together.
fetch_ref="+refs/heads/$branch:refs/remotes/origin/$branch"
if ! git config --get-all remote.origin.fetch | grep -Fxq "$fetch_ref"; then
  git config --add remote.origin.fetch "$fetch_ref"
fi
git fetch origin "refs/heads/$branch:refs/remotes/origin/$branch"
target=$(git rev-parse "refs/remotes/origin/$branch")
git merge-base --is-ancestor HEAD "$target"
backup=$(mktemp -d /var/tmp/speedbox-test-before-wireguard.XXXXXXXX)
printf '%s\n' "$(git rev-parse HEAD)" > "$backup/commit"
cp -p .env "$backup/env"
runuser -u "$web_user" -- "$php_bin" scripts/update-database.php queue-idle
was_down=0
cleanup() {
  status=$?
  if ((was_down)); then runuser -u "$web_user" -- "$php_bin" artisan up || true; fi
  if ((status)); then printf 'TEST_UPDATE_STOPPED\nBACKUP=%s\n' "$backup" >&2; fi
}
trap cleanup EXIT
runuser -u "$web_user" -- "$php_bin" artisan down
was_down=1
# Stop only the units owned by this script, if this is a repeated test update.
systemctl stop "$timer_unit" "$schedule_unit" "$queue_unit" 2>/dev/null || true
runuser -u "$web_user" -- "$php_bin" scripts/update-database.php queue-idle
"$php_bin" scripts/update-database.php backup "$backup/database.sql"
umask 022
if git show-ref --verify --quiet "refs/heads/$branch"; then git switch "$branch"; else git switch --create "$branch"; fi
git branch --set-upstream-to="origin/$branch" "$branch"
git merge --ff-only "$target"
umask 077
chown -R "$web_user:$(id -gn "$web_user")" storage bootstrap/cache
runuser -u "$web_user" -- "$php_bin" artisan optimize:clear
runuser -u "$web_user" -- "$php_bin" artisan migrate --force
runuser -u "$web_user" -- "$php_bin" scripts/wireguard/repair-test-link.php
runuser -u "$web_user" -- "$php_bin" artisan config:cache
runuser -u "$web_user" -- "$php_bin" artisan route:cache
runuser -u "$web_user" -- "$php_bin" artisan view:cache
runuser -u "$web_user" -- "$php_bin" artisan queue:restart
# Reload this project's already-running Octane, including Supervisor/aaPanel
# processes which have no project-specific systemd unit.
if [[ "$existing_octane" != none ]]; then
  case "$existing_octane" in
    swoole|roadrunner|frankenphp) runuser -u "$web_user" -- "$php_bin" artisan octane:reload --server="$existing_octane" ;;
    auto) runuser -u "$web_user" -- "$php_bin" artisan octane:reload ;;
    *) echo 'Unknown test Octane server.' >&2; exit 1 ;;
  esac
fi
# No Octane installation or web-server configuration changes.
if [[ "$existing_queue" == 0 || -f /etc/systemd/system/$queue_unit ]]; then
  cat > /etc/systemd/system/$queue_unit <<EOF
# Speedbox isolated test deployment
[Unit]
Description=Speedbox test panel queue
After=network-online.target
[Service]
Type=simple
User=$web_user
Group=$(id -gn "$web_user")
WorkingDirectory=$project
ExecStart=$php_bin artisan queue:work database --queue=default --sleep=3 --tries=1 --timeout=1200
Restart=always
RestartSec=5
TimeoutStopSec=1300
UMask=0077
[Install]
WantedBy=multi-user.target
EOF
fi
if [[ "$existing_schedule" == 0 || -f /etc/systemd/system/$timer_unit ]]; then
  cat > /etc/systemd/system/$schedule_unit <<EOF
# Speedbox isolated test deployment
[Unit]
Description=Speedbox test panel scheduled tasks
[Service]
Type=oneshot
User=$web_user
Group=$(id -gn "$web_user")
WorkingDirectory=$project
ExecStart=$php_bin artisan schedule:run --no-interaction
UMask=0077
EOF
  cat > /etc/systemd/system/$timer_unit <<EOF
# Speedbox isolated test deployment
[Unit]
Description=Speedbox test panel scheduler timer
[Timer]
OnBootSec=30
OnUnitInactiveSec=60
AccuracySec=2
Unit=$schedule_unit
[Install]
WantedBy=timers.target
EOF
fi
systemctl daemon-reload
[[ ! -f /etc/systemd/system/$queue_unit ]] || systemctl enable --now "$queue_unit"
[[ ! -f /etc/systemd/system/$timer_unit ]] || systemctl enable --now "$timer_unit"
runuser -u "$web_user" -- "$php_bin" artisan up
was_down=0
sleep 1
[[ ! -f /etc/systemd/system/$queue_unit ]] || systemctl is-active --quiet "$queue_unit"
[[ ! -f /etc/systemd/system/$timer_unit ]] || systemctl is-active --quiet "$timer_unit"
if [[ "$existing_octane" != none ]]; then
  login_status=000
  for attempt in 1 2 3 4 5; do
    login_status=$(curl -sS --max-time 3 -o /dev/null -H 'Host: io.filmdo.ir' -w '%{http_code}' http://127.0.0.1:7011/login || true)
    [[ "$login_status" != 200 ]] || break
    sleep 1
  done
  printf 'TEST_LOGIN_HTTP=%s\n' "$login_status"
  [[ "$login_status" == 200 ]] || { echo 'Test login is not ready; update cannot be reported as successful.' >&2; exit 1; }
fi
printf 'WIREGUARD_TEST_UPDATE_READY\nCOMMIT=%s\nBACKUP=%s\n' "$(git rev-parse HEAD)" "$backup"

# Report the actual subscription transport separately from a successful application update.
runuser -u "$web_user" -- "$php_bin" scripts/wireguard/diagnose-link.php || echo 'LINK_DIAGNOSTIC_INCOMPLETE'
