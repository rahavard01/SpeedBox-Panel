#!/usr/bin/env python3
"""Run the real Agent suite on Linux without replacing deployed binaries.

Requires a normal local IPv4 and Unix-domain sockets. Reuse a compatible
installed Go SDK. A temporary download requires the explicit --download-go flag.
The database, queues, system services and installed agent are never used.
"""
import argparse
import gzip
import hashlib
import http.client
import json
import os
from pathlib import Path
import platform
import re
import shutil
import subprocess
import tarfile
import tempfile
import urllib.request
import urllib.error

VERSION = '1.26.10'
OFFICIAL_DOWNLOADS = ('https://go.dev/dl/', 'https://golang.google.cn/dl/')


def minimum_go(root):
    directive = re.search(r'^go\s+(\d+)\.(\d+)(?:\.(\d+))?\s*$',
                          (root / 'agent/go.mod').read_text(), re.MULTILINE)
    if not directive:
        raise RuntimeError('The Agent go.mod has no supported Go version directive.')
    return tuple(int(part or 0) for part in directive.groups())


def go_candidates(root, env):
    if env.get('GO_BIN'):
        return [env['GO_BIN']]
    candidates = [root / 'storage/app/tools/go/bin/go', shutil.which('go', path=env.get('PATH'))]
    if env.get('GOROOT'):
        candidates.append(Path(env['GOROOT']) / 'bin/go')
    candidates.extend([Path('/usr/local/go/bin/go'), Path('/usr/bin/go'), Path('/opt/go/bin/go'),
                       Path('/www/server/go/bin/go')])
    candidates.extend(sorted(Path('/usr/lib').glob('go-*/bin/go'), reverse=True))
    candidates.extend(sorted((Path.home() / 'sdk').glob('go*/bin/go'), reverse=True))
    caches = [Path(env['GOMODCACHE'])] if env.get('GOMODCACHE') else []
    caches.extend(Path(base) / 'pkg/mod' for base in env.get('GOPATH', str(Path.home() / 'go')).split(os.pathsep) if base)
    for cache in caches:
        candidates.extend(sorted(cache.glob('golang.org/toolchain@*/bin/go'), reverse=True))
    return [candidate for candidate in candidates if candidate]


def installed_go(root, env):
    required = minimum_go(root)
    checked, seen = [], set()
    probe_env = dict(env, GOTOOLCHAIN='local')
    # Each selected SDK locates its own standard library. A stale GOROOT from
    # another installation must not mix compiler and standard-library versions.
    probe_env.pop('GOROOT', None)
    for candidate in go_candidates(root, env):
        executable = Path(shutil.which(str(candidate), path=env.get('PATH')) or candidate).expanduser().resolve()
        if executable in seen:
            continue
        seen.add(executable)
        if not executable.is_file() or not os.access(executable, os.X_OK):
            checked.append(f'{executable}: not executable or not found')
            continue
        try:
            result = subprocess.run([str(executable), 'version'], cwd=root, env=probe_env,
                                    text=True, capture_output=True, timeout=15)
        except (OSError, subprocess.TimeoutExpired) as error:
            checked.append(f'{executable}: {error}')
            continue
        version = result.stdout.strip()
        match = re.fullmatch(r'go version go(\d+)\.(\d+)(?:\.(\d+))?\s+\S+', version)
        found = tuple(int(part or 0) for part in match.groups()) if match else None
        checked.append(f'{executable}: {version or result.stderr.strip() or "version check failed"}')
        if result.returncode == 0 and found is not None and found >= required:
            return str(executable), version, checked
    return None, '', checked


def prepare_go(temp, arch):
    filename = f'go{VERSION}.linux-{arch}.tar.gz'
    failures = []
    for base in OFFICIAL_DOWNLOADS:
        try:
            with urllib.request.urlopen(base + '?mode=json&include=all', timeout=45) as response:
                releases = json.load(response)
            release = next(row for row in releases if row['version'] == 'go' + VERSION)
            artifact = next(row for row in release['files'] if row['filename'] == filename)
            break
        except (urllib.error.URLError, TimeoutError, http.client.HTTPException) as error:
            failures.append(f'{base}metadata: {error}')
    else:
        raise RuntimeError('Could not read official Go release metadata: ' + '; '.join(failures))
    archive = temp / filename
    for base in OFFICIAL_DOWNLOADS:
        url = base + filename
        digest = hashlib.sha256()
        try:
            with urllib.request.urlopen(url, timeout=120) as response, archive.open('wb') as output:
                while chunk := response.read(1024 * 1024):
                    digest.update(chunk)
                    output.write(chunk)
        except (urllib.error.URLError, TimeoutError, http.client.HTTPException) as error:
            archive.unlink(missing_ok=True)
            failures.append(f'{url}: {error}')
            print(f'Go download unavailable at {base}: {error}; trying the next official endpoint.', flush=True)
            continue
        # A transport failure may use another official endpoint. An integrity
        # failure stops immediately and never executes or extracts the file.
        if digest.hexdigest() != artifact['sha256'] or archive.stat().st_size != artifact['size']:
            archive.unlink(missing_ok=True)
            raise RuntimeError('Official Go archive verification failed: ' + url)
        print('Official Go archive SHA256 verified: ' + base, flush=True)
        break
    else:
        raise RuntimeError('Could not download Go from the official endpoints: ' + '; '.join(failures))
    with tarfile.open(archive) as source:
        for member in source.getmembers():
            name = Path(member.name)
            if name.is_absolute() or '..' in name.parts or not (member.isfile() or member.isdir()):
                raise RuntimeError('Unsafe Go archive entry.')
        source.extractall(temp, **({'filter': 'data'} if hasattr(tarfile, 'data_filter') else {}))
    for executable in [temp / 'go/bin/go', temp / 'go/bin/gofmt', *(temp / f'go/pkg/tool/linux_{arch}').glob('*')]:
        executable.chmod(0o755)
    return str(temp / 'go/bin/go')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path.cwd())
    parser.add_argument('--check-go', action='store_true', help='Show installed SDK selection and exit without running tests.')
    parser.add_argument('--download-go', action='store_true', help='Allow a verified temporary SDK download only if no installed SDK is compatible.')
    args = parser.parse_args()
    root = args.root.resolve()
    if not (root / 'agent/go.mod').is_file():
        raise SystemExit('Run from the panel checkout or provide --root.')
    if platform.system() != 'Linux':
        raise SystemExit('The release runtime suite requires Linux.')
    arch = {'x86_64': 'amd64', 'aarch64': 'arm64'}.get(platform.machine())
    if not arch:
        raise SystemExit('Unsupported release-test host architecture.')
    env = dict(os.environ, GOTOOLCHAIN='local')
    go, version, checked = installed_go(root, env)
    env.pop('GOROOT', None)
    if args.check_go:
        print('\n'.join(checked), flush=True)
        if not go:
            raise SystemExit('No compatible installed Go SDK found. No SDK was downloaded.')
        print('SELECTED INSTALLED GO: ' + go, flush=True)
        return
    if not go and not args.download_go:
        print('\n'.join(checked), flush=True)
        parser.error('No compatible installed Go SDK found. Set GO_BIN to its executable path. Automatic SDK download is disabled.')
    if go:
        print('Using installed Go: ' + go + ' (' + version + ')', flush=True)
    subprocess.run(['python3', str(root / 'scripts/verify-wattunnel.py')], check=True)
    with tempfile.TemporaryDirectory(prefix='speedbox-agent-release-') as directory:
        temp = Path(directory)
        if not go:
            print(f'Preparing temporary Go {VERSION}; no system installation.', flush=True)
            go = prepare_go(temp, arch)
        wattun = temp / 'wattun'
        with gzip.open(root / f'wattunnel-dist/wattun-linux-{arch}.gz', 'rb') as source, wattun.open('wb') as output:
            shutil.copyfileobj(source, output)
        wattun.chmod(0o700)
        env.update(SPEEDBOX_TEST_WATTUN=str(wattun), GOMAXPROCS=os.environ.get('GOMAXPROCS', '2'))
        print(subprocess.check_output([go, 'version'], env=env, text=True).strip(), flush=True)
        print('Running real Agent tests; first compilation may take several minutes.', flush=True)
        result = subprocess.run([go, 'test', '-mod=readonly', '-p', '2', '-count=1', '-timeout', '5m', './internal/...'],
                                cwd=root / 'agent', env=env)
        if result.returncode:
            raise SystemExit(result.returncode)
        print('AGENT RELEASE RUNTIME VERIFIED', flush=True)


if __name__ == '__main__':
    main()
