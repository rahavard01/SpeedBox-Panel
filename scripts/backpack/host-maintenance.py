#!/usr/bin/env python3
"""Private SSH helper for the isolated SpeedBox engine; never opens a port.

The tuning table is derived from the bundled, pinned upstream Optimize source.
That table is covered by the upstream license shipped in this directory.
No changes are made by status. Mutations are serialized per physical host.
"""
import fcntl
import gzip
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ENGINE = Path('/opt/speedbox-backpack/backpack')
STATE = ENGINE.parent / 'installed.json'
UPDATES = ENGINE.parent / 'updates'
SYSCTL = Path('/etc/sysctl.d/zz-speedbox-backpack.conf')
LIMITS = Path('/etc/security/limits.d/99-speedbox-backpack.conf')
RESERVATIONS = ENGINE.parent / 'reserved-ports.json'
UNIT = re.compile(r'^speedbox-backpack-sb-[a-f0-9]{32}\.service$')
LIMIT_TEXT = ('# Managed by SpeedBox: BackPack open-file and process limits\n'
              '* soft nofile 1048576\n* hard nofile 1048576\n'
              'root soft nofile 1048576\nroot hard nofile 1048576\n'
              '* soft nproc 1048576\n* hard nproc 1048576\n')


def run(args, timeout=25):
    p = subprocess.run(args, capture_output=True, text=True, timeout=timeout)
    return p.returncode == 0, (p.stdout + p.stderr).strip()


def normalized(s):
    return ' '.join(str(s).split())


def digest(path):
    try:
        h = hashlib.sha256()
        with open(path, 'rb') as f:
            for b in iter(lambda: f.read(1024 * 1024), b''):
                h.update(b)
        return h.hexdigest()
    except FileNotFoundError:
        return ''


def read_json(path, fallback=None):
    try:
        return json.loads(path.read_text())
    except (FileNotFoundError, ValueError):
        return fallback


def atomic(path, content, mode=0o644):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix='.' + path.name + '-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(content.encode() if isinstance(content, str) else content)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def ports(value):
    found = set()
    for part in str(value).split(','):
        if not part.strip():
            continue
        if not re.fullmatch(r'\d+(?:-\d+)?', part.strip()):
            raise ValueError('invalid reserved port list')
        ends = [int(n) for n in part.split('-')]
        if min(ends) < 1 or max(ends) > 65535 or ends[-1] < ends[0]:
            raise ValueError('invalid reserved port range')
        found.update(range(ends[0], ends[-1] + 1))
    return found


def port_text(values):
    groups = []
    for p in sorted(set(values)):
        if groups and groups[-1][1] + 1 == p:
            groups[-1][1] = p
        else:
            groups.append([p, p])
    return ','.join(str(a) if a == b else f'{a}-{b}' for a, b in groups)


def read_sysctl(key):
    ok, out = run(['sysctl', '-n', key])
    return normalized(out) if ok else None


def reserved_ports(request):
    needed = {int(p) for p in request.get('ports', []) if 1 <= int(p) <= 65535}
    # Include other services and manual tunnels, without touching their configs.
    ok, text = run(['ss', '-H', '-lntu'])
    if not ok:
        raise RuntimeError('could not inspect listening ports')
    for line in text.splitlines():
        columns = line.split()
        if len(columns) >= 5:
            match = re.search(r':(\d+)$', columns[4])
            if match:
                needed.add(int(match[1]))
    current = ports(read_sysctl('net.ipv4.ip_local_reserved_ports') or '')
    owned = set(read_json(RESERVATIONS, {}).get('added', []))
    # Remove only ports this integration added; preserve all external reservations.
    external = current - owned
    return needed, external | needed, sorted(needed - external)


def boot_settings():
    by_name = {}
    for folder in ['/etc/sysctl.d', '/run/sysctl.d', '/usr/local/lib/sysctl.d', '/usr/lib/sysctl.d', '/lib/sysctl.d']:
        for p in sorted(Path(folder).glob('*.conf')):
            by_name.setdefault(p.name, p)
    settings = {}
    for p in [by_name[n] for n in sorted(by_name)] + [Path('/etc/sysctl.conf')]:
        try:
            for line in p.read_text().splitlines():
                line = line.strip()
                if not line or line[0] in '#;' or '=' not in line:
                    continue
                k, v = line.lstrip('-').split('=', 1)
                settings[k.strip().replace('/', '.')] = normalized(v)
        except (FileNotFoundError, PermissionError):
            pass
    return settings


def active_units():
    ok, output = run(['systemctl', 'list-units', '--all', '--type=service', '--plain', '--no-legend', 'speedbox-backpack-sb-*.service'])
    if not ok:
        raise RuntimeError('systemd is unavailable')
    names = [line.split()[0] for line in output.splitlines() if line.split() and UNIT.fullmatch(line.split()[0])]
    return [name for name in names if run(['systemctl', 'is-active', '--quiet', name])[0]]


def bridge_version():
    if not ENGINE.is_file():
        return None
    fd, name = tempfile.mkstemp(prefix='speedbox-version-')
    try:
        with os.fdopen(fd, 'w') as f:
            json.dump({'action': 'version'}, f)
        ok, out = run([str(ENGINE), 'speedbox-json', name])
        if not ok:
            return None
        answer = json.loads(out)
        return answer.get('data') if answer.get('ok') is True else None
    finally:
        os.unlink(name)


def status(request):
    wanted = request['tuning']
    persistent = boot_settings()
    rows = []
    for key, value in wanted.items():
        live = read_sysctl(key)
        rows.append({'key': key, 'wanted': value, 'current': live,
                     'ok': live == normalized(value) and persistent.get(key) == normalized(value)})
    needed, _, _ = reserved_ports(request)
    current = ports(read_sysctl('net.ipv4.ip_local_reserved_ports') or '')
    saved_ports = ports(persistent.get('net.ipv4.ip_local_reserved_ports', ''))
    reservation_ok = needed <= current and needed <= saved_ports
    limits_ok = False
    required = {('*', 'soft', 'nofile'), ('*', 'hard', 'nofile'),
                ('root', 'soft', 'nofile'), ('root', 'hard', 'nofile'),
                ('*', 'soft', 'nproc'), ('*', 'hard', 'nproc')}
    for path in [LIMITS, Path('/etc/security/limits.d/99-backpack.conf')]:
        if not path.exists():
            continue
        configured = set()
        for line in path.read_text().splitlines():
            columns = line.split('#', 1)[0].split()
            if len(columns) == 4 and columns[3].isdigit() and int(columns[3]) >= 1048576:
                configured.add(tuple(columns[:3]))
        limits_ok |= required <= configured
    binary_hash = digest(ENGINE)
    version = bridge_version()
    expected = request['expected']
    installed = read_json(STATE, {})
    running_old = False
    for name in active_units():
        ok, pid = run(['systemctl', 'show', '-p', 'MainPID', '--value', name])
        if not ok or not pid.isdigit() or int(pid) <= 0:
            raise RuntimeError('could not inspect an active engine process')
        try:
            running_old |= digest(f'/proc/{pid}/exe') != expected['binary_sha256']
        except PermissionError:
            raise RuntimeError('could not verify running engine version')
    current_version = expected['version'] if binary_hash == expected['binary_sha256'] else (
        installed.get('version') if installed.get('sha256') == binary_hash else (version or {}).get('upstream', ''))
    return {'checked_at': int(time.time()), 'optimized': all(r['ok'] for r in rows) and reservation_ok and limits_ok,
            'parameters': rows, 'reserved_ports_ok': reservation_ok, 'limits_ok': limits_ok,
            'installed': bool(binary_hash), 'version': current_version,
            'target_version': expected['version'], 'sha256': binary_hash,
            'protocol': (version or {}).get('protocol'),
            'compatible': bool(version) and version.get('protocol') == expected['protocol'],
            'up_to_date': binary_hash == expected['binary_sha256'] and not running_old,
            'restart_required': running_old}


def reserve(request, logs):
    if not SYSCTL.exists():
        return
    _, combined, added = reserved_ports(request)
    text = SYSCTL.read_text()
    text = '\n'.join(line for line in text.splitlines() if not line.startswith('net.ipv4.ip_local_reserved_ports'))
    text += '\nnet.ipv4.ip_local_reserved_ports = ' + port_text(combined) + '\n'
    atomic(SYSCTL, text)
    ok, out = run(['sysctl', '-w', 'net.ipv4.ip_local_reserved_ports=' + port_text(combined)])
    logs.append(('OK: ' if ok else 'FAILED: ') + out)
    if not ok:
        raise RuntimeError('could not reserve tunnel ports')
    atomic(RESERVATIONS, json.dumps({'added': added}), 0o600)


def optimize(request, logs):
    ok, out = run(['modprobe', 'tcp_bbr']) if shutil.which('modprobe') else (False, 'modprobe unavailable; BBR may be built in')
    if not ok:
        logs.append(out)
    rows = dict(request['tuning'])
    _, combined, added = reserved_ports(request)
    rows['net.ipv4.ip_local_reserved_ports'] = port_text(combined)
    atomic(SYSCTL, '# Managed by SpeedBox: pinned BackPack Optimize\n' + ''.join(f'{k} = {v}\n' for k, v in rows.items()))
    count = 0
    for key, value in rows.items():
        ok, out = run(['sysctl', '-w', key + '=' + value])
        count += int(ok)
        logs.append(('OK: ' if ok else 'FAILED: ') + out)
    atomic(LIMITS, LIMIT_TEXT)
    if ports(read_sysctl('net.ipv4.ip_local_reserved_ports') or '') == combined:
        atomic(RESERVATIONS, json.dumps({'added': added}), 0o600)
    logs.append(f'Applied {count}/{len(rows)} kernel parameters. File limits saved; existing services pick them up on restart/reboot.')
    logs.append('Optimization affects all services on this server. Unsupported kernel settings are listed above.')


def transaction(request):
    revision = request.get('revision', '')
    if not re.fullmatch(r'[a-f0-9]{32}', revision):
        raise ValueError('invalid update revision')
    return UPDATES / revision


def stage(request, logs):
    folder = transaction(request)
    if (folder / 'state.json').exists():
        return
    current = status(request)
    if current['installed'] and not current['compatible']:
        raise RuntimeError('installed engine is not compatible with this panel protocol')
    archive = Path(request['archive'])
    expected = request['expected']
    if digest(archive) != expected['archive_sha256']:
        raise RuntimeError('archive checksum mismatch')
    folder.mkdir(parents=True, mode=0o700, exist_ok=True)
    binary = gzip.decompress(archive.read_bytes())
    if hashlib.sha256(binary).hexdigest() != expected['binary_sha256']:
        raise RuntimeError('binary checksum mismatch')
    atomic(folder / 'next', binary, 0o755)
    # Validate the actual executable before replacing anything.
    fd, probe = tempfile.mkstemp(dir=str(folder))
    try:
        with os.fdopen(fd, 'w') as f:
            json.dump({'action': 'version'}, f)
        ok, answer = run([str(folder / 'next'), 'speedbox-json', probe])
        data = json.loads(answer) if ok else {}
        if data.get('ok') is not True or data.get('data', {}).get('protocol') != expected['protocol']:
            raise RuntimeError('new engine protocol verification failed')
    finally:
        os.unlink(probe)
    had_binary = ENGINE.exists()
    if had_binary:
        shutil.copy2(ENGINE, folder / 'previous')
    if STATE.exists():
        shutil.copy2(STATE, folder / 'previous-metadata')
    atomic(folder / 'state.json', json.dumps({'had_binary': had_binary, 'units': active_units(),
           'changed': not current['up_to_date'], 'previous_sha256': current['sha256'], 'activated': False}), 0o600)
    logs.append('Verified package and saved current engine and running-service list.')


def activate(request, logs):
    folder = transaction(request)
    saved = read_json(folder / 'state.json')
    if not saved:
        raise RuntimeError('update was not staged')
    if not saved['changed'] or saved['activated']:
        return
    if digest(folder / 'next') != request['expected']['binary_sha256']:
        raise RuntimeError('staged binary checksum mismatch')
    # Persist activation intent before any binary or service mutation.
    saved['activated'] = True
    atomic(folder / 'state.json', json.dumps(saved), 0o600)
    atomic(ENGINE, (folder / 'next').read_bytes(), 0o755)
    expected = request['expected']
    atomic(STATE, json.dumps({'version': expected['version'], 'sha256': expected['binary_sha256'],
                             'protocol': expected['protocol']}), 0o600)
    for name in saved['units']:
        ok, out = run(['systemctl', 'restart', name], 40)
        logs.append(('OK: ' if ok else 'FAILED: ') + name + ('\n' + out if out else ''))
        if not ok:
            raise RuntimeError('a previously active service did not restart')


def rollback(request, logs):
    folder = transaction(request)
    saved = read_json(folder / 'state.json')
    if not saved or not saved.get('activated'):
        return
    if saved['had_binary']:
        if digest(folder / 'previous') != saved['previous_sha256']:
            raise RuntimeError('rollback binary checksum mismatch')
        atomic(ENGINE, (folder / 'previous').read_bytes(), 0o755)
    else:
        ENGINE.unlink(missing_ok=True)
    metadata = folder / 'previous-metadata'
    if metadata.exists():
        atomic(STATE, metadata.read_bytes(), 0o600)
    else:
        STATE.unlink(missing_ok=True)
    failures = []
    for name in saved['units']:
        ok, out = run(['systemctl', 'restart', name], 40)
        logs.append(('RESTORED: ' if ok else 'RESTORE FAILED: ') + name + ('\n' + out if out else ''))
        if not ok:
            failures.append(name)
    if failures:
        raise RuntimeError('rollback needs review: ' + ', '.join(failures))
    saved['activated'] = False
    atomic(folder / 'state.json', json.dumps(saved), 0o600)


def execute(request):
    action = request['action']
    logs = []
    if action not in ['status', 'optimize', 'reserve', 'stage', 'activate', 'rollback', 'commit']:
        raise ValueError('invalid maintenance action')
    if action != 'status' and os.geteuid() != 0:
        raise RuntimeError('root privileges are required')
    ENGINE.parent.mkdir(parents=True, exist_ok=True) if action != 'status' else None
    if action == 'optimize':
        optimize(request, logs)
    elif action == 'reserve':
        reserve(request, logs)
    elif action == 'stage':
        stage(request, logs)
    elif action == 'activate':
        activate(request, logs)
    elif action == 'rollback':
        rollback(request, logs)
    elif action == 'commit':
        folder = transaction(request)
        saved = read_json(folder / 'state.json')
        if saved:
            saved['committed'] = True
            atomic(folder / 'state.json', json.dumps(saved), 0o600)
    result = {'logs': logs}
    if action in ['status', 'optimize', 'reserve', 'activate', 'rollback']:
        result['status'] = status(request)
    return result


def main():
    logs = []
    try:
        request = json.loads(Path(sys.argv[1]).read_text())
        # The queue holds database leases; flock also protects alias records on a host.
        with open('/run/lock/speedbox-backpack-maintenance.lock', 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = execute(request)
        print(json.dumps({'ok': True, 'data': result}))
    except Exception as error:
        print(json.dumps({'ok': False, 'error': str(error)}))
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
