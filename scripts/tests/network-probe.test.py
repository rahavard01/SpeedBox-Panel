#!/usr/bin/env python3
import copy,gzip,hashlib,importlib.util,json,os,socket,subprocess,tempfile,time,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('probe',ROOT/'scripts/network-tunnels/probe.py');p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)
def config():return {'id':'a'*32,'engine':'gre','side':'foreign','token':'b'*64,'transport':'gre','ip_family':'ipv4','version':'3.0.0','local_endpoint':'192.0.2.1','peer_host':'192.0.2.2','inner_iran':'10.253.1.1','inner_foreign':'10.253.1.2','gre_key':123}
class Probe(unittest.TestCase):
 def test_untrusted_config(self):
  for key,value in [('id','../../etc'),('token','x'),('peer_host','x;reboot'),('transport','wireguard'),('gre_key',0)]:
   c=config();c[key]=value
   with self.assertRaises((ValueError,KeyError)):p.validate(c)
 def test_ownership_cleanup(self):
  c=config()
  with patch.object(p,'run',return_value=subprocess.CompletedProcess([],0,json.dumps([{'ifalias':'another-owner'}]),'')) as run:
   with self.assertRaisesRegex(RuntimeError,'ownership'):p.cleanup(c)
   self.assertEqual(run.call_count,1)
 def test_scoped_gre_routes(self):
  c=config();calls=[]
  def run(args,check=True):
   calls.append(args)
   return subprocess.CompletedProcess(args,1 if args[:4]==['ip','link','show','dev'] else 0,json.dumps([{'ifalias':p.alias(c)}]) if args[:4]==['ip','-j','link','show'] else '[]','')
  with patch.object(p,'run',side_effect=run):p.setup_gre(c)
  routes=[a for a in calls if a[:3]==['ip','route','add']];self.assertEqual(len(routes),1);self.assertEqual(routes[0][3],'10.253.1.1/32');self.assertNotIn('default',sum(calls,[]));self.assertFalse(any('sysctl' in a or 'nft' in a for a in calls))
 def test_route_collision_kept(self):
  def run(args,check=True):return subprocess.CompletedProcess(args,1 if 'link' in args else 0,'[{"dst":"10.0.0.0/8"}]','')
  with patch.object(p,'run',side_effect=run):
   with self.assertRaisesRegex(RuntimeError,'overlaps'):p.setup_gre(config())
 def test_start_records_before_process_and_has_deadline(self):
  c=config()
  with tempfile.TemporaryDirectory() as d,patch.object(p,'ROOT',Path(d)):
   def run(args,check=True):
    self.assertTrue((p.folder(c['id'])/'config.json').exists());self.assertIn('--property=RuntimeMaxSec=220',args);self.assertTrue(any('ExecStopPost=' in a for a in args));p.atomic(p.folder(c['id'])/'state.json',{'state':'ready','port':12345});return subprocess.CompletedProcess(args,0,'','')
   with patch.object(p,'run',side_effect=run):self.assertEqual(p.dispatch({'action':'start','id':c['id'],'config':c})['port'],12345)
 def test_measurement_has_a_wall_clock_budget(self):
  with socket.socket(type=socket.SOCK_DGRAM) as silent:
   silent.bind(('127.0.0.1',0));started=time.monotonic();result=p.measure_protocol('127.0.0.1',silent.getsockname()[1],'c'*64,'udp',budget_seconds=.03)
  self.assertLess(time.monotonic()-started,.6);self.assertEqual(result['status'],'down');self.assertEqual(result['ok'],0)
 def test_real_authenticated_echo(self):
  echo=p.Echo('127.0.0.1','c'*64);echo.start()
  try:
   with patch.object(p,'SAMPLES',3),patch.object(p,'BULK',65536),patch.object(p,'PACKETS',16):
    for proto in ('tcp','udp'):
     result=p.measure_protocol('127.0.0.1',echo.port,'c'*64,proto);self.assertEqual(result['status'],'ok',result);self.assertGreater(result['download_bytes'],0);self.assertGreater(result['upload_bytes'],0)
   with p.tcp_request('127.0.0.1',echo.port,'d'*64,'echo') as conn:
    with self.assertRaises((OSError,ConnectionError)):p.line(conn)
  finally:echo.close()
 def test_partial_tcp_upload_is_acknowledged_without_false_instability(self):
  echo=p.Echo('127.0.0.1','c'*64);echo.start()
  try:
   with p.tcp_request('127.0.0.1',echo.port,'c'*64,'upload-frames') as conn:
    for _ in range(4):conn.sendall(p.struct.pack('!I',32768)+b'x'*32768)
    conn.sendall(p.struct.pack('!I',0))
    self.assertEqual(p.line(conn)['bytes'],131072)
  finally:echo.close()
 def test_gre_alias_is_set_and_verified_before_address_or_route(self):
  c=config();calls=[]
  def run(args,check=True):
   calls.append(args)
   result=json.dumps([{'ifalias':p.alias(c)}]) if args[:4]==['ip','-j','link','show'] else '[]'
   return subprocess.CompletedProcess(args,1 if args[:4]==['ip','link','show','dev'] else 0,result,'')
  with patch.object(p,'run',side_effect=run):p.setup_gre(c)
  a=calls.index(['ip','link','set','dev',p.iface(c),'alias',p.alias(c)])
  b=next(i for i,x in enumerate(calls) if x[:3]==['ip','address','add'])
  self.assertLess(a,b)
class RealGost(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.tmp=tempfile.TemporaryDirectory();cls.binaries={};manifest=json.loads((ROOT/'gost-dist/manifest.json').read_text())
  for version in ('2.11.5','3.0.0'):
   entry=manifest['artifacts'][version]['amd64'];raw=(ROOT/'gost-dist'/entry['file']).read_bytes();assert hashlib.sha256(raw).hexdigest()==entry['sha256'];dest=Path(cls.tmp.name)/version;dest.write_bytes(gzip.decompress(raw));dest.chmod(0o700);cls.binaries[version]=dest
 @classmethod
 def tearDownClass(cls):cls.tmp.cleanup()
 def test_measured_paths(self):
  for version,binary in self.binaries.items():
   for foreign in ('127.0.0.1','::1'):
    with self.subTest(version=version,foreign=foreign):
     echo=p.Echo(foreign,'c'*64);echo.start()
     with socket.socket() as guard:guard.bind(('127.0.0.1',0));port=guard.getsockname()[1]
     host='['+foreign+']' if ':' in foreign else foreign
     child=subprocess.Popen([str(binary),*['-L='+proto+'://127.0.0.1:'+str(port)+'/'+host+':'+str(echo.port) for proto in ('tcp','udp')]],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
     try:
      end=time.monotonic()+8
      while time.monotonic()<end:
       try:
        with socket.create_connection(('127.0.0.1',port),.2):break
       except OSError:time.sleep(.05)
      with patch.object(p,'SAMPLES',3),patch.object(p,'BULK',65536),patch.object(p,'PACKETS',16):
       for proto in ('tcp','udp'):
        result=p.measure_protocol('127.0.0.1',port,'c'*64,proto);self.assertEqual(result['ok'],3,result)
        self.assertGreater(result['download_bytes'],0,result);self.assertGreater(result['upload_bytes'],0,result)
        expected=16000 if proto=='udp' else 65536
        complete=result['download_bytes']==expected and result['upload_bytes']==expected
        self.assertEqual(result['status'],'ok' if proto=='tcp' or complete else 'unstable',result)
        if proto=='udp':self.assertEqual(result['bulk_loss_percent']==0,complete,result)
     finally:child.terminate();child.wait(timeout=4);echo.close()
if __name__=='__main__':unittest.main(verbosity=2)
