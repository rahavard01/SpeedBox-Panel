"""Real official helpers, two engines, verified bytes and reconnect on loopback."""
import argparse,base64,contextlib,hashlib,json,os,pathlib,socket,ssl,subprocess,tempfile,threading,time,urllib.request,tarfile,zipfile

ROOT=pathlib.Path(__file__).resolve().parents[2]
HELPERS=pathlib.Path('/opt/speedbox-backpack/helpers')

def port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

def run(args,**kwargs):return subprocess.run(args,check=True,capture_output=True,**kwargs).stdout

def prepare_helpers():
    manifest=json.loads((ROOT/'scripts/backpack/helpers.json').read_text())
    for tool,entry in manifest.items():
        target=HELPERS/tool/(entry['version']+'-amd64')/tool
        if target.is_file():continue
        target.parent.mkdir(parents=True,exist_ok=True)
        e=entry['archives']['amd64']
        with tempfile.TemporaryDirectory(prefix='backpack-helper-test-') as folder:
            archive=pathlib.Path(folder)/'archive'
            with urllib.request.urlopen(e['url'],timeout=180) as response:archive.write_bytes(response.read())
            if hashlib.sha256(archive.read_bytes()).hexdigest()!=e['sha256']:raise RuntimeError('official helper checksum mismatch')
            if e['format']=='zip':
                with zipfile.ZipFile(archive) as ar:data=ar.read(tool)
            else:
                with tarfile.open(archive) as ar:
                    member=next(x for x in ar.getmembers() if x.isfile() and pathlib.Path(x.name).name==tool)
                    data=ar.extractfile(member).read()
            target.write_bytes(data);target.chmod(0o755)

@contextlib.contextmanager
def echo():
    s=socket.socket();s.bind(('127.0.0.1',0));s.listen();s.settimeout(.2);stop=threading.Event()
    def handle(c):
        with c:
            while True:
                data=c.recv(65536)
                if not data:return
                c.sendall(data)
    def accept():
        while not stop.is_set():
            try:c,_=s.accept()
            except socket.timeout:continue
            except OSError:return
            threading.Thread(target=handle,args=(c,),daemon=True).start()
    thread=threading.Thread(target=accept,daemon=True);thread.start()
    try:yield s.getsockname()[1]
    finally:stop.set();s.close();thread.join(1)

def transfer(entry,payload):
    with socket.create_connection(('127.0.0.1',entry),2) as c:
        c.settimeout(4);c.sendall(payload);out=bytearray()
        while len(out)<len(payload):
            data=c.recv(65536)
            if not data:raise RuntimeError('short managed reply')
            out.extend(data)
        if bytes(out)!=payload:raise RuntimeError('managed payload mismatch')

def await_transfer(entry,payload):
    until=time.monotonic()+45
    while True:
        try:transfer(entry,payload);return
        except (OSError,RuntimeError):
            if time.monotonic()>until:raise
            time.sleep(.3)

def stop(child):
    if child.poll() is None:child.terminate()
    try:child.wait(8)
    except subprocess.TimeoutExpired:child.kill();child.wait()

def main():
    parser=argparse.ArgumentParser();parser.add_argument('binary');parser.add_argument('--download-helpers',action='store_true');parser.add_argument('--modes',default='naive,xhttp,reality');args=parser.parse_args()
    binary=str(pathlib.Path(args.binary).resolve())
    if args.download_helpers:prepare_helpers()
    with tempfile.TemporaryDirectory(prefix='backpack-managed-live-') as folder,echo() as backend:
        folder=pathlib.Path(folder);cert=folder/'cert.pem';key=folder/'key.pem';private=folder/'reality.key'
        run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-subj','/CN=tunnel.example.com','-addext','subjectAltName=DNS:tunnel.example.com,IP:127.0.0.1','-days','1'])
        run(['openssl','genpkey','-algorithm','X25519','-out',str(private)])
        encode=lambda b:base64.urlsafe_b64encode(b).decode().rstrip('=')
        private_raw=run(['openssl','pkey','-in',str(private),'-outform','DER'])[-32:]
        public_raw=run(['openssl','pkey','-in',str(private),'-pubout','-outform','DER'])[-32:]
        # REALITY's cover is a real TLS 1.3 / H2 server owned by this test.
        cover_port=port();cover=subprocess.Popen(['openssl','s_server','-accept',f'127.0.0.1:{cover_port}','-cert',str(cert),'-key',str(key),'-tls1_3','-alpn','h2','-quiet','-ign_eof'],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for index,mode in enumerate(args.modes.split(',')):
                if mode not in ('naive','xhttp','reality'):raise ValueError('invalid test mode')
                children=[];logs=[]
                try:
                    outer,internal,entry=port(),port(),port();name='sb-'+format(index+100,'032x')
                    m={'mode':mode,'server_name':'127.0.0.1' if mode=='naive' else 'tunnel.example.com','username':'sb-live','password':'test-'+os.urandom(16).hex(),'uuid':'6bf7a33e-7833-4e72-9219-506585657345','connections':4}
                    if mode=='reality':m.update(target=f'127.0.0.1:{cover_port}',private_key=encode(private_raw),public_key=encode(public_raw),short_id='0123456789abcdef')
                    else:m.update(certificate=cert.read_text(),key=key.read_text())
                    if mode=='xhttp':m['path']='/sb-live'
                    for role in ['server','client']:
                        request={'action':'render','name':name,'kind':'reverse','role':role,'transport':mode,'host':'127.0.0.1','port':outer,'bridge_port':internal,'token':'b'*64,'ports':f'127.0.0.1:{entry}={backend}','managed':m}
                        answer=json.loads(run([binary,'speedbox-json','-'],input=json.dumps(request).encode()))
                        if not answer.get('ok'):raise RuntimeError(str(answer))
                        config=folder/(mode+'-'+role+'.toml');config.write_text(answer['data']['main']);config.chmod(0o600)
                        log=folder/(mode+'-'+role+'.log');logs.append(log)
                        with log.open('wb') as output:children.append(subprocess.Popen([binary,'-c',str(config)],stdout=output,stderr=output))
                    payload=os.urandom(262144);await_transfer(entry,payload)
                    stop(children[1])
                    try:transfer(entry,b'peer-must-be-present')
                    except (OSError,RuntimeError):pass
                    else:raise AssertionError('managed path bypassed its peer')
                    client=folder/(mode+'-client.toml')
                    with logs[1].open('ab') as output:children[1]=subprocess.Popen([binary,'-c',str(client)],stdout=output,stderr=output)
                    await_transfer(entry,payload)
                    print('PASS:',mode,'official helpers, verified payload, peer failure and recovery',flush=True)
                except Exception:
                    for log in logs:print(log.name,log.read_text(errors='replace')[-5000:],flush=True)
                    raise
                finally:
                    for child in children:stop(child)
        finally:stop(cover)

if __name__=='__main__':main()
