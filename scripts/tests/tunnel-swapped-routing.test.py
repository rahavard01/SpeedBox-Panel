#!/usr/bin/env python3
"""Real Iran ingress -> foreign backend through every swapped native carrier.

Run as root in an isolated CI VM. Private namespaces own all addresses, TUN
interfaces and routes; no existing host tunnel or firewall is touched.
"""
import argparse,json,os,pathlib,socket,subprocess,tempfile,time,uuid

def run(args,**kw):return subprocess.run(args,check=True,capture_output=True,text=True,**kw).stdout

def main():
    parser=argparse.ArgumentParser();parser.add_argument('binary');args=parser.parse_args()
    binary=str(pathlib.Path(args.binary).resolve());suffix=uuid.uuid4().hex[:6]
    iran='sbi'+suffix;foreign='sbf'+suffix;processes=[]
    with tempfile.TemporaryDirectory(prefix='speedbox-swapped-') as temporary:
        root=pathlib.Path(temporary)
        try:
            for ns in [iran,foreign]:run(['ip','netns','add',ns])
            run(['ip','link','add','vi'+suffix,'type','veth','peer','name','vf'+suffix])
            for ns,dev,ip in [(iran,'vi'+suffix,'192.0.2.1'),(foreign,'vf'+suffix,'192.0.2.2')]:
                run(['ip','link','set',dev,'netns',ns]);run(['ip','-n',ns,'addr','add',ip+'/24','dev',dev])
                run(['ip','-n',ns,'link','set',dev,'up']);run(['ip','-n',ns,'link','set','lo','up'])
            echo='''import socket,threading,time
def handle(c):
 with c:
  while True:
   b=c.recv(65536)
   if not b:return
   c.sendall(b)
def tcp():
 s=socket.socket();s.bind(('0.0.0.0',61443));s.listen()
 while True:
  c,_=s.accept();threading.Thread(target=handle,args=(c,),daemon=True).start()
def udp():
 s=socket.socket(type=socket.SOCK_DGRAM);s.bind(('0.0.0.0',61443))
 while True:
  b,a=s.recvfrom(65536);s.sendto(b,a)
threading.Thread(target=tcp,daemon=True).start();threading.Thread(target=udp,daemon=True).start();time.sleep(600)
'''
            backend=subprocess.Popen(['ip','netns','exec',foreign,'python3','-c',echo]);processes.append(backend)
            probe='''import socket
payload=b'speedbox-real-swapped-payload'*1024
with socket.create_connection(('127.0.0.1',60443),2) as c:
 c.settimeout(3);c.sendall(payload);out=b''
 while len(out)<len(payload):
  b=c.recv(65536)
  if not b:raise RuntimeError('short response')
  out+=b
 assert out==payload
s=socket.socket(type=socket.SOCK_DGRAM);s.settimeout(3);s.sendto(b'udp-proof',('127.0.0.1',60443));assert s.recv(100)==b'udp-proof'
'''
            for transport in ['tcp','tcpmux','stealth','ws','wss','wsmux','wssmux','kcp','quic','udp','pck']:
                current=[]
                try:
                    for ns,role,local,peer in [(foreign,'client','10.203.250.2','10.203.250.1'),(iran,'server','10.203.250.1','10.203.250.2')]:
                        request={'action':'render-swapped','name':'sb-'+'a'*32,'kind':'reverse','swapped':True,'role':role,'transport':transport,'host':'192.0.2.2','port':58443,'bridge_port':55211,'token':'b'*64,'ports':'60443=61443','udp':True,'local_ip':local+'/30','peer_ip':peer}
                        path=root/(ns+'.request');path.write_text(json.dumps(request))
                        answer=json.loads(run([binary,'speedbox-json',str(path)]))
                        assert answer['ok'],answer
                        for layer,body in answer['data'].items():
                            config=root/(ns+'-'+layer+'.toml');config.write_text(body)
                            log=open(root/(transport+'-'+ns+'-'+layer+'.log'),'wb')
                            child=subprocess.Popen(['ip','netns','exec',ns,binary,'-c',str(config)],stdout=log,stderr=log)
                            current.append(child);processes.append(child);log.close()
                    deadline=time.monotonic()+35
                    while True:
                        try:run(['ip','netns','exec',iran,'python3','-c',probe],timeout=10);break
                        except (subprocess.SubprocessError,OSError):
                            if time.monotonic()>deadline:
                                for path in root.glob(transport+'-*.log'):print(path.name,path.read_text()[-5000:])
                                raise
                            time.sleep(.3)
                    print('PASS:',transport,'physical Iran TCP/UDP ingress -> foreign backend',flush=True)
                finally:
                    for child in current:
                        if child.poll() is None:child.terminate()
                    for child in current:
                        try:child.wait(timeout=8)
                        except subprocess.TimeoutExpired:child.kill();child.wait()
        finally:
            for child in processes:
                if child.poll() is None:child.kill();child.wait()
            for ns in [iran,foreign]:subprocess.run(['ip','netns','delete',ns],capture_output=True)

if __name__=='__main__':main()
