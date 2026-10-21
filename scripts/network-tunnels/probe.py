#!/usr/bin/env python3
"""Bounded, authenticated engine tests. No global routes, firewall or sysctl edits."""
import contextlib,hmac,ipaddress,json,os,re,shutil,socket,statistics,subprocess,sys,threading,time
from pathlib import Path
ROOT=Path('/var/lib/speedbox-network-tests')
GOST=Path('/opt/speedbox-network')
SAMPLES=20
BULK=8*1024*1024
PACKETS=256

def run(args,check=True):
 return subprocess.run(args,text=True,capture_output=True,timeout=12,check=check)
def identity(value):
 if not isinstance(value,str) or not re.fullmatch('[a-f0-9]{32}',value):raise ValueError('invalid test identity')
 return value
def folder(value):return ROOT/identity(value)
def atomic(path,value):
 path.parent.mkdir(parents=True,exist_ok=True,mode=0o700);tmp=path.with_suffix('.tmp');tmp.write_text(json.dumps(value));tmp.chmod(0o600);tmp.replace(path)
def read(path):return json.loads(path.read_text())
def validate(c):
 identity(c['id'])
 if c['engine'] not in ('gost','gre') or c['side'] not in ('iran','foreign'):raise ValueError('invalid engine or side')
 if not re.fullmatch('[a-f0-9]{64}',c['token']):raise ValueError('invalid test token')
 family=6 if c['ip_family']=='ipv6' else 4 if c['ip_family']=='ipv4' else 0
 if not family or ipaddress.ip_address(c['peer_host']).version!=family:raise ValueError('invalid peer address family')
 if c['engine']=='gost':
  if c['version'] not in ('2.11.5','3.0.0') or c['transport']!='tcp':raise ValueError('invalid GOST method')
 else:
  if c['transport'] not in ('gre','gretap','ip6gre','ip6gretap') or c['transport'].startswith('ip6')!=(family==6):raise ValueError('invalid GRE method')
  if ipaddress.ip_address(c['local_endpoint']).version!=family:raise ValueError('invalid local address')
  for key in ('inner_iran','inner_foreign'):
   if ipaddress.ip_address(c[key]).version!=4:raise ValueError('invalid test subnet')
  a=ipaddress.ip_address(c['inner_iran']);b=ipaddress.ip_address(c['inner_foreign']);block=ipaddress.ip_network('10.253.0.0/16')
  if a==b or a not in block or b not in block or int(a)//4!=int(b)//4 or not 1<=int(c['gre_key'])<=4294967295:raise ValueError('invalid test key or subnet')
 if c['side']=='iran' and not 1<=int(c['peer_port'])<=65535:raise ValueError('invalid peer port')
 return c

def iface(c):return 'sbt'+c['id'][:11]
def alias(c):return 'speedbox-probe:'+c['id']
def cleanup(c):
 if c['engine']!='gre':return
 answer=run(['ip','-j','link','show','dev',iface(c)],False)
 if answer.returncode:return
 links=json.loads(answer.stdout)
 if not links or links[0].get('ifalias')!=alias(c):raise RuntimeError('test interface ownership differs; interface kept')
 run(['ip','link','delete','dev',iface(c)])

def setup_gre(c):
 name=iface(c);local=c['local_endpoint'] if c['side']=='iran' else c['peer_host'];peer=c['peer_host'] if c['side']=='iran' else c['local_endpoint']
 own=c['inner_'+c['side']];other=c['inner_foreign' if c['side']=='iran' else 'inner_iran']
 if run(['ip','link','show','dev',name],False).returncode==0:raise RuntimeError('test interface already exists')
 for address in (own,other):
  # Refuse a pre-existing specific route. Never replace a host's route.
  found=run(['ip','-j','route','show','match',address+'/32'])
  if any(row.get('dst','default')!='default' for row in json.loads(found.stdout)):raise RuntimeError('test address overlaps an existing route')
 args=['ip','link','add',name,'alias',alias(c),'type',c['transport'],'local',local,'remote',peer,'key',str(c['gre_key'])]
 args+=['hoplimit','64','encaplimit','4'] if c['transport'].startswith('ip6') else ['ttl','64']
 run(args)
 try:
  run(['ip','link','set','dev',name,'mtu','1400'])
  run(['ip','address','add',own+'/32','dev',name])
  run(['ip','link','set','dev',name,'up'])
  run(['ip','route','add',other+'/32','dev',name,'src',own])
 except BaseException:
  cleanup(c);raise

def line(sock):
 buf=bytearray()
 while len(buf)<2048:
  b=sock.recv(1)
  if not b:raise ConnectionError('short test response')
  if b==b'\n':return json.loads(buf)
  buf.extend(b)
 raise ValueError('test header too long')
def send(sock,value):sock.sendall(json.dumps(value).encode()+b'\n')

class Echo:
 def __init__(self,host,token,port=0):
  self.token=token;family=socket.AF_INET6 if ':' in host else socket.AF_INET
  self.tcp=socket.socket(family);self.udp=socket.socket(family,socket.SOCK_DGRAM);self.udp.setsockopt(socket.SOL_SOCKET,socket.SO_RCVBUF,1048576)
  self.tcp.bind((host,port));self.port=self.tcp.getsockname()[1];self.udp.bind((host,self.port));self.tcp.listen(4);self.tcp.settimeout(.5);self.udp.settimeout(.5)
  self.closed=False;self.slots=threading.BoundedSemaphore(4);self.uploads={}
 def authorized(self,q):return isinstance(q.get('token'),str) and hmac.compare_digest(q['token'],self.token)
 def tcp_client(self,conn):
  try:
   with conn:
    conn.settimeout(5);q=line(conn)
    if not self.authorized(q):return
    mode=q.get('op')
    if mode=='echo':send(conn,{'seq':q.get('seq')})
    elif mode=='download':
     data=b'x'*32768
     for _ in range(BULK//len(data)):conn.sendall(data)
    elif mode=='upload':
     n=0
     while n<BULK:
      data=conn.recv(min(65536,BULK-n))
      if not data:break
      n+=len(data)
     send(conn,{'bytes':n})
  except (OSError,ValueError):pass
  finally:self.slots.release()
 def tcp_loop(self):
  while not self.closed:
   try:
    conn,_=self.tcp.accept()
    if not self.slots.acquire(False):conn.close();continue
    threading.Thread(target=self.tcp_client,args=(conn,),daemon=True).start()
   except socket.timeout:continue
   except OSError:break
 def udp_loop(self):
  while not self.closed:
   try:
    data,addr=self.udp.recvfrom(4096);q=json.loads(data)
    if not self.authorized(q):continue
    nonce=q.get('nonce','')
    if not re.fullmatch('[a-f0-9]{16}',nonce):continue
    mode=q.get('op');key=nonce
    if mode=='echo':self.udp.sendto(json.dumps({'nonce':nonce,'seq':q.get('seq')}).encode(),addr)
    elif mode=='download':
     i=q.get('i')
     if isinstance(i,int) and 0<=i<PACKETS:self.udp.sendto(json.dumps({'nonce':nonce,'i':i,'data':'x'*1000}).encode(),addr)
    elif mode=='upload':
     if key not in self.uploads:
      if len(self.uploads)>=8:self.uploads.clear()
      self.uploads[key]={'seen':set(),'first':time.monotonic(),'last':time.monotonic()}
     state=self.uploads[key];i=q.get('i')
     if isinstance(i,int) and 0<=i<PACKETS and q.get('data')=='x'*1000:
      state['seen'].add(i);state['last']=time.monotonic();self.udp.sendto(json.dumps({'nonce':nonce,'ack':i}).encode(),addr)
    elif mode=='report':
     state=self.uploads.get(key);answer={'nonce':nonce,'bytes':len(state['seen'])*1000 if state else 0,'seconds':max(.001,state['last']-state['first']) if state else 1};self.udp.sendto(json.dumps(answer).encode(),addr)
   except socket.timeout:continue
   except (OSError,ValueError,TypeError):continue
 def start(self):
  for target in (self.tcp_loop,self.udp_loop):threading.Thread(target=target,daemon=True).start()
 def close(self):self.closed=True;self.tcp.close();self.udp.close()

def tcp_request(host,port,token,mode,seq=0):
 conn=socket.create_connection((host,port),timeout=1.5);conn.settimeout(4);send(conn,{'token':token,'op':mode,'seq':seq});return conn

def measure_protocol(host,port,token,proto,budget_seconds=45):
 deadline=time.monotonic()+max(.01,min(float(budget_seconds),45))
 timings=[];udp=None;nonce=os.urandom(8).hex()
 if proto=='udp':udp=socket.socket(socket.AF_INET6 if ':' in host else socket.AF_INET,socket.SOCK_DGRAM);udp.setsockopt(socket.SOL_SOCKET,socket.SO_RCVBUF,1048576);udp.settimeout(1.5);udp.connect((host,port))
 def request(op,**extra):udp.send(json.dumps({'token':token,'nonce':nonce,'op':op,**extra}).encode())
 def response(required=None,expected=None):
  end=min(deadline,time.monotonic()+1.5)
  while time.monotonic()<end:
   udp.settimeout(max(.01,end-time.monotonic()))
   q=json.loads(udp.recv(4096))
   if q.get('nonce')==nonce and (required is None or required in q and (expected is None or q[required]==expected)):return q
  raise TimeoutError('no authenticated UDP reply')
 try:
  for seq in range(SAMPLES):
   if time.monotonic()>=deadline:break
   start=time.monotonic()
   try:
    if udp:request('echo',seq=seq);answer=response('seq',seq)
    else:
     with tcp_request(host,port,token,'echo',seq) as conn:answer=line(conn)
    if answer.get('seq')==seq:timings.append((time.monotonic()-start)*1000)
   except (OSError,ValueError):pass
   time.sleep(.1)
  download=upload=0;download_bytes=upload_bytes=0
  if timings and time.monotonic()<deadline:
   try:
    if udp:
     nonce=os.urandom(8).hex();seen=set();start=time.monotonic()
     for base in range(0,PACKETS,16):
      if time.monotonic()>=deadline:break
      window=range(base,min(PACKETS,base+16))
      for i in window:request('download',i=i)
      for _ in window:
       if time.monotonic()>=deadline:break
       try:q=response('data')
       except (OSError,ValueError):break
       if q.get('data')=='x'*1000 and isinstance(q.get('i'),int) and 0<=q['i']<PACKETS:seen.add(q['i'])
     elapsed=time.monotonic()-start;download_bytes=len(seen)*1000;download=download_bytes*8/max(.001,elapsed)/1e6
     nonce=os.urandom(8).hex()
     for base in range(0,PACKETS,16):
      if time.monotonic()>=deadline:break
      window=range(base,min(PACKETS,base+16))
      for i in window:request('upload',i=i,data='x'*1000)
      for _ in window:
       if time.monotonic()>=deadline:break
       try:response('ack')
       except (OSError,ValueError):break
     request('report');q=response('bytes');upload_bytes=min(PACKETS*1000,max(0,int(q.get('bytes',0))));upload=upload_bytes*8/max(.001,float(q.get('seconds',1)))/1e6
    else:
     start=time.monotonic()
     with tcp_request(host,port,token,'download') as conn:
      end=min(deadline,time.monotonic()+8)
      while download_bytes<BULK and time.monotonic()<end:
       buf=conn.recv(min(65536,BULK-download_bytes))
       if not buf:break
       download_bytes+=len(buf)
     download=download_bytes*8/max(.001,time.monotonic()-start)/1e6
     start=time.monotonic()
     with tcp_request(host,port,token,'upload') as conn:
      chunk=b'x'*32768
      end=min(deadline,time.monotonic()+8)
      for _ in range(BULK//len(chunk)):
       if time.monotonic()>=end:break
       conn.sendall(chunk)
      upload_bytes=min(BULK,max(0,int(line(conn).get('bytes',0))))
     upload=upload_bytes*8/max(.001,time.monotonic()-start)/1e6
   except (OSError,ValueError):pass
  ok=len(timings);expected=PACKETS*1000 if udp else BULK;status='ok' if ok==SAMPLES and download_bytes==expected and upload_bytes==expected else 'unstable' if ok>0 else 'down'
  return {'status':status,'ok':ok,'total':SAMPLES,'rtt':round(statistics.mean(timings),2) if timings else 0,'jitter':round(statistics.pstdev(timings),2) if len(timings)>1 else 0,'download':round(download,3),'upload':round(upload,3),'download_bytes':download_bytes,'upload_bytes':upload_bytes,'loss_percent':round(100*(1-ok/SAMPLES),2),'bulk_loss_percent':round(100*(1-min(download_bytes,upload_bytes)/expected),2)}
 finally:
  if udp:udp.close()

def measure(c,state):
 host='127.0.0.1' if c['engine']=='gost' else c['inner_foreign'];port=state['port']
 return {proto:measure_protocol(host,port,c['token'],proto) for proto in ('tcp','udp')}

def serve(c):
 validate(c);base=folder(c['id']);echo=None;proc=None
 try:
  if c['engine']=='gre':setup_gre(c)
  if c['side']=='foreign':
   host=c['inner_foreign'] if c['engine']=='gre' else '::' if c['ip_family']=='ipv6' else '0.0.0.0';echo=None
   for _ in range(30):
    candidate=Echo(host,c['token'])
    if candidate.port not in c.get('reserved_ports',[]):echo=candidate;break
    candidate.close()
   if echo is None:raise RuntimeError('no unreserved temporary test port available')
   echo.start();port=echo.port
  elif c['engine']=='gost':
   for _ in range(30):
    with socket.socket() as guard,socket.socket(type=socket.SOCK_DGRAM) as datagram:
     guard.bind(('127.0.0.1',0));port=guard.getsockname()[1]
     try:datagram.bind(('127.0.0.1',port))
     except OSError:continue
     if port not in c.get('reserved_ports',[]):break
   else:raise RuntimeError('no unreserved temporary test port available')
   peer='['+c['peer_host']+']' if ':' in c['peer_host'] else c['peer_host']
   args=[str(GOST/('gost-'+c['version']))]+['-L='+proto+'://127.0.0.1:'+str(port)+'/'+peer+':'+str(c['peer_port']) for proto in ('tcp','udp')]
   proc=subprocess.Popen(args,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
   deadline=time.monotonic()+8
   while time.monotonic()<deadline:
    if proc.poll() is not None:raise RuntimeError('temporary GOST process exited')
    try:
     with socket.create_connection(('127.0.0.1',port),.2):break
    except OSError:time.sleep(.05)
   else:raise RuntimeError('temporary GOST listener did not start')
  else:port=c['peer_port']
  atomic(base/'state.json',{'state':'ready','port':port})
  deadline=time.monotonic()+200
  while time.monotonic()<deadline:
   if proc and proc.poll() is not None:raise RuntimeError('temporary GOST process stopped')
   time.sleep(.5)
 except BaseException as error:
  atomic(base/'state.json',{'state':'failed','error':str(error)[:400]});raise
 finally:
  if echo:echo.close()
  if proc and proc.poll() is None:
   proc.terminate()
   with contextlib.suppress(subprocess.TimeoutExpired):proc.wait(timeout=3)
  cleanup(c)

def dispatch(q):
 test_id=identity(q['id']);base=folder(test_id);config_path=base/'config.json';unit='speedbox-net-test-'+test_id
 action=q['action']
 if action=='start':
  c=validate(q['config'])
  if c['id']!=test_id:raise ValueError('test identity mismatch')
  if base.exists():raise RuntimeError('test already started; cleanup required')
  atomic(config_path,c)
  helper=str(Path(__file__).resolve())
  run(['systemd-run','--quiet','--collect','--unit='+unit,'--property=RuntimeMaxSec=220','--property=TimeoutStopSec=8','--property=KillMode=control-group','--property=ExecStopPost=/usr/bin/python3 '+helper+' cleanup '+str(config_path),'/usr/bin/python3',helper,'serve',str(config_path)])
  for _ in range(120):
   if (base/'state.json').exists():
    state=read(base/'state.json')
    if state.get('state')=='failed':raise RuntimeError(state.get('error','test startup failed'))
    return state
   time.sleep(.1)
  raise RuntimeError('temporary test did not become ready')
 if action=='stop':
  result=run(['systemctl','stop',unit+'.service'],False)
  if config_path.exists():cleanup(validate(read(config_path)))
  elif q.get('engine')=='gre':cleanup({'id':test_id,'engine':'gre'})
  active=run(['systemctl','is-active','--quiet',unit+'.service'],False)
  if active.returncode==0:raise RuntimeError('temporary test is still running')
  shutil.rmtree(base,ignore_errors=True)
  return {'stopped':True}
 if action=='measure':
  c=validate(read(config_path))
  if c['side']!='iran':raise ValueError('measurement must originate in Iran')
  return measure(c,read(base/'state.json'))
 raise ValueError('unsupported test action')

if __name__=='__main__':
 os.umask(0o077)
 try:
  if len(sys.argv)==3 and sys.argv[1] in ('serve','cleanup'):
   path=Path(sys.argv[2]);c=validate(read(path))
   if path!=folder(c['id'])/'config.json':raise ValueError('invalid test config path')
   if sys.argv[1]=='serve':serve(c)
   else:cleanup(c);shutil.rmtree(folder(c['id']),ignore_errors=True)
  else:
   if os.geteuid()!=0:raise RuntimeError('root or passwordless sudo required')
   answer=dispatch(read(Path(sys.argv[1])));print(json.dumps({'ok':True,'data':answer}))
 except Exception as error:
  print(json.dumps({'ok':False,'error':str(error)[:500]}));sys.exit(1)
