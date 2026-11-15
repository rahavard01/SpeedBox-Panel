"""Fetch the checksum-pinned Zig compiler used by upstream's static builds."""
import hashlib,json,os,platform,sys,tarfile,tempfile,urllib.request
from pathlib import Path

def install(destination):
    if platform.system()!='Linux' or platform.machine() not in ('x86_64','amd64'):
        raise RuntimeError('This build helper requires an x86-64 Linux build host')
    lock=json.loads((Path(__file__).with_name('upstream.json')).read_text())['zig']
    destination=Path(destination).resolve();destination.mkdir(parents=True,exist_ok=True)
    target=destination/('zig-x86_64-linux-'+lock['version'])/'zig'
    if target.is_file():return target.parent
    with tempfile.TemporaryDirectory(prefix='speedbox-zig-') as directory:
        archive=Path(directory)/'zig.tar.xz';digest=hashlib.sha256()
        with urllib.request.urlopen(lock['url'],timeout=180) as response,archive.open('wb') as output:
            for chunk in iter(lambda:response.read(1048576),b''):
                digest.update(chunk);output.write(chunk)
        if digest.hexdigest()!=lock['sha256']:raise RuntimeError('Zig archive checksum mismatch')
        with tarfile.open(archive) as ar:ar.extractall(destination,filter='data')
    if not target.is_file():raise RuntimeError('Zig executable is missing')
    return target.parent

if __name__=='__main__':print(install(sys.argv[1]))
