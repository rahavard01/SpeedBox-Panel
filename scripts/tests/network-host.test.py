#!/usr/bin/env python3
"""Isolated worker ownership/quota tests plus real GOST TCP, UDP and gRPC transfers.
No sysctl, firewall, interface or systemd mutations are executed by this suite.
"""
import copy,datetime as dt,gzip,hashlib,importlib.util,json,os,socket,subprocess,tempfile,threading,time,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('host',ROOT/'scripts/network-tunnels/host.py');h=importlib.util.module_from_spec(spec);spec.loader.exec_module(h)
DEFAULTS=json.loads((ROOT/'scripts/network-tunnels/defaults.json').read_text())
def config(engine='gre',side='iran'):
 s={**copy.deepcopy(DEFAULTS),'engine':engine,'transport':'gre' if engine=='gre' else 'tcp','name':'fixture','peer_host':'192.0.2.2','local_endpoint':'192.0.2.1','public_host':'192.0.2.1','inner_iran':'10.226.0.1/30','inner_foreign':'10.226.0.2/30','mappings':[{'public_port':12443,'public_end':12443,'target_port':443,'target_end':443}]}
 return {'id':'sb-'+'a'*32,'engine':engine,'side':side,'secret':'b'*64,'enabled':True,'subnet':33000,'settings':s}
class Worker(unittest.TestCase):
 def test_stop_must_be_confirmed_before_success(self):
  c=config('gost')
  with tempfile.TemporaryDirectory() as d,patch.object(h,'ROOT',Path(d)/'state'),patch.object(h,'ETC',Path(d)/'etc'),patch.object(h,'UNITS',Path(d)/'units'),patch.object(h,'run',return_value=subprocess.CompletedProcess([],0,'','')),patch.object(h,'active_unit',return_value=True):
   with self.assertRaisesRegex(RuntimeError,'did not stop'):h.dispatch({'action':'stop','config':c})

 def test_rollback_preserves_desired_activity_and_boot_independently(self):
  for engine in ('gre','gost'):
   for active in (False,True):
    for enabled in (False,True):
     with self.subTest(engine=engine,active=active,enabled=enabled),tempfile.TemporaryDirectory() as d,patch.object(h,'ROOT',Path(d)/'state'),patch.object(h,'ETC',Path(d)/'etc'),patch.object(h,'UNITS',Path(d)/'units'),patch.object(h,'preflight',return_value=[]):
      c=config(engine);p=h.paths(c['id']);h.atomic(p['config'],c);h.atomic(p['unit'],h.service_text(c));calls=[]
      def run(argv,**kw):
       calls.append(argv)
       code=(0 if enabled else 1) if argv[1]=='is-enabled' else (0 if active else 3) if argv[1]=='is-active' else 0
       return subprocess.CompletedProcess(argv,code,'','')
      with patch.object(h,'run',side_effect=run):
       h.dispatch({'action':'prepare','revision':'c'*32,'config':c});calls.clear()
       h.dispatch({'action':'rollback','revision':'c'*32,'config':c})
      self.assertTrue(h.read(p['config'])['enabled'],'observed outage must not rewrite desired policy')
      self.assertIn(['systemctl','enable' if enabled else 'disable',p['unit'].name],calls)
      self.assertIn(['systemctl','restart' if active else 'stop',p['unit'].name],calls)

 def test_gre_startup_failure_retries_with_backoff_and_honors_stop_and_quota(self):
  c=config();c['settings']['monitor_interval']=5
  offline={'checked_at':100,'active':False,'connected':False,'state':'offline','destinations':{},'quota_exhausted':False,'quota_stopped':False,'last_restart':0}
  with tempfile.TemporaryDirectory() as d,patch.object(h,'ROOT',Path(d)),patch.object(h,'snapshot',return_value=offline.copy()),patch.object(h,'install',side_effect=RuntimeError('missing device')) as install,patch.object(h.time,'time',return_value=100):
   h.daemon_tick(c);saved=h.read(h.paths(c['id'])['runtime']);self.assertEqual(saved['recovery']['next_at'],105);self.assertEqual(install.call_count,1)
   with patch.object(h.time,'time',return_value=106):h.daemon_tick(c)
   saved=h.read(h.paths(c['id'])['runtime']);self.assertEqual(saved['recovery']['next_at'],116);self.assertEqual(install.call_count,2)
   with patch.object(h.time,'time',return_value=112):h.daemon_tick(c)
   self.assertEqual(install.call_count,2)
  for change in ('stop','quota'):
   cfg=config();row=offline.copy()
   if change=='stop':cfg['enabled']=False
   else:row['quota_exhausted']=True;cfg['settings']['quota_mode']='enforce'
   with tempfile.TemporaryDirectory() as d,patch.object(h,'ROOT',Path(d)),patch.object(h,'snapshot',return_value=row),patch.object(h,'install') as install,patch.object(h,'run'):
    h.daemon_tick(cfg);install.assert_not_called()

 def test_gost_tcp_accept_is_not_authenticated_path_health(self):
  c=config('gost');c['settings']['transport']='grpc';c['health_probe']=2
  with tempfile.TemporaryDirectory() as d,patch.object(h,'ROOT',Path(d)),patch.object(h,'active_unit',return_value=True),patch.object(h,'tcp_check',return_value={'ok':True}),patch.object(h,'run',side_effect=RuntimeError('wrong relay key')):
   self.assertFalse(h.snapshot(c)['connected'])
  del c['health_probe']
  with tempfile.TemporaryDirectory() as d,patch.object(h,'ROOT',Path(d)),patch.object(h,'active_unit',return_value=True),patch.object(h,'tcp_check',return_value={'ok':True}):
   self.assertIsNone(h.snapshot(c)['connected'])

 def test_gost_authenticated_path_succeeds_with_independent_target_checks(self):
  c=config('gost');c['health_probe']=2
  with tempfile.TemporaryDirectory() as d,patch.object(h,'ROOT',Path(d)),patch.object(h,'active_unit',return_value=True),patch.object(h,'tcp_check',return_value={'ok':True}),patch.object(h,'run',return_value='AUTHENTICATED ORIGIN PATH READY'):
   self.assertTrue(h.snapshot(c)['connected'])
 def test_four_gre_types(self):
  for kind in ['gre','gretap','ip6gre','ip6gretap']:
   c=config();s=c['settings'];s['transport']=kind
   if kind.startswith('ip6'):s.update(local_endpoint='2001:db8::1',peer_host='2001:db8::2')
   h.validate(c);commands=h.gre_commands(c);self.assertIn(kind,commands[0]);self.assertNotIn('default',sum(commands,[]));self.assertLessEqual(len(h.paths(c['id'])['iface']),15)
 def test_invalid_inputs(self):
  for mutation in [lambda c:c.update(id='sb-../../etc/shadow'),lambda c:c['settings'].update(local_endpoint='x;reboot'),lambda c:c['settings'].update(gre_key='1;reboot'),lambda c:c['settings'].update(inner_foreign='10.227.0.1/30'),lambda c:c['settings'].update(proxy_protocol=True),lambda c:c['settings'].update(mtu=0),lambda c:c['settings'].update(bind_device='eth0;reboot')]:
   c=config();mutation(c)
   with self.assertRaises((ValueError,TypeError)):h.validate(c)
 def test_owned_interface_gate(self):
  c=config();fake=subprocess.CompletedProcess([],0,json.dumps([{'ifalias':'some-other-service'}]),'')
  with patch.object(h,'run',return_value=fake):
   with self.assertRaisesRegex(RuntimeError,'unmanaged'):h.own_interface(c)
 def test_nft_single_selector(self):
  c=config();c['settings']['mappings'][0]['destinations']=[{'address':'10.226.0.2','port':443,'weight':1},{'address':'10.226.0.3','port':8443,'weight':2}];c['settings']['allowed_sources']=['192.0.2.0/24']
  rules=h.nft_rules(c);self.assertEqual(rules.count('numgen inc'),1);self.assertIn('map {',rules);self.assertIn('fib daddr type local',rules);self.assertIn('saddr != @allowed drop',rules);self.assertIn('comment "speedbox:'+c['id'],rules);self.assertNotIn('flush ruleset',rules);self.assertIn('ct direction original counter name traffic_rx',rules)
 def test_separate_conntrack_marks(self):
  a=config();b=config();b['subnet']+=1;self.assertNotEqual(a['subnet']<<12,b['subnet']<<12);self.assertIn(str(a['subnet']<<12),h.nft_rules(a));self.assertIn(str(b['subnet']<<12),h.nft_rules(b))
 def test_stop_is_not_resurrected(self):
  c=config();c['enabled']=False
  with tempfile.TemporaryDirectory() as d,patch.object(h,'ROOT',Path(d)),patch.object(h,'ETC',Path(d)),patch.object(h,'UNITS',Path(d)),patch.object(h,'run') as run:
   run.return_value=subprocess.CompletedProcess([],3,'','')
   with patch.object(h,'snapshot',return_value={'checked_at':int(time.time()),'state':'stopped','destinations':{},'quota_exhausted':False,'quota_stopped':False,'last_restart':0}),patch.object(h,'install') as install:
    h.daemon_tick(c);install.assert_not_called()
 def test_period_calendar(self):
  c=config()['settings'];c['quota_period']='daily';stamp=dt.datetime(2026,10,20,21,tzinfo=dt.timezone.utc).timestamp();self.assertEqual(h.period_key(c,stamp),'2026-10-21')
 def test_systemd_quotes_and_secret(self):
  self.assertEqual(h.unit_argument('x%y'),'"x%%y"');self.assertIn('speedbox-network:managed=1',h.service_text(config()))
 def test_gost_unsupported_quota(self):
  c=config('gost');c['settings']['quota_gb']=1
  with self.assertRaisesRegex(ValueError,'quota'):h.validate(c)
 def test_unknown_udp_health_is_not_red_or_green(self):
  c=config('gost');c['settings']['protocol']='udp';c['settings']['monitor_port']=None
  with tempfile.TemporaryDirectory() as d,patch.object(h,'ROOT',Path(d)),patch.object(h,'ETC',Path(d)),patch.object(h,'UNITS',Path(d)),patch.object(h,'active_unit',return_value=True):
   result=h.snapshot(c);self.assertIsNone(result['connected']);self.assertFalse(result['traffic_available'])
 def test_explicit_ip_family(self):
  for family,peer in [('ipv4','192.0.2.2'),('ipv6','2001:db8::2')]:
   c=config('gost');c['settings'].update(ip_family=family,peer_host=peer);h.validate(c)
   c['settings']['ip_family']='ipv6' if family=='ipv4' else 'ipv4'
   with self.assertRaisesRegex(ValueError,'family'):h.validate(c)
 def test_foreign_direct_has_no_listener(self):
  self.assertIsNone(h.service_text(config('gost','foreign')))
class RealGost(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.dir=tempfile.TemporaryDirectory();cls.binary={}
  manifest=json.loads((ROOT/'gost-dist/manifest.json').read_text())
  for version in ['2.11.5','3.0.0']:
   e=manifest['artifacts'][version]['amd64'];raw=(ROOT/'gost-dist'/e['file']).read_bytes();assert hashlib.sha256(raw).hexdigest()==e['sha256'];binary=gzip.decompress(raw);assert hashlib.sha256(binary).hexdigest()==e['binary_sha256'];path=Path(cls.dir.name)/('gost-'+version);path.write_bytes(binary);path.chmod(0o700);cls.binary[version]=path
 @classmethod
 def tearDownClass(cls):cls.dir.cleanup()
 def freeport(self,kind=socket.SOCK_STREAM):
  with socket.socket(type=kind) as sock:sock.bind(('127.0.0.1',0));return sock.getsockname()[1]
 def transfer(self,version,udp=False,grpc=False,ipv6=False):
  kind=socket.SOCK_DGRAM if udp else socket.SOCK_STREAM;target=socket.socket(socket.AF_INET6 if ipv6 else socket.AF_INET,kind);target.bind(('::1' if ipv6 else '127.0.0.1',0));target.settimeout(8)
  if not udp:target.listen()
  errors=[]
  def echo():
   try:
    if udp:data,addr=target.recvfrom(4096);target.sendto(data,addr)
    else:
     conn,_=target.accept()
     with conn:conn.settimeout(8);data=conn.recv(4096);conn.sendall(data)
   except Exception as exc:errors.append(exc)
  thread=threading.Thread(target=echo,daemon=True);thread.start()
  c=config('gost');s=c['settings'];s.update(version=version,peer_host='::1' if ipv6 else '127.0.0.1',ip_family='ipv6' if ipv6 else 'ipv4',protocol='udp' if udp else 'tcp',transport='grpc' if grpc else 'tcp',tunnel_port=self.freeport());public=self.freeport(kind);s['mappings']=[{'public_port':public,'public_end':public,'target_port':target.getsockname()[1],'target_end':target.getsockname()[1]}]
  processes=[];logs=tempfile.TemporaryFile(mode='w+')
  try:
   if grpc:
    foreign=copy.deepcopy(c);foreign['side']='foreign';processes.append(subprocess.Popen([str(self.binary[version]),*h.gost_arguments(foreign)],stdout=subprocess.DEVNULL,stderr=logs));time.sleep(.25)
   processes.append(subprocess.Popen([str(self.binary[version]),*h.gost_arguments(c)],stdout=subprocess.DEVNULL,stderr=logs))
   deadline=time.monotonic()+5
   while time.monotonic()<deadline:
    if processes[-1].poll() is not None:
     logs.seek(0);self.fail('GOST exited before listening: '+logs.read())
    files=['/proc/net/udp','/proc/net/udp6'] if udp else ['/proc/net/tcp','/proc/net/tcp6']
    if any(any(line.split()[1].endswith(':'+format(public,'04X')) for line in Path(file).read_text().splitlines()[1:]) for file in files):break
    time.sleep(.02)
   else:self.fail('GOST listener was not ready before timeout')
   with socket.socket(type=kind) as client:
    client.settimeout(6);client.connect(('127.0.0.1',public));client.sendall(b'speedbox-real-transfer');self.assertEqual(client.recv(4096),b'speedbox-real-transfer')
   thread.join(2);self.assertFalse(errors)
  finally:
   for proc in reversed(processes):proc.terminate();proc.wait(timeout=4)
   target.close();logs.close()
 def test_v2_tcp(self):self.transfer('2.11.5')
 def test_v2_udp(self):self.transfer('2.11.5',udp=True)
 def test_v3_tcp(self):self.transfer('3.0.0')
 def test_v3_udp(self):self.transfer('3.0.0',udp=True)
 def test_v2_ipv6_tcp(self):self.transfer('2.11.5',ipv6=True)
 def test_v2_ipv6_udp(self):self.transfer('2.11.5',udp=True,ipv6=True)
 def test_v3_ipv6_tcp(self):self.transfer('3.0.0',ipv6=True)
 def test_v3_ipv6_udp(self):self.transfer('3.0.0',udp=True,ipv6=True)
 def test_v3_grpc_tcp(self):self.transfer('3.0.0',grpc=True)
 def test_v3_grpc_udp(self):self.transfer('3.0.0',udp=True,grpc=True)
if __name__=='__main__':unittest.main(verbosity=2)
