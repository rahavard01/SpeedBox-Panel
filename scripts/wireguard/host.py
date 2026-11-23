#!/usr/bin/env python3
"""Native WireGuard controller. Root-only stdin control; no HTTP listener.
Last committed quota/expiry policy continues to be enforced without the panel.
"""
import base64, contextlib, fcntl, hashlib, ipaddress, json, os, pathlib, re, signal, subprocess, sys, time
BASE = pathlib.Path('/var/lib/speedbox-wireguard')
OPT = pathlib.Path('/opt/speedbox-wireguard/host.py')

def run(*args, data=None, check=True):
    p = subprocess.run(args, input=data, text=True, capture_output=True, timeout=120)
    if check and p.returncode:
        raise RuntimeError('command failed: ' + args[0] + ': ' + p.stderr[-500:])
    return p.stdout.strip()

def atomic(path, value):
    temp = path.with_suffix('.tmp'); temp.write_text(json.dumps(value)); temp.chmod(0o600)
    with temp.open('r+') as f: os.fsync(f.fileno())
    os.replace(temp, path)
    fd = os.open(path.parent, os.O_DIRECTORY)
    try: os.fsync(fd)
    finally: os.close(fd)

def read(path, default=None):
    return json.loads(path.read_text()) if path.exists() else default

def key(value):
    if not isinstance(value, str) or len(base64.b64decode(value, validate=True)) != 32: raise ValueError('invalid key')
    return value

def validate(p):
    # PHP encodes an empty associative array as []; normalize before iteration.
    p['accounts']=p.get('accounts') or {}
    p['retired']=p.get('retired') or {}
    if not re.fullmatch(r'[a-f0-9-]{36}',p['owner_id']): raise ValueError('invalid owner')
    if not re.fullmatch(r'sbw[1-9][0-9]{0,10}', p['interface']): raise ValueError('invalid interface')
    net = ipaddress.ip_network(p['network'], strict=True)
    if net.version != 4 or net.prefixlen != 24 or not net.is_private: raise ValueError('private IPv4 /24 required')
    if not 1024 <= int(p['listen_port']) <= 65535: raise ValueError('invalid port')
    if not 1280 <= int(p['mtu']) <= 1420: raise ValueError('invalid MTU')
    addresses, keys = set(), set()
    if len(p.get('peers', [])) > 250: raise ValueError('subnet full')
    for peer in p.get('peers', []):
        addr = ipaddress.ip_address(peer['address'])
        if addr not in net or int(addr)-int(net.network_address) < 2 or addr == net.broadcast_address: raise ValueError('invalid peer address')
        if str(addr) in addresses or peer['public_key'] in keys: raise ValueError('duplicate peer')
        addresses.add(str(addr)); keys.add(key(peer['public_key'])); key(peer['preshared_key'])
        if not 0 < int(peer['user_id']) < 2**31: raise ValueError('invalid account')
    for account in p.get('accounts', {}).values():
        if int(account['ceiling']) < 0 or not 0 <= int(account.get('speed_mbps', 0)) <= 10000: raise ValueError('invalid quota/speed')
    return p

@contextlib.contextmanager
def locked(folder):
    with (folder/'lock').open('a') as f:
        fcntl.flock(f, fcntl.LOCK_EX); yield

def snapshot(folder, iface):
    ledger = read(folder/'ledger.json', {'peers': {}})
    output = run('wg', 'show', iface, 'dump', check=False)
    for line in output.splitlines()[1:]:
        cols = line.split('\t')
        if len(cols) < 8: raise ValueError('invalid wg dump')
        state = ledger['peers'].setdefault(cols[0], {'rx':0,'tx':0,'last_rx':0,'last_tx':0,'handshake':0})
        rx, tx = int(cols[5]), int(cols[6])
        # Every managed removal first snapshots then clears last_*; an unexpected
        # counter regression fails closed instead of granting fresh quota.
        if rx < state['last_rx'] or tx < state['last_tx']: raise RuntimeError('WireGuard counters regressed; review required')
        state['rx'] += rx-state['last_rx']; state['tx'] += tx-state['last_tx']
        state.update(last_rx=rx, last_tx=tx, handshake=int(cols[4]))
    atomic(folder/'ledger.json', ledger)
    return ledger

def remove_peer(folder, iface, pub, ledger):
    run('wg','set',iface,'peer',pub,'remove')
    if pub in ledger['peers']: ledger['peers'][pub].update(last_rx=0,last_tx=0)
    atomic(folder/'ledger.json', ledger)

def wanted(p, ledger, now=None):
    now = now or time.time()
    usage = {}
    for peer in p.get('peers', []):
        s = ledger['peers'].get(peer['public_key'], {})
        uid = str(peer['user_id']); usage[uid] = usage.get(uid, 0)+s.get('rx',0)+s.get('tx',0)
    for pub,uid in p.get('retired', {}).items():
        state=ledger['peers'].get(pub,{})
        usage[str(uid)]=usage.get(str(uid),0)+state.get('rx',0)+state.get('tx',0)
    eligible = set()
    for peer in p.get('peers', []):
        a = p.get('accounts', {}).get(str(peer['user_id']), {})
        if (p['enabled'] and peer['enabled'] and a.get('enabled')
                and (not a.get('expires_at') or int(a['expires_at']) > now)
                and usage.get(str(peer['user_id']),0) < int(a['ceiling'])):
            eligible.add(peer['public_key'])
    return eligible

def firewall(p, enable=True):
    iface = p['interface']; chain = 'SBW'+iface[3:]; net = p['network']
    if enable:
        # Reject overlapping unmanaged routes/interfaces before creating anything.
        run('iptables','-w','-N',chain,check=False)
        for args in [('-A',chain,'-i',iface,'-j','ACCEPT'), ('-A',chain,'-o',iface,'-m','conntrack','--ctstate','ESTABLISHED,RELATED','-j','ACCEPT')]:
            if subprocess.run(['iptables','-w','-C',*args[1:]],capture_output=True).returncode: run('iptables','-w',*args)
        checks = [('filter','FORWARD',['-j',chain]), ('filter','INPUT',['-p','udp','--dport',str(p['listen_port']),'-m','comment','--comment',iface,'-j','ACCEPT']),
                  ('nat','POSTROUTING',['-s',net,'!','-o',iface,'-m','comment','--comment',iface,'-j','MASQUERADE'])]
        for table, ch, args in checks:
            if subprocess.run(['iptables','-w','-t',table,'-C',ch,*args],capture_output=True).returncode: run('iptables','-w','-t',table,'-I',ch,'1',*args)
    else:
        for table,ch,args in [('filter','FORWARD',['-j',chain]),('filter','INPUT',['-p','udp','--dport',str(p['listen_port']),'-m','comment','--comment',iface,'-j','ACCEPT']),('nat','POSTROUTING',['-s',net,'!','-o',iface,'-m','comment','--comment',iface,'-j','MASQUERADE'])]: run('iptables','-w','-t',table,'-D',ch,*args,check=False)
        run('iptables','-w','-F',chain,check=False); run('iptables','-w','-X',chain,check=False)

def shaping(p):
    iface=p['interface']; ifb='sbi'+iface[3:]
    run('modprobe','ifb')
    run('ip','link','add',ifb,'type','ifb',check=False); run('ip','link','set',ifb,'up')
    run('tc','qdisc','replace','dev',iface,'handle','ffff:','ingress')
    run('tc','filter','replace','dev',iface,'parent','ffff:','protocol','ip','pref','1','u32','match','u32','0','0','action','mirred','egress','redirect','dev',ifb)
    for dev in [iface,ifb]:
        run('tc','qdisc','replace','dev',dev,'root','handle','1:','htb','default','1')
        run('tc','class','replace','dev',dev,'parent','1:','classid','1:1','htb','rate','10gbit')
    ids={uid:n+10 for n,uid in enumerate(p['accounts'])}
    for uid,account in p['accounts'].items():
        speed=int(account.get('speed_mbps',0))
        if not speed: continue
        for dev in [iface,ifb]: run('tc','class','replace','dev',dev,'parent','1:','classid','1:'+str(ids[uid]),'htb','rate',str(speed)+'mbit','ceil',str(speed)+'mbit')
    for n,peer in enumerate(p['peers'],10):
        uid=str(peer['user_id'])
        if not int(p['accounts'].get(uid,{}).get('speed_mbps',0)): continue
        for dev,direction in [(iface,'dst'),(ifb,'src')]:
            run('tc','filter','replace','dev',dev,'parent','1:','protocol','ip','pref',str(n),'u32','match','ip',direction,peer['address']+'/32','flowid','1:'+str(ids[uid]))

def configure_peers(folder,p,ledger):
    iface=p['interface']; active=wanted(p,ledger)
    existing=set(run('wg','show',iface,'peers').split())
    for pub in existing-active: remove_peer(folder,iface,pub,ledger)
    for peer in p['peers']:
        pub=peer['public_key']
        if pub not in active or pub in existing: continue
        secret=folder/'peer.psk'; secret.write_text(peer['preshared_key']+'\n'); secret.chmod(0o600)
        try: run('wg','set',iface,'peer',pub,'preshared-key',str(secret),'allowed-ips',peer['address']+'/32')
        finally: secret.unlink(missing_ok=True)
        ledger['peers'].setdefault(pub,{'rx':0,'tx':0,'handshake':0}).update(last_rx=0,last_tx=0)
    atomic(folder/'ledger.json',ledger)

def daemon(iface):
    folder=BASE/iface
    p=validate(read(folder/'policy.json'))
    # Recover after a killed daemon only when the kernel interface proves
    # possession of this installation's private key. Never adopt another WG.
    if run('ip','link','show',iface,check=False):
        expected=run('wg','pubkey',data=(folder/'private.key').read_text())
        actual=run('wg','show',iface,'public-key',check=False)
        if not actual or actual!=expected: raise RuntimeError('unowned interface already exists')
        with locked(folder):
            ledger=snapshot(folder,iface)
            run('ip','link','delete',iface)
            for entry in ledger['peers'].values(): entry.update(last_rx=0,last_tx=0)
            atomic(folder/'ledger.json',ledger)
    if run('ss','-H','-uln','sport = :'+str(p['listen_port'])): raise RuntimeError('UDP listen port is already occupied')
    for route in json.loads(run('ip','-j','route','show')):
        dst=route.get('dst','default')
        if dst!='default' and ipaddress.ip_network(dst,strict=False).overlaps(ipaddress.ip_network(p['network'])): raise RuntimeError('network overlaps an existing route')
    stop=[False]
    for sig in [signal.SIGTERM,signal.SIGINT]: signal.signal(sig,lambda *_:stop.__setitem__(0,True))
    created=False
    try:
        run('ip','link','add',iface,'type','wireguard'); created=True
        run('wg','set',iface,'private-key',str(folder/'private.key'),'listen-port',str(p['listen_port']))
        network=ipaddress.ip_network(p['network']); run('ip','address','add',str(network.network_address+1)+'/24','dev',iface)
        run('ip','link','set','dev',iface,'mtu',str(p['mtu']),'up')
        with locked(folder):
            ledger=read(folder/'ledger.json',{'peers':{}})
            for entry in ledger['peers'].values(): entry.update(last_rx=0,last_tx=0)
            atomic(folder/'ledger.json',ledger)
        firewall(p); stamp=None
        while not stop[0]:
            with locked(folder):
                p=validate(read(folder/'policy.json')); ledger=snapshot(folder,iface)
                configure_peers(folder,p,ledger)
                current=json.dumps({'speeds':{uid:int(a.get('speed_mbps',0)) for uid,a in p['accounts'].items()},'peers':[(peer['address'],peer['user_id']) for peer in p['peers']]},sort_keys=True)
                if stamp!=current: shaping(p); stamp=current
                atomic(folder/'report.json',{'public_key':run('wg','show',iface,'public-key'),'peers':ledger['peers'],'taken':int(time.time()),'policy_hash':hashlib.sha256(json.dumps(p,sort_keys=True).encode()).hexdigest()})
            for _ in range(10):
                if stop[0]: break
                time.sleep(.5)
    finally:
        if created:
            with locked(folder):
                try: snapshot(folder,iface)
                finally:
                    run('ip','link','delete',iface,check=False)
                    ledger=read(folder/'ledger.json',{'peers':{}})
                    for entry in ledger['peers'].values(): entry.update(last_rx=0,last_tx=0)
                    atomic(folder/'ledger.json',ledger)
            firewall(p,False); run('ip','link','delete','sbi'+iface[3:],check=False)

def remove_instance(p):
    iface=p['interface'];folder=BASE/iface;service='speedbox-wireguard@'+iface
    if BASE.is_symlink() or folder.is_symlink():raise RuntimeError('symlinks forbidden')
    if not folder.exists():
        if run('ip','link','show',iface,check=False):raise RuntimeError('unowned interface exists')
        if run('systemctl','is-active',service,check=False) in ['active','activating','deactivating'] or run('systemctl','is-enabled',service,check=False)=='enabled':raise RuntimeError('unowned service exists')
        return {'removed':True}
    allowed={'policy.json','private.key','peer.psk','ledger.json','report.json','lock','policy.tmp','ledger.tmp','report.tmp'}
    if any(child.is_symlink() or child.name not in allowed or not child.is_file() for child in folder.iterdir()):raise RuntimeError('unexpected managed state files')
    old=read(folder/'policy.json',{})
    if old.get('owner_id')!=p['owner_id'] or old.get('interface')!=iface:raise RuntimeError('unowned instance')
    actual=run('wg','show',iface,'public-key',check=False)
    if actual:
        if not (folder/'private.key').is_file():raise RuntimeError('ownership key missing')
        if run('wg','pubkey',data=(folder/'private.key').read_text())!=actual:raise RuntimeError('interface ownership key mismatch')
    # Never hold the ledger lock while stopping: the daemon takes it on exit.
    if run('systemctl','show','--property=LoadState','--value',service,check=False)!='not-found':
        run('systemctl','disable','--now',service)
    if run('systemctl','is-active',service,check=False) in ['active','activating','deactivating']:raise RuntimeError('native service is still running')
    with locked(folder):
        actual=run('wg','show',iface,'public-key',check=False)
        if run('ip','link','show',iface,check=False):
            if not actual or not (folder/'private.key').exists() or run('wg','pubkey',data=(folder/'private.key').read_text())!=actual:raise RuntimeError('unowned interface remains')
            run('ip','link','delete',iface)
        firewall(old,False)
        if run('ip','link','show','sbi'+iface[3:],check=False):run('ip','link','delete','sbi'+iface[3:])
        saved=run('iptables-save')
        if 'SBW'+iface[3:] in saved or re.search(r'--comment ["\']?'+re.escape(iface)+r'(?:["\']?\s|$)',saved):raise RuntimeError('owned firewall rules remain')
        # Shared helper, systemd template and forwarding sysctl belong to all instances.
        for child in folder.iterdir():
            if child.name not in ['policy.json','lock']:child.unlink()
        (folder/'policy.json').unlink()
    (folder/'lock').unlink(missing_ok=True);folder.rmdir()
    return {'removed':True}

def control(request):
    action=request['action'];
    if action not in ['install','sync','status','preflight','remove']: raise ValueError('invalid action')
    p=validate(request['policy']); iface=p['interface']; folder=BASE/iface
    if action=='remove':return remove_instance(p)
    if action=='preflight':
        owned=folder/'policy.json'
        own=owned.exists() and read(owned).get('owner_id')==p['owner_id']
        if not own and run('ss','-H','-uln','sport = :'+str(p['listen_port'])): raise RuntimeError('UDP listen port is already occupied')
        used=[]
        for route in json.loads(run('ip','-j','route','show')):
            if own and route.get('dev')==iface: continue
            if route.get('dst','default')!='default': used.append(ipaddress.ip_network(route['dst'],strict=False))
        candidates=[]
        for value in request.get('candidates',[p['network']]):
            net=ipaddress.ip_network(value,strict=True)
            if net.version!=4 or net.prefixlen!=24 or not net.is_private: raise ValueError('invalid candidate')
            if not any(net.overlaps(other) for other in used): candidates.append(str(net))
        return {'networks':candidates}
    BASE.mkdir(mode=0o700,parents=True,exist_ok=True)
    if BASE.is_symlink() or folder.is_symlink(): raise RuntimeError('symlinks forbidden')
    if action=='install':
        if folder.exists():
            old=read(folder/'policy.json',{})
            if old.get('owner_id')!=p['owner_id'] or not (folder/'private.key').is_file(): raise RuntimeError('unowned or incomplete instance directory')
            action='sync'
    if action=='install':
        # Installation is explicit and occurs once, never on status polling.
        run('apt-get','update','-qq'); run('apt-get','install','-y','wireguard-tools','iproute2','iptables','kmod')
        folder.mkdir(mode=0o700)
        private=run('wg','genkey'); (folder/'private.key').write_text(private+'\n'); (folder/'private.key').chmod(0o600)
        atomic(folder/'ledger.json',{'peers':{}}); atomic(folder/'policy.json',p)
        pathlib.Path('/etc/sysctl.d/90-speedbox-wireguard.conf').write_text('net.ipv4.ip_forward=1\n')
        run('sysctl','-w','net.ipv4.ip_forward=1')
        unit=pathlib.Path('/etc/systemd/system/speedbox-wireguard@.service')
        unit.write_text('[Unit]\nDescription=Speedbox native WireGuard %i\nAfter=network-online.target\nWants=network-online.target\n[Service]\nType=simple\nUMask=0077\nExecStart=/usr/bin/python3 /opt/speedbox-wireguard/host.py daemon %i\nRestart=on-failure\nRestartSec=10\nTimeoutStopSec=100\nNoNewPrivileges=true\nProtectHome=true\nPrivateTmp=true\n[Install]\nWantedBy=multi-user.target\n')
        run('systemctl','daemon-reload')
    if not (folder/'ledger.json').exists() or not (folder/'private.key').exists(): raise RuntimeError('managed state missing; refusing quota reset')
    with locked(folder):
        old=read(folder/'policy.json')
        if any(old.get(k)!=p.get(k) for k in ['owner_id','interface','network','listen_port','mtu']): raise RuntimeError('immutable interface settings changed')
        if action in ['install','sync']: atomic(folder/'policy.json',p)
        report=read(folder/'report.json',{})
        report['peers']=read(folder/'ledger.json')['peers']
        report['public_key']=run('wg','pubkey',data=(folder/'private.key').read_text())
    service='speedbox-wireguard@'+iface
    if action in ['install','sync']:
        if p['enabled']:
            run('systemctl','enable','--now',service)
            for _ in range(40):
                fresh=read(folder/'report.json',{})
                if fresh.get('taken',0)>=time.time()-10 and fresh.get('policy_hash')==hashlib.sha256(json.dumps(p,sort_keys=True).encode()).hexdigest(): break
                if run('systemctl','is-active',service,check=False)!='active': raise RuntimeError('native service failed; inspect systemd journal')
                time.sleep(.25)
            else: raise RuntimeError('native service did not publish a fresh report')
        else: run('systemctl','disable','--now',service)
    report['running']=run('systemctl','is-active',service,check=False)=='active'
    return report

if __name__=='__main__':
    os.umask(0o077)
    if os.geteuid()!=0: raise SystemExit('root required')
    try:
        if len(sys.argv)==3 and sys.argv[1]=='daemon': daemon(sys.argv[2])
        else: print(json.dumps({'ok':True,'data':control(json.load(sys.stdin))}))
    except Exception as e:
        # Never include input, private keys or a subprocess command in errors.
        print(json.dumps({'ok':False,'error':str(e)})); sys.exit(1)
