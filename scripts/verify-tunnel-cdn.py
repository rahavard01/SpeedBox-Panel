#!/usr/bin/env python3
"""Verify the source and both distributed certificate-helper architectures."""
import gzip,hashlib,json,pathlib
root=pathlib.Path(__file__).resolve().parents[1]
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
m=json.loads((root/'tunnel-cdn-dist/manifest.json').read_text())
assert m['protocol']==1 and m['go_version']=='1.26.10'
for name,digest in m['sources'].items():
    p=root/'scripts/tunnel-cdn'/('host.py' if name=='host.py' else 'certificate/'+name)
    # Windows checkout may use CRLF for text sources.
    assert hashlib.sha256(p.read_bytes().replace(b'\r\n',b'\n')).hexdigest()==digest,name
assert set(m['archives'])=={'amd64','arm64'}
for arch,e in m['archives'].items():
    archive=root/'tunnel-cdn-dist'/e['file'];assert sha(archive)==e['sha256'],arch
    binary=gzip.decompress(archive.read_bytes());assert binary[:4]==b'\x7fELF' and hashlib.sha256(binary).hexdigest()==e['binary_sha256'],arch
print('CDN CERTIFICATE ARTIFACTS OK: pinned source and two Linux architectures')
