#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

fail() { printf '[X] %s\n' "$*" >&2; exit 1; }

PHP_COMMAND="${PHP_BIN:-php}"
PHP_PATH="$(command -v "$PHP_COMMAND" || true)"
[ -n "$PHP_PATH" ] || fail "PHP was not found. Select the website PHP version before continuing."
export PATH="$(dirname "$PHP_PATH"):$PATH"

COMPOSER_COMMAND="${COMPOSER_BIN:-composer}"
command -v "$COMPOSER_COMMAND" >/dev/null 2>&1 || fail "Composer was not found. Run the installer to prepare Composer."

printf '[•] Checking PHP runtime requirements (%s)\n' "$PHP_PATH"
"$PHP_PATH" -r '
$missing = [];
if (version_compare(PHP_VERSION, "8.2.0", "<")) {
    $missing[] = "PHP 8.2 or newer (current: ".PHP_VERSION.")";
}
foreach (["pdo_mysql", "pcntl"] as $extension) {
    if (!extension_loaded($extension)) $missing[] = "PHP extension: ".$extension;
}
$octane = in_array("--octane", $argv, true);
if (is_file(".env") && preg_match("/^SPEEDBOX_OCTANE_ENABLED=(.*)$/m", file_get_contents(".env"), $match)) {
    $octane = $octane || filter_var(trim($match[1], " \t\r\n\"\x27"), FILTER_VALIDATE_BOOLEAN);
}
if ($octane && !extension_loaded("swoole") && !extension_loaded("openswoole")) {
    $missing[] = "PHP extension: swoole (App Store -> PHP -> Install extensions, then restart PHP)";
}
foreach (["putenv", "proc_open", "proc_close", "proc_get_status", "proc_terminate", "pcntl_alarm", "pcntl_signal", "pcntl_async_signals", "symlink"] as $function) {
    if (!function_exists($function)) $missing[] = "PHP function: ".$function." (check Disabled functions)";
}
if ($missing) {
    fwrite(STDERR, "[X] Missing PHP requirements:\n - ".implode("\n - ", $missing)."\n");
    exit(1);
}
echo "[OK] PHP runtime requirements are available.\n";
' -- "$@"

printf '[•] Checking Composer platform requirements\n'
"$COMPOSER_COMMAND" check-platform-reqs --lock --no-dev --no-interaction

for tool in git curl rsync tar sha256sum openssl ssh scp ssh-keyscan ssh-keygen sshpass; do
    command -v "$tool" >/dev/null 2>&1 || fail "Server tool not found: $tool"
done

printf '[OK] PHP requirements and server tools are ready.\n'

