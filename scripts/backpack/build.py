#!/usr/bin/env python3
"""Reproducible integration build; source is pinned, archived and kept with the patch."""
import gzip,hashlib,json,os,pathlib,shutil,subprocess,tarfile,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
PIN='132467bfdfb2698d74ada2992413e75bfccbfbd3'
HERE=pathlib.Path(__file__).resolve().parent
def sha(p):
    raw=p.read_bytes()
    if p.suffix in ['.go','.py']:raw=raw.replace(b'\r\n',b'\n')
    return hashlib.sha256(raw).hexdigest()
with tempfile.TemporaryDirectory(prefix='speedbox-backpack-build-') as work:
    work=pathlib.Path(work)
    with tarfile.open(HERE/'upstream-source.tar.gz') as ar:
        ar.extractall(work,filter='data')
    source=work/'BackPack'
    p=source/'internal/app/app.go';s=p.read_text()
    for old,new in [('"/root/BackPack"','"/opt/speedbox-backpack"'),('"/etc/backpack"','"/etc/speedbox-backpack"'),('"backpack-"','"speedbox-backpack-"'),('"/usr/local/bin/backpack"','"/opt/speedbox-backpack/backpack"')]:
        assert old in s,old;s=s.replace(old,new)
    p.write_text(s)
    p=source/'internal/optimize/optimize.go';s=p.read_text();assert 'Apply(func(string) {}, reserve)' in s
    p.write_text(s.replace('Apply(func(string) {}, reserve)','// SpeedBox leaves global kernel tuning to the administrator.'))
    p=source/'cmd/optimization.go';s=p.read_text();assert 'optimize.EngineStartupTuning()' in s
    p.write_text(s.replace('optimize.EngineStartupTuning()', '[][2]string{}').replace('\"github.com/backpack/backpack/internal/optimize\"','_ \"github.com/backpack/backpack/internal/optimize\"'))
    p=source/'internal/manage/conntest.go';s=p.read_text()
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
    manifest={'protocol':1,'upstream_commit':PIN,'upstream_version':'v1.9.0','source_sha256':sha(HERE/'upstream-source.tar.gz'),
              'integration_sources':{f:sha(HERE/f) for f in ['speedbox.go','bridge.go','bridge_test.go','build.py']},'archives':{}}
    for arch in ['amd64','arm64']:
        binary=work/('backpack-linux-'+arch)
        subprocess.run(['go','build','-trimpath','-buildvcs=false','-ldflags=-s -w','-o',str(binary),'.'],cwd=source,env={**os.environ,'GOOS':'linux','GOARCH':arch,'CGO_ENABLED':'0'},check=True)
        output=ROOT/'backpack-dist'/('backpack-linux-'+arch+'.gz');output.parent.mkdir(exist_ok=True)
        output.write_bytes(gzip.compress(binary.read_bytes(),mtime=0))
        manifest['archives'][arch]={'sha256':sha(output),'binary_sha256':sha(binary)}
    (ROOT/'backpack-dist/manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
    print('BackPack integration: tests and two architecture builds passed')
