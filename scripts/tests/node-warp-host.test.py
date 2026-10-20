#!/usr/bin/env python3
"""Exercise the real host script with fake commands; never install packages or contact a host."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class WarpHostTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='speedbox-warp-host-test-')
        self.root = Path(self.temporary.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.account = self.root / 'account'
        self.account.write_text('Free')
        self.log = self.root / 'calls'
        self.script = self.root / 'warp.sh'
        # Only the lock location is redirected into this test's private directory.
        self.script.write_text((ROOT / 'scripts/server-warp.sh').read_text().replace('/run/lock/speedbox-warp.lock', str(self.root / 'lock')))
        self.env = {**os.environ, 'PATH': str(self.bin) + ':/usr/bin:/bin', 'WARP_TEST_ACCOUNT': str(self.account), 'WARP_TEST_LOG': str(self.log)}
        self.command('id', 'echo 0')
        self.command('sleep', ':')
        self.command('systemctl', 'echo systemctl >> "$WARP_TEST_LOG"')
        self.command('ss', 'printf \'LISTEN 0 128 %s:40000 0.0.0.0:* users:(("%s",pid=1,fd=4))\\n\' "${WARP_TEST_LISTEN:-127.0.0.1}" "${WARP_TEST_PROCESS:-warp-svc}"')
        self.command('curl', 'printf \'warp=%s\\nip=203.0.113.2\\n\' "${WARP_TEST_TRACE:-on}"')
        self.command('warp-cli', '''
[[ "$1" == --accept-tos ]] && shift
case "$1 $2" in
  'settings ') echo "Mode: ${WARP_TEST_MODE:-WarpProxy on port 40000}" ;;
  'registration show') printf 'Account type: %s\\n' "$(cat "$WARP_TEST_ACCOUNT")" ;;
  'registration new') echo new >> "$WARP_TEST_LOG"; echo Free > "$WARP_TEST_ACCOUNT" ;;
  'registration delete') echo delete >> "$WARP_TEST_LOG" ;;
  'registration license')
    if [[ "${WARP_TEST_BAD_LICENSE:-0}" == 1 ]]; then echo "private key: $3" >&2; exit 1; fi
    echo Unlimited > "$WARP_TEST_ACCOUNT" ;;
  *) : ;;
esac
''')

    def tearDown(self):
        self.temporary.cleanup()

    def command(self, name, content):
        target = self.bin / name
        target.write_text('#!/usr/bin/env bash\n' + content + '\n')
        target.chmod(0o700)

    def run_host(self, action='test', account='keep', license=None, **env):
        secret = self.root / 'license'
        if license is not None:
            secret.write_text(license)
            secret.chmod(0o600)
        result = subprocess.run(['bash', str(self.script), action, account, str(secret)], env={**self.env, **env}, capture_output=True, text=True, timeout=15)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('', result.stderr)
        if license:
            self.assertNotIn(license, result.stdout)
        return json.loads(result.stdout)

    def test_free_proxy_ready(self):
        result = self.run_host()
        self.assertTrue(result['ready'])
        self.assertEqual('free', result['account'])
        self.assertEqual('203.0.113.2', result['ip'])
        self.assertFalse(self.log.exists(), 'read-only test reconfigured the service')

    def test_plus_license_is_verified(self):
        result = self.run_host('prepare', 'plus', 'valid-license-123')
        self.assertTrue(result['ready'])
        self.assertEqual('plus', result['account'])

    def test_invalid_license_never_reports_free_success(self):
        result = self.run_host('prepare', 'plus', 'invalid-license-123', WARP_TEST_BAD_LICENSE='1')
        self.assertFalse(result['ready'])
        self.assertEqual('license_invalid', result['error'])

    def test_keep_preserves_existing_paid_account(self):
        self.account.write_text('Unlimited')
        result = self.run_host('prepare', 'keep')
        self.assertTrue(result['ready'])
        self.assertEqual('plus', result['account'])
        self.assertNotIn('delete', self.log.read_text())

    def test_explicit_free_changes_account(self):
        self.account.write_text('Unlimited')
        self.assertEqual('free', self.run_host('prepare', 'free')['account'])
        self.assertEqual(1, self.log.read_text().count('delete'))

    def test_prepare_is_idempotent(self):
        self.run_host('prepare')
        self.run_host('prepare')
        self.assertNotIn('new', self.log.read_text())

    def test_existing_whole_host_tunnel_is_not_changed(self):
        result = self.run_host('prepare', WARP_TEST_MODE='WarpWithDnsOverHttps')
        self.assertEqual('existing_mode', result['error'])
        self.assertFalse(self.log.exists())

    def test_other_process_port_is_not_replaced(self):
        result = self.run_host('prepare', WARP_TEST_PROCESS='nginx')
        self.assertEqual('port_in_use', result['error'])
        self.assertFalse(self.log.exists())

    def test_public_proxy_is_not_accepted(self):
        self.assertEqual('public_listener', self.run_host(WARP_TEST_LISTEN='0.0.0.0')['error'])

    def test_direct_trace_is_not_warp(self):
        self.assertEqual('connection_failed', self.run_host(WARP_TEST_TRACE='off')['error'])

    def test_organizational_account_is_not_replaced(self):
        self.account.write_text('Team')
        result = self.run_host('prepare', 'free')
        self.assertEqual('existing_team', result['error'])
        self.assertNotIn('delete', self.log.read_text())


if __name__ == '__main__':
    unittest.main(verbosity=2)
