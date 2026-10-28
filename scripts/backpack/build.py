#!/usr/bin/env python3
"""Reproducible integration build; source is pinned, archived and kept with the patch."""
import gzip,hashlib,io,json,os,pathlib,shutil,subprocess,sys,tarfile,tempfile,urllib.request
from l3_confirm import apply as confirm_l3
ROOT=pathlib.Path(__file__).resolve().parents[2]
PIN='ff86354bcafbd054e7888e48343145323c9b2207'
HERE=pathlib.Path(__file__).resolve().parent
if '--refresh-source' in sys.argv:
    # The release's immutable commit, never a floating development branch.
    raw=urllib.request.urlopen('https://codeload.github.com/AminMGMT/BackPack/tar.gz/'+PIN,timeout=120).read()
    buf=io.BytesIO()
    with tarfile.open(fileobj=io.BytesIO(raw),mode='r:gz') as incoming,tarfile.open(fileobj=buf,mode='w') as outgoing:
        for entry in incoming.getmembers():
            parts=pathlib.PurePosixPath(entry.name).parts[1:]
            if not parts:continue
            entry.name=str(pathlib.PurePosixPath('BackPack',*parts));entry.uid=entry.gid=entry.mtime=0;entry.uname=entry.gname=''
            outgoing.addfile(entry,incoming.extractfile(entry) if entry.isfile() else None)
    (HERE/'upstream-source.tar.gz').write_bytes(gzip.compress(buf.getvalue(),mtime=0))
def sha(p):
    raw=p.read_bytes()
    if p.suffix in ['.go','.py']:raw=raw.replace(b'\r\n',b'\n')
    return hashlib.sha256(raw).hexdigest()
with tempfile.TemporaryDirectory(prefix='speedbox-backpack-build-') as work:
    work=pathlib.Path(work)
    with tarfile.open(HERE/'upstream-source.tar.gz') as ar:
        ar.extractall(work,filter='data')
    source=work/'BackPack'
    confirm_l3(source)
    shutil.copy2(HERE/'l3_confirm_test.go',source/'internal/tunnel/l3/speedbox_confirm_test.go')
    for name in ['l3_keepalive.go','l3_keepalive_test.go']:
        shutil.copy2(HERE/name,source/'internal/tunnel/l3'/('speedbox_'+name))
    p=source/'internal/app/app.go';s=p.read_text()
    for old,new in [('"/root/BackPack"','"/opt/speedbox-backpack"'),('"/etc/backpack"','"/etc/speedbox-backpack"'),('"backpack-"','"speedbox-backpack-"'),('"/usr/local/bin/backpack"','"/opt/speedbox-backpack/backpack"')]:
        assert old in s,old;s=s.replace(old,new)
    p.write_text(s)
    p=source/'internal/optimize/optimize.go';s=p.read_text();assert 'Apply(func(string) {}, reserve)' in s
    p.write_text(s.replace('Apply(func(string) {}, reserve)','// SpeedBox leaves global kernel tuning to the administrator.'))
    p=source/'cmd/optimization.go';s=p.read_text();assert 'optimize.EngineStartupTuning()' in s
    p.write_text(s.replace('optimize.EngineStartupTuning()', '[][2]string{}').replace('\"github.com/backpack/backpack/internal/optimize\"','_ \"github.com/backpack/backpack/internal/optimize\"'))
    p=source/'internal/manage/conntest.go';s=p.read_text()
    # Bound setup attempts, keeping the full upstream 60-sample stability test
    # and the verified 1 MiB round-trip throughput measurement unchanged.
    assert 'connTestConnectWait = 60 * time.Second' in s
    s=s.replace('connTestConnectWait = 60 * time.Second','connTestConnectWait = 30 * time.Second')
    s=s.replace('return connTestConnectWait + 30*time.Second','return connTestConnectWait + 15*time.Second')
    assert 'rtt += took' in s
    s=s.replace('rtt += took','rtt += took\n\t\tr.RTTms = int((rtt / time.Duration(r.OK)).Milliseconds())')
    assert s.count('fmt.Sprintf("bpt%d", i)')==2
    s=s.replace('Iface: fmt.Sprintf("bpt%d", i)', 'Iface: speedboxTestIface(id, i)')
    s=s.replace('block := ctFreeL3Block(i)', 'block := ctFreeL3Block(i)\n\t\t\tif l3BlockOwner(block+"1") != "" { c.skip="private network already in use"; s.cases=append(s.cases,c); continue }')
    s=s.replace('spec.Name, spec.Iface = name, fmt.Sprintf("bpt%d", i)', 'spec.Name, spec.Iface = name, speedboxTestIface(link.ID, i)')
    p.write_text(s)
    p=source/'internal/manage/directwebapi.go';s=p.read_text()
    start=s.index('func l3BlockOwner(addr string) string {')
    tail=s[start:];assert tail.count('return ""')==1
    s=s[:start]+tail.replace('return ""','return speedboxKernelBlockOwner(addr)',1)
    p.write_text(s)
    for src,dst in [('speedbox.go','speedbox.go'),('bridge.go','internal/manage/speedbox_bridge.go'),('bridge_test.go','internal/manage/speedbox_bridge_test.go')]:shutil.copy2(HERE/src,source/dst)
    subprocess.run(['go','test','./internal/manage','-run','^TestSpeedbox','-count=1'],cwd=source,check=True)
    subprocess.run(['go','test','./internal/tunnel/l3','-count=1'],cwd=source,check=True)
    manifest={'protocol':1,'upstream_commit':PIN,'upstream_version':'v1.8.5','upstream_release':'v1.8.5','speed_measurement':'round_trip_1mib','source_sha256':sha(HERE/'upstream-source.tar.gz'),
              'integration_sources':{f:sha(HERE/f) for f in ['speedbox.go','bridge.go','bridge_test.go','build.py','l3_confirm.py','l3_confirm_test.go','l3_keepalive.go','l3_keepalive_test.go']},'archives':{}}
    for arch in ['amd64','arm64']:
        binary=work/('backpack-linux-'+arch)
        subprocess.run(['go','build','-trimpath','-buildvcs=false','-ldflags=-s -w','-o',str(binary),'.'],cwd=source,env={**os.environ,'GOOS':'linux','GOARCH':arch,'CGO_ENABLED':'0'},check=True)
        output=ROOT/'backpack-dist'/('backpack-linux-'+arch+'.gz');output.parent.mkdir(exist_ok=True)
        output.write_bytes(gzip.compress(binary.read_bytes(),mtime=0))
        manifest['archives'][arch]={'sha256':sha(output),'binary_sha256':sha(binary)}
    (ROOT/'backpack-dist/manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
    print('BackPack integration: tests and two architecture builds passed')


