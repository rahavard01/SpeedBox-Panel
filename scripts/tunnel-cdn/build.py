#!/usr/bin/env python3
"""Build the pinned, standalone ACME helper for both supported architectures."""
import gzip,hashlib,json,os,pathlib,subprocess
HERE=pathlib.Path(__file__).resolve().parent
ROOT=HERE.parents[1]
SOURCE=HERE/'certificate'
OUT=ROOT/'tunnel-cdn-dist'
OUT.mkdir(exist_ok=True)
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest()
assert subprocess.check_output(['go','version'],text=True).split()[2]=='go1.26.10','Use the pinned patched Go toolchain'
subprocess.run(['go','test','./...'],cwd=SOURCE,check=True)
manifest={'protocol':1,'version':'1.0.0','go_version':'1.26.10','sources':{p.name:digest(p) for p in [SOURCE/'main.go',SOURCE/'go.mod',SOURCE/'go.sum',HERE/'host.py']},'archives':{}}
for arch in ('amd64','arm64'):
    binary=OUT/('certificate-linux-'+arch)
    subprocess.run(['go','build','-trimpath','-buildvcs=false','-ldflags=-s -w -buildid=','-o',str(binary),'.'],cwd=SOURCE,env={**os.environ,'GOOS':'linux','GOARCH':arch,'CGO_ENABLED':'0','GOTOOLCHAIN':'local'},check=True)
    archive=binary.with_suffix('.gz');archive.write_bytes(gzip.compress(binary.read_bytes(),mtime=0))
    manifest['archives'][arch]={'file':archive.name,'sha256':digest(archive),'binary_sha256':digest(binary)}
    binary.unlink()
(OUT/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
print('CDN certificate helper: two architectures built')
