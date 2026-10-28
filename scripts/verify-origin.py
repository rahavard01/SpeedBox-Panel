#!/usr/bin/env python3
"""Verify bundled endpoints against their exact sources and Linux architecture."""
import gzip,hashlib,json,pathlib,struct
root=pathlib.Path(__file__).resolve().parents[1]
manifest=json.loads((root/'origin-dist/manifest.json').read_text())
assert manifest['format']==2,'unsupported origin artifact format'
for name,expected in manifest['source_files'].items():
    path=root/name
    assert not path.is_symlink() and path.is_file(),f'missing origin source: {name}'
    assert hashlib.sha256(path.read_bytes().replace(b'\r\n',b'\n')).hexdigest()==expected,f'origin source mismatch: {name}'
for arch,machine in (('amd64',62),('arm64',183)):
    entry=manifest['archives'][arch]
    for info in (entry,entry['selftest']):
        path=root/'origin-dist'/info['file'];assert not path.is_symlink() and path.is_file(),'missing origin artifact'
        data=path.read_bytes();assert hashlib.sha256(data).hexdigest()==info['sha256'],'origin archive mismatch'
        binary=gzip.decompress(data);assert hashlib.sha256(binary).hexdigest()==info['binary_sha256'],'origin binary mismatch'
        assert len(binary)==info['bytes'] and binary[:6]==b'\x7fELF\x02\x01' and struct.unpack('<H',binary[18:20])[0]==machine,'incorrect origin ELF'
print('ORIGIN ARTIFACTS VERIFIED: exact sources, endpoint and selftest for amd64/arm64')
