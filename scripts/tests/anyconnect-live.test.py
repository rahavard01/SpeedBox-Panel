#!/usr/bin/env python3
"""Run only as root on an isolated CI runner with disposable network state."""
import pathlib
import json
import secrets
import shutil
import subprocess
import tempfile
import time
import uuid

from importlib.util import module_from_spec, spec_from_file_location

source = pathlib.Path(__file__).resolve().parents[1] / 'anyconnect' / 'host.py'
spec = spec_from_file_location('anyconnect_host', source)
host = module_from_spec(spec)
spec.loader.exec_module(host)
path = pathlib.Path(host.SELF)
path.parent.mkdir(parents=True, exist_ok=True)
shutil.copyfile(source, path)
path.chmod(0o755)

domain = 'ac-ci.example.test'
certs = pathlib.Path('/etc/letsencrypt/live') / domain
certs.mkdir(parents=True, exist_ok=True)
host.run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
         '-subj', f'/CN={domain}', '-addext', f'subjectAltName=DNS:{domain}',
         '-keyout', str(certs / 'privkey.pem'), '-out', str(certs / 'fullchain.pem'))
with pathlib.Path('/etc/hosts').open('a') as hosts:
    hosts.write(f'\n127.0.0.1 {domain}\n')

password = secrets.token_hex(12)
policy = {
    'id': 9890, 'owner': str(uuid.uuid4()), 'endpoint': domain, 'endpoint_port': 19443,
    'listen_port': 19443, 'network': '10.90.249.0/24', 'dns': ['1.1.1.1'], 'mtu': 1300,
    'tunneled': False, 'link_network': None, 'radius_secret': secrets.token_hex(32),
}
account = {
    'id': 1, 'username': 'fixture@ci.test', 'hash': '',
    'uploaded': 0, 'downloaded': 0, 'quota': 2**30, 'used': 0, 'expires': int(time.time()) + 600,
    'banned': False, 'connections': 1, 'speed': 10,
}
account['hash'] = subprocess.run(
    ['python3', '-c', 'import crypt,sys; print(crypt.crypt(sys.stdin.read(), crypt.mksalt(crypt.METHOD_BLOWFISH)))'],
    input=password, text=True, capture_output=True, check=True,
).stdout.strip()
proc = None
client_log = tempfile.TemporaryFile(mode='w+t')
try:
    host.control({'policy': policy, 'action': 'install', 'accounts': [account]})
    proc = subprocess.Popen(
        ['openconnect', '--protocol=anyconnect', '--non-inter', '--passwd-on-stdin', '--script=/bin/true',
         '--user', account['username'], '--cafile', str(certs / 'fullchain.pem'), f'https://{domain}:19443'],
        stdin=subprocess.PIPE, stdout=client_log, stderr=client_log, text=True,
    )
    proc.stdin.write(password + '\n')
    proc.stdin.close()
    for _ in range(30):
        report = host.control({'policy': policy, 'action': 'report'})
        with host.sqlite(host.store(policy)) as database:
            accounting_started = database.execute('SELECT 1 FROM sessions WHERE id = ?', (account['id'],)).fetchone()
        sessions = json.loads(host.occtl(policy, '--json', 'show', 'users'))
        dtls_connected = any(
            session.get('Username') == account['username'] and 'DTLS' in (session.get('DTLS cipher') or '')
            for session in sessions
        )
        if report['accounts'][account['id']]['online'] == 1 and accounting_started and dtls_connected:
            print('OpenConnect authenticated, started RADIUS accounting, and negotiated DTLS.')
            break
        if proc.poll() is not None:
            raise RuntimeError(f'OpenConnect exited before connecting with status {proc.returncode}')
        time.sleep(1)
    else:
        raise RuntimeError('OpenConnect did not establish an authenticated and accounted DTLS session')
    host.control({'policy': policy, 'action': 'sync', 'accounts': [account | {'banned': True}]})
    report = host.control({'policy': policy, 'action': 'report'})
    assert report['accounts'][account['id']]['online'] == 0, 'Blocked account still has an active connection'
except Exception:
    client_log.seek(0)
    print(client_log.read()[-4000:])
    print(host.run('journalctl', '-n', '60', '-u', host.unit(policy, 'vpn'), '-u', host.unit(policy, 'radius'), check=False))
    raise
finally:
    client_log.close()
    if proc and proc.poll() is None:
        proc.terminate()
        proc.wait(timeout=10)
    host.control({'policy': policy, 'action': 'remove'})
