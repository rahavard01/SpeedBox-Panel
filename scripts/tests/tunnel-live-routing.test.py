#!/usr/bin/env python3
"""Real TCP engines, payload integrity and no direct fallback. No systemd or root changes."""
import argparse,contextlib,hashlib,os,pathlib,socket,subprocess,tempfile,threading,time
p=argparse.ArgumentParser();p.add_argument('binary');args=p.parse_args();binary=str(pathlib.Path(args.binary).resolve())
def port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
@contextlib.contextmanager
def echo():
    s=socket.socket();s.bind(('127.0.0.1',0));s.listen();s.settimeout(.2);stop=threading.Event()
    def run():
        while not stop.is_set():
            try:c,_=s.accept()
            except socket.timeout:continue
            def handle(c):
                with c:
                    while True:
                        b=c.recv(65536)
                        if not b:return
                        c.sendall(b)
            threading.Thread(target=handle,args=(c,),daemon=True).start()
    threading.Thread(target=run,daemon=True).start()
    try:yield s.getsockname()[1]
    finally:stop.set();s.close()
def transfer(host,port,payload):
    with socket.create_connection((host,port),2) as c:
        c.settimeout(3);c.sendall(payload);out=b''
        while len(out)<len(payload):
            b=c.recv(65536)
            if not b:raise RuntimeError('truncated payload')
            out+=b
        assert hashlib.sha256(out).digest()==hashlib.sha256(payload).digest()
with tempfile.TemporaryDirectory(prefix='speedbox-live-') as tmp,echo() as backend,echo() as other:
    tmp=pathlib.Path(tmp);control,entry=port(),port();token='a'*64
    server=f'''[server]\ntransport="tcp"\nbind_addr="127.0.0.1:{control}"\ntoken="{token}"\nports=["127.0.0.1:{entry}={backend}"]\nkeepalive_period=5\nheartbeat=3\n'''
    client=f'''[client]\ntransport="tcp"\nremote_addr="127.0.0.1:{control}"\ntoken="{token}"\nconnection_pool=2\nkeepalive_period=5\nheartbeat=3\n'''
    processes=[]
    try:
        for name,text in [('iran',server),('foreign',client)]:
            path=tmp/(name+'.toml');path.write_text(text);log=open(tmp/(name+'.log'),'wb')
            processes.append(subprocess.Popen([binary,'-c',str(path)],stdout=log,stderr=log))
        payload=os.urandom(262144);deadline=time.monotonic()+15
        while True:
            try:transfer('127.0.0.1',entry,payload);break
            except (OSError,RuntimeError):
                if time.monotonic()>deadline:raise
                time.sleep(.15)
        transfer('127.0.0.1',other,payload)
        processes[1].terminate();processes[1].wait(timeout=10)
        try:transfer('127.0.0.1',entry,b'no-direct-fallback')
        except (OSError,RuntimeError):pass
        else:raise AssertionError('tunnel silently bypassed the stopped peer')
        transfer('127.0.0.1',other,payload)
        print('PASS: real two-engine TCP forwarding, payload integrity, peer failure and unrelated service isolation')
    finally:
        for proc in processes:
            if proc.poll() is None:proc.terminate()
        for proc in processes:
            try:proc.wait(timeout=10)
            except subprocess.TimeoutExpired:proc.kill();proc.wait()
