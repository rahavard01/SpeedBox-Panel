#!/usr/bin/env python3
"""Developer build of the source-identity endpoint and its target-host selftest."""
import gzip,hashlib,json,os,pathlib,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
HERE=pathlib.Path(__file__).resolve().parent
def digest(data):return hashlib.sha256(data).hexdigest()
def source_files():
    files=[p.relative_to(ROOT).as_posix() for p in HERE.iterdir() if p.is_file() and p.suffix in ('.go','.mod','.py')]
    return sorted(files+['agent/internal/origin/registry.go','agent/go.mod','scripts/verify-origin.py'])
env={**os.environ,'GOOS':'linux','CGO_ENABLED':'0'}
subprocess.run(['go','test','./...','-count=1'],cwd=HERE,check=True)
out=ROOT/'origin-dist';out.mkdir(exist_ok=True)
manifest={'format':2,'source_files':{f:digest((ROOT/f).read_bytes().replace(b'\r\n',b'\n')) for f in source_files()},'archives':{}}
with tempfile.TemporaryDirectory(prefix='speedbox-origin-build-') as d:
    for arch in ('amd64','arm64'):
        entries={}
        for name,command in [('tunnel-origin',['go','build','-buildvcs=false','-trimpath','-ldflags=-s -w']),('selftest',['go','test','-c','-trimpath','-ldflags=-s -w'])]:
            path=pathlib.Path(d)/f'{name}-{arch}'
            subprocess.run([*command,'-o',str(path),'.'],cwd=HERE,env={**env,'GOARCH':arch},check=True)
            data=path.read_bytes();compressed=gzip.compress(data,mtime=0)
            filename=f'{name}-linux-{arch}.gz';(out/filename).write_bytes(compressed)
            entries[name]={'file':filename,'sha256':digest(compressed),'binary_sha256':digest(data),'bytes':len(data)}
        manifest['archives'][arch]={**entries['tunnel-origin'],'selftest':entries['selftest']}
(out/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
print('ORIGIN ENDPOINT: two Linux architectures and target-host selftests built')
