#!/usr/bin/env python3
"""Developer release step. Package locally built agents; never run by server install/update."""
from pathlib import Path
import gzip, hashlib, json, os, struct, tarfile, tempfile

root = Path(__file__).resolve().parents[1]
output = root / 'agent-dist'
output.mkdir(exist_ok=True)
sources = sorted(p.relative_to(root).as_posix() for p in (root/'agent').rglob('*')
                 if p.is_file() and '.git' not in p.relative_to(root).parts)
sources += ['scripts/build-agent.sh', 'scripts/build-agent-from-source.sh',
            'scripts/agent-artifacts.php', 'scripts/package-agent.py']
manifest = {'format': 1, 'version': 'panel-speedbox-http-obfs-uuid-v1',
            'source_files': {}, 'archives': {}}
for name in sorted(sources):
    path = root / name
    if path.is_symlink(): raise RuntimeError('Source symlink: '+name)
    manifest['source_files'][name] = hashlib.sha256(path.read_bytes().replace(b'\r\n', b'\n')).hexdigest()
with tempfile.TemporaryDirectory(prefix='.package-', dir=output) as temporary:
    stage = Path(temporary)
    for arch, machine in [('amd64', 62), ('arm64', 183)]:
        archive = stage / ('linux-'+arch+'.tar.gz')
        binaries = {}
        with archive.open('wb') as raw, gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=0, compresslevel=6) as zipped, tarfile.open(fileobj=zipped, mode='w', format=tarfile.USTAR_FORMAT) as tar:
            for program in ['xboard-node', 'xbctl']:
                name = program+'-linux-'+arch
                path = root/'storage/app/agent'/name
                data = path.read_bytes()
                if path.is_symlink() or data[:6] != b'\x7fELF\x02\x01' or struct.unpack('<H', data[18:20])[0] != machine:
                    raise RuntimeError('Invalid Linux binary: '+name)
                binaries[name] = {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
                info = tarfile.TarInfo(name); info.size = len(data); info.mode = 0o755; info.mtime = 0
                with path.open('rb') as binary: tar.addfile(info, binary)
        manifest['archives'][arch] = {'bytes': archive.stat().st_size,
            'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'binaries': binaries}
    (stage/'manifest.json').write_text(json.dumps(manifest, indent=2, sort_keys=True)+'\n', encoding='utf-8')
    for name in ['linux-amd64.tar.gz', 'linux-arm64.tar.gz', 'manifest.json']:
        os.replace(stage/name, output/name)
print('AGENT RELEASE PACKAGED: commit agent-dist, agent source and build scripts together.')
