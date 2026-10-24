#!/usr/bin/env python3
import gzip,hashlib,json,pathlib
r=pathlib.Path(__file__).resolve().parents[1];m=json.loads((r/'wattunnel-dist/manifest.json').read_text())
assert m['protocol']==1 and m['upstream_commit']=='7c30c59a65f962ceb6763daf23952ecbc5e7de91'
assert m['version']=='2.10.9-sb3' and m['go_version']=='1.26.10'
sha=lambda b:hashlib.sha256(b).hexdigest()
assert set(m['integration_sources'])=={'build.py','udp_reply_linux.go','udp_reply_test.go','origin_linux.go','origin_test.go','origin_patch.py'}
for name,digest in m['integration_sources'].items():assert sha((r/'scripts/wattunnel'/name).read_bytes().replace(b'\r\n',b'\n'))==digest,name
assert set(m['archives'])=={'amd64','arm64'}
for arch,e in m['archives'].items():
    b=(r/'wattunnel-dist'/e['file']).read_bytes();assert sha(b)==e['sha256'],arch
    raw=gzip.decompress(b);assert raw[:4]==b'\x7fELF' and sha(raw)==e['binary_sha256'],arch
print('WATTUNNEL ARTIFACTS OK: pinned source, patched Go, UDP overlay and two architectures')


