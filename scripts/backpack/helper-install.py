"""Install one digest-pinned official helper; never download or start services."""
import hashlib,json,os,re,stat,sys,tarfile,tempfile,zipfile
from pathlib import Path

ROOT=Path('/opt/speedbox-backpack/helpers')

def digest(path):
    h=hashlib.sha256()
    with path.open('rb') as f:
        for chunk in iter(lambda:f.read(1048576),b''):h.update(chunk)
    return h.hexdigest()

def execute(request):
    e=request['expected'];tool=e['tool'];version=e['version'];arch=e['arch']
    if tool not in ('naive','sing-box','xray') or arch not in ('amd64','arm64') or not re.fullmatch(r'v[0-9.]+(?:-[0-9]+)?',version):raise ValueError('invalid helper identity')
    if not re.fullmatch('[a-f0-9]{64}',e['sha256']):raise ValueError('invalid helper checksum')
    folder=ROOT/tool/(version+'-'+arch);target=folder/tool;state=folder/'installed.json'
    for p in [ROOT.parent,ROOT,ROOT/tool,folder,target,state]:
        if p.is_symlink():raise ValueError('unsafe helper path')
    try:
        saved=json.loads(state.read_text())
        current=saved.get('archive_sha256')==e['sha256'] and target.is_file() and os.access(target,os.X_OK) and digest(target)==saved.get('binary_sha256')
    except (OSError,ValueError):current=False
    if current or request['action']=='status':return {'current':current}
    if request['action']!='install':raise ValueError('unsupported helper action')
    archive=Path(request['archive'])
    if not re.fullmatch(r'/tmp/speedbox-managed\.[A-Za-z0-9_-]+/helper\.archive',str(archive)) or archive.is_symlink():raise ValueError('invalid helper archive path')
    if digest(archive)!=e['sha256']:raise ValueError('helper archive checksum mismatch')
    folder.mkdir(parents=True,exist_ok=True,mode=0o755)
    fd,temp=tempfile.mkstemp(prefix='.helper-',dir=folder)
    try:
        with os.fdopen(fd,'wb') as out:
            if e['format']=='zip':
                with zipfile.ZipFile(archive) as ar:
                    matches=[x for x in ar.infolist() if not x.is_dir() and Path(x.filename).name==tool]
                    if len(matches)!=1:raise ValueError('ambiguous helper binary')
                    item=matches[0]
                    if item.file_size>256*1024*1024:raise ValueError('helper is too large')
                    with ar.open(item) as src:
                        import shutil
                        shutil.copyfileobj(src,out)
            else:
                with tarfile.open(archive) as ar:
                    matches=[x for x in ar.getmembers() if x.isfile() and Path(x.name).name==tool]
                    if len(matches)!=1:raise ValueError('ambiguous helper binary')
                    if matches[0].size>256*1024*1024:raise ValueError('helper is too large')
                    with ar.extractfile(matches[0]) as src:
                        import shutil
                        shutil.copyfileobj(src,out)
            out.flush();os.fsync(out.fileno())
        with Path(temp).open('rb') as f:header=f.read(20)
        if len(header)<20 or header[:4]!=b'\x7fELF':raise ValueError('helper is not an ELF executable')
        if header[4]!=2 or header[5]!=1 or int.from_bytes(header[18:20],'little')!={'amd64':62,'arm64':183}[arch]:raise ValueError('helper architecture mismatch')
        os.chmod(temp,0o755);binary_hash=digest(Path(temp));os.replace(temp,target)
        saved={'archive_sha256':e['sha256'],'binary_sha256':binary_hash}
        fd,name=tempfile.mkstemp(prefix='.state-',dir=folder)
        with os.fdopen(fd,'w') as f:json.dump(saved,f);f.flush();os.fsync(f.fileno())
        os.chmod(name,0o644);os.replace(name,state)
        return {'current':True,'binary_sha256':binary_hash}
    finally:
        if os.path.exists(temp):os.unlink(temp)

if __name__=='__main__':
    try:print(json.dumps({'ok':True,'data':execute(json.load(sys.stdin))}))
    except Exception as error:
        print(json.dumps({'ok':False,'error':str(error)}));sys.exit(1)
