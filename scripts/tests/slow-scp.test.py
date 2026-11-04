#!/usr/bin/env python3
"""Real OpenSSH clients and AsyncSSH, with a bandwidth cap and job deadline."""
import argparse
import asyncio
import hashlib
import json
import os
from pathlib import Path
import tempfile
import time

import asyncssh

ROOT = Path(__file__).resolve().parents[2]


async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='speedbox-slow-scp-') as temporary:
        tmp = Path(temporary)
        host, identity = [asyncssh.generate_private_key('ssh-ed25519') for _ in range(2)]
        pending = set()

        class Server(asyncssh.SSHServer):
            def begin_auth(self, username): return True
            def public_key_auth_supported(self): return True
            def validate_public_key(self, username, key): return key == identity.convert_to_public()

        class SFTP(asyncssh.SFTPServer):
            def exit(self): self.channel.exit(0)

        async def process(proc):
            task = asyncio.current_task()
            pending.add(task)
            child = await asyncio.create_subprocess_shell(proc.command, stdin=asyncio.subprocess.PIPE,
                        stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE)
            try:
                out, err = await child.communicate((await proc.stdin.read()).encode())
                proc.stdout.write(out.decode())
                proc.stderr.write(err.decode())
                proc.exit(child.returncode)
            finally:
                if child.returncode is None:
                    child.terminate()
                    await child.communicate()
                pending.discard(task)

        daemon = await asyncssh.create_server(Server, '127.0.0.1', 0, server_host_keys=[host],
                      process_factory=process, sftp_factory=SFTP)
        wrapper = tmp / 'bin'
        wrapper.mkdir()
        (wrapper / 'scp').write_text('''#!/usr/bin/env python3
import json, os, sys
with open(os.environ['SCP_ARGS_LOG'], 'a') as out:
    out.write(json.dumps(sys.argv[1:]) + '\\n')
os.execv('/usr/bin/scp', ['scp', '-l', '512', *sys.argv[1:]])
''')
        (wrapper / 'scp').chmod(0o755)
        payload = tmp / 'payload'
        payload.write_bytes(os.urandom(256 * 1024))
        server = dict(id=1, host='127.0.0.1', ssh_port=daemon.get_port(), ssh_user='root', auth_type='key',
                      private_key=identity.export_private_key().decode(), host_fingerprint=host.get_fingerprint('sha256'))
        env = {**os.environ, 'PATH': str(wrapper) + ':' + os.environ['PATH'],
               'SCP_ARGS_LOG': str(tmp / 'scp-args.jsonl'), 'SPEEDBOX_SSH_TEST_STORAGE': str(tmp / 'storage')}

        async def check(name, **changes):
            fixture = dict(server=server, mode='upload', source=str(payload),
                           destination=str(tmp / name), upload_only=True, **changes)
            path = tmp / (name + '.json')
            path.write_text(json.dumps(fixture))
            return await run(path)

        async def run(path):
            start = time.monotonic()
            child = await asyncio.create_subprocess_exec(args.php, str(ROOT / 'scripts/tests/ssh-host-key.test.php'),
                    str(path), stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE, env=env)
            out, err = await asyncio.wait_for(child.communicate(), 20)
            return child.returncode, out.decode(), err.decode(), time.monotonic() - start

        try:
            code, out, err, elapsed = await check('too-short', upload_timeout=1)
            assert code != 0 and 'timeout of 1 seconds' in err, err
            code, out, err, elapsed = await check('long-enough', upload_timeout=10)
            assert code == 0, err
            assert elapsed > 1, 'bandwidth cap did not slow the transfer'
            assert hashlib.sha256(payload.read_bytes()).digest() == hashlib.sha256((tmp / 'long-enough').read_bytes()).digest()
            code, out, err, elapsed = await check('job-budget', upload_timeout=10, budget=2)
            assert code != 0 and elapsed < 4.5, (elapsed, err)
            fixture = tmp / 'command-budget.json'
            fixture.write_text(json.dumps(dict(server=server, command='sleep 5', budget=1)))
            code, out, err, elapsed = await run(fixture)
            assert code != 0 and elapsed < 3, (elapsed, err)
            calls = [json.loads(line) for line in (tmp / 'scp-args.jsonl').read_text().splitlines()]
            assert len(calls) == 3 and all('-C' in call for call in calls), calls
            assert not list((tmp / 'storage/app/private').glob('speedbox-*')), 'temporary identity or host pins leaked'
            print('PASS: throttled SCP fails short timeout; longer timeout preserves SHA256; compression, transfer/command budgets, secret cleanup')
        finally:
            daemon.close()
            await daemon.wait_closed()
            if pending:
                await asyncio.wait_for(asyncio.gather(*pending, return_exceptions=True), 6)


asyncio.run(main())
