"""Exercise installer backup/recovery functions with disposable paths and fake systemd."""
import subprocess
import tempfile
import unittest
from pathlib import Path

INSTALLER = Path(__file__).resolve().parents[2] / 'agent/install.sh'


class AgentRollbackTests(unittest.TestCase):
    def exercise(self, corrupt=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            # Load the real installer functions without invoking its CLI.
            script = root / 'installer.sh'
            source = INSTALLER.read_text()
            source = source.replace('main "$@"', '# fixture: do not run CLI')
            script.write_text(source)
            scenario = r'''
set -Eeuo pipefail
source "$1/installer.sh"
trap - ERR EXIT
INSTALL_ROOT="$1/node"
BACKUP_DIR="$INSTALL_ROOT/backups"
CONFIG_FILE="$INSTALL_ROOT/config.yml"
CREDENTIALS_FILE="$INSTALL_ROOT/credentials.env"
INSTALL_META="$INSTALL_ROOT/install-meta.json"
BINARY_PATH="$1/xboard-node"
CLI_PATH="$1/xbctl"
SERVICE_PATH="$1/xboard-node.service"
mkdir -p "$BACKUP_DIR"
systemctl() { return 0; }
wait_for_health() { return 0; }
load_health_port_from_config() { :; }
for path in "$BINARY_PATH" "$CLI_PATH" "$CONFIG_FILE" "$CREDENTIALS_FILE" "$INSTALL_META" "$SERVICE_PATH"; do
    printf 'previous bytes\n' > "$path"
    chmod 700 "$path"
done
backup_existing_state
REQUESTED_BACKUP="$BACKUP_PATH"
for path in "$BINARY_PATH" "$CLI_PATH" "$CONFIG_FILE" "$CREDENTIALS_FILE" "$INSTALL_META" "$SERVICE_PATH"; do
    printf 'trial bytes\n' > "$path"
done
if [ "$2" = corrupt ]; then
    printf 'tampered\n' > "$BACKUP_PATH/xboard-node"
    if perform_recorded_rollback; then exit 31; fi
    grep -q 'trial bytes' "$BINARY_PATH"
else
    perform_recorded_rollback
    for path in "$BINARY_PATH" "$CLI_PATH" "$CONFIG_FILE" "$CREDENTIALS_FILE" "$INSTALL_META" "$SERVICE_PATH"; do
        grep -q 'previous bytes' "$path"
    done
fi
'''
            result = subprocess.run(['bash', '-c', scenario, 'fixture', str(root),
                                     'corrupt' if corrupt else 'valid'], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_previous_binaries_config_credentials_and_service_are_restored(self):
        self.exercise()

    def test_corrupt_backup_is_rejected_before_replacing_live_files(self):
        self.exercise(corrupt=True)


if __name__ == '__main__':
    unittest.main(verbosity=2)
