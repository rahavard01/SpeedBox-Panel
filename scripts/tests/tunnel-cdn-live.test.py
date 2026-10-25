#!/usr/bin/env python3
"""Real engines behind a local TLS-terminating edge with a different origin certificate.
No systemd, external network, global routes or privileged ports are used.
"""
import contextlib,gzip,hashlib,importlib.util,json,os,pathlib,socket,ssl,subprocess,tempfile,threading,time
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('wat_test',ROOT/'scripts/tests/wattunnel-host.test.py');fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)
def port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
def transfer(entry,udp=False):
    payload=os.urandom(1200 if udp else 65536)
    with socket.socket(type=socket.SOCK_DGRAM if udp else socket.SOCK_STREAM) as c:
        c.settimeout(2);c.connect(('127.0.0.1',entry));c.sendall(payload);reply=b''
        while len(reply)<len(payload):
            b=c.recv(65536)
            if not b:break
            reply+=b
        assert reply==payload,'payload integrity'
class Edge:
    def __init__(self,origin,cert,key,tls):
        self.s=socket.socket();self.s.bind(('127.0.0.1',0));self.s.listen(64);self.s.settimeout(.2);self.port=self.s.getsockname()[1];self.closed=False;self.count=0;self.sni=[];self.streams=[]
        ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);ctx.set_alpn_protocols(['h2','http/1.1']);ctx.set_servername_callback(lambda sock,name,ctx:self.sni.append(name))
        def connection(raw):
            downstream=upstream=None
            try:
                raw.settimeout(4);downstream=ctx.wrap_socket(raw,server_side=True) if tls else raw
                upstream=socket.create_connection(('127.0.0.1',origin),3)
                if tls:
                    client=ssl._create_unverified_context();alpn=downstream.selected_alpn_protocol()
                    if alpn:client.set_alpn_protocols([alpn])
                    upstream=client.wrap_socket(upstream,server_hostname='tunnel.example.com')
                downstream.settimeout(None);upstream.settimeout(None);self.count+=1;self.streams.extend([downstream,upstream])
                def pipe(a,b):
                    try:
                        while True:
                            data=a.recv(65536)
                            if not data:break
                            b.sendall(data)
                    except OSError:pass
                    finally:
                        with contextlib.suppress(OSError):a.shutdown(socket.SHUT_RDWR)
                        with contextlib.suppress(OSError):b.shutdown(socket.SHUT_RDWR)
                worker=threading.Thread(target=pipe,args=(downstream,upstream),daemon=True);worker.start();pipe(upstream,downstream)
            except OSError:pass
            finally:
                for conn in [downstream,upstream,raw]:
                    if conn:
                        with contextlib.suppress(OSError):conn.close()
        def serve():
            while not self.closed:
                try:raw,_=self.s.accept();threading.Thread(target=connection,args=(raw,),daemon=True).start()
                except socket.timeout:pass
                except OSError:return
        threading.Thread(target=serve,daemon=True).start()
    def close(self):
        self.closed=True;self.s.close()
        for c in self.streams:
            with contextlib.suppress(OSError):c.shutdown(socket.SHUT_RDWR);c.close()

with tempfile.TemporaryDirectory(prefix='speedbox-cdn-live-') as tmp:
    tmp=pathlib.Path(tmp);bins={}
    for engine,directory in [('backpack','backpack-dist'),('wattunnel','wattunnel-dist')]:
        manifest=json.loads((ROOT/directory/'manifest.json').read_text());entry=manifest['archives']['amd64'];raw=gzip.decompress((ROOT/directory/entry.get('file','backpack-linux-amd64.gz')).read_bytes());assert hashlib.sha256(raw).hexdigest()==entry['binary_sha256'];binary=tmp/engine;binary.write_bytes(raw);binary.chmod(0o700);bins[engine]=str(binary)
    for name in ('edge','origin'):
        subprocess.run(['openssl','req','-x509','-newkey','ec','-pkeyopt','ec_paramgen_curve:P-256','-nodes','-days','2','-subj','/CN=tunnel.example.com','-addext','subjectAltName=DNS:tunnel.example.com','-keyout',str(tmp/(name+'.key')),'-out',str(tmp/(name+'.crt'))],check=True,capture_output=True)
    for engine,carrier in [('backpack',x) for x in ['ws','wss','wsmux','wssmux']]+[('wattunnel',x) for x in ['ws','wss','grpc']]:
        backend=fixture.Echo();origin,entry=port(),port();secure=carrier in ('wss','wssmux','grpc');edge=Edge(origin,tmp/'edge.crt',tmp/'edge.key',secure);processes=[];logs=[]
        try:
            if engine=='backpack':
                token='a'*64
                server=f'[server]\ntransport="{carrier}"\nbind_addr="127.0.0.1:{origin}"\ntoken="{token}"\nports=["127.0.0.1:{entry}={backend.port}"]\naccept_udp=true\nkeepalive_period=5\nheartbeat=3\n'
                client=f'[client]\ntransport="{carrier}"\nremote_addr="tunnel.example.com:{edge.port}"\nedge_ip="127.0.0.1"\ntoken="{token}"\nconnection_pool=2\nretry_interval=1\nkeepalive_period=5\nheartbeat=3\n'
                if secure:server+=f'tls_cert="{tmp}/origin.crt"\ntls_key="{tmp}/origin.key"\nsimple_auth=true\n';client+='simple_auth=true\n'
                commands=[]
                for name,text in [('server',server),('client',client)]:
                    cfg=tmp/(engine+'-'+carrier+'-'+name+'.toml');cfg.write_text(text);commands.append([bins[engine],'-c',str(cfg)])
            else:
                key=tmp/'wat.key';key.write_text('c'*64)
                s=fixture.settings();s.update(transport=carrier,tunnel_port=origin,protocol='both',connection_mode='cdn',cdn={'domain':'tunnel.example.com','edge_ip':'127.0.0.1','port':edge.port},mappings=[dict(public_port=entry,target_port=backend.port)])
                commands=[]
                for side in ('foreign','iran'):
                    c=fixture.config(s,side);c['settings']['metrics_port']=c['subnet']=port();argv=[bins[engine],*fixture.h.wat.arguments(c,key)]
                    if side=='foreign' and secure:argv+=['-tls-cert',str(tmp/'origin.crt'),'-tls-key',str(tmp/'origin.key')]
                    commands.append(argv)
            for i,argv in enumerate(commands):
                log=tmp/(engine+'-'+carrier+'-'+str(i)+'.log');logs.append(log)
                with log.open('wb') as out:processes.append(subprocess.Popen(argv,stdout=out,stderr=out))
                time.sleep(.15)
            deadline=time.monotonic()+18
            while True:
                try:transfer(entry);transfer(entry,udp=True);break
                except (OSError,AssertionError):
                    if time.monotonic()>deadline:raise AssertionError(engine+'/'+carrier+' failed: '+'\n'.join(p.read_text()[-1600:] for p in logs))
                    time.sleep(.15)
            assert edge.count>0,'edge was bypassed'
            if secure:assert 'tunnel.example.com' in edge.sni,'domain not preserved as SNI'
            edge.close()
            try:transfer(entry)
            except (OSError,AssertionError):pass
            else:raise AssertionError('silent direct fallback after edge closed')
            print('PASS:',engine,carrier,'TCP + UDP through independent edge/origin ports; TLS termination and no fallback',flush=True)
        finally:
            edge.close();backend.close()
            for p in processes:
                if p.poll() is None:p.terminate()
            for p in processes:
                try:p.wait(timeout=5)
                except subprocess.TimeoutExpired:p.kill();p.wait()
