#!/usr/bin/env python3
"""Actual bounded systemd worker and stdin polling, with a local authenticated echo."""
import importlib.util,json,os,subprocess,time,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('probe',ROOT/'scripts/network-tunnels/probe.py')
p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)

def request(action,test_id):
 result=subprocess.run(['/usr/bin/python3',str(ROOT/'scripts/network-tunnels/probe.py'),'-'],input=json.dumps({'action':action,'id':test_id}),text=True,capture_output=True,timeout=20)
 reply=json.loads(result.stdout)
 assert result.returncode==0 and reply.get('ok'),(result.stdout,result.stderr)
 return reply['data']

if __name__=='__main__':
 assert os.geteuid()==0,'requires root on a systemd CI runner'
 test_id=uuid.uuid4().hex;echo=p.Echo('127.0.0.1','c'*64);echo.start();base=p.folder(test_id)
 config={'id':test_id,'engine':'gost','version':'3.0.0','transport':'tcp','ip_family':'ipv4','side':'iran','token':'c'*64,'peer_host':'127.0.0.1','peer_port':echo.port}
 p.atomic(base/'config.json',config);p.atomic(base/'state.json',{'state':'ready','port':echo.port})
 try:
  request('measure-start',test_id);samples=[];end=time.monotonic()+20
  while time.monotonic()<end:
   state=request('measure-state',test_id);samples.append(state)
   if state['state'] in ('completed','failed'):break
   time.sleep(.15)
  assert state['state']=='completed',state
  assert any(s['state']=='running' and s['protocols'].get('tcp',{}).get('ok',0)>0 for s in samples),'no live TCP sample'
  assert any(s['state']=='running' and s['protocols'].get('tcp',{}).get('status')=='ok' for s in samples),'no live protocol completion'
  assert all(state['protocols'][proto]['ok']==20 for proto in ('tcp','udp')),state
  assert all(state['protocols'][proto]['download_bytes']>0 and state['protocols'][proto]['upload_bytes']>0 for proto in ('tcp','udp')),state
  # Cancellation while a second worker runs must remove the worker and files.
  (base/'measurement.json').unlink();request('measure-start',test_id)
  request('stop',test_id);assert not base.exists()
  assert subprocess.run(['systemctl','is-active','--quiet','speedbox-net-measure-'+test_id+'.service']).returncode!=0
  print('PASS: real systemd worker, stdin requests, live TCP/UDP samples, speed measurements and cancellation')
 finally:
  if base.exists():request('stop',test_id)
  echo.close()
