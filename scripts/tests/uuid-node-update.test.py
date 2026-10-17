#!/usr/bin/env python3
"""Temporary filesystem and mocked systemd only; never touches a real node."""
from pathlib import Path
import hashlib
import os
import subprocess
import tempfile

source = Path(__file__).resolve().parents[2] / 'scripts/update-uuid-node.sh'
checks = 0
for scenario in ['success', 'restart-fails', 'inactive', 'wrong-exec', 'wrong-hash']:
    with tempfile.TemporaryDirectory(prefix='speedbox-uuid-update-test-') as temp:
        root = Path(temp)
        for part in ['usr/local/bin', 'var/backups', 'tmp', 'proc/123', 'tools']:
            (root / part).mkdir(parents=True, exist_ok=True)
        stage = root / 'tmp/speedbox-uuid-node.ABCDEFGH'
        stage.mkdir()
        binary = root / 'usr/local/bin/xboard-node'
        binary.write_text('#!/usr/bin/env bash\necho old-version\n')
        binary.chmod(0o755)
        old = binary.read_bytes()
        new = stage / 'xboard-node'
        new.write_text('#!/usr/bin/env bash\necho panel-speedbox-http-obfs-uuid-v1\n')
        new.chmod(0o755)
        expected = hashlib.sha256(new.read_bytes()).hexdigest()
        (root / 'proc/123/exe').symlink_to(binary)
        script = source.read_text()
        for absolute in ['/usr/local/bin', '/var/backups', '/tmp/speedbox-uuid-node', '/proc/']:
            script = script.replace(absolute, str(root) + absolute)
        test_script = root / 'update.sh'
        test_script.write_text(script)
        systemctl = root / 'tools/systemctl'
        systemctl.write_text('''#!/usr/bin/env bash
set -eu
case "$1" in
 is-active) [[ "$CASE" != inactive ]];;
 show)
  if [[ "$*" = *ExecStart* ]]; then
   if [[ "$CASE" = wrong-exec ]]; then echo 'path=/wrong/php ;'; else echo "path=$ROOT/usr/local/bin/xboard-node ;"; fi
  else echo 123; fi;;
 restart)
  echo restart >> "$ROOT/restarts"
  if [[ "$CASE" = restart-fails && ! -f "$ROOT/failed-once" ]]; then touch "$ROOT/failed-once"; exit 1; fi;;
 *) exit 1;;
esac
''')
        systemctl.chmod(0o755)
        sleep = root / 'tools/sleep'
        sleep.write_text('#!/usr/bin/env bash\nexit 0\n')
        sleep.chmod(0o755)
        env = dict(os.environ, PATH=str(root / 'tools') + ':' + os.environ['PATH'], ROOT=str(root), CASE=scenario)
        args = ['bash', str(test_script), str(new), '0' * 64 if scenario == 'wrong-hash' else expected]
        run = subprocess.run(args, env=env, capture_output=True, text=True)
        if scenario == 'success':
            assert run.returncode == 0 and 'UUID NODE UPDATED' in run.stdout, run.stdout + run.stderr
            assert binary.read_bytes() == new.read_bytes()
            assert next((root / 'var/backups/speedbox-uuid-node').glob('*/xboard-node.before')).read_bytes() == old
            again = subprocess.run(args, env=env, capture_output=True, text=True)
            assert again.returncode == 0 and 'ALREADY UPDATED' in again.stdout
            assert (root / 'restarts').read_text().splitlines() == ['restart']
            checks += 5
        else:
            assert run.returncode != 0, scenario
            assert binary.read_bytes() == old, scenario
            if scenario == 'restart-fails':
                assert 'old binary restored' in run.stderr
                assert len((root / 'restarts').read_text().splitlines()) == 2
                checks += 2
            else:
                assert not (root / 'restarts').exists()
                checks += 1
            checks += 2
print(f'UUID NODE UPDATE OK: {checks} checks; temporary files and mocked services only.')
