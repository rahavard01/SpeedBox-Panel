#!/usr/bin/env python3
import gzip,hashlib,json,pathlib
root=pathlib.Path(__file__).resolve().parents[1]
sha=lambda b:hashlib.sha256(b).hexdigest()
m=json.loads((root/'backpack-dist/manifest.json').read_text())
assert m['protocol']==1 and m['upstream_commit']=='132467bfdfb2698d74ada2992413e75bfccbfbd3'
assert sha((root/'scripts/backpack/upstream-source.tar.gz').read_bytes())==m['source_sha256']
for name,digest in m['integration_sources'].items():assert sha((root/'scripts/backpack'/name).read_bytes().replace(b'\r\n',b'\n'))==digest,name
assert set(m['archives'])=={'amd64','arm64'}
for arch,item in m['archives'].items():
    raw=(root/'backpack-dist'/f'backpack-linux-{arch}.gz').read_bytes()
    assert sha(raw)==item['sha256'],arch
    binary=gzip.decompress(raw)
    assert binary[:4]==b'\x7fELF' and sha(binary)==item['binary_sha256'],arch
print('BACKPACK ARTIFACTS OK: pinned upstream, integration source and two Linux builds')
