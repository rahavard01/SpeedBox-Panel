"""Exercise the actual remote receipt probe without SSH or live services."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SOURCE = (ROOT / 'app/Services/TunnelOriginRemote.php').read_text()
PROBE = re.search(r"INSTALLED_PROBE = <<<'PY'\n(.*?)\nPY;", SOURCE, re.S)[1]


class ReceiptTest(unittest.TestCase):
    def setUp(self):
        if not Path('/proc/sys/kernel/random/boot_id').is_file():
            self.skipTest('Receipt ownership and boot checks require Linux; run on test server')
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.root.chmod(0o700)
        self.binary = self.root / 'tunnel-origin'
        self.helper = self.root / 'host.py'
        self.binary.write_bytes(b'reviewed endpoint')
        self.helper.write_bytes(b'reviewed helper')
        self.binary.chmod(0o700)
        self.helper.chmod(0o700)
        self.receipt = self.root / 'verified.json'
        self.boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
        entry = {'binary_sha256': hashlib.sha256(self.binary.read_bytes()).hexdigest()}
        self.expected = {'release': 'reviewed-release', 'helper': hashlib.sha256(self.helper.read_bytes()).hexdigest(),
                         'archives': {'amd64': entry, 'arm64': entry}}
        bindir = self.root / 'bin'
        bindir.mkdir()
        command = bindir / 'systemctl'
        command.write_text('#!/bin/sh\nexit 0\n')
        command.chmod(0o700)
        self.env = {**os.environ, 'PATH': str(bindir)}
        self.write_receipt()

    def write_receipt(self, **overrides):
        self.receipt.write_text(json.dumps({'release': 'reviewed-release', 'boot': self.boot, **overrides}))
        self.receipt.chmod(0o600)

    def probe(self):
        r = subprocess.run([sys.executable, '-c', PROBE, str(self.root)], input=json.dumps(self.expected),
                           text=True, capture_output=True, env=self.env, check=True)
        return json.loads(r.stdout)

    def test_unchanged_verified_release_is_ready(self):
        self.assertTrue(self.probe()['ready'])

    def test_missing_receipt_requires_full_verification(self):
        self.receipt.unlink()
        self.assertFalse(self.probe()['ready'])

    def test_new_boot_or_release_requires_full_verification(self):
        for change in ({'boot': 'other-boot'}, {'release': 'other-release'}):
            self.write_receipt(**change)
            self.assertFalse(self.probe()['ready'])

    def test_modified_installed_files_are_rejected(self):
        for path in (self.binary, self.helper):
            original = path.read_bytes()
            path.write_bytes(b'unreviewed')
            self.assertFalse(self.probe()['ready'])
            path.write_bytes(original)

    def test_unsafe_permissions_or_symlinks_are_rejected(self):
        self.receipt.chmod(0o644)
        self.assertFalse(self.probe()['ready'])
        self.write_receipt()
        target = self.root / 'elsewhere'
        self.receipt.rename(target)
        self.receipt.symlink_to(target)
        self.assertFalse(self.probe()['ready'])

    def test_missing_dependency_is_rejected(self):
        self.env['PATH'] = str(self.root / 'missing')
        self.assertFalse(self.probe()['ready'])


if __name__ == '__main__':
    unittest.main()
