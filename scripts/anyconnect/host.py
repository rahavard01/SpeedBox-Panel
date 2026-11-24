#!/usr/bin/env python3
"""Host-side controller for owned SpeedBox ocserv instances and their local RADIUS policies."""
import argparse
import collections
import contextlib
import fcntl
import grp
import hashlib
import ipaddress
import json
import os
import pathlib
import re
import shutil
import socket
import sqlite3
import ssl
import subprocess
import sys
import tempfile
import time

BASE = pathlib.Path('/var/lib/speedbox-anyconnect')
UNITS = pathlib.Path('/etc/systemd/system')
SELF = '/opt/speedbox-anyconnect/host.py'
LE_CERTIFICATES = pathlib.Path('/etc/letsencrypt/live')
ACME_HELPER = pathlib.Path('/opt/speedbox-tunnel-cdn/certificate')
LEGACY_USERNAME = re.compile(r'[A-Za-z0-9_.+=-]{1,120}@[A-Za-z0-9.-]{1,120}', re.ASCII)
LOGIN_USERNAME = re.compile(r'[A-Za-z0-9_.+=-]{1,250}', re.ASCII)


def valid_username(value):
    return bool(value and value not in ('.', '..') and (LEGACY_USERNAME.fullmatch(value) or LOGIN_USERNAME.fullmatch(value)))
HOSTNAME = re.compile(r'(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}')
HASH = re.compile(r'\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}')
NETWORKS = tuple(map(ipaddress.ip_network, ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')))


def run(*argv, check=True, deadline=35):
    result = subprocess.run(argv, text=True, capture_output=True, timeout=deadline)
    if result.returncode and check:
        raise RuntimeError(f'{argv[0]} exited with status {result.returncode}: {result.stderr.strip()[-350:]}')
    return result.stdout.strip()


def private_network(value, prefix):
    net = ipaddress.IPv4Network(value, strict=True)
    if net.prefixlen != prefix or not any(net.subnet_of(parent) for parent in NETWORKS):
        raise ValueError(f'Expected private IPv4 /{prefix}')
    return net


def validate(policy):
    if not 0 < policy['id'] < 10**9 or not re.fullmatch(r'[a-f0-9-]{36}', policy['owner']):
        raise ValueError('Invalid instance identity')
    if not HOSTNAME.fullmatch(policy['endpoint']) or len(policy['endpoint']) > 253:
        raise ValueError('Invalid endpoint domain')
    if any(not 1 <= policy[key] <= 65535 for key in ('listen_port', 'endpoint_port')):
        raise ValueError('Invalid listener port')
    private_network(policy['network'], 24)
    if not 1280 <= policy['mtu'] <= 1500 or not 1 <= len(policy['dns']) <= 3:
        raise ValueError('Invalid MTU or DNS list')
    for dns in policy['dns']:
        ipaddress.IPv4Address(dns)
    if policy['tunneled']:
        if private_network(policy['link_network'], 30).overlaps(private_network(policy['network'], 24)):
            raise ValueError('VPN and tunnel networks overlap')
    if 'side' not in policy and not re.fullmatch(r'[a-f0-9]{64}', policy['radius_secret']):
        raise ValueError('Invalid RADIUS secret')


def save(path, content, mode=0o600, group=None):
    if path.exists() and path.read_text() == content:
        return False
    fd, name = tempfile.mkstemp(prefix='.speedbox-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as target:
            target.write(content)
            target.flush()
            os.fsync(target.fileno())
            os.fchmod(target.fileno(), mode)
            if group is not None:
                os.fchown(target.fileno(), -1, group)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)
    return True


def unit(policy, suffix):
    return f'speedbox-ac-{policy["id"]}-{suffix}'


def folder(instance):
    return BASE / str(instance)


def store(policy):
    return folder(policy['id']) / 'accounting' / 'control.sqlite3'


@contextlib.contextmanager
def owned(policy, component, create=False):
    root = folder(policy['id'])
    if root.is_symlink() or BASE.is_symlink():
        raise RuntimeError('Invalid managed state path')
    root.mkdir(parents=True, exist_ok=True, mode=0o750)
    marker = root / f'{component}-owner'
    with (root / 'control.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if marker.is_symlink():
            raise RuntimeError('Invalid ownership marker')
        if marker.exists() and marker.read_text() != policy['owner']:
            raise RuntimeError('Host already owns a different AnyConnect instance')
        if not marker.exists():
            if not create:
                yield None
                return
            save(marker, policy['owner'])
        yield root


def sqlite(path):
    db = sqlite3.connect(path, timeout=10)
    db.row_factory = sqlite3.Row
    return db


def initialize_db(policy, group):
    path = store(policy)
    path.parent.mkdir(mode=0o770, exist_ok=True)
    if path.parent.is_symlink() or path.is_symlink():
        raise RuntimeError('Invalid accounting storage path')
    os.chown(path.parent, -1, group)
    path.parent.chmod(0o2770)
    with sqlite(path) as db:
        db.executescript("""
            CREATE TABLE IF NOT EXISTS accounts (
              id INTEGER PRIMARY KEY, username TEXT UNIQUE, hash TEXT, uploaded INTEGER,
              downloaded INTEGER, quota INTEGER, used INTEGER, expires INTEGER,
              banned INTEGER, connections INTEGER, speed INTEGER
            );
            CREATE TABLE IF NOT EXISTS sessions (
              session TEXT PRIMARY KEY, id INTEGER, uploaded INTEGER NOT NULL, downloaded INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS sessions_by_account ON sessions(id);
            CREATE TABLE IF NOT EXISTS names (username TEXT PRIMARY KEY, id INTEGER NOT NULL);
        """)
    os.chown(path, -1, group)
    path.chmod(0o660)


def validate_account(account):
    if not valid_username(account['username']) or not valid_username(account.get('login', account['username'])) or not HASH.fullmatch(account['hash']):
        raise ValueError('Invalid account identity or password hash')
    if not all(isinstance(account[field], int) and account[field] >= 0 for field in (
        'id', 'uploaded', 'downloaded', 'quota', 'connections', 'speed'
    )):
        raise ValueError('Invalid account policy')


def totals(db, account_id):
    return db.execute(
        'SELECT COALESCE(SUM(uploaded), 0), COALESCE(SUM(downloaded), 0) FROM sessions WHERE id = ?',
        (account_id,),
    ).fetchone()


def allowed(account, counts, now):
    unseen = max(0, counts[0] - account['uploaded']) + max(0, counts[1] - account['downloaded'])
    return not account['banned'] and (account['expires'] is None or account['expires'] > now) \
        and account['used'] + unseen < account['quota']


def update_accounts(policy, accounts):
    root = folder(policy['id'])
    profiles = root / 'users'
    profiles.mkdir(mode=0o700, exist_ok=True)
    for account in accounts:
        validate_account(account)
    names = {account['username']: account['id'] for account in accounts}
    for account in accounts:
        login = account.get('login', account['username'])
        if names.setdefault(login, account['id']) != account['id']:
            raise ValueError('Conflicting AnyConnect login names')
    disconnect = set()
    with sqlite(store(policy)) as db:
        db.execute('CREATE TABLE IF NOT EXISTS logins (username TEXT PRIMARY KEY, id INTEGER NOT NULL)')
        existing = {row['id']: row for row in db.execute('SELECT * FROM accounts')}
        previous_names = active_names(db)
        db.execute('DELETE FROM accounts')
        db.execute('DELETE FROM logins')
        db.executemany('INSERT INTO accounts VALUES(:id,:username,:hash,:uploaded,:downloaded,:quota,:used,:expires,:banned,:connections,:speed)', accounts)
        db.executemany('INSERT INTO logins VALUES(?,?)', names.items())
        db.executemany('INSERT INTO names VALUES(?,?) ON CONFLICT(username) DO UPDATE SET id=excluded.id', names.items())
    for account in accounts:
        username = account['username']
        login = account.get('login', username)
        data = f'max-same-clients = {account["connections"]}\n'
        if account['speed']:
            speed = account['speed'] * 125000
            data += f'rx-data-per-sec = {speed}\ntx-data-per-sec = {speed}\n'
        old = existing.get(account['id'])
        changed = save(profiles / username, data)
        if login != username:
            save(profiles / login, data)
        if old and (changed or old['username'] != username or old['hash'] != account['hash']):
            disconnect.update(name for name, owner in previous_names.items() if owner == account['id'])
    for path in profiles.iterdir():
        if path.name not in names and (valid_username(path.name) or path.name.startswith('.speedbox-')):
            path.unlink()
    disconnect.update(name for name, owner in previous_names.items() if names.get(name) != owner)
    return disconnect


def active_names(db):
    if db.execute("SELECT 1 FROM sqlite_master WHERE name='logins'").fetchone():
        return dict(db.execute('SELECT username,id FROM logins'))
    return dict(db.execute('SELECT username,id FROM accounts'))


def radius_request(instance, action, env):
    username = env.get('Tmp-String-0' if action == 'authenticate' else 'User-Name', '')
    if not valid_username(username):
        return 1 if action == 'authenticate' else 0
    with sqlite(store({'id': instance})) as db:
        if action == 'authenticate':
            if db.execute("SELECT 1 FROM sqlite_master WHERE name='logins'").fetchone():
                row = db.execute('SELECT a.* FROM logins l JOIN accounts a ON a.id=l.id WHERE l.username=?', (username,)).fetchone()
            else:
                row = db.execute('SELECT * FROM accounts WHERE username = ?', (username,)).fetchone()
            if not row or not allowed(row, totals(db, row['id']), int(time.time())):
                return 1
            print(f'Crypt-Password := "{row["hash"]}"')
            return 0

        session_id = env.get('Acct-Session-Id', '')
        if not session_id or len(session_id) > 200:
            return 0
        current = db.execute('SELECT id FROM sessions WHERE session = ?', (session_id,)).fetchone()
        owner = current or db.execute('SELECT id FROM names WHERE username = ?', (username,)).fetchone()
        if owner:
            def counter(prefix):
                return int(env.get(prefix + '-Octets', '0')) + int(env.get(prefix + '-Gigawords', '0')) * 2**32
            db.execute("""
                INSERT INTO sessions VALUES (?, ?, ?, ?)
                ON CONFLICT(session) DO UPDATE SET uploaded=MAX(uploaded,excluded.uploaded),
                  downloaded=MAX(downloaded,excluded.downloaded)
            """, (session_id, owner['id'], counter('Acct-Input'), counter('Acct-Output')))
    return 0


def occtl(policy, *args, check=True):
    return run('occtl', '--socket-file', f'/run/{unit(policy, "vpn")}/occtl.sock', *args, check=check)


def online(policy):
    result = json.loads(occtl(policy, '--json', 'show', 'users') or '[]')
    if not isinstance(result, list):
        raise RuntimeError('Unexpected occtl session response')
    return collections.Counter(
        row.get('Username') for row in result
        if isinstance(row, dict) and row.get('State') == 'connected'
    )


def account_sessions(sessions, names):
    counts = collections.Counter()
    for name, count in sessions.items():
        if name in names:
            counts[names[name]] += count
    return counts


def enforce(policy, force_disconnect=()):
    sessions = online(policy)
    with sqlite(store(policy)) as db:
        accounts = {row['id']: row for row in db.execute('SELECT * FROM accounts')}
        names = active_names(db)
        counts = account_sessions(sessions, names)
        now = int(time.time())
        consumption = {id: totals(db, id) for id in accounts}
        for username in sessions:
            if not valid_username(username):
                continue
            account = accounts.get(names.get(username))
            if (not account or username in force_disconnect or not allowed(account, consumption[account['id']], now)
                    or (account['connections'] > 0 and counts[account['id']] > account['connections'])):
                occtl(policy, 'disconnect', 'user', username, check=False)
                sessions[username] = 0
        counts = account_sessions(sessions, names)
        return {
            id: dict(zip(('uploaded', 'downloaded', 'online'), (*consumption[id], counts[id])))
            for id in accounts
        }


def install_packages(packages):
    needed = [name for binary, name in packages.items() if not shutil.which(binary)]
    if needed:
        run('apt-get', 'update', '-qq', deadline=120)
        run('apt-get', 'install', '-y', *needed, deadline=180)


def free_radius_ports(root):
    path = root / 'radius-ports.json'
    if path.exists():
        return json.loads(path.read_text())
    with contextlib.ExitStack() as stack:
        sockets = [stack.enter_context(socket.socket(socket.AF_INET, socket.SOCK_DGRAM)) for _ in range(2)]
        for sock in sockets:
            sock.bind(('127.0.0.1', 0))
        ports = [sock.getsockname()[1] for sock in sockets]
        save(path, json.dumps(ports))
        return ports


def write_radius(policy, root, group):
    radius_root = root / 'radius'
    stock = pathlib.Path('/etc/freeradius/3.0')
    if not stock.is_dir():
        raise RuntimeError('FreeRADIUS 3 configuration directory not found')
    if not radius_root.exists():
        shutil.copytree(stock, radius_root, symlinks=True)
        for enabled in ('sites-enabled', 'mods-enabled'):
            path = radius_root / enabled
            shutil.rmtree(path)
            path.mkdir()
    for path in (radius_root, radius_root / 'mods-enabled', radius_root / 'sites-enabled'):
        os.chown(path, -1, group)
        path.chmod(0o750)

    auth_port, acct_port = free_radius_ports(root)
    changed = save(radius_root / 'clients.conf',
                   'client speedbox_ocserv {\n ipaddr = 127.0.0.1\n secret = ' + policy['radius_secret'] + '\n}\n', 0o640, group)
    config = (stock / 'radiusd.conf').read_text()
    config = re.sub(r'(?m)^run_dir\s*=.*$', f'run_dir = /run/{unit(policy, "radius")}', config)
    config = re.sub(r'(?m)^pidfile\s*=.*$', f'pidfile = /run/{unit(policy, "radius")}/radius.pid', config)
    changed |= save(radius_root / 'radiusd.conf', config, 0o640, group)

    for module in ('pap', 'always'):
        link = radius_root / 'mods-enabled' / module
        if not link.is_symlink():
            link.symlink_to('../mods-available/' + module)
            changed = True
    modules = f"""
exec sb_auth {{
    wait = yes
    input_pairs = control
    output_pairs = control
    shell_escape = no
    program = "/usr/bin/python3 {SELF} authenticate {policy['id']}"
}}
exec sb_accounting {{
    wait = yes
    input_pairs = request
    shell_escape = no
    program = "/usr/bin/python3 {SELF} accounting {policy['id']}"
}}
"""
    changed |= save(radius_root / 'mods-enabled' / 'speedbox', modules, 0o640, group)
    site = f"""
server speedbox_local {{
    listen {{
        ipaddr = 127.0.0.1
        port = {auth_port}
        type = auth
    }}
    listen {{
        ipaddr = 127.0.0.1
        port = {acct_port}
        type = acct
    }}
    authorize {{
        update control {{
            Tmp-String-0 := &User-Name
        }}
        sb_auth
        pap
    }}
    authenticate {{
        Auth-Type PAP {{
            pap
        }}
    }}
    accounting {{
        sb_accounting
        if (fail) {{
            do_not_respond
        }}
    }}
}}
"""
    changed |= save(radius_root / 'sites-enabled' / 'speedbox', site, 0o640, group)
    radiusclient = (
        f'authserver 127.0.0.1:{auth_port}\nacctserver 127.0.0.1:{acct_port}\n'
        f'servers {root}/radius-servers\ndictionary /etc/radcli/dictionary\n'
        'radius_timeout 3\nradius_retries 2\n'
    )
    save(root / 'radiusclient.conf', radiusclient)
    save(root / 'radius-servers', f'127.0.0.1 {policy["radius_secret"]}\n')
    return changed


def validate_certificate(cert, key, domain):
    if not cert.is_file() or not key.is_file() or cert.stat().st_size > 131072 or key.stat().st_size > 65536:
        raise ValueError('فایل‌های گواهی TLS روی سرور خروجی پیدا نشدند یا معتبر نیستند.')
    try:
        info = ssl._ssl._test_decode_cert(str(cert))
        names = [name.lower() for kind, name in info.get('subjectAltName', []) if kind == 'DNS']
        if not any(name == domain or name.startswith('*.') and domain.partition('.')[2] == name[2:] for name in names):
            raise ValueError('گواهی TLS دامنهٔ ورودی را پوشش نمی‌دهد.')
        now = time.time()
        if not ssl.cert_time_to_seconds(info['notBefore']) <= now < ssl.cert_time_to_seconds(info['notAfter']):
            raise ValueError('گواهی TLS منقضی شده یا هنوز معتبر نیست.')
        public = run('openssl', 'x509', '-in', str(cert), '-pubkey', '-noout')
        if public != run('openssl', 'pkey', '-in', str(key), '-passin', 'pass:', '-pubout'):
            raise ValueError('گواهی TLS و کلید خصوصی متناظر نیستند.')
    except (ssl.SSLError, RuntimeError) as error:
        raise ValueError('خواندن گواهی TLS یا کلید خصوصی آن ناموفق بود.') from error


def select_certificate(root, target):
    current = root / 'certificate-current'
    temporary = root / '.certificate-next'
    temporary.unlink(missing_ok=True)
    temporary.symlink_to(target)
    temporary.replace(current)


def http_acme_failure(output):
    problems = {
        'addressInUse': 'پورت چالش روی سرور صادرکننده اشغال است.',
        'permissionDenied': 'ابزار صدور اجازهٔ باز کردن پورت چالش را ندارد.',
        'connectionTimeout': 'مهلت اتصال ابزار به Let’s Encrypt به پایان رسید.',
        'accountDoesNotExist': 'حساب ثبت‌شدهٔ ACME در Let’s Encrypt پیدا نشد.',
        'badNonce': 'Let’s Encrypt شناسهٔ موقت درخواست ACME را نپذیرفت.',
        'caa': 'رکورد CAA دامنه اجازهٔ صدور نداده یا بررسی آن ناموفق بوده است.',
        'connection': 'Let’s Encrypt نتوانست به مسیر چالش دامنه وصل شود.',
        'dns': 'Let’s Encrypt هنگام بررسی DNS دامنه خطا گرفت.',
        'incorrectResponse': 'Let’s Encrypt از مسیر چالش پاسخ مورد انتظار را نگرفت.',
        'invalidContact': 'Let’s Encrypt ایمیل حساب ACME را نپذیرفت.',
        'malformed': 'Let’s Encrypt درخواست صدور ACME را نامعتبر اعلام کرد.',
        'rateLimited': 'Let’s Encrypt برای این درخواست محدودیت تعداد اعمال کرده است.',
        'rejectedIdentifier': 'Let’s Encrypt نام دامنه را برای صدور نپذیرفت.',
        'serverInternal': 'Let’s Encrypt در پردازش درخواست خطای داخلی داد.',
        'unauthorized': 'Let’s Encrypt تأیید مالکیت دامنه را نپذیرفت.',
    }
    try:
        reply = json.loads(output)
    except ValueError:
        reply = {}
    if not isinstance(reply, dict):
        reply = {}
    stages = {
        'challenge setup': 'آماده‌سازی چالش',
        'account registration': 'ثبت حساب',
        'certificate order': 'ثبت سفارش یا تأیید دامنه',
    }
    stage = stages.get(str(reply.get('stage')))
    code = str(reply.get('code'))
    status = reply.get('status')
    heading = f'صدور HTTP-01 ناموفق بود. مرحله: {stage}.' if stage else 'صدور HTTP-01 ناموفق بود.'
    if code in problems:
        return f'{heading} {problems[code]} ({code})'
    if type(status) is int and 400 <= status <= 599:
        return f'{heading} پاسخ Let’s Encrypt برابر HTTP {status} بود.'
    diagnostic = reply.get('diagnostic')
    if isinstance(diagnostic, str) and diagnostic.strip():
        return f'{heading} {diagnostic[:365]}'
    return f'{heading} ابزار صدور کد خطای مشخصی گزارش نکرد.'


def issue_acme(domain, cache, config):
    mode = config['mode']
    cache.mkdir(mode=0o700, exist_ok=True)
    request = {'state_dir': str(cache), 'domain': domain, 'cert_mode': mode, 'email': config['email']}
    if mode == 'dns':
        request.update(dns_provider=config['dns_provider'], dns_env=config['dns_env'])
        lock = contextlib.nullcontext()
    else:
        port = config.get('http_port', 80)
        if type(port) is not int or not 1 <= port <= 65535:
            raise ValueError('پورت چالش HTTP معتبر نیست.')
        request['http_port'] = port
        lock = (BASE / '.http-acme.lock').open('a')
    with lock as handle:
        if handle is not None:
            try:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise RuntimeError('صدور گواهی HTTP-01 دیگری روی همین سرور در جریان است.') from None
        result = subprocess.run([str(ACME_HELPER)], input=json.dumps(request), capture_output=True, text=True, timeout=420)
    if result.returncode:
        if mode == 'http':
            if shutil.which('ss'):
                check_port(port, 't')
            raise RuntimeError(http_acme_failure(result.stdout))
        raise RuntimeError('صدور یا تمدید گواهی TLS ناموفق بود. دسترسی DNS و تنظیمات دامنه را بررسی کنید.')
    cert, key = cache / 'fullchain.pem', cache / 'privkey.pem'
    validate_certificate(cert, key, domain)
    return cert.read_text(), key.read_text()


def prepare_certificate(policy, root, config):
    mode = config['mode']
    domain = policy['endpoint']
    previous = root / 'certificate-current'
    previous_target = previous.readlink() if previous.is_symlink() else None
    if mode == 'existing':
        target = LE_CERTIFICATES / domain
        validate_certificate(target / 'fullchain.pem', target / 'privkey.pem', domain)
    else:
        if mode in ('dns', 'http'):
            cache = root / ('certificate-acme' if mode == 'dns' else 'certificate-acme-http')
            cert, key = issue_acme(domain, cache, config)
        elif mode == 'content':
            cert, key = config['cert_content'], config['key_content']
        else:
            raise ValueError('روش گواهی TLS معتبر نیست.')

        # Switch the pair as one unit only after both files have been validated.
        bundle = root / 'certificate-material'
        bundle.mkdir(mode=0o700, exist_ok=True)
        target = bundle / hashlib.sha256((cert.strip() + '\0' + key.strip()).encode()).hexdigest()
        with tempfile.TemporaryDirectory(dir=bundle) as name:
            staging = pathlib.Path(name)
            save(staging / 'fullchain.pem', cert.strip() + '\n')
            save(staging / 'privkey.pem', key.strip() + '\n')
            validate_certificate(staging / 'fullchain.pem', staging / 'privkey.pem', domain)
            if not target.exists():
                os.rename(staging, target)

    if target == previous_target:
        return
    select_certificate(root, target)
    vpn_name = unit(policy, 'vpn')
    try:
        if run('systemctl', 'is-active', vpn_name, check=False) == 'active':
            run('systemctl', 'kill', '--kill-who=main', '--signal=HUP', vpn_name)
    except (RuntimeError, subprocess.SubprocessError):
        if previous_target is None:
            previous.unlink(missing_ok=True)
        else:
            select_certificate(root, previous_target)
        raise RuntimeError('اعمال گواهی TLS روی سرویس فعال ناموفق بود.')
    bundle = root / 'certificate-material'
    if bundle.is_dir():
        for old in bundle.iterdir():
            # Keep the previous pair until the next successful switch.
            if old not in (target, previous_target):
                shutil.rmtree(old, ignore_errors=True)


def certificate(policy, root):
    current = root / 'certificate-current'
    existing = LE_CERTIFICATES / policy['endpoint']
    location = current if current.is_symlink() and current.readlink() != existing else existing
    cert, key = location / 'fullchain.pem', location / 'privkey.pem'
    validate_certificate(cert, key, policy['endpoint'])
    return cert, key


def wire_addresses(policy):
    net = private_network(policy['link_network'], 30)
    return str(net[1]), str(net[2])


def vpn_config(policy, root):
    cert, key = certificate(policy, root)
    net = private_network(policy['network'], 24)
    options = {
        'listen-host': wire_addresses(policy)[1] if policy['tunneled'] else '0.0.0.0',
        'tcp-port': policy['listen_port'], 'udp-port': policy['listen_port'],
        'run-as-user': 'nobody', 'run-as-group': 'nogroup',
        'socket-file': f'/run/{unit(policy, "vpn")}/ipc',
        'occtl-socket-file': f'/run/{unit(policy, "vpn")}/occtl.sock',
        'server-cert': cert, 'server-key': key, 'use-occtl': 'true',
        'device': f'acv{policy["id"]}', 'ipv4-network': net.network_address, 'ipv4-netmask': net.netmask,
        'route': 'default', 'default-domain': policy['endpoint'], 'mtu': policy['mtu'],
        'config-per-user': root / 'users', 'stats-report-time': 15,
        'max-clients': 240, 'max-same-clients': 0, 'auth-timeout': 40,
        'cookie-timeout': 120, 'keepalive': 30, 'dpd': 40, 'mobile-dpd': 60,
        'rekey-time': 86400, 'rekey-method': 'ssl',
        'auth': f'"radius[config={root}/radiusclient.conf]"',
        'acct': f'"radius[config={root}/radiusclient.conf]"',
    }
    return ''.join(f'{name} = {value}\n' for name, value in options.items()) + ''.join(
        f'dns = {server}\n' for server in policy['dns']
    )


def fw(table, chain, rule, on):
    prefix = ('iptables', '-w', '-t', table)
    result = subprocess.run([*prefix, '-C', chain, *rule], capture_output=True)
    if on and result.returncode != 0:
        run(*prefix, '-I', chain, '1', *rule)
    elif not on and result.returncode == 0:
        run(*prefix, '-D', chain, *rule)


def vpn_rules(policy, on):
    pool = policy['network']
    tag = ('-m', 'comment', '--comment', unit(policy, 'vpn'))
    listen = ['-d', wire_addresses(policy)[1]] if policy['tunneled'] else []
    for proto in ('tcp', 'udp'):
        fw('filter', 'INPUT', [*listen, '-p', proto, '--dport', str(policy['listen_port']), *tag, '-j', 'ACCEPT'], on)
    iface = f'acv{policy["id"]}+'
    fw('filter', 'FORWARD', ['-i', iface, '-s', pool, *tag, '-j', 'ACCEPT'], on)
    fw('filter', 'FORWARD', ['-o', iface, '-d', pool, '-m', 'conntrack', '--ctstate', 'RELATED,ESTABLISHED', *tag, '-j', 'ACCEPT'], on)
    fw('nat', 'POSTROUTING', ['-s', pool, '!', '-d', pool, *tag, '-j', 'MASQUERADE'], on)


def write_unit(name, body):
    return save(UNITS / f'{name}.service', body, 0o644)


def check_port(port, switches, own_pids=(), kernel_owner=False):
    listeners = run('ss', '-Hlnp' + switches, 'sport', '=', f':{port}')
    for line in listeners.splitlines():
        pids = set(re.findall(r'pid=(\d+)', line))
        if pids.intersection(own_pids):
            continue
        if kernel_owner and not pids:
            continue
        raise RuntimeError(f'پورت {port} روی سرور اشغال است.')


def check_network(network, owned_interfaces):
    for route in json.loads(run('ip', '-j', '-4', 'route', 'show', 'table', 'all')):
        dest = route.get('dst', 'default')
        if dest == 'default' or owned_interfaces.fullmatch(route.get('dev', '')):
            continue
        candidate = ipaddress.ip_network(dest, strict=False)
        if candidate.prefixlen and candidate.overlaps(network):
            raise RuntimeError(f'شبکهٔ {network} با مسیر موجود سرور ({candidate}) تداخل دارد.')


def install_vpn(policy, root, accounts):
    installed_ocserv = bool(shutil.which('ocserv'))
    install_packages({'ocserv': 'ocserv', 'freeradius': 'freeradius', 'iptables': 'iptables',
                      'ip': 'iproute2', 'openssl': 'openssl'})
    if not installed_ocserv:
        run('systemctl', 'disable', '--now', 'ocserv.service')
    vpn_name = unit(policy, 'vpn')
    pid = run('systemctl', 'show', vpn_name, '-p', 'MainPID', '--value', check=False)
    own_pids = {pid} if pid.isdecimal() and pid != '0' else set()
    check_port(policy['listen_port'], 'tu', own_pids)
    if not own_pids:
        check_network(private_network(policy['network'], 24), re.compile(r'(?!)'))
    radius_group = grp.getgrnam('freerad').gr_gid
    os.chown(BASE, -1, radius_group)
    BASE.chmod(0o750)
    os.chown(root, -1, radius_group)
    root.chmod(0o750)
    initialize_db(policy, radius_group)
    update_accounts(policy, accounts)
    radius_name = unit(policy, 'radius')
    radius_changed = write_radius(policy, root, radius_group)
    radius_changed |= write_unit(radius_name, f"""[Unit]
Description=SpeedBox AnyConnect local RADIUS {policy['id']}
After=network.target
[Service]
Type=simple
RuntimeDirectory={radius_name}
ExecStart=/usr/sbin/freeradius -f -d {root}/radius
Restart=on-failure
[Install]
WantedBy=multi-user.target
""")
    vpn_changed = save(root / 'ocserv.conf', vpn_config(policy, root))
    vpn_changed |= write_unit(vpn_name, f"""[Unit]
Description=SpeedBox AnyConnect {policy['id']}
After=network-online.target {radius_name}.service
Requires={radius_name}.service
[Service]
Type=simple
RuntimeDirectory={vpn_name}
ExecStartPre=/usr/bin/python3 {SELF} firewall {policy['id']} start
ExecStart=/usr/sbin/ocserv -f -c {root}/ocserv.conf
ExecStopPost=/usr/bin/python3 {SELF} firewall {policy['id']} stop
Restart=on-failure
[Install]
WantedBy=multi-user.target
""")
    enforcement = unit(policy, 'enforce')
    write_unit(enforcement, f"""[Unit]
Description=Enforce local SpeedBox AnyConnect account policies
After={vpn_name}.service
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 {SELF} enforce {policy['id']}
""")
    save(UNITS / f'{enforcement}.timer', """[Unit]
Description=Check SpeedBox AnyConnect account policies
[Timer]
OnBootSec=20s
OnUnitActiveSec=10s
AccuracySec=1s
[Install]
WantedBy=timers.target
""", 0o644)
    run('ocserv', '-t', '-c', str(root / 'ocserv.conf'))
    check = subprocess.run(['freeradius', '-XC', '-d', str(root / 'radius')],
                           text=True, capture_output=True, timeout=30)
    if check.returncode:
        details = (check.stdout + check.stderr).replace(policy['radius_secret'], '[redacted]')
        raise RuntimeError('FreeRADIUS configuration: ' + details.strip()[-1300:])
    save(root / 'vpn-policy.json', json.dumps(policy))
    run('systemctl', 'daemon-reload')
    if radius_changed:
        run('systemctl', 'restart', radius_name)
    if vpn_changed:
        run('systemctl', 'restart', vpn_name)
    run('systemctl', 'enable', '--now', radius_name, vpn_name, f'{enforcement}.timer')
    for _ in range(15):
        if pathlib.Path(f'/run/{vpn_name}/occtl.sock').exists():
            online(policy)
            return
        time.sleep(1)
    raise RuntimeError('AnyConnect daemon did not become ready')


def stop_vpn(policy, root, remove):
    if root is None:
        return
    units = [unit(policy, 'enforce') + '.timer', unit(policy, 'vpn') + '.service', unit(policy, 'radius') + '.service']
    for name in units:
        if (UNITS / name).is_file():
            run('systemctl', 'disable', '--now', name)
    if remove:
        for name in [*units, unit(policy, 'enforce') + '.service']:
            (UNITS / name).unlink(missing_ok=True)
        run('systemctl', 'daemon-reload')
        for name in ('radius', 'users', 'accounting', 'radiusclient.conf', 'radius-servers',
                     'radius-ports.json', 'ocserv.conf', 'vpn-policy.json', 'vpn-owner',
                     'certificate-current', 'certificate-acme', 'certificate-acme-http', 'certificate-material'):
            path = root / name
            if path.is_dir() and not path.is_symlink():
                shutil.rmtree(path)
            else:
                path.unlink(missing_ok=True)


def tunnel_iface(policy):
    return f'acp{policy["id"]}'


def link_rules(policy, on):
    iface = tunnel_iface(policy)
    ingress, foreign = wire_addresses(policy)
    tag = ('-m', 'comment', '--comment', iface)
    fw('filter', 'INPUT', ['-p', 'udp', '--dport', str(policy['link_port']), *tag, '-j', 'ACCEPT'], on)
    if policy['side'] != 'iran':
        return
    for proto in ('tcp', 'udp'):
        incoming = ['-p', proto, '--dport', str(policy['endpoint_port'])]
        outgoing = ['-d', foreign, '-p', proto, '--dport', str(policy['listen_port'])]
        fw('nat', 'PREROUTING', ['!', '-i', iface, '-m', 'addrtype', '--dst-type', 'LOCAL', *incoming,
                               *tag, '-j', 'DNAT', '--to-destination', f'{foreign}:{policy["listen_port"]}'], on)
        fw('nat', 'POSTROUTING', ['-o', iface, *outgoing, *tag, '-j', 'SNAT', '--to-source', ingress], on)
        fw('filter', 'FORWARD', ['-o', iface, *outgoing, *tag, '-j', 'ACCEPT'], on)
    fw('filter', 'FORWARD', ['-i', iface, '-s', foreign, '-m', 'conntrack', '--ctstate', 'ESTABLISHED,RELATED',
                             *tag, '-j', 'ACCEPT'], on)


def manage_link(policy, action):
    if policy['side'] not in ('iran', 'foreign') or not 1024 <= policy['link_port'] <= 65535:
        raise ValueError('Invalid tunnel role or port')
    for field in ('link_private', 'link_public', 'link_peer'):
        if not re.fullmatch(r'[A-Za-z0-9+/]{43}=', policy[field]):
            raise ValueError('Invalid tunnel key')
    try:
        ipaddress.IPv4Address(policy['foreign_host'])
    except ValueError:
        if not HOSTNAME.fullmatch(policy['foreign_host']):
            raise ValueError('Invalid tunnel host')
    iface = tunnel_iface(policy)
    config = pathlib.Path('/etc/wireguard') / f'{iface}.conf'
    service = f'wg-quick@{iface}'
    with owned(policy, 'link', create=action == 'install_link') as root:
        if root is None:
            return
        recorded = root / 'link-policy.json'
        if config.is_symlink() or (config.is_file() and f'PrivateKey = {policy["link_private"]}\n' not in config.read_text()):
            raise RuntimeError('Tunnel interface belongs to another service')
        if action == 'check_link':
            remote = wire_addresses(policy)[1 if policy['side'] == 'iran' else 0]
            run('ping', '-I', iface, '-c', '1', '-W', '3', remote, check=False)
            parts = run('wg', 'show', iface, 'latest-handshakes').split()
            if len(parts) != 2 or parts[0] != policy['link_peer'] or int(parts[1]) < time.time() - 180:
                raise RuntimeError('AnyConnect site tunnel handshake failed')
            return
        if action != 'install_link':
            if config.exists():
                run('systemctl', 'disable', '--now', service)
            if recorded.exists():
                link_rules(json.loads(recorded.read_text()), False)
            if action == 'remove_link':
                config.unlink(missing_ok=True)
                recorded.unlink(missing_ok=True)
                (root / 'link-owner').unlink(missing_ok=True)
            return

        install_packages({'wg': 'wireguard-tools', 'iptables': 'iptables', 'ping': 'iputils-ping', 'ss': 'iproute2'})
        own_port = config.is_file() and run('wg', 'show', iface, 'listen-port', check=False) == str(policy['link_port'])
        check_port(policy['link_port'], 'u', kernel_owner=own_port)
        if policy['side'] == 'iran':
            check_port(policy['endpoint_port'], 'tu')
        check_network(private_network(policy['link_network'], 30), re.compile(re.escape(iface)))
        config.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        ingress, foreign = wire_addresses(policy)
        local, peer = (ingress, foreign) if policy['side'] == 'iran' else (foreign, ingress)
        endpoint = f'Endpoint = {policy["foreign_host"]}:{policy["link_port"]}\n' if policy['side'] == 'iran' else ''
        if not config.exists() and run('ip', 'link', 'show', iface, check=False):
            raise RuntimeError('Tunnel interface is already occupied')
        save(recorded, json.dumps(policy))
        changes = save(config, f"""[Interface]
Address = {local}/30
ListenPort = {policy['link_port']}
PrivateKey = {policy['link_private']}
MTU = 1380
PostUp = /usr/bin/python3 {SELF} link-firewall {policy['id']} start
PostDown = /usr/bin/python3 {SELF} link-firewall {policy['id']} stop

[Peer]
PublicKey = {policy['link_peer']}
AllowedIPs = {peer}/32
{endpoint}PersistentKeepalive = 25
""")
        if changes and run('systemctl', 'is-active', service, check=False) == 'active':
            run('systemctl', 'restart', service)
        run('systemctl', 'enable', '--now', service)


def control(request):
    policy = request['policy']
    validate(policy)
    action = request['action']
    if action == 'issue_http_certificate':
        if not policy['tunneled'] or policy.get('side') != 'iran' or policy['certificate']['mode'] != 'http':
            raise ValueError('سرور ورودی برای صدور گواهی HTTP-01 معتبر نیست.')
        with owned(policy, 'http-certificate', create=True) as root:
            try:
                cert, key = issue_acme(policy['endpoint'], root / 'certificate-acme-ingress', policy['certificate'])
            except (OSError, subprocess.SubprocessError):
                raise RuntimeError('صدور گواهی HTTP-01 روی سرور ورودی کامل نشد.') from None
            return {'cert_content': cert, 'key_content': key}
    if action in ('install_link', 'check_link', 'stop_link', 'remove_link'):
        manage_link(policy, action)
        if action == 'remove_link' and policy['side'] == 'iran':
            with owned(policy, 'http-certificate') as root:
                if root:
                    cache = root / 'certificate-acme-ingress'
                    if cache.is_dir():
                        shutil.rmtree(cache)
                    (root / 'http-certificate-owner').unlink()
        return {}
    with owned(policy, 'vpn', create=action in ('install', 'prepare_certificate')) as root:
        if action in ('stop', 'remove'):
            stop_vpn(policy, root, action == 'remove')
            return {}
        if not root:
            raise RuntimeError('AnyConnect instance is not installed')
        if action == 'prepare_certificate':
            try:
                install_packages({'openssl': 'openssl'})
                prepare_certificate(policy, root, policy['certificate'])
            except (KeyError, OSError, subprocess.SubprocessError):
                raise RuntimeError('آماده‌سازی گواهی TLS روی سرور خروجی ناموفق بود.') from None
        elif action == 'install':
            install_vpn(policy, root, request['accounts'])
        elif action == 'sync':
            if vpn_config(policy, root) != (root / 'ocserv.conf').read_text():
                install_vpn(policy, root, request['accounts'])
            else:
                enforce(policy, update_accounts(policy, request['accounts']))
        elif action == 'report':
            return {'accounts': enforce(policy)}
        elif action == 'report_online':
            with sqlite(store(policy)) as db:
                return {'online': account_sessions(online(policy), active_names(db))}
        else:
            raise ValueError('Unknown AnyConnect action')
    return {}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', nargs='?', choices=['authenticate', 'accounting', 'firewall', 'link-firewall', 'enforce'])
    parser.add_argument('instance', nargs='?', type=int)
    parser.add_argument('phase', nargs='?', choices=['start', 'stop'])
    args = parser.parse_args()
    if args.action is None:
        try:
            print(json.dumps({'ok': True, 'data': control(json.load(sys.stdin))}))
        except (OSError, ValueError, RuntimeError, KeyError, sqlite3.Error, subprocess.SubprocessError) as error:
            print(json.dumps({'ok': False, 'error': str(error)}))
        return 0
    if not args.instance or args.instance <= 0:
        return 1
    if args.action in ('authenticate', 'accounting'):
        try:
            return radius_request(args.instance, args.action, os.environ)
        except (OSError, ValueError, sqlite3.Error):
            return 2
    root = folder(args.instance)
    if args.action == 'link-firewall':
        policy = json.loads((root / 'link-policy.json').read_text())
        if args.phase == 'start':
            run('sysctl', '-w', 'net.ipv4.ip_forward=1')
        link_rules(policy, args.phase == 'start')
    else:
        policy = json.loads((root / 'vpn-policy.json').read_text())
        if args.action == 'enforce':
            enforce(policy)
        else:
            if args.phase == 'start':
                run('sysctl', '-w', 'net.ipv4.ip_forward=1')
            vpn_rules(policy, args.phase == 'start')
    return 0


if __name__ == '__main__':
    sys.exit(main())
