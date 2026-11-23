#!/usr/bin/env python3
"""Owned, native WireGuard site-to-site UDP path. No existing carrier is used."""
import base64, fcntl, ipaddress, json, os, pathlib, re, shlex, shutil, subprocess, sys, time
BASE=pathlib.Path('/var/lib/speedbox-wireguard-site')
SELF='/opt/speedbox-wireguard/site.py'
def run(*args,data=None,check=True):
    p=subprocess.run(args,input=data,text=True,capture_output=True,timeout=240)
    if check and p.returncode:raise RuntimeError(args[0]+': '+p.stderr[-400:])
    return p.stdout.strip()
def atomic(path,value):
    temp=path.with_suffix('.tmp');temp.write_text(json.dumps(value));temp.chmod(0o600);os.replace(temp,path)
def validate(p):
    if not re.fullmatch(r'sbe[1-9][0-9]{0,10}',p['interface']):raise ValueError('invalid interface')
    if not re.fullmatch(r'[a-f0-9-]{36}',p['owner_id']):raise ValueError('invalid owner')
    if p['side'] not in ['iran','foreign']:raise ValueError('invalid side')
    for name in ['private_key','public_key','peer_key','preshared_key']:
        if len(base64.b64decode(p[name],validate=True))!=32:raise ValueError('invalid key')
    net=ipaddress.ip_network(p['network'],strict=True)
    if net.version!=4 or net.prefixlen!=30 or not net.is_private:raise ValueError('private /30 required')
    for name in ['port','client_port','target_port']:
        if not 1024<=int(p[name])<=65535:raise ValueError('invalid port')
    if p['port'] in [p['client_port'],p['target_port']]:raise ValueError('site port collides with client port')
    host=p['foreign_host']
    try:ipaddress.ip_address(host)
    except ValueError:
        if len(host)>253 or not re.fullmatch(r'[A-Za-z0-9.-]+',host):raise ValueError('invalid endpoint')
    return p

def addresses(p):
    net=ipaddress.ip_network(p['network']);return str(net.network_address+(1 if p['side']=='iran' else 2)),str(net.network_address+(2 if p['side']=='iran' else 1))
def metadata(iface):
    if not re.fullmatch(r'sbe[1-9][0-9]{0,10}',iface):raise ValueError('invalid interface')
    return BASE/iface/'policy.json'
def command(table,chain,args,enable):
    base=['iptables','-w','-t',table]
    if enable:
        if subprocess.run([*base,'-C',chain,*args],capture_output=True).returncode:run(*base,'-I',chain,'1',*args)
    else:run(*base,'-D',chain,*args,check=False)
def routes(p,enable):
    iface=p['interface'];local,peer=addresses(p);tag='speedbox-'+iface
    rules=[('filter','INPUT',['-p','udp','--dport',str(p['port']),'-m','comment','--comment',tag,'-j','ACCEPT']),
           ('filter','INPUT',['-i',iface,'-s',peer+'/32','-d',local+'/32','-m','comment','--comment',tag,'-j','ACCEPT'])]
    if p['side']=='iran':
        dest=peer+':'+str(p['target_port'])
        rules += [('nat','PREROUTING',['!','-i',iface,'-p','udp','--dport',str(p['client_port']),'-m','comment','--comment',tag,'-j','DNAT','--to-destination',dest]),
                  ('nat','POSTROUTING',['-o',iface,'-d',peer+'/32','-p','udp','--dport',str(p['target_port']),'-m','comment','--comment',tag,'-j','SNAT','--to-source',local]),
                  ('filter','FORWARD',['-o',iface,'-d',peer+'/32','-p','udp','--dport',str(p['target_port']),'-m','comment','--comment',tag,'-j','ACCEPT']),
                  ('filter','FORWARD',['-i',iface,'-s',peer+'/32','-m','conntrack','--ctstate','ESTABLISHED,RELATED','-m','comment','--comment',tag,'-j','ACCEPT'])]
    for table,chain,args in rules:command(table,chain,args,enable)

def available(p,candidates):
    existing=run('ip','-j','route','show');taken=[]
    owned=metadata(p['interface'])
    own=owned.exists() and json.loads(owned.read_text()).get('owner_id')==p['owner_id']
    for route in json.loads(existing):
        if own and route.get('dev')==p['interface']:continue
        if route.get('dst','default')!='default':taken.append(ipaddress.ip_network(route['dst'],strict=False))
    result=[]
    for candidate in candidates:
        net=ipaddress.ip_network(candidate,strict=True)
        if net.version!=4 or net.prefixlen!=30 or not net.is_private:raise ValueError('invalid candidate')
        if not any(net.overlaps(other) for other in taken):result.append(str(net))
    return result

def status(p):
    out=run('wg','show',p['interface'],'dump',check=False);latest=0
    for line in out.splitlines()[1:]:
        cols=line.split('\t')
        if len(cols)>=8 and cols[0]==p['peer_key']:latest=int(cols[4])
    return {'handshake':latest,'healthy':latest>time.time()-180}
def port_in_rule(line,port):
    parts=shlex.split(line)
    if 'udp' not in parts:return False
    for flag in ['--dport','--dports']:
        if flag not in parts:continue
        value=parts[parts.index(flag)+1]
        for group in value.split(','):
            ends=group.replace('-',':').split(':')
            if all(v.isdigit() for v in ends) and int(ends[0])<=port<=int(ends[-1]):return True
    return False

def control(request):
    p=validate(request['policy']);path=metadata(p['interface']);folder=path.parent
    if BASE.is_symlink() or folder.is_symlink():raise RuntimeError('symlinks forbidden')
    action=request['action']
    if action=='preflight':return {'networks':available(p,request.get('candidates',[p['network']]))}
    if action=='probe':
        if not path.exists() or json.loads(path.read_text()).get('owner_id')!=p['owner_id']:raise RuntimeError('unowned path')
        _,peer=addresses(p)
        run('ping','-I',p['interface'],'-c','1','-W','4',peer,check=False)
        answer=status(p)
        if not answer['healthy']:raise RuntimeError('WireGuard link handshake failed; check UDP reachability between the two servers')
        return answer
    if action not in ['apply','disable','remove']:raise ValueError('invalid action')
    if action=='remove' and not folder.exists():
        conf=pathlib.Path('/etc/wireguard')/(p['interface']+'.conf')
        if conf.exists() or run('ip','link','show',p['interface'],check=False):raise RuntimeError('unowned interface or configuration exists')
        if run('systemctl','is-active','wg-quick@'+p['interface'],check=False) in ['active','activating','deactivating'] or run('systemctl','is-enabled','wg-quick@'+p['interface'],check=False)=='enabled':raise RuntimeError('unowned service exists')
        return {'removed':True}
    BASE.mkdir(mode=0o700,parents=True,exist_ok=True)
    folder.mkdir(mode=0o700,exist_ok=True)
    with (folder/'lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        old=json.loads(path.read_text()) if path.exists() else None
        unit='wg-quick@'+p['interface']
        if old and (old['owner_id']!=p['owner_id'] or old['side']!=p['side']):raise RuntimeError('unowned path')
        if old and shutil.which('wg'):
            actual=run('wg','show',p['interface'],'public-key',check=False)
            if actual and actual!=old['public_key']:raise RuntimeError('interface ownership key mismatch')
        conf=pathlib.Path('/etc/wireguard')/(p['interface']+'.conf')
        if not old and (conf.exists() or run('ip','link','show',p['interface'],check=False)):raise RuntimeError('unowned interface or configuration exists')
        if action=='remove':
            if not old:raise RuntimeError('ownership metadata missing')
            if any(child.is_symlink() or child.name not in ['policy.json','policy.tmp','lock'] or not child.is_file() for child in folder.iterdir()):raise RuntimeError('unexpected managed state files')
            if conf.is_symlink():raise RuntimeError('symlinks forbidden')
            if conf.exists():
                configured=re.search(r'^PrivateKey\s*=\s*(\S+)',conf.read_text(),re.M)
                if not configured or configured[1]!=old['private_key']:raise RuntimeError('configuration ownership key mismatch')
            if run('systemctl','show','--property=LoadState','--value',unit,check=False)!='not-found':run('systemctl','disable','--now',unit)
            if run('systemctl','is-active',unit,check=False) in ['active','activating','deactivating']:raise RuntimeError('site service is still running')
            actual=run('wg','show',p['interface'],'public-key',check=False)
            if run('ip','link','show',p['interface'],check=False):
                if actual!=old['public_key']:raise RuntimeError('unowned interface remains')
                run('ip','link','delete',p['interface'])
            routes(old,False)
            if 'speedbox-'+p['interface'] in run('iptables-save'):raise RuntimeError('owned firewall rules remain')
            conf.unlink(missing_ok=True)
            (folder/'policy.tmp').unlink(missing_ok=True)
            path.unlink();(folder/'lock').unlink();folder.rmdir()
            return {'removed':True}
        if action=='disable':
            if old:
                run('systemctl','disable','--now',unit,check=False);routes(old,False)
            return {'disabled':True}
        if p['network'] not in available(p,[p['network']]):raise RuntimeError('site network overlaps an existing route')
        if not old:
            for port in [p['port']]+([p['client_port']] if p['side']=='iran' else []):
                if run('ss','-H','-uln','sport = :'+str(port)):raise RuntimeError('UDP port is already occupied')
        if not shutil.which('wg') or not shutil.which('wg-quick') or not shutil.which('iptables') or not shutil.which('modprobe'):
            run('apt-get','update','-qq');run('apt-get','install','-y','wireguard-tools','iproute2','iptables','iputils-ping','kmod')
        if not shutil.which('ping'):run('apt-get','install','-y','iputils-ping')
        if p['side']=='iran' and (not old or old.get('client_port')!=p['client_port']):
            if run('ss','-H','-uln','sport = :'+str(p['client_port'])):raise RuntimeError('client UDP port is already occupied')
            saved=run('iptables-save','-t','nat')
            for line in saved.splitlines():
                if 'speedbox-'+p['interface'] not in line and port_in_rule(line,p['client_port']):raise RuntimeError('client UDP port already has another forwarding rule')
        # Check key correspondence before replacing any owned configuration.
        if run('wg','pubkey',data=p['private_key']+'\n')!=p['public_key']:raise ValueError('key mismatch')
        changed=old!=p
        if changed and old:run('systemctl','stop',unit)
        if changed:
            local,peer=addresses(p);host=p['foreign_host'];host='['+host+']' if ':' in host else host
            text='[Interface]\nPrivateKey = '+p['private_key']+'\nAddress = '+local+'/30\nListenPort = '+str(p['port'])+'\nMTU = 1380\nTable = off\n'
            text+='PostUp = /usr/bin/python3 '+SELF+' routes %i up\nPostDown = /usr/bin/python3 '+SELF+' routes %i down\n\n[Peer]\nPublicKey = '+p['peer_key']+'\nPresharedKey = '+p['preshared_key']+'\nAllowedIPs = '+peer+'/32\n'
            if p['side']=='iran':text+='Endpoint = '+host+':'+str(p['port'])+'\nPersistentKeepalive = 25\n'
            conf.parent.mkdir(mode=0o700,exist_ok=True);atomic(path,p)
            temp=conf.with_suffix('.tmp');temp.write_text(text);temp.chmod(0o600);os.replace(temp,conf)
        pathlib.Path('/etc/sysctl.d/90-speedbox-wireguard.conf').write_text('net.ipv4.ip_forward=1\n')
        run('sysctl','-w','net.ipv4.ip_forward=1')
        run('systemctl','enable','--now',unit)
        routes(p,True)
        return status(p)

if __name__=='__main__':
    os.umask(0o077)
    if os.geteuid()!=0:raise SystemExit('root required')
    try:
        if len(sys.argv)==4 and sys.argv[1]=='routes':
            p=json.loads(metadata(sys.argv[2]).read_text());routes(p,sys.argv[3]=='up')
        else:print(json.dumps({'ok':True,'data':control(json.load(sys.stdin))}))
    except Exception as e:
        print(json.dumps({'ok':False,'error':str(e)}));sys.exit(1)
