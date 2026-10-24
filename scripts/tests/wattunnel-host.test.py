#!/usr/bin/env python3
"""Real engine pairs and host lifecycle in temporary directories; no host tuning."""
import concurrent.futures,copy,gzip,hashlib,importlib.util,json,os,socket,subprocess,tempfile,threading,time,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('host',ROOT/'scripts/network-tunnels/host.py');h=importlib.util.module_from_spec(spec);spec.loader.exec_module(h)
def port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
def settings():
    return dict(version=h.wat.VERSION,transport='tcp',ip_family='ipv4',reverse=False,peer_host='127.0.0.1',local_endpoint='127.0.0.1',protocol='both',tunnel_port=port(),metrics_port=port(),mtu=0,conns=2,sessions=1,lanes=4,icmp_type='echo',fec='off',max_mbps=0,tcp_cc='system',path='/wat',sni='',tls_pin='',fingerprint='go',host_header='',tls_small_ch=False,tls_frag=0,hedge=False,ping_every=2,udp_idle=60,monitor=True,monitor_interval=15,restart_hours=0,mappings=[dict(public_port=port(),target_port=12345)])
def config(s,side='iran'):
    s=copy.deepcopy(s)
    for m in s['mappings']:m.setdefault('public_end',m['public_port']);m.setdefault('target_end',m['target_port'])
    return dict(id='sb-'+'a'*32,engine='wattunnel',side=side,secret='c'*64,subnet=s['metrics_port'],enabled=True,settings=s)
class Echo:
    def __init__(self):
        self.tcp=socket.socket();self.tcp.bind(('127.0.0.1',0));self.port=self.tcp.getsockname()[1];self.tcp.listen(32)
        self.udp=socket.socket(type=socket.SOCK_DGRAM);self.udp.bind(('127.0.0.1',self.port));self.closed=False
        def tcp():
            while not self.closed:
                try:c,_=self.tcp.accept()
                except OSError:return
                def client(c):
                    with c:
                        while True:
                            try:b=c.recv(65536)
                            except OSError:return
                            if not b:return
                            c.sendall(b)
                threading.Thread(target=client,args=(c,),daemon=True).start()
        def udp():
            while not self.closed:
                try:b,a=self.udp.recvfrom(65535);self.udp.sendto(b,a)
                except OSError:return
        threading.Thread(target=tcp,daemon=True).start();threading.Thread(target=udp,daemon=True).start()
    def close(self):self.closed=True;self.tcp.close();self.udp.close()
class Validation(unittest.TestCase):
    def test_reject_invalid_combinations(self):
        for change in [dict(transport='udp',reverse=True),dict(transport='icmp',ip_family='ipv6'),dict(transport='icmp',sessions=2),dict(transport='udp',mtu=16000),dict(path='/x;id'),dict(conns=65),dict(tls_pin='bad'),dict(mappings=[dict(public_port=50001,public_end=50001,target_port=12345,target_end=10**9)])]:
            with self.subTest(change=change):
                c=config({**settings(),**change})
                with self.assertRaises((ValueError,TypeError)):h.validate(c)
    def test_private_key_allowlist_and_capabilities(self):
        c=config(settings(),'foreign');h.validate(c)
        args=h.wat.arguments(c,'/private/credential')
        self.assertEqual(args[args.index('-allow-ports')+1],'12345,12345/udp');self.assertNotIn(c['secret'],' '.join(args))
        text=h.service_text(c);self.assertIn('NoNewPrivileges=true',text);self.assertNotIn('CAP_NET_ADMIN',text);self.assertNotIn('CAP_NET_RAW',text)
        c['settings']['transport']='icmp';self.assertIn('CAP_NET_RAW',h.service_text(c))
    def test_trusted_origin_flags_and_legacy_rollback(self):
        s=settings();c=config(s,'foreign');c['origin_ports']=[12345];h.validate(c)
        args=h.wat.arguments(c,'/private/key')
        self.assertEqual(args[args.index('-speedbox-origin-ports')+1],'12345')
        c['origin_ports']=[12346]
        with self.assertRaises(ValueError):h.validate(c)
        c['origin_ports']=[12345];c['settings']['version']='2.10.9-sb1'
        with self.assertRaises(ValueError):h.validate(c)
        c['origin_ports']=[];h.validate(c)
        self.assertIn('wattun-2.10.9-sb1',h.service_text(c))
        c['settings']['version']='2.10.9-sb2';c['origin_ports']=[12345];h.validate(c)
        self.assertIn('wattun-2.10.9-sb2',h.service_text(c))
        self.assertIn('-speedbox-origin-ports',h.wat.arguments(c,'/private/key'))
    def test_lifecycle_and_transaction(self):
        c=config(settings());calls=[]
        with tempfile.TemporaryDirectory() as td,patch.object(h,'ETC',Path(td)/'etc'),patch.object(h,'ROOT',Path(td)/'state'),patch.object(h,'UNITS',Path(td)/'units'),patch.object(h,'active_unit',return_value=True),patch.object(h,'run',side_effect=lambda args,**kw:(calls.append(args) or subprocess.CompletedProcess(args,0,'',''))),patch.object(h,'snapshot',return_value={'active':True}),patch.object(h,'preflight',return_value=[]):
            rev='d'*32;h.dispatch(dict(action='prepare',revision=rev,config=c));h.dispatch(dict(action='apply',config=c))
            p=h.paths(c['id']);self.assertEqual(p['config'].stat().st_mode&0o777,0o600);self.assertEqual(p['config'].with_suffix('.key').stat().st_mode&0o777,0o600)
            h.dispatch(dict(action='rollback',revision=rev,config=c));self.assertFalse(p['config'].exists());self.assertFalse(p['config'].with_suffix('.key').exists())
            self.assertTrue(all(x[0] not in ('sysctl','nft','iptables','ip') for x in calls))
class RealEngine(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory();cls.base=Path(cls.tmp.name);m=json.loads((ROOT/'wattunnel-dist/manifest.json').read_text());e=m['archives']['amd64']
        b=(ROOT/'wattunnel-dist'/e['file']).read_bytes();assert hashlib.sha256(b).hexdigest()==e['sha256'];b=gzip.decompress(b);assert hashlib.sha256(b).hexdigest()==e['binary_sha256']
        cls.binary=cls.base/('wattun-'+h.wat.VERSION);cls.binary.write_bytes(b);cls.binary.chmod(0o700)
    @classmethod
    def tearDownClass(cls):cls.tmp.cleanup()
    def pair(self,carrier,reverse=False,family='ipv4',pin=False):
        echo=Echo();procs=[];s=settings();s.update(transport=carrier,reverse=reverse,ip_family=family);s['mappings'][0]['target_port']=echo.port
        if family=='ipv6':s.update(peer_host='::1',local_endpoint='::1')
        directory=self.base/(carrier+str(reverse)+family+str(pin));directory.mkdir(exist_ok=True)
        try:
            with patch.object(h,'OPT',self.base),patch.object(h,'ETC',directory),patch.object(h,'ROOT',directory/'state'),patch.object(h,'UNITS',directory):
                configs={side:config({**s,'metrics_port':port()},side) for side in ('iran','foreign')}
                listener='iran' if reverse else 'foreign';dialler='foreign' if reverse else 'iran'
                for side in (listener,dialler):
                    c=configs[side];h.validate(c);h.wat.prepare_files(h,c)
                    if pin and side==dialler:
                        import ssl
                        cert=h.paths(c['id'])['config'].with_suffix('.crt')
                        c['settings']['tls_pin']=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
                    args=h.wat.arguments(c,h.paths(c['id'])['config'].with_suffix('.key'))
                    if side=='iran':args[args.index('-bind')+1]='::1' if family=='ipv6' else '127.0.0.1'
                    if carrier in ('tls','wss','h2','grpc') and side==listener:
                        args+=['-tls-cert',str(h.paths(c['id'])['config'].with_suffix('.crt')),'-tls-key',str(h.paths(c['id'])['config'].with_suffix('.tls-key'))]
                    log=(directory/(side+'.log')).open('w');proc=subprocess.Popen([str(self.binary),*args],stdout=log,stderr=log);log.close();procs.append(proc)
                deadline=time.monotonic()+12
                with patch.object(h,'active_unit',return_value=True):
                    while time.monotonic()<deadline:
                        snapshots=[h.snapshot(c) for c in configs.values()]
                        if all(x['connected'] for x in snapshots):break
                        if any(p.poll() is not None for p in procs):self.fail('\n'.join(p.read_text() for p in directory.glob('*.log')))
                        time.sleep(.05)
                    self.assertTrue(all(x['connected'] for x in snapshots),(carrier,reverse,snapshots))
                forward=s['mappings'][0]['public_port'];ingress='::1' if family=='ipv6' else '127.0.0.1'
                def transfer(_):
                    want=os.urandom(256*1024)
                    with socket.create_connection((ingress,forward),10) as c:
                        c.settimeout(15);c.sendall(want);got=b''
                        while len(got)<len(want):
                            chunk=c.recv(len(want)-len(got))
                            if not chunk:break
                            got+=chunk
                        self.assertEqual(got,want)
                with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:list(pool.map(transfer,range(4)))
                with socket.socket(socket.AF_INET6 if family=='ipv6' else socket.AF_INET,socket.SOCK_DGRAM) as udp:
                    udp.settimeout(12)
                    for size in ([64,8192] if carrier=='icmp' else [64,4096,8192,60000]):
                        want=os.urandom(size);udp.sendto(want,(ingress,forward));got,_=udp.recvfrom(65535);self.assertEqual(got,want,(carrier,reverse,size,len(got)))
                print('PASS',carrier,'reverse' if reverse else 'normal',family,'pinned' if pin else '',flush=True)
        finally:
            for p in procs:p.terminate()
            for p in procs:
                try:p.wait(timeout=4)
                except subprocess.TimeoutExpired:p.kill();p.wait()
            echo.close()
    @unittest.skipUnless(os.environ.get('SPEEDBOX_TEST_SYSTEMD')=='1','real systemd lifecycle runs on the privileged build runner')
    def test_real_systemd_lifecycle(self):
        import shutil,uuid
        directory=Path('/opt')/('speedbox-wat-ci-'+uuid.uuid4().hex);directory.mkdir(mode=0o700)
        shutil.copy2(self.binary,directory/self.binary.name)
        try:
            with patch.object(h,'OPT',directory),patch.object(h,'ETC',directory/'config'),patch.object(h,'ROOT',directory/'state'):
                for carrier,reverse in [('tls',False),('icmp',True)]:
                    echo=Echo();s=settings();s.update(transport=carrier,reverse=reverse);s['mappings'][0]['target_port']=echo.port
                    configs={side:config({**s,'metrics_port':port()},side) for side in ('iran','foreign')}
                    for side,c in configs.items():c['id']='sb-'+uuid.uuid4().hex
                    try:
                        for side in (('iran','foreign') if reverse else ('foreign','iran')):h.install(configs[side])
                        deadline=time.monotonic()+12
                        while time.monotonic()<deadline:
                            if all(h.snapshot(c)['connected'] for c in configs.values()):break
                            time.sleep(.1)
                        self.assertTrue(all(h.snapshot(c)['connected'] for c in configs.values()),'systemd engine did not connect')
                        self.assertTrue(all(h.preflight(c)[-1]['ok'] for c in configs.values()),'own listeners treated as conflicts')
                        for c in configs.values():h.dispatch({'action':'stop','config':c})
                        self.assertTrue(all(not h.active_unit(c) for c in configs.values()))
                        for c in configs.values():h.dispatch({'action':'start','config':c})
                        self.assertTrue(all(h.active_unit(c) for c in configs.values()))
                        for c in configs.values():
                            rev=uuid.uuid4().hex;before=copy.deepcopy(c);h.dispatch({'action':'prepare','revision':rev,'config':c})
                            c['settings']['conns']=3;h.dispatch({'action':'apply','config':c});h.dispatch({'action':'rollback','revision':rev,'config':c})
                            self.assertEqual(2,h.read(h.paths(c['id'])['config'])['settings']['conns'])
                            h.dispatch({'action':'commit','revision':rev,'config':c})
                        print('PASS real systemd apply/stop/start/rollback',carrier,reverse,flush=True)
                    finally:
                        for c in configs.values():
                            h.remove(c)
                            for suffix in ('.crt','.tls-key'):h.paths(c['id'])['config'].with_suffix(suffix).unlink(missing_ok=True)
                        echo.close()
        finally:shutil.rmtree(directory,ignore_errors=True)

    def test_probe_helpers_use_real_engine_and_private_echo(self):
        import multiprocessing,signal
        pspec=importlib.util.spec_from_file_location('wat_probe',ROOT/'scripts/network-tunnels/probe.py');probe=importlib.util.module_from_spec(pspec);pspec.loader.exec_module(probe)
        context=multiprocessing.get_context('fork')
        for carrier,reverse in [('udp',False),('tcp',True),('wss',True)]:
            with self.subTest(carrier=carrier,reverse=reverse),tempfile.TemporaryDirectory() as td:
                td=Path(td);children=[];test_id='b'*32
                backend=port()
                while not 33000<=backend<=60000:backend=port()
                config_base={'id':test_id,'engine':'wattunnel','version':h.wat.VERSION,'transport':carrier,'reverse':reverse,'ip_family':'ipv4','token':'d'*64,'peer_host':'127.0.0.1','local_endpoint':'127.0.0.1','backend_port':backend,'reserved_ports':[]}
                def child(c,root):
                    os.setsid();probe.ROOT=root;probe.GOST=self.base
                    probe.folder(c['id']).mkdir(parents=True)
                    probe.serve(probe.validate(c))
                def start(side,peer_port):
                    root=td/side;c={**config_base,'side':side,'peer_port':peer_port}
                    proc=context.Process(target=child,args=(c,root));proc.start();children.append(proc)
                    state_file=root/test_id/'state.json';deadline=time.monotonic()+10
                    while time.monotonic()<deadline:
                        if state_file.exists():
                            state=json.loads(state_file.read_text());self.assertEqual('ready',state['state'],state);return c,state
                        self.assertTrue(proc.is_alive(),'probe exited before ready');time.sleep(.03)
                    self.fail('probe did not become ready')
                try:
                    first='iran' if reverse else 'foreign';first_config,first_state=start(first,None)
                    second_config,second_state=start('foreign' if reverse else 'iran',first_state['peer_port'])
                    c,state=(first_config,first_state) if reverse else (second_config,second_state)
                    time.sleep(.4)
                    for proto in ('tcp','udp'):
                        value=probe.measure_protocol('127.0.0.1',state['port'],c['token'],proto,budget_seconds=30)
                        self.assertEqual('ok',value['status'],value);self.assertEqual(20,value['ok'])
                    print('PASS real probe helper',carrier,reverse,flush=True)
                finally:
                    for child_proc in children:
                        if child_proc.is_alive():os.killpg(child_proc.pid,signal.SIGTERM)
                    for child_proc in children:child_proc.join(3)

    def test_all_carriers_and_reverse(self):
        for carrier in h.wat.CARRIERS:
            if carrier=='icmp':
                try:raw=socket.socket(socket.AF_INET,socket.SOCK_RAW,socket.IPPROTO_ICMP);raw.close()
                except PermissionError:print('SKIP raw ICMP unavailable locally',flush=True);continue
            for reverse in ([False] if carrier=='udp' else [False,True]):
                with self.subTest(carrier=carrier,reverse=reverse):self.pair(carrier,reverse)
    def test_ipv6_and_certificate_pin(self):
        for carrier in ('udp','tcp','tls','ws','wss','h2','h2c','grpc'):
            for reverse in ([False] if carrier=='udp' else [False,True]):
                with self.subTest(carrier=carrier,reverse=reverse):self.pair(carrier,reverse,'ipv6',pin=carrier in ('tls','wss','h2','grpc'))
if __name__=='__main__':unittest.main()


