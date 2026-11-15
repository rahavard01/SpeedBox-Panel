#!/usr/bin/env python3
"""Build a pinned upstream snapshot with the checked SpeedBox integration."""
import argparse,gzip,hashlib,io,json,os,pathlib,shutil,subprocess,tarfile,tempfile,urllib.request
from integration import apply
# Archives have no VCS checkout; this also applies to child builds in upstream tests.
os.environ.setdefault('GOFLAGS', '-buildvcs=false')
HERE=pathlib.Path(__file__).resolve().parent
ROOT=HERE.parents[1]
LOCK=json.loads((HERE/'upstream.json').read_text())
PIN=LOCK['commit']

def sha(p):
    raw=p.read_bytes()
    if p.suffix in ['.go','.py']: raw=raw.replace(b'\r\n',b'\n')
    return hashlib.sha256(raw).hexdigest()

def refresh():
    with urllib.request.urlopen('https://codeload.github.com/AminMGMT/BackPack/tar.gz/'+PIN,timeout=120) as response:
        raw=response.read()
    buf=io.BytesIO()
    with tarfile.open(fileobj=io.BytesIO(raw),mode='r:gz') as incoming,tarfile.open(fileobj=buf,mode='w') as outgoing:
        for entry in incoming.getmembers():
            parts=pathlib.PurePosixPath(entry.name).parts[1:]
            if not parts: continue
            entry.name=str(pathlib.PurePosixPath('BackPack',*parts))
            entry.uid=entry.gid=entry.mtime=0;entry.uname=entry.gname=''
            outgoing.addfile(entry,incoming.extractfile(entry) if entry.isfile() else None)
    archive=gzip.compress(buf.getvalue(),mtime=0)
    if hashlib.sha256(archive).hexdigest()!=LOCK['source_sha256']:
        raise RuntimeError('Upstream archive differs from reviewed source lock')
    (HERE/'upstream-source.tar.gz').write_bytes(archive)

def prepare(work):
    if (work/'BackPack').exists(): raise RuntimeError('Preparation directory must be empty')
    if sha(HERE/'upstream-source.tar.gz')!=LOCK['source_sha256']:
        raise RuntimeError('Pinned source archive checksum mismatch')
    with tarfile.open(HERE/'upstream-source.tar.gz') as archive: archive.extractall(work,filter='data')
    source=work/'BackPack'
    apply(source)
    files=['speedbox.go']
    files += [str(p.relative_to(source)) for p in (source/'internal/manage').glob('speedbox_*.go')]
    files += [str(p.relative_to(source)) for p in (source/'internal/tunnel/l3').glob('speedbox_*.go')]
    subprocess.run(['gofmt','-w',*files],cwd=source,check=True)
    return source

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--refresh-source',action='store_true')
    parser.add_argument('--prepare-only',type=pathlib.Path)
    args=parser.parse_args()
    if args.refresh_source: refresh()
    if args.prepare_only:
        args.prepare_only.mkdir(parents=True,exist_ok=True)
        print(prepare(args.prepare_only.resolve()))
        return
    with tempfile.TemporaryDirectory(prefix='speedbox-backpack-build-') as directory:
        work=pathlib.Path(directory);source=prepare(work)
        # Tests must include libpcap: a CGO-free build cannot run new direct PCK.
        subprocess.run(['go','test','./internal/manage','./internal/tunnel/l3',
                        './internal/client/transport','./internal/server/transport',
                        './internal/utils/handlers','./internal/utils/network',
                        './internal/web','-count=1','-timeout=30m'],cwd=source,check=True)
        manifest={'protocol':1,'upstream_commit':PIN,'upstream_version':LOCK['version'],
                  'upstream_release':LOCK['release'],'upstream_status':LOCK['status'],
                  'speed_measurement':'sustained_directional','legacy_l3':['udp','quic','pck'],
                  'static_pcap':True,'source_sha256':LOCK['source_sha256'],
                  'integration_sources':{},'archives':{}}
        for path in sorted(HERE.rglob('*')):
            if path.is_file() and path.suffix in ['.go','.py','.json']:
                manifest['integration_sources'][str(path.relative_to(HERE))]=sha(path)
        for arch in ['amd64','arm64']:
            binary=work/('backpack-linux-'+arch)
            subprocess.run(['bash','scripts/build-linux.sh',arch,str(binary)],cwd=source,check=True)
            compressed=work/(binary.name+'.gz')
            compressed.write_bytes(gzip.compress(binary.read_bytes(),mtime=0))
            manifest['archives'][arch]={'sha256':sha(compressed),'binary_sha256':sha(binary)}
        # Publish only after BOTH architectures and every check have succeeded.
        target=ROOT/'backpack-dist';target.mkdir(exist_ok=True)
        for arch in manifest['archives']:
            shutil.copy2(work/('backpack-linux-'+arch+'.gz'),target)
        (target/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
        print('BackPack integration: tests and two static Linux builds passed')

if __name__=='__main__': main()


