#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Execute a private snapshot: git fast-forward may replace update.sh on disk.
if [[ "${SPEEDBOX_UPDATE_SNAPSHOT:-}" != 1 ]]; then
  task_project="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
  task_snapshot="$(mktemp /tmp/speedbox-update.XXXXXXXX.sh)"
  cp -- "${BASH_SOURCE[0]}" "$task_snapshot"
  exec env SPEEDBOX_UPDATE_SNAPSHOT=1 SPEEDBOX_UPDATE_PROJECT="$task_project" bash "$task_snapshot" "$@"
fi

AGENT_PREVIEW=''
trap '[[ -z "$AGENT_PREVIEW" ]] || rm -rf -- "$AGENT_PREVIEW"; rm -f -- "${BASH_SOURCE[0]}"' EXIT

# Same terminal-aware branding used by the installer.
speedbox_banner() {
  local subtitle="$1" columns="${COLUMNS:-}" mode="plain" line col character weight
  local red green blue color_index banner_width=0 band
  local -a banner_rows=(
    '#######1######1 #######1#######1######1 ######1  ######1 ##1  ##1'
    '##233334##233##1##233334##233334##233##1##233##1##2333##16##1##24'
    '#######1######24#####1  #####1  ##5  ##5######24##5   ##5 6###24 '
    '63333##5##23334 ##2334  ##2334  ##5  ##5##233##1##5   ##5 ##2##1 '
    '#######5##5     #######1#######1######24######246######24##24 ##1'
    '63333334634     63333334633333346333334 6333334  6333334 634  634'
  )
  local -a compact_rows=(
    '#####  ####   #####  #####  ####   ####    ###   #   #'
    '#      #   #  #      #      #   #  #   #  #   #  #   #'
    '#      #   #  #      #      #   #  #   #  #   #   # # '
    '#####  ####   ####   ####   #   #  ####   #   #    #  '
    '    #  #      #      #      #   #  #   #  #   #   # # '
    '    #  #      #      #      #   #  #   #  #   #  #   #'
    '#####  #      #####  #####  ####   ####    ###   #   #'
  )
  if [[ ! "$columns" =~ ^[0-9]+$ ]] || (( columns <= 0 )); then
    columns=80
    if [[ -t 1 ]] && command -v stty >/dev/null 2>&1; then
      read -r _ columns < <(stty size 2>/dev/null || true) || true
      [[ "$columns" =~ ^[0-9]+$ ]] && (( columns > 0 )) || columns=80
    fi
  fi
  if (( columns < 65 )); then banner_rows=("${compact_rows[@]}"); fi
  if (( columns < 55 )); then banner_rows=("SPEEDBOX"); fi
  for line in "${banner_rows[@]}"; do
    (( ${#line} <= banner_width )) || banner_width=${#line}
  done
  (( ${#subtitle} <= banner_width )) || banner_width=${#subtitle}
  if [[ -t 1 && "${TERM:-dumb}" != "dumb" && -z "${NO_COLOR+x}" ]]; then
    case "${COLORTERM:-} ${TERM:-}" in
      *truecolor*|*24bit*|*direct*) mode="rgb" ;;
      *256color*) mode="256" ;;
      *) mode="ansi" ;;
    esac
  fi
  # The final row is a gradient separator beneath the subtitle.
  local separator=''
  for ((col=0; col<banner_width; col++)); do separator+='-'; done
  printf '\n'
  banner_rows+=("" "$subtitle" "$separator")
  local row=0 total=${#banner_rows[@]}
  for line in "${banner_rows[@]}"; do
    printf ' '
    for ((col=0; col<${#line}; col++)); do
      character="${line:col:1}"; weight=1
      if (( row == total - 1 )); then character='─'; weight=1
      elif (( row < total - 3 )); then
        case "$character" in
          '#') character='█' ;;
          '1') character='╗'; weight=2 ;;
          '2') character='╔'; weight=2 ;;
          '3') character='═'; weight=2 ;;
          '4') character='╝'; weight=2 ;;
          '5') character='║'; weight=2 ;;
          '6') character='╚'; weight=2 ;;
        esac
      fi
      # Clear both bold and faint before each cell; bold alone does not clear faint.
      case "$mode" in
        rgb)
          red=$((192 - 144 * col / (banner_width - 1)))
          green=$((178 + 52 * col / (banner_width - 1)))
          blue=$((255 - 20 * col / (banner_width - 1)))
          printf '\033[22;%d;38;2;%d;%d;%dm%s' "$weight" "$red" "$green" "$blue" "$character" ;;
        256)
          band=$((col * 5 / banner_width))
          case "$band" in 0) color_index=183;; 1) color_index=147;; 2) color_index=117;; 3) color_index=87;; *) color_index=51;; esac
          printf '\033[22;%d;38;5;%dm%s' "$weight" "$color_index" "$character" ;;
        ansi)
          color_index=95
          (( col < banner_width / 2 )) || color_index=96
          printf '\033[22;%d;%dm%s' "$weight" "$color_index" "$character" ;;
        *) printf '%s' "$character" ;;
      esac
    done
    [[ "$mode" == plain ]] || printf '\033[0m'
    printf '\n'
    row=$((row + 1))
  done
  printf '\n'
}

speedbox_banner "PANEL UPDATER"

fail() { printf '[X] %s\n' "$*" >&2; exit 1; }
step() { printf '[*] %s\n' "$*"; }
for command in git realpath flock tar gzip curl systemctl runuser awk sha256sum; do
  command -v "$command" >/dev/null || fail "Missing command: $command"
done
[[ "$(id -u)" = 0 ]] || fail 'Run this server updater as root.'
cd "${SPEEDBOX_UPDATE_PROJECT:?Missing project path}"
TOP="$(git rev-parse --show-toplevel)"
[[ "$(pwd -P)" = "$(realpath "$TOP")" ]] || fail 'Run from the project root.'
[[ -f .env && -f vendor/autoload.php ]] || fail 'Existing installation required.'
[[ ! -f storage/framework/down ]] || fail 'Application is already in maintenance; stopped.'
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || fail 'Tracked local changes found; stopped.'
if [[ -e public/storage && ! -L public/storage ]]; then
  fail 'public/storage contains real data; migrate it safely to storage/app/public before updating.'
fi
if [[ -L public/storage && "$(realpath -m public/storage)" != "$(realpath -m storage/app/public)" ]]; then
  fail 'public/storage points somewhere unexpected; resolve it before updating.'
fi
exec 9>"$(git rev-parse --git-path speedbox-update.lock)"
flock -n 9 || fail 'Another update is already running.'

PHP="$(command -v "${PHP_BIN:-php}" || true)"
[[ -n "$PHP" && -x "$PHP" ]] || fail 'Select the website PHP using PHP_BIN or PATH.'
export PATH="$(dirname "$PHP"):$PATH"
PHP="$(realpath "$PHP")"
"$PHP" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || fail 'PHP 8.2 or newer required.'
OLD="$(git rev-parse HEAD)"
BRANCH="$(git branch --show-current)"
[[ -n "$BRANCH" ]] || fail 'Detached HEAD is not supported.'
REMOTE="$(git config "branch.$BRANCH.remote")"
MERGE_REF="$(git config "branch.$BRANCH.merge")"
[[ -n "$REMOTE" && "$REMOTE" != . && "$MERGE_REF" = refs/heads/* ]] || fail 'Set an upstream branch first.'
git fetch "$REMOTE" "$MERGE_REF"
UPSTREAM="$(git rev-parse FETCH_HEAD)"
TARGET="${SPEEDBOX_UPDATE_COMMIT:-$UPSTREAM}"
[[ "$TARGET" =~ ^[0-9a-f]{40}$ ]] || fail 'Target must be a full commit SHA.'
git merge-base --is-ancestor "$OLD" "$TARGET" || fail 'Update is not a fast-forward.'
git merge-base --is-ancestor "$TARGET" "$UPSTREAM" || fail 'Target is not on the fetched upstream.'
if [[ "$OLD" = "$TARGET" ]]; then
  printf 'Already updated: %s\n' "$TARGET"
  rm -f -- "${BASH_SOURCE[0]}"
  exit 0
fi

CHANGES_MIGRATIONS=0
git diff --quiet "$OLD" "$TARGET" -- database/migrations || CHANGES_MIGRATIONS=1
if (( CHANGES_MIGRATIONS )) && [[ "${SPEEDBOX_ALLOW_MIGRATIONS:-}" != 1 ]]; then
  fail 'Migration files changed. Review them, then explicitly set SPEEDBOX_ALLOW_MIGRATIONS=1.'
fi
DEPENDENCIES=0
git diff --quiet "$OLD" "$TARGET" -- composer.json composer.lock || DEPENDENCIES=1
AGENT=0
git diff --quiet "$OLD" "$TARGET" -- agent agent-dist scripts/build-agent.sh scripts/build-agent-from-source.sh scripts/agent-artifacts.php scripts/package-agent.py || AGENT=1
COMPOSER=''
if (( DEPENDENCIES )); then
  COMPOSER="$(command -v "${COMPOSER_BIN:-composer}" || true)"
  [[ -n "$COMPOSER" ]] || fail 'Composer PHP script/phar not found.'
  "$PHP" "$COMPOSER" --version >/dev/null || fail 'COMPOSER_BIN must point to a Composer PHP script/phar.'
fi

resolve_service() {
  local role="$1" explicit="$2" unit directory command_line
  local -a matches=() candidates=()
  if [[ -n "$explicit" ]]; then
    [[ "$explicit" =~ ^[A-Za-z0-9@_.-]+(\.service)?$ ]] || return 1
    candidates=("${explicit%.service}.service")
  else
    mapfile -t candidates < <(systemctl list-unit-files --type=service --no-legend --no-pager | awk '{print $1}')
  fi
  for unit in "${candidates[@]}"; do
    directory="$(systemctl show "$unit" --property=WorkingDirectory --value 2>/dev/null)" || continue
    [[ -n "$directory" && "$(realpath -m "$directory")" = "$TOP" ]] || continue
    command_line="$(systemctl show "$unit" --property=ExecStart --value 2>/dev/null)" || continue
    [[ "$command_line" = *artisan* && "$command_line" = *"$role"* ]] || continue
    matches+=("$unit")
  done
  [[ "${#matches[@]}" = 1 ]] || return 1
  printf '%s\n' "${matches[0]}"
}
HTTP_SERVICE="$(resolve_service 'octane:start' "${SPEEDBOX_OCTANE_SERVICE:-}")" || fail 'Cannot uniquely identify this project Octane service; set SPEEDBOX_OCTANE_SERVICE.'
QUEUE_SERVICE="$(resolve_service 'queue:work' "${SPEEDBOX_QUEUE_SERVICE:-}")" || fail 'Cannot uniquely identify this project queue service; set SPEEDBOX_QUEUE_SERVICE.'
systemctl is-active --quiet "$HTTP_SERVICE" || fail 'Octane service is not active.'
systemctl is-active --quiet "$QUEUE_SERVICE" || fail 'Queue service is not active.'
WEB_USER="$(systemctl show "$HTTP_SERVICE" --property=User --value)"
[[ -n "$WEB_USER" && "$WEB_USER" != root ]] || fail 'A non-root HTTP service user is required.'
id "$WEB_USER" >/dev/null
run_php() { runuser -u "$WEB_USER" -- "$PHP" "$@"; }
read -r ENABLED PORT HEALTH_HOST < <(run_php scripts/update-database.php inspect)
[[ "$ENABLED" = 1 && "$PORT" =~ ^[0-9]+$ ]] || fail 'Existing Octane configuration is required.'
(( PORT > 0 && PORT < 65536 )) || fail 'Invalid Octane port.'

# Verify and unpack the TARGET release before pausing queues or entering maintenance.
if (( AGENT )); then
  step 'Checking target prebuilt node release before maintenance'
  AGENT_PREVIEW="$(mktemp -d /tmp/speedbox-agent-preview-XXXXXXXX)"
  git archive "$TARGET" agent agent-dist scripts/build-agent.sh scripts/build-agent-from-source.sh scripts/agent-artifacts.php scripts/package-agent.py | tar -x -C "$AGENT_PREVIEW"
  PHP_BIN="$PHP" bash "$AGENT_PREVIEW/scripts/build-agent.sh"
  [[ "$(realpath -m "$TOP/storage/app/agent")" = "$TOP/storage/app/agent" ]] || fail 'Agent output must not be a symlink.'
fi

BACKUP_BASE="${SPEEDBOX_BACKUP_DIR:-$(dirname "$TOP")/speedbox-backups}"
mkdir -p -- "$BACKUP_BASE"
BACKUP_BASE="$(realpath "$BACKUP_BASE")"
[[ "$BACKUP_BASE/" != "$TOP/"* ]] || fail 'Backup directory must be outside the project.'
BACKUP="$(mktemp -d "$BACKUP_BASE/update-$(date +%Y%m%d-%H%M%S)-XXXXXXXX")"
git archive "$OLD" | gzip > "$BACKUP/source.tar.gz"
gzip -t "$BACKUP/source.tar.gz"
cp -- .env "$BACKUP/environment.before"
printf '%s\n' "$OLD" > "$BACKUP/old-commit"
printf '%s\n' "$TARGET" > "$BACKUP/target-commit"
if (( DEPENDENCIES )); then
  tar -czf "$BACKUP/vendor.tar.gz" vendor
  gzip -t "$BACKUP/vendor.tar.gz"
fi

if (( AGENT )); then
  mkdir "$BACKUP/agent-before"
  for name in xboard-node-linux-amd64 xbctl-linux-amd64 xboard-node-linux-arm64 xbctl-linux-arm64; do
    path="$TOP/storage/app/agent/$name"
    [[ ! -L "$path" && ( ! -e "$path" || -f "$path" ) ]] || fail 'Unsafe existing agent binary.'
    if [[ -f "$path" ]]; then cp -a -- "$path" "$BACKUP/agent-before/$name"; fi
  done
fi

PAUSED=0 DOWN=0 CODE_CHANGED=0 DB_STARTED=0 SUCCESS=0 ARTIFACTS_CHANGED=0
cleanup() {
  local status=$?
  trap - EXIT
  set +e
  if (( ! SUCCESS )); then
    # Never advertise a mixed code/schema installation as healthy.
    if (( DOWN )); then
      cp -- "$BACKUP/maintenance.down" storage/framework/down
      chown "$WEB_USER" storage/framework/down
      run_php artisan down >/dev/null 2>&1
    fi
    if (( CODE_CHANGED && ! DB_STARTED )); then
      if git reset --keep "$OLD"; then
        if (( DEPENDENCIES )); then
          mv vendor "$BACKUP/vendor-failed"
          tar -xzf "$BACKUP/vendor.tar.gz" -C "$TOP"
        fi
        ARTIFACTS_RESTORED=1
        if (( ARTIFACTS_CHANGED )); then
          for name in xboard-node-linux-amd64 xbctl-linux-amd64 xboard-node-linux-arm64 xbctl-linux-arm64; do
            if [[ -f "$BACKUP/agent-before/$name" ]]; then
              if cp -a -- "$BACKUP/agent-before/$name" "$TOP/storage/app/agent/.restore-$name"; then
                mv -f -- "$TOP/storage/app/agent/.restore-$name" "$TOP/storage/app/agent/$name" || ARTIFACTS_RESTORED=0
              else ARTIFACTS_RESTORED=0; fi
            else rm -f -- "$TOP/storage/app/agent/$name" || ARTIFACTS_RESTORED=0; fi
          done
        fi
        if (( ARTIFACTS_RESTORED )); then
          run_php artisan optimize:clear
          systemctl restart "$HTTP_SERVICE"
        else
          printf '[X] Previous agent artifacts could not be fully restored; use backup: %s\n' "$BACKUP" >&2
        fi
      fi
    fi
    if (( DOWN )); then
      # Keep the queue stopped and the application down until recovery is checked.
      systemctl stop "$QUEUE_SERVICE"
      printf '[X] Update failed; maintenance retained. Backup: %s\n' "$BACKUP" >&2
      (( DB_STARTED )) && printf '[X] Migrations began; do not restore SQL automatically over newer data.\n' >&2
    elif (( PAUSED )); then
      systemctl kill --kill-who=main --signal=SIGCONT "$QUEUE_SERVICE"
    fi
  fi
  [[ -z "$AGENT_PREVIEW" ]] || rm -rf -- "$AGENT_PREVIEW"
  rm -f -- "${BASH_SOURCE[0]}"
  exit "$status"
}
trap cleanup EXIT

step 'Pausing the queue and checking for running jobs'
systemctl kill --kill-who=main --signal=SIGUSR2 "$QUEUE_SERVICE"
PAUSED=1
sleep 1
run_php scripts/update-database.php queue-idle || fail 'A queue job is running/reserved. Wait for it to finish before updating.'
run_php artisan down
DOWN=1
cp -- storage/framework/down "$BACKUP/maintenance.down"
systemctl stop "$QUEUE_SERVICE"

step "Taking database backup in $BACKUP"
# The helper runs as root to write the private backup; credentials stay in PHP.
"$PHP" scripts/update-database.php backup "$BACKUP/database.sql-or-sqlite"
sha256sum "$BACKUP/database.sql-or-sqlite" > "$BACKUP/database.sha256"

step "Fast-forwarding to $TARGET"
umask 022
CODE_CHANGED=1
git merge --ff-only "$TARGET"
# No reset --hard, git clean, .env rewrite, or removal of uploaded files.
if (( DEPENDENCIES )); then
  export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1
  "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --prefer-dist
fi
if (( AGENT )); then
  "$PHP" scripts/agent-artifacts.php verify "$TOP"
  mkdir -p storage/app/agent
  ARTIFACTS_CHANGED=1
  for name in xboard-node-linux-amd64 xbctl-linux-amd64 xboard-node-linux-arm64 xbctl-linux-arm64; do
    install -m 0755 -- "$AGENT_PREVIEW/storage/app/agent/$name" "storage/app/agent/.new-$name"
    mv -f -- "storage/app/agent/.new-$name" "storage/app/agent/$name"
  done
fi

mkdir -p storage/app/public
chown -R "$WEB_USER":"$(id -gn "$WEB_USER")" storage bootstrap/cache
run_php artisan optimize:clear
if (( CHANGES_MIGRATIONS )); then
  DB_STARTED=1
  run_php artisan migrate --force
fi
run_php artisan config:cache
run_php artisan route:cache
run_php artisan view:cache
if [[ ! -e public/storage && ! -L public/storage ]]; then ln -s "$(realpath storage/app/public)" public/storage; fi

systemctl restart "$HTTP_SERVICE"
health() {
  local path="$1" code
  code="$(curl -sS --connect-timeout 3 --max-time 10 -o /dev/null -w '%{http_code}' -H "Host: $HEALTH_HOST" "http://127.0.0.1:$PORT/$path")" || return 1
  [[ "$code" = 200 ]]
}
READY=0
for attempt in 1 2 3 4 5; do
  if systemctl is-active --quiet "$HTTP_SERVICE" && health up; then READY=1; break; fi
  sleep 1
done
(( READY )) || fail 'Octane health check failed.'
run_php artisan up
health login && health api/ping || fail 'Post-update HTTP health check failed.'
systemctl start "$QUEUE_SERVICE"
systemctl is-active --quiet "$QUEUE_SERVICE"
[[ "$(git rev-parse HEAD)" = "$TARGET" ]]
SUCCESS=1
printf 'UPDATE FINISHED\nCommit: %s\nBackup: %s\n' "$TARGET" "$BACKUP"
