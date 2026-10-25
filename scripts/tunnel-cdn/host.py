#!/usr/bin/env python3
"""Owned CDN certificates and bounded probes. No firewall, routes or global tuning."""
import concurrent.futures,fcntl,hashlib,ipaddress,json,os,re,shutil,socket,ssl,subprocess,sys,tempfile,threading,time,urllib.request
from pathlib import Path

ROOT=Path('/var/lib/speedbox-tunnel-cdn')
ETC=Path('/etc/speedbox-tunnel-cdn')
OPT=Path('/opt/speedbox-tunnel-cdn')
UNITS=Path('/etc/systemd/system')
IDENTITY=re.compile(r'^sb-[a-f0-9]{32}$')

def identity(value):
    if not isinstance(value,str) or not IDENTITY.fullmatch(value):raise ValueError('invalid owned identifier')
    return value

def run(args,timeout=25,check=True,stdin=None):
    result=subprocess.run([str(v) for v in args],input=stdin,text=True,capture_output=True,timeout=timeout)
    if check and result.returncode:raise RuntimeError('اجرای ابزار سرور کامل نشد.')
    return result.stdout.strip() if check else result

def atomic(path,value):
    path=Path(path);path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    raw=value if isinstance(value,str) else json.dumps(value,ensure_ascii=False)
    fd,tmp=tempfile.mkstemp(dir=path.parent,prefix='.'+path.name)
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,'w') as f:f.write(raw);f.flush();os.fsync(f.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def read(path,default=None):
    try:return json.loads(Path(path).read_text())
    except FileNotFoundError:return default

def public_ip(value):
    address=ipaddress.ip_address(value)
    if not address.is_global:raise ValueError('نشانی لبه باید عمومی باشد.')
    return str(address)

def resolve(c,origins):
    domain=c['domain'];port=int(c['port'])
    if not re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?',domain) or not 1<=port<=65535:raise ValueError('دامنه یا پورت معتبر نیست.')
    addresses=sorted({public_ip(x[4][0]) for x in socket.getaddrinfo(domain,port,type=socket.SOCK_STREAM)})
    origin_ips=set()
    for origin in origins:
        origin_ips.update(x[4][0] for x in socket.getaddrinfo(origin,None,type=socket.SOCK_STREAM))
    if not addresses or origin_ips.intersection(addresses):raise ValueError('دامنه به IP مستقیم یکی از سرورها اشاره می‌کند؛ مسیر CDN تأیید نشد.')
    if c.get('edge_ip'):
        edge=public_ip(c['edge_ip'])
        if edge not in addresses:raise ValueError('IP لبه در پاسخ فعلی DNS دامنه نیست؛ برای بررسی مطمئن آن را خالی کنید.')
        addresses=[edge]
    return {'addresses':addresses,'origin_addresses':sorted(origin_ips)}

def pair_info(cert,key,domain):
    cert,key=Path(cert),Path(key)
    if not cert.is_file() or not key.is_file() or cert.stat().st_size>131072 or key.stat().st_size>65536:raise ValueError('فایل‌های گواهی کامل یا معتبر نیستند.')
    info=ssl._ssl._test_decode_cert(str(cert));domain=domain.lower()
    names=[name.lower() for kind,name in info.get('subjectAltName',[]) if kind=='DNS']
    if not any(name==domain or name.startswith('*.') and domain.partition('.')[2]==name[2:] for name in names):raise ValueError('گواهی دامنهٔ تونل را پوشش نمی‌دهد.')
    if run(['openssl','x509','-in',cert,'-noout','-checkend','3600'],check=False).returncode:raise ValueError('گواهی منقضی شده یا به انقضا نزدیک است.')
    certpub=run(['openssl','x509','-in',cert,'-pubkey','-noout'])
    keypub=run(['openssl','pkey','-in',key,'-pubout'])
    if certpub.strip()!=keypub.strip():raise ValueError('گواهی و کلید خصوصی متناظر نیستند.')
    if ssl.cert_time_to_seconds(info['notBefore'])>time.time():raise ValueError('گواهی هنوز معتبر نشده است.')
    return {'expires_at':int(ssl.cert_time_to_seconds(info['notAfter'])),'sha256':hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text().split('-----END CERTIFICATE-----')[0]+'-----END CERTIFICATE-----')).hexdigest()}

def certificate(r):
    name=identity(r['id']);c=r['cdn'];cfg=c.get('cert_config',{});domain=c['domain'];mode=cfg.get('cert_mode','none')
    if mode=='none':return {}
    home=ETC/name;home.mkdir(parents=True,exist_ok=True,mode=0o700)
    with (home/'.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        staging=Path(tempfile.mkdtemp(prefix='.certificate-',dir=home))
        try:
            crt,key=staging/'fullchain.pem',staging/'privkey.pem'
            if mode=='content':atomic(crt,cfg['cert_content']);atomic(key,cfg['key_content'])
            elif mode=='file':
                for source,target in [(cfg['cert_file'],crt),(cfg['key_file'],key)]:
                    p=Path(source)
                    if not p.is_absolute() or '..' in p.parts or not p.is_file() or p.stat().st_size>131072:raise ValueError('مسیر فایل گواهی معتبر نیست.')
                    atomic(target,p.read_text())
            elif mode in ('http','dns'):
                # Cache by domain and provider; credentials never appear in arguments.
                cache=hashlib.sha256((domain+':'+mode+':'+cfg.get('dns_provider','')).encode()).hexdigest()
                req={k:cfg[k] for k in ('email','http_port','dns_provider','dns_env') if k in cfg}
                req.update({'state_dir':str(ROOT/'acme'/cache),'domain':domain,'cert_mode':mode})
                cache_dir=ROOT/'acme'/cache;cache_dir.mkdir(parents=True,exist_ok=True,mode=0o700)
                with (cache_dir/'.lock').open('a') as account_lock:
                    fcntl.flock(account_lock,fcntl.LOCK_EX)
                    result=run([OPT/'certificate'],timeout=420,stdin=json.dumps(req))
                result=json.loads(result)
                if not result.get('ok'):raise RuntimeError('صدور گواهی کامل نشد.')
                atomic(crt,Path(result['data']['cert_file']).read_text());atomic(key,Path(result['data']['key_file']).read_text())
            else:raise ValueError('روش گواهی معتبر نیست.')
            info=pair_info(crt,key,domain)
            targetcrt,targetkey=home/'fullchain.pem',home/'privkey.pem'
            changed=not targetcrt.exists() or targetcrt.read_bytes()!=crt.read_bytes()
            # No engine is started until both files have been validated and installed.
            os.replace(key,targetkey);os.replace(crt,targetcrt)
            info.update({'cert_file':str(targetcrt),'key_file':str(targetkey),'changed':changed})
            atomic(home/'info.json',info)
            return info
        finally:shutil.rmtree(staging,ignore_errors=True)

def unit_name(r):
    name=identity(r['id'])
    if r['engine']=='backpack':return 'speedbox-backpack-'+name+'.service'
    if r['engine']=='wattunnel':return 'speedbox-net-'+name+'.service'
    raise ValueError('invalid engine')

def renewal(r):
    name=identity(r['id']);unit='speedbox-cdn-cert-'+name;mode=r['cdn'].get('cert_config',{}).get('cert_mode')
    home=ETC/name
    if mode not in ('http','dns') or r.get('temporary'):
        run(['systemctl','disable','--now',unit+'.timer'],check=False)
        for suffix in ('.service','.timer'):(UNITS/(unit+suffix)).unlink(missing_ok=True)
        (home/'renew.json').unlink(missing_ok=True)
        run(['systemctl','daemon-reload'])
        return
    atomic(home/'renew.json',r)
    service='[Unit]\nDescription=SpeedBox tunnel certificate renewal\nAfter=network-online.target\nWants=network-online.target\n[Service]\nType=oneshot\nUMask=0077\nExecStart=/usr/bin/python3 /opt/speedbox-tunnel-cdn/host.py renew '+str(home/'renew.json')+'\n'
    timer='[Unit]\nDescription=Renew SpeedBox tunnel certificate\n[Timer]\nOnCalendar=daily\nRandomizedDelaySec=3600\nPersistent=true\n[Install]\nWantedBy=timers.target\n'
    atomic(UNITS/(unit+'.service'),service);atomic(UNITS/(unit+'.timer'),timer)
    run(['systemctl','daemon-reload']);run(['systemctl','enable','--now',unit+'.timer'])

def revision(r):
    name=identity(r['id']);rev=r['revision']
    if not re.fullmatch('[a-f0-9]{32}',rev):raise ValueError('invalid revision')
    home=ETC/name;backup=ROOT/'revisions'/(name+'-'+rev);action=r['action']
    if action=='snapshot':
        if not backup.exists():
            backup.mkdir(parents=True,mode=0o700)
            if home.exists():shutil.copytree(home,backup/'certificate',ignore=shutil.ignore_patterns('.lock','.certificate-*'))
        return {'saved':True}
    if action=='rollback':
        if backup.exists():
            shutil.rmtree(home,ignore_errors=True)
            if (backup/'certificate').exists():shutil.copytree(backup/'certificate',home)
            req=read(home/'renew.json')
            if req:renewal(req)
            else:renewal({'id':name,'cdn':{'cert_config':{}}})
        return {'restored':True}
    if action=='commit':shutil.rmtree(backup,ignore_errors=True);return {'committed':True}
    raise ValueError('invalid revision action')

def peers(r):
    state=resolve(r['cdn'],r['origins']);unit=unit_name(r)
    # Swapped BackPack carriers run in their own companion unit.
    if r.get('carrier_id'):unit=unit.replace(r['id'],identity(r['carrier_id']))
    pid=run(['systemctl','show',unit,'-p','MainPID','--value'])
    if not pid.isdigit() or int(pid)<=0:return {'cdn_verified':False,'peer_addresses':[],'resolved_addresses':state['addresses'],'checked_at':int(time.time())}
    sockets=run(['ss','-Hntp'],check=False).stdout;observed=[]
    for line in sockets.splitlines():
        if not re.search(r'\bpid='+re.escape(pid)+r',',line):continue
        fields=line.split()
        if len(fields)<5 or fields[0]!='ESTAB':continue
        peer=fields[4]
        try:host,port=peer.rsplit(':',1);host=str(ipaddress.ip_address(host.strip('[]')))
        except ValueError:continue
        if int(port)==int(r['cdn']['port']):observed.append(host)
    verified=bool(observed) and all(p in state['addresses'] and p not in state['origin_addresses'] for p in observed)
    return {'cdn_verified':verified,'peer_addresses':sorted(set(observed)),'resolved_addresses':state['addresses'],'checked_at':int(time.time())}

def echo(port):
    def tcp():
        with socket.socket() as s:
            s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1);s.bind(('127.0.0.1',port));s.listen(32);s.settimeout(1)
            def reply(c):
                with c:
                    c.settimeout(5)
                    try:
                        while True:
                            b=c.recv(65536)
                            if not b:break
                            c.sendall(b)
                    except OSError:pass
            end=time.monotonic()+300
            while time.monotonic()<end:
                try:c,_=s.accept();threading.Thread(target=reply,args=(c,),daemon=True).start()
                except socket.timeout:pass
    def udp():
        with socket.socket(socket.AF_INET,socket.SOCK_DGRAM) as s:
            s.bind(('127.0.0.1',port));s.settimeout(1);end=time.monotonic()+300
            while time.monotonic()<end:
                try:b,a=s.recvfrom(65536);s.sendto(b,a)
                except socket.timeout:pass
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:list(pool.map(lambda fn:fn(),[tcp,udp]))

def free_port():
    for _ in range(50):
        with socket.socket() as t,socket.socket(socket.AF_INET,socket.SOCK_DGRAM) as u:
            t.bind(('127.0.0.1',0));port=t.getsockname()[1]
            if port<32768:continue
            try:u.bind(('127.0.0.1',port));return port
            except OSError:continue
    raise RuntimeError('پورت آزاد برای آزمون پیدا نشد.')

def probe(r):
    port=int(r['port']);protocols=r['protocols'];result={}
    for protocol in protocols:
        samples=[];payload=os.urandom(16384 if protocol=='tcp' else 1200)
        for _ in range(10):
            started=time.monotonic()
            try:
                if protocol=='tcp':
                    with socket.create_connection(('127.0.0.1',port),2) as s:
                        s.settimeout(2);s.sendall(payload);received=b''
                        while len(received)<len(payload):
                            b=s.recv(len(payload)-len(received))
                            if not b:break
                            received+=b
                else:
                    with socket.socket(socket.AF_INET,socket.SOCK_DGRAM) as s:
                        s.settimeout(2);s.sendto(payload,('127.0.0.1',port));received=s.recv(65536)
                if received==payload:samples.append((time.monotonic()-started)*1000)
            except OSError:pass
        ok=len(samples)
        result[protocol]={'status':'ok' if ok==10 else 'unstable' if ok>=8 else 'down','ok':ok,'total':10,'rtt':round(sum(samples)/ok,2) if ok else None}
    return {'protocols':result,'data_verified':bool(result) and all(x['status']=='ok' for x in result.values()),'checked_at':int(time.time())}

def execute(r):
    action=r['action']
    if action in ('snapshot','rollback','commit'):return revision(r)
    if action=='certificate':return certificate(r)
    if action=='info':return read(ETC/identity(r['id'])/'info.json',{})
    if action=='arm':
        unit=unit_name(r);directory=Path('/run/systemd/system')/(unit+'.d');alive=Path('/run/speedbox-cdn-tests')/identity(r['id']);atomic(alive,'1');atomic(directory/'speedbox-cdn-test.conf','[Unit]\nConditionPathExists='+str(alive)+'\n[Service]\nRuntimeMaxSec=300\nRestart=no\n');run(['systemctl','daemon-reload']);return {'armed':True}
    if action=='disarm':
        unit=unit_name(r);directory=Path('/run/systemd/system')/(unit+'.d');(directory/'speedbox-cdn-test.conf').unlink(missing_ok=True);(Path('/run/speedbox-cdn-tests')/identity(r['id'])).unlink(missing_ok=True);run(['systemctl','daemon-reload']);return {'disarmed':True}
    if action=='renewal':renewal(r);return {'installed':True}
    if action=='resolve':return resolve(r['cdn'],r['origins'])
    if action=='peers':return peers(r)
    if action=='probe':return probe(r)
    if action=='free-port':return {'port':free_port()}
    if action=='echo-start':
        name=identity(r['id']);port=int(r['port']);unit='speedbox-cdn-echo-'+name
        run(['systemd-run','--quiet','--collect','--unit='+unit,'--property=RuntimeMaxSec=310','--property=TimeoutStopSec=5','/usr/bin/python3',OPT/'host.py','echo',str(port)])
        return {'started':True}
    if action=='echo-stop':
        unit='speedbox-cdn-echo-'+identity(r['id'])+'.service';run(['systemctl','stop',unit],check=False)
        state=run(['systemctl','show',unit,'-p','ActiveState','--value'],check=False).stdout.strip()
        return {'stopped':state not in ('active','activating','deactivating')}
    if action=='delete':
        name=identity(r['id']);renewal({'id':name,'cdn':{'cert_config':{}}});shutil.rmtree(ETC/name,ignore_errors=True)
        return {'deleted':True}
    raise ValueError('unsupported action')

def main():
    if len(sys.argv)>1 and sys.argv[1]=='echo':echo(int(sys.argv[2]));return
    if len(sys.argv)>2 and sys.argv[1]=='renew':
        r=read(sys.argv[2]);r['action']='certificate';info=certificate(r)
        if info['changed']:
            units=[unit_name(r)]
            if r.get('carrier_id'):units.insert(0,unit_name({**r,'id':identity(r['carrier_id'])}))
            run(['systemctl','try-restart',*units])
        return
    try:
        request=json.load(sys.stdin);print(json.dumps({'ok':True,'data':execute(request)},ensure_ascii=False))
    except Exception:
        # Provider/library diagnostics can include request credentials. Never echo them.
        print(json.dumps({'ok':False,'error':'بررسی CDN یا گواهی کامل نشد؛ دامنه، پورت‌ها، گواهی، دسترسی DNS و آزادبودن پورت آزمون را بررسی کنید.'},ensure_ascii=False));sys.exit(1)
if __name__=='__main__':main()
