#!/usr/bin/env python3
"""Real command/upload routing through a temporary reverse SSH channel.

No external server is contacted. Authentication and host-key mismatch must stop
before fallback; commands that partially execute must never be replayed.
"""
import argparse,json,os,pathlib,socket,subprocess,tempfile,threading,time

ROOT=pathlib.Path(__file__).resolve().parents[2]
def run(args,**kw):return subprocess.run(args,check=True,capture_output=True,text=True,**kw).stdout.strip()
def port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--php',default=os.environ.get('PHP_AUDIT_BIN','php'));args=parser.parse_args()
    processes=[]
    with tempfile.TemporaryDirectory(prefix='speedbox-ssh-test-') as temporary:
        tmp=pathlib.Path(temporary)
        try:
            for name in ['host','identity','wrong']:
                run(['ssh-keygen','-q','-t','ed25519','-N','','-f',str(tmp/name)])
            fingerprint=run(['ssh-keygen','-lf',str(tmp/'host.pub'),'-E','sha256']).split()[1]
            direct,reverse=port(),port()
            config=tmp/'sshd_config';config.write_text(f'''Port {direct}
ListenAddress 127.0.0.1
HostKey {tmp}/host
PidFile {tmp}/sshd.pid
AuthorizedKeysFile {tmp}/identity.pub
StrictModes no
PermitRootLogin yes
PasswordAuthentication no
KbdInteractiveAuthentication no
UsePAM yes
AllowTcpForwarding yes
Subsystem sftp internal-sftp
''')
            pathlib.Path('/run/sshd').mkdir(mode=0o755,exist_ok=True)
            log=open(tmp/'sshd.log','w');daemon=subprocess.Popen(['/usr/sbin/sshd','-D','-e','-f',str(config)],stdout=log,stderr=log);processes.append(daemon)
            deadline=time.monotonic()+5
            while True:
                try:
                    with socket.create_connection(('127.0.0.1',direct),.2):break
                except OSError:
                    if time.monotonic()>deadline:raise
                    time.sleep(.1)
            reverse_log=open(tmp/'reverse.log','w');tunnel=subprocess.Popen(['ssh','-v','-NT','-i',str(tmp/'identity'),'-p',str(direct),'-o','StrictHostKeyChecking=no','-o','UserKnownHostsFile=/dev/null','-o','ExitOnForwardFailure=yes','-R',f'127.0.0.1:{reverse}:127.0.0.1:{direct}','root@127.0.0.1'],stdout=reverse_log,stderr=reverse_log);processes.append(tunnel)
            deadline=time.monotonic()+5
            while True:
                try:
                    with socket.create_connection(('127.0.0.1',reverse),.2):break
                except OSError:
                    if time.monotonic()>deadline:raise
                    time.sleep(.1)
            blackhole=socket.socket();blackhole.bind(('127.0.0.1',0));blackhole.listen();silent=blackhole.getsockname()[1];connections=[]
            def accept():
                while True:
                    try:c,_=blackhole.accept();connections.append(c)
                    except OSError:return
            threading.Thread(target=accept,daemon=True).start()
            server={'id':1,'host':'127.0.0.1','ssh_port':direct,'ssh_user':'root','auth_type':'key','private_key':(tmp/'identity').read_text(),'host_fingerprint':fingerprint,'reverse_ssh_port':reverse}
            def check(name,changes=None,**payload):
                fixture=tmp/(name+'.json');fixture.write_text(json.dumps({'server':{**server,**(changes or {})},**payload}))
                return subprocess.run([args.php,str(ROOT/'scripts/tests/ssh-route.test.php'),str(fixture)],capture_output=True,text=True,env={**os.environ,'SPEEDBOX_SSH_TEST_STORAGE':str(tmp/'storage')},timeout=45)
            r=check('direct',command='printf DIRECT_OK');assert r.returncode==0 and r.stdout=='DIRECT_OK',(r.stdout,r.stderr)
            # Blackhole the direct SSH handshake; use a real authenticated reverse route.
            source=tmp/'payload';source.write_text('REVERSE_UPLOAD_OK')
            r=check('reverse-upload',{'ssh_port':silent},mode='upload',source=str(source),destination=str(tmp/'received'))
            assert r.returncode==0 and r.stdout=='REVERSE_UPLOAD_OK',(r.stdout,r.stderr)
            forwarded=lambda:(tmp/'reverse.log').read_text().count('client_request_forwarded_tcpip:')
            before=forwarded();assert before>0,'reverse channel did not forward SSH traffic'
            # Keep fallback fully working, but do not use it to hide bad credentials.
            r=check('wrong-auth',{'private_key':(tmp/'wrong').read_text()},command='printf MUST_NOT_RUN');assert r.returncode!=0 and 'Permission denied' in r.stderr,r
            assert forwarded()==before,'authentication failure used fallback'
            r=check('wrong-host',{'host_fingerprint':'SHA256:'+'a'*43},command='printf MUST_NOT_RUN');assert r.returncode!=0 and 'اثر انگشت' in r.stderr,r
            assert forwarded()==before,'host-key mismatch used fallback'
            marker=tmp/'once'
            r=check('partial-command',command='printf x >> '+str(marker)+'; false');assert r.returncode!=0 and marker.read_text()=='x',r
            for conn in connections:conn.close()
            blackhole.close()
            print('PASS: direct SSH, real reverse SCP/command, authentication refusal, host pinning and no command replay')
        except BaseException:
            if (tmp/'sshd.log').exists():print((tmp/'sshd.log').read_text()[-4000:])
            raise
        finally:
            for process in reversed(processes):
                if process.poll() is None:process.terminate()
                try:process.wait(timeout=5)
                except subprocess.TimeoutExpired:process.kill();process.wait()

if __name__=='__main__':main()
