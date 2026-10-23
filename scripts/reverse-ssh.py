#!/usr/bin/env python3
"""Prepare one restricted, persistent reverse management channel on the panel.

Run as root on the panel, then run the generated root-only script on the
managed server. No panel password or unrestricted panel private key is used.
"""
import argparse,base64,hashlib,os,pathlib,pwd,re,shlex,subprocess

def run(args):
    return subprocess.run(args,check=True,capture_output=True,text=True).stdout.strip()

def foreign_script(panel_host,panel_port,user,port,target_port,key,known):
    channel=hashlib.sha256(known.encode()).hexdigest()[:12]+'-'+str(port)
    directory='/etc/speedbox-reverse-ssh/'+channel
    unit='speedbox-reverse-ssh-'+channel+'.service'
    args=['/usr/bin/ssh','-NT','-i',directory+'/key','-p',str(panel_port),
          '-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','ExitOnForwardFailure=yes',
          '-o','ConnectTimeout=10','-o','ServerAliveInterval=15','-o','ServerAliveCountMax=3',
          '-o','StrictHostKeyChecking=yes','-o','HostKeyAlias=speedbox-reverse-panel',
          '-o','UserKnownHostsFile='+directory+'/known_hosts',
          '-R',f'127.0.0.1:{port}:127.0.0.1:{target_port}',user+'@'+panel_host]
    service='\n'.join(['[Unit]','Description=SpeedBox reverse SSH management channel',
        'After=network-online.target','Wants=network-online.target','[Service]',
        'Type=simple','UMask=0077','ExecStart='+shlex.join(args),
        'Restart=always','RestartSec=5','NoNewPrivileges=yes','ProtectSystem=strict',
        'ProtectHome=yes','PrivateTmp=yes','[Install]','WantedBy=multi-user.target',''])
    def encoded(value):return base64.b64encode(value.encode()).decode()
    return '\n'.join(['#!/usr/bin/env bash','set -euo pipefail',
        'test "$(id -u)" = 0','command -v ssh >/dev/null','command -v systemctl >/dev/null',
        'install -d -m 0700 '+shlex.quote(directory),
        'printf %s '+shlex.quote(encoded(key))+' | base64 -d > '+directory+'/key',
        'printf %s '+shlex.quote(encoded(known))+' | base64 -d > '+directory+'/known_hosts',
        'chmod 0600 '+directory+'/key '+directory+'/known_hosts',
        'printf %s '+shlex.quote(encoded(service))+' | base64 -d > /etc/systemd/system/'+unit,
        'chmod 0644 /etc/systemd/system/'+unit,'systemctl daemon-reload',
        'systemctl enable '+unit,'systemctl restart '+unit,'systemctl is-active '+unit,
        'echo "Reverse SSH installed. Check its connection in the panel before deployment."',''])

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--panel-host',required=True)
    parser.add_argument('--panel-port',type=int,default=22)
    parser.add_argument('--reverse-port',type=int,required=True)
    parser.add_argument('--target-ssh-port',type=int,default=22)
    args=parser.parse_args()
    if os.geteuid()!=0:parser.error('run as root on the panel server')
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.:-]{0,252}',args.panel_host):parser.error('invalid panel host')
    if not 1024<=args.reverse_port<=65535 or not all(1<=p<=65535 for p in [args.panel_port,args.target_ssh_port]):parser.error('invalid port')
    os.umask(0o077)
    directory=pathlib.Path('/var/lib/speedbox-reverse-ssh')/str(args.reverse_port)
    user='sbrs'+str(args.reverse_port)
    try:
        pwd.getpwnam(user)
        if not (directory/'owned').is_file():raise RuntimeError('existing user is not owned by this helper')
    except KeyError:
        run(['useradd','--system','--create-home','--shell','/bin/false','--password','NP',user])
    directory.mkdir(parents=True,exist_ok=True,mode=0o700)
    (directory/'owned').write_text(user)
    key=directory/'key'
    if not key.exists():run(['ssh-keygen','-q','-t','ed25519','-N','','-f',str(key),'-C','speedbox-reverse-'+str(args.reverse_port)])
    home=pathlib.Path(pwd.getpwnam(user).pw_dir);ssh=home/'.ssh';ssh.mkdir(mode=0o700,exist_ok=True)
    (ssh/'authorized_keys').write_text('command="/bin/false",restrict,port-forwarding,permitlisten="127.0.0.1:'+str(args.reverse_port)+'" '+key.with_suffix('.pub').read_text())
    os.chmod(ssh/'authorized_keys',0o600)
    uid=pwd.getpwnam(user).pw_uid;gid=pwd.getpwnam(user).pw_gid
    os.chown(home,uid,gid);os.chown(ssh,uid,gid);os.chown(ssh/'authorized_keys',uid,gid)
    config=pathlib.Path('/etc/ssh/sshd_config.d')/('80-speedbox-reverse-'+str(args.reverse_port)+'.conf')
    old=config.read_bytes() if config.exists() else None
    config.parent.mkdir(exist_ok=True)
    config.write_text(f'''Match User {user}
    AuthenticationMethods publickey
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    AllowTcpForwarding remote
    PermitListen 127.0.0.1:{args.reverse_port}
    GatewayPorts no
    PermitTTY no
    PermitTunnel no
    AllowAgentForwarding no
    X11Forwarding no
    MaxSessions 0
Match all
''');os.chmod(config,0o644)
    try:
        run(['/usr/sbin/sshd','-t'])
        effective=run(['/usr/sbin/sshd','-T','-C','user='+user+',host=localhost,addr=127.0.0.1'])
        settings=dict(line.split(' ',1) for line in effective.splitlines() if ' ' in line)
        wanted={'allowtcpforwarding':'remote','maxsessions':'0','passwordauthentication':'no','authenticationmethods':'publickey','gatewayports':'no'}
        if any(settings.get(k)!=v for k,v in wanted.items()):raise RuntimeError('restricted SSH account settings are not effective; check sshd Include and Match configuration')
    except BaseException:
        if old is None:config.unlink()
        else:config.write_bytes(old)
        raise
    run(['systemctl','reload','ssh.service'])
    scan=run(['ssh-keyscan','-T','5','-p',str(args.panel_port),'127.0.0.1'])
    known='\n'.join('speedbox-reverse-panel '+' '.join(line.split()[1:3]) for line in scan.splitlines() if line and not line.startswith('#'))+'\n'
    if not known.strip():raise RuntimeError('local panel SSH host key could not be read')
    output=directory/'foreign-setup.sh'
    output.write_text(foreign_script(args.panel_host,args.panel_port,user,args.reverse_port,args.target_ssh_port,key.read_text(),known));output.chmod(0o600)
    print('Run the contents of this root-only file ON THE FOREIGN SERVER: '+str(output))
    print('Then set reverse SSH port '+str(args.reverse_port)+' and the FOREIGN SSH SHA256 fingerprint in its panel record.')

if __name__=='__main__':main()
