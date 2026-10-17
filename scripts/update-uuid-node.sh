#!/usr/bin/env bash
# Called over the panel's existing authenticated SSH connection. Changes only
# the installed node executable; bindings, certificates and config stay intact.
set -Eeuo pipefail
umask 077
test "$(id -u)" = 0
SOURCE="${1:?binary path required}"
EXPECTED="${2:?sha256 required}"
[[ "$SOURCE" =~ ^/tmp/speedbox-uuid-node\.[A-Za-z0-9]+/xboard-node$ ]]
[[ "$EXPECTED" =~ ^[a-f0-9]{64}$ ]]
SERVICE=xboard-node.service
BINARY=/usr/local/bin/xboard-node
test -f "$BINARY"
test ! -L "$BINARY"
systemctl is-active --quiet "$SERVICE"
systemctl show "$SERVICE" -p ExecStart --value | grep -Fq 'path=/usr/local/bin/xboard-node ;'
printf '%s  %s\n' "$EXPECTED" "$SOURCE" | sha256sum -c -
chmod 0755 "$SOURCE"
"$SOURCE" -v | grep -Fq 'panel-speedbox-http-obfs-uuid-v1'
if [[ "$(sha256sum "$BINARY" | awk '{print $1}')" = "$EXPECTED" ]]; then
  printf 'UUID NODE ALREADY UPDATED\n'
  exit 0
fi
BACKUP_DIR=/var/backups/speedbox-uuid-node
install -d -m 0700 "$BACKUP_DIR"
BACKUP="$(mktemp -d "$BACKUP_DIR/update-XXXXXXXX")"
cp -a -- "$BINARY" "$BACKUP/xboard-node.before"
sha256sum "$BINARY" > "$BACKUP/before.sha256"
TEMP="$(mktemp /usr/local/bin/.xboard-node-uuid.XXXXXXXX)"
CHANGED=0
rollback() {
  local status=$?
  trap - ERR
  set +e
  if (( CHANGED )); then
    cp -a -- "$BACKUP/xboard-node.before" "$TEMP"
    mv -f -- "$TEMP" "$BINARY"
    systemctl restart "$SERVICE"
    printf 'UUID node update failed; old binary restored. Backup: %s\n' "$BACKUP" >&2
  fi
  rm -f -- "$TEMP"
  exit "$status"
}
trap rollback ERR
install -m 0755 -- "$SOURCE" "$TEMP"
CHANGED=1
mv -f -- "$TEMP" "$BINARY"
systemctl restart "$SERVICE"
for attempt in 1 2 3; do
  sleep 1
  systemctl is-active --quiet "$SERVICE"
done
PID="$(systemctl show "$SERVICE" -p MainPID --value)"
[[ "$PID" =~ ^[1-9][0-9]*$ ]]
test "$(sha256sum "/proc/$PID/exe" | awk '{print $1}')" = "$EXPECTED"
trap - ERR
printf 'UUID NODE UPDATED\nBackup: %s\n' "$BACKUP"
