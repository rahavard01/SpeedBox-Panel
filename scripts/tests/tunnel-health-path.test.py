#!/usr/bin/env python3
"""Real pinned GOST + authenticated endpoint paths; temporary loopback ports only."""
import copy,gzip,hashlib,importlib.util,json,os,signal,socket,subprocess,sys,tempfile,threading,time,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
def load(name,path):
 spec=importlib.util.spec_from_file_location(name,ROOT/path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
h=load('path_host','scripts/network-tunnels/host.py');o=load('path_origin','scripts/tunnel-origin/host.py');p=load('path_probe','scripts/network-tunnels/probe.py')
ID='sb-'+'a'*32;SECRET='b'*64
def port(low=1024):
 for _ in range(100):
  with socket.socket() as s:s.bind(('127.0.0.1',0));v=s.getsockname()[1]
  if low<=v<=60000:return v
 raise RuntimeError('no test port')
class Echo:
 def __init__(self):
  self.tcp=socket.socket();self.tcp.bind(('127.0.0.1',0));self.tcp.listen();self.port=self.tcp.getsockname()[1];self.udp=socket.socket(type=socket.SOCK_DGRAM);self.udp.bind(('127.0.0.1',self.port));self.closed=False
  def client(c):
   try:
    with c:
     while True:
      b=c.recv(65536)
      if not b:return
      c.sendall(b)
   except OSError:pass
  def tcp():
   while not self.closed:
    try:c,_=self.tcp.accept();threading.Thread(target=client,args=(c,),daemon=True).start()
    except OSError:return
  def udp():
   while not self.closed:
    try:b,a=self.udp.recvfrom(65536);self.udp.sendto(b,a)
    except OSError:return
  threading.Thread(target=tcp,daemon=True).start();threading.Thread(target=udp,daemon=True).start()
 def close(self):self.closed=True;self.tcp.close();self.udp.close()
class Paths(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.tmp=tempfile.TemporaryDirectory();cls.base=Path(cls.tmp.name);cls.origin=Path(os.environ['SPEEDBOX_TEST_ORIGIN']).resolve();cls.gost={}
  manifest=json.loads((ROOT/'gost-dist/manifest.json').read_text())
  for version in ('2.11.5','3.0.0'):
   entry=manifest['artifacts'][version]['amd64'];packed=(ROOT/'gost-dist'/entry['file']).read_bytes();assert hashlib.sha256(packed).hexdigest()==entry['sha256'];raw=gzip.decompress(packed);assert hashlib.sha256(raw).hexdigest()==entry['binary_sha256'];binary=cls.base/('gost-'+version);binary.write_bytes(raw);binary.chmod(0o700);cls.gost[version]=binary
 @classmethod
 def tearDownClass(cls):cls.tmp.cleanup()
 def processes(self):
  processes=[];self.addCleanup(lambda:[self.stop(x) for x in reversed(processes)])
  return processes
 def stop(self,proc):
  if proc.poll() is None:
   os.killpg(proc.pid,signal.SIGTERM)
   try:proc.wait(timeout=4)
   except subprocess.TimeoutExpired:os.killpg(proc.pid,signal.SIGKILL);proc.wait()
 def start(self,processes,argv):
  child=subprocess.Popen(list(map(str,argv)),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True);processes.append(child);return child
 def test_real_health_through_native_paths_and_wrong_grpc_credentials(self):
  for version,transport in [('2.11.5','tcp'),('3.0.0','tcp'),('3.0.0','grpc')]:
   for valid in ([True,False] if transport=='grpc' else [True]):
    with self.subTest(version=version,transport=transport,valid=valid),tempfile.TemporaryDirectory() as d:
     d=Path(d);processes=[];echo=Echo()
     try:
      relay,foreign,public,carrier=port(),port(),port(),port()
      plan={'version':2,'side':'iran','port':relay,'secret':SECRET,'entries':[],'health_only':True,'tcp':True,'udp':True}
      with patch.object(o,'RUN',d/'run'):
       egress=o.config_for({**plan,'side':'foreign'},ID);egress['listen']='127.0.0.1:'+str(foreign)
       ingress=o.config_for(plan,ID)
      for name,cfg in [('foreign',egress),('iran',ingress)]: (d/(name+'.json')).write_text(json.dumps(cfg))
      self.start(processes,[self.origin,d/'foreign.json'])
      end=time.monotonic()+5
      while not Path(egress['ready_file']).exists() and time.monotonic()<end:time.sleep(.02)
      self.assertTrue(Path(egress['ready_file']).exists())
      settings={**json.loads((ROOT/'scripts/network-tunnels/defaults.json').read_text()),'engine':'gost','version':version,'transport':transport,'protocol':'both','peer_host':'127.0.0.1','tunnel_port':carrier,'mappings':[{'public_port':public,'public_end':public,'target_port':echo.port,'target_end':echo.port}]}
      c={'id':ID,'engine':'gost','side':'iran','enabled':True,'secret':SECRET if valid else 'c'*64,'subnet':33000,'settings':settings}
      native=o.transform('network',{'action':'apply','config':c},plan)['config'];native['settings']['mappings'][-1].update(target_port=foreign,target_end=foreign)
      if transport=='grpc':self.start(processes,[self.gost[version],*h.gost_arguments({**c,'side':'foreign','secret':SECRET})]);time.sleep(.15)
      self.start(processes,[self.gost[version],*h.gost_arguments(native)])
      end=time.monotonic()+5
      while time.monotonic()<end:
       try:
        with socket.create_connection(('127.0.0.1',relay),.2):break
       except OSError:time.sleep(.03)
      real_run=h.run
      def run(argv,**kwargs):
       if str(argv[0])=='/opt/speedbox-origin/tunnel-origin':argv=[self.origin,'probe',d/'iran.json']
       return real_run(argv,**kwargs)
      with patch.object(h,'ROOT',d/'state'),patch.object(h,'active_unit',return_value=True),patch.object(h,'run',side_effect=run):
       result=h.snapshot(native)
      self.assertEqual(result['path_verified'],valid,result);self.assertEqual(result['connected'],valid,result)
      if valid:
       for kind in (socket.SOCK_STREAM,socket.SOCK_DGRAM):
        with socket.socket(type=kind) as s:
         s.settimeout(3);s.connect(('127.0.0.1',public));s.sendall(b'unchanged-user-forward');self.assertEqual(s.recv(4096),b'unchanged-user-forward')
     finally:
      for proc in reversed(processes):self.stop(proc)
      echo.close()
 def test_real_grpc_candidate_worker_transfers_tcp_and_udp(self):
  runner='''import importlib.util,json,pathlib,signal,sys
spec=importlib.util.spec_from_file_location('probe',sys.argv[1]);p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)
p.ROOT=pathlib.Path(sys.argv[2]);p.GOST=pathlib.Path(sys.argv[3])
def stop(*args):raise SystemExit()
signal.signal(signal.SIGTERM,stop);p.serve(json.loads(pathlib.Path(sys.argv[4]).read_text()))
'''
  with tempfile.TemporaryDirectory() as d:
   d=Path(d);processes=[];testid='d'*32;backend=port(33000)
   c={'id':testid,'engine':'gost','version':'3.0.0','transport':'grpc','ip_family':'ipv4','token':SECRET,'peer_host':'127.0.0.1','backend_port':backend,'reserved_ports':[]}
   try:
    for side in ('foreign','iran'):
     cfg={**c,'side':side,'peer_port':None if side=='foreign' else state['port']};cfgpath=d/(side+'.json');cfgpath.write_text(json.dumps(cfg));base=d/side
     log=open(d/(side+'.log'),'w+');self.addCleanup(log.close)
     child=subprocess.Popen(list(map(str,[sys.executable,'-c',runner,ROOT/'scripts/network-tunnels/probe.py',base,self.base,cfgpath])),stdout=log,stderr=log,start_new_session=True);processes.append(child)
     end=time.monotonic()+8;statepath=base/testid/'state.json'
     while not statepath.exists() and child.poll() is None and time.monotonic()<end:time.sleep(.03)
     log.seek(0);self.assertTrue(statepath.exists(),side+': '+log.read());state=json.loads(statepath.read_text());self.assertEqual(state['state'],'ready',state)
    with patch.object(p,'SAMPLES',2),patch.object(p,'BULK',65536),patch.object(p,'PACKETS',16):
     for protocol in ('tcp','udp'):
      result=p.measure_protocol('127.0.0.1',state['port'],SECRET,protocol);self.assertEqual(result['ok'],2,result);self.assertGreater(result['download_bytes'],0,result);self.assertGreater(result['upload_bytes'],0,result)
   finally:
    for proc in reversed(processes):self.stop(proc)
if __name__=='__main__':unittest.main(verbosity=2)
