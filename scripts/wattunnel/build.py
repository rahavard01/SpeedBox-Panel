#!/usr/bin/env python3
"""Build only the pinned engine; no upstream installer or host-manager scripts."""
import gzip,hashlib,io,json,os,pathlib,shutil,subprocess,tarfile,tempfile,urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[2]
HERE=pathlib.Path(__file__).resolve().parent
PIN='7c30c59a65f962ceb6763daf23952ecbc5e7de91'
VERSION='2.10.9-sb3'
def sha(p):return hashlib.sha256(p.read_bytes().replace(b'\r\n',b'\n') if p.suffix in ('.go','.py') else p.read_bytes()).hexdigest()
assert subprocess.check_output(['go','version'],text=True).split()[2]=='go1.26.10','Use the patched, pinned Go 1.26.10 toolchain'
with tempfile.TemporaryDirectory(prefix='speedbox-wat-') as td:
    work=pathlib.Path(td)
    raw=urllib.request.urlopen('https://codeload.github.com/JohnWatsoon/WatTunnel/tar.gz/'+PIN,timeout=120).read()
    source=work/'source';source.mkdir()
    with tarfile.open(fileobj=io.BytesIO(raw),mode='r:gz') as ar:
        for m in ar.getmembers():
            parts=pathlib.PurePosixPath(m.name).parts[1:]
            if not parts:continue
            # Only engine source and licenses are inputs; never execute shell managers.
            if parts[0] not in ('cmd','wat','go.mod','go.sum','LICENSE'):continue
            m.name=str(pathlib.PurePosixPath(*parts));ar.extract(m,source,filter='data')
    p=source/'cmd/wattun/udp.go';s=p.read_text()
    old='\tbuf := make([]byte, 4096)\n\tfor {\n\t\tn, err := f.conn.Read(buf)'
    assert s.count(old)==1
    s=s.replace(old,'\tfor {\n\t\tbuf, err := speedboxReadUDP(f.conn)\n\t\tn := len(buf)')
    old='\t\t\tsess.SendDatagram(id, f.port, buf[:n])\n\t\t}\n\t}\n}\n'
    assert s.count(old)==1
    s=s.replace(old,'\t\t\tsess.SendDatagram(id, f.port, buf[:n])\n\t\t}\n\t\tspeedboxReleaseUDP(buf)\n\t}\n}\n')
    s=s.replace('// 4 KB fits DNS (EDNS0 max) and QUIC/WireGuard replies; 4096 flows x 64 KB was a 256 MB-per-session\n\t// OOM vector (audit 2026-10-22). A larger UDP reply is truncated (UDP has no partial read).','// SpeedBox borrows a full-size buffer only after the socket becomes readable.')
    p.write_text(s)
    from origin_patch import apply
    apply(source)
    overlays=('udp_reply_linux.go','udp_reply_test.go','origin_linux.go','origin_test.go')
    for name in overlays:shutil.copy2(HERE/name,source/'cmd/wattun'/name)
    env={**os.environ,'CGO_ENABLED':'0','GOTOOLCHAIN':'local'}
    subprocess.run(['go','test','-race','-count=1','-timeout=90s','./cmd/wattun','-run','^TestSpeedbox'],cwd=source,env={**env,'CGO_ENABLED':'1'},check=True)
    manifest={'protocol':1,'upstream_commit':PIN,'upstream_version':'2.10.9','version':VERSION,'go_version':'1.26.10',
              'source_archive_sha256':hashlib.sha256(raw).hexdigest(),'integration_sources':{p.name:sha(p) for p in [HERE/n for n in ('build.py','origin_patch.py',*overlays)]},'archives':{}}
    out=ROOT/'wattunnel-dist';out.mkdir(exist_ok=True)
    shutil.copy2(source/'LICENSE',out/'LICENSE')
    for arch in ('amd64','arm64'):
        binary=work/('wattun-linux-'+arch)
        subprocess.run(['go','build','-trimpath','-buildvcs=false','-ldflags=-s -w -buildid=','-o',str(binary),'./cmd/wattun'],cwd=source,env={**env,'GOOS':'linux','GOARCH':arch},check=True)
        p=out/(binary.name+'.gz');p.write_bytes(gzip.compress(binary.read_bytes(),mtime=0))
        manifest['archives'][arch]={'file':p.name,'sha256':sha(p),'binary_sha256':sha(binary)}
    (out/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
    print('WatTunnel: UDP regression and patched amd64/arm64 builds passed')


