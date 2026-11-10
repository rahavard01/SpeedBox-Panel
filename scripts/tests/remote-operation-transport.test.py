#!/usr/bin/env python3
"""Run the shipped SSH bootstrap locally, isolated from /opt and live services."""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class RemoteHelperTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='speedbox-helper-test-')
        self.root = Path(self.temp.name)
        self.cache = self.root / 'cache'
        source = (ROOT / 'app/Services/RemoteScriptRunner.php').read_text()
        self.bootstrap = re.search(r"private const BOOTSTRAP = <<<'PY'\n(.*?)\nPY;", source, re.S)[1]
        self.bootstrap = self.bootstrap.replace("Path('/opt/speedbox-remote-scripts')", 'Path(' + repr(str(self.cache)) + ')')
        self.counter = self.root / 'actions'
        self.helper = b'''import json,sys\nfrom pathlib import Path\np=json.loads(Path(sys.argv[1]).read_text())\nc=Path(p['counter']);c.write_text(c.read_text()+'x' if c.exists() else 'x')\nprint(json.dumps({'ok':not p.get('fail'),'data':p}))\nsys.exit(2 if p.get('fail') else 0)\n'''
        self.digest = hashlib.sha256(self.helper).hexdigest()
        self.packet = {'hash': self.digest, 'request': {'counter': str(self.counter)}, 'architecture_fields': {}}

    def tearDown(self):
        self.temp.cleanup()

    def run_packet(self, packet=None, success=True):
        p = subprocess.run(['python3', '-c', self.bootstrap], input=json.dumps(packet or self.packet), capture_output=True, text=True)
        self.assertEqual(success, p.returncode == 0, p.stderr)
        return json.loads(p.stdout) if p.stdout else None

    def install(self, **changes):
        return self.run_packet({**self.packet, 'content': base64.b64encode(self.helper).decode(), **changes})

    def test_cache_miss_does_not_execute_and_verified_bytes_run_once(self):
        self.assertEqual({'speedbox_helper_missing': self.digest}, self.run_packet())
        self.assertFalse(self.counter.exists())
        self.assertTrue(self.install()['ok'])
        self.assertEqual('x', self.counter.read_text())
        target = self.cache / (self.digest + '.py')
        before = target.stat().st_mtime_ns
        self.assertTrue(self.run_packet()['ok'])
        self.assertEqual(before, target.stat().st_mtime_ns)
        self.assertEqual('xx', self.counter.read_text())
        self.assertEqual(0o700, target.stat().st_mode & 0o777)
        self.assertEqual([], list(self.cache.glob('.request-*')))

    def test_corrupt_cache_requires_verified_repair_before_action(self):
        self.install()
        (self.cache / (self.digest + '.py')).write_text('untrusted bytes')
        self.assertEqual({'speedbox_helper_missing': self.digest}, self.run_packet())
        self.assertEqual('x', self.counter.read_text())
        self.install()
        self.assertEqual('xx', self.counter.read_text())

    def test_bad_content_never_executes(self):
        self.run_packet({**self.packet, 'content': base64.b64encode(b'wrong helper').decode()}, success=False)
        self.assertFalse(self.counter.exists())

    def test_failed_action_has_one_side_effect_and_cleans_private_request(self):
        self.run_packet({**self.packet, 'content': base64.b64encode(self.helper).decode(), 'request': {**self.packet['request'], 'fail': True}}, success=False)
        self.assertEqual('x', self.counter.read_text())
        self.assertEqual([], list(self.cache.glob('.request-*')))

    def test_symlinks_are_rejected(self):
        other = self.root / 'other';other.mkdir()
        self.cache.symlink_to(other, target_is_directory=True)
        self.run_packet(success=False)
        self.assertFalse(self.counter.exists())
        self.cache.unlink();self.cache.mkdir(mode=0o700)
        target = self.cache / (self.digest + '.py')
        target.symlink_to(self.root / 'outside')
        self.run_packet(success=False)
        self.assertFalse((self.root / 'outside').exists())

    def test_architecture_fields_resolve_before_execution(self):
        choices = {'amd64': {'version': 'reviewed'}, 'arm64': {'version': 'reviewed'}}
        self.assertEqual({'version': 'reviewed'}, self.install(architecture_fields={'expected': choices})['data']['expected'])

    def test_group_writable_cache_is_rejected(self):
        self.cache.mkdir(mode=0o777);self.cache.chmod(0o777)
        self.run_packet(success=False)
        self.assertFalse(self.counter.exists())

    def test_artifact_probe_requires_fresh_hash_permissions_and_dependencies(self):
        source = (ROOT / 'app/Services/RemoteScriptRunner.php').read_text()
        script = re.search(r"\$script = <<<'PY'\n(.*?)\nPY;", source, re.S)[1]
        binary = self.root / 'binary'
        binary.write_bytes(b'reviewed artifact');binary.chmod(0o700)
        digest = hashlib.sha256(binary.read_bytes()).hexdigest()
        packet = {'files': {str(binary): {'sha256': {'amd64': digest, 'arm64': digest}, 'executable': True}},
                  'commands': ['python3'], 'directories': [str(self.root)]}
        def probe():
            p = subprocess.run(['python3', '-c', script], input=json.dumps(packet), capture_output=True, text=True, check=True)
            return json.loads(p.stdout)['current']
        self.assertTrue(probe())
        binary.write_bytes(b'changed artifact');self.assertFalse(probe())
        binary.write_bytes(b'reviewed artifact');binary.chmod(0o600);self.assertFalse(probe())
        binary.chmod(0o700);packet['commands']=['speedbox-nonexistent-dependency'];self.assertFalse(probe())
        packet['commands']=[];packet['directories']=[str(self.root / 'missing')];self.assertFalse(probe())


if __name__ == '__main__':
    unittest.main(verbosity=2)
