#!/usr/bin/env python3
"""Paired source-identity adapter around existing tunnel operations.

The native engine owns its carrier; this worker owns only the endpoint adapter.
Original public/node ports stay in panel settings. Its private carrier mapping
and the endpoint service are snapshotted and rolled back together.
"""
import copy
import fcntl
import hashlib
import json
import os
import re
import socket
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT=Path('/var/lib/speedbox-origin')
ETC=Path('/etc/speedbox-origin')
RUN=Path('/run/speedbox-origin')
UNITS=Path('/etc/systemd/system')
BINARY='/opt/speedbox-origin/tunnel-origin'
ID=re.compile(r'^sb-[a-f0-9]{32}$')
MARKER='# Managed by SpeedBox: authenticated tunnel origin\n'

def private_directory(path):
    path.mkdir(parents=True,exist_ok=True,mode=0o700)
    if path.is_symlink() or (path.stat().st_mode&0o777)!=0o700 or path.stat().st_uid!=os.geteuid():
        raise RuntimeError('origin directory is not private: '+str(path))

def own_unit(identifier):
    unit=paths(identifier)['unit']
    if unit.is_symlink() or (unit.exists() and not unit.read_text().startswith(MARKER)):
        raise RuntimeError('refusing to change an unmanaged origin service')

def command(args,timeout=25,check=True):
    r=subprocess.run(args,text=True,capture_output=True,timeout=timeout)
    if check and r.returncode: raise RuntimeError((r.stderr or r.stdout or 'origin operation failed')[-1200:])
    return r

def atomic(path,data):
    path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    fd,name=tempfile.mkstemp(prefix='.',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as f:
            f.write(data if isinstance(data,str) else json.dumps(data,sort_keys=True));f.flush();os.fsync(f.fileno())
        os.chmod(name,0o600);os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)

def read(path,default=None):
    try:return json.loads(path.read_text())
    except FileNotFoundError:return default

def paths(identifier):
    if not ID.fullmatch(str(identifier)):raise ValueError('invalid origin owner')
    return {'state':ROOT/(identifier+'.json'),'config':ETC/(identifier+'.json'),
            'unit':UNITS/('speedbox-origin-'+identifier+'.service'),'ready':RUN/(identifier+'.ready')}

def validate(plan):
    if not isinstance(plan,dict) or plan.get('version')!=2 or plan.get('side') not in ('iran','foreign'):raise ValueError('invalid identity plan')
    if type(plan.get('port')) is not int or not 1024<=plan['port']<=65535:raise ValueError('invalid identity relay port')
    if not re.fullmatch(r'[a-f0-9]{64}',str(plan.get('secret',''))):raise ValueError('invalid identity credential')
    if not isinstance(plan.get('entries'),list) or not (0 if plan.get('health_only') else 1)<=len(plan['entries'])<=256:raise ValueError('invalid identity mapping count')
    if plan.get('health_only') and plan['entries']:raise ValueError('health-only endpoint cannot forward node traffic')
    used=set()
    for row in plan['entries']:
        for key in ('public_port','target_port'):
            if type(row.get(key)) is not int or not 1<=row[key]<=65535:raise ValueError('invalid identity mapping')
        if row['public_port'] in used or plan['port'] in (row['public_port'],row['target_port']):raise ValueError('overlapping identity mapping')
        used.add(row['public_port'])
    return plan

def transform(backend,request,plan):
    """Only managed-node mappings pass through the authenticated adapter."""
    r=copy.deepcopy(request);r.pop('origin_plan',None)
    p=validate(plan);number=p['port'];owned={v['public_port'] for v in p['entries']}
    if backend=='backpack':
        if r.get('action')=='apply':
            keep=[]
            for value in r['ports'].split(','):
                left=value.split('=',1)[0]
                if int(left) not in owned:keep.append(value)
            r['ports']=','.join([*keep,f'{number}={number}'])
        return r
    cfg=r['config'];cfg.pop('origin_plan',None);s=cfg['settings'];keep=[]
    for m in s['mappings']:
        start=m['public_port'];end=m.get('public_end',start);cursor=start
        while cursor<=end:
            if cursor in owned:cursor+=1;continue
            first=cursor
            while cursor+1<=end and cursor+1 not in owned:cursor+=1
            offset=first-start;row=copy.deepcopy(m)
            row.update(public_port=first,public_end=cursor,target_port=m['target_port']+offset,target_end=m['target_port']+cursor-start)
            for d in row.get('destinations',[]):d['port']+=offset
            keep.append(row);cursor+=1
    keep.append({'public_port':number,'public_end':number,'target_port':number,'target_end':number,'destinations':[],**({'origin_relay':True} if cfg['engine']=='gost' else {})})
    s['mappings']=keep
    if cfg['engine']=='gost':cfg['health_probe']=2
    if cfg['engine']=='gre':cfg['origin_mark']=(int(cfg['subnet'])<<12)+4095
    return r

def native(backend,request):
    if backend=='backpack':
        r=subprocess.run(['/opt/speedbox-backpack/backpack','speedbox-json','-'],input=json.dumps(request),capture_output=True,text=True,timeout=140)
    else:
        with tempfile.NamedTemporaryFile(mode='w',prefix='speedbox-native-',suffix='.json') as f:
            json.dump(request,f);f.flush();r=subprocess.run(['python3','/opt/speedbox-network/host.py',f.name],capture_output=True,text=True,timeout=170)
    try:answer=json.loads(r.stdout)
    except ValueError:raise RuntimeError('native tunnel did not return structured output')
    if r.returncode or answer.get('ok') is not True:raise RuntimeError(answer.get('error','native tunnel failed'))
    return answer

def config_for(plan,identifier):
    p=validate(plan);tcp=bool(p.get('tcp',True));udp=bool(p.get('udp',False))
    c={'role':'ingress' if p['side']=='iran' else 'egress','secret':p['secret'],'tcp':tcp,'udp':udp,'ready_file':str(paths(identifier)['ready'])}
    if p['side']=='iran':
        c.update(backend=p.get('backend',f"127.0.0.1:{p['port']}"),routes=[{'listen':f":{v['public_port']}",'target':v['target_port']} for v in p['entries']],allowed=p.get('allowed',[]),mark=int(p.get('mark',0)))
        if p.get('health_only'):c['routes']=[{'listen':'127.0.0.1:0','target':1}]
    else:
        c.update(listen=f":{p['port']}",targets=sorted({v['target_port'] for v in p['entries']}))
        if p.get('health_only'):c.update(role='health',targets=[1])
    return c

def active(identifier):return command(['systemctl','is-active','--quiet',paths(identifier)['unit'].name],check=False).returncode==0
def boot_enabled(identifier):
    p=paths(identifier)
    return p['unit'].exists() and command(['systemctl','is-enabled','--quiet',p['unit'].name],check=False).returncode==0
def stop(identifier):
    own_unit(identifier)
    p=paths(identifier)
    if p['unit'].exists():command(['systemctl','stop',p['unit'].name],timeout=35)
    p['ready'].unlink(missing_ok=True)

def install(identifier,plan,enabled=True,service_state=None):
    own_unit(identifier)
    p=paths(identifier);c=config_for(plan,identifier)
    pending=bool(enabled and plan.get('staged') and plan['side']=='iran' and not plan.get('health_only'))
    if pending:enabled=False
    for directory in (ROOT,ETC,RUN):private_directory(directory)
    atomic(p['config'],c)
    if plan.get('health_only') and plan['side']=='iran':
        stop(identifier)
        if p['unit'].exists():
            command(['systemctl','disable',p['unit'].name],check=False);p['unit'].unlink();command(['systemctl','daemon-reload'])
        atomic(p['state'],{'plan':plan,'enabled':enabled,'pending':False})
        return
    unit=MARKER+'[Unit]\nDescription=SpeedBox authenticated tunnel source identity\nAfter=network-online.target\nWants=network-online.target\n[Service]\nType=simple\nExecStart='+BINARY+' '+str(p['config'])+'\nRestart=on-failure\nRestartSec=2\nTimeoutStopSec=15\nLimitNOFILE=1048576\nUMask=0077\n[Install]\nWantedBy=multi-user.target\n'
    atomic(p['unit'],unit);os.chmod(p['unit'],0o644);command(['systemctl','daemon-reload'])
    boot=enabled if service_state is None else service_state['enabled']
    running=enabled if service_state is None else service_state['active']
    command(['systemctl','enable' if boot else 'disable',p['unit'].name])
    if running:
        p['ready'].unlink(missing_ok=True);command(['systemctl','restart',p['unit'].name])
        for _ in range(40):
            status=command(['systemctl','show','--property=MainPID','--value',p['unit'].name],check=False).stdout.strip()
            if status.isdigit() and int(status)>0 and read(p['ready'],{}).get('pid')==int(status):break
            time.sleep(.1)
        else:raise RuntimeError('origin endpoint did not become ready; inspect its service log')
    else:stop(identifier)
    atomic(p['state'],{'plan':plan,'enabled':enabled,'pending':pending})

def remove(identifier):
    p=paths(identifier);stop(identifier)
    if p['unit'].exists():command(['systemctl','disable',p['unit'].name],check=False)
    for key in ('state','config','unit'):p[key].unlink(missing_ok=True)
    command(['systemctl','daemon-reload'])

def preflight(identifier,plan):
    validate(plan);p=paths(identifier);saved=read(p['state'],{})
    # Restarting an existing carrier must not depend on the node being online.
    # Each flow still registers before backend connect and fails closed if the
    # broker is down; the adapter recovers automatically when the node returns.
    if saved.get('plan',{}).get('port')==plan['port']:return
    if plan['side']=='foreign' and not plan.get('health_only'):
        with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as c:
            c.settimeout(3);c.connect('/run/speedbox-origin/broker.sock');c.sendall(b'{"operation":"ping","version":1}\n')
            if c.makefile('rb').readline(32)!=b'ORIGIN1\n':raise RuntimeError('node origin broker is unavailable')
    for family,host in ((socket.AF_INET,'0.0.0.0'),(socket.AF_INET6,'::')):
        for kind in (socket.SOCK_STREAM,socket.SOCK_DGRAM):
            try:
                with socket.socket(family,kind) as c:
                    if family==socket.AF_INET6:c.setsockopt(socket.IPPROTO_IPV6,socket.IPV6_V6ONLY,1)
                    c.bind((host,plan['port']))
            except OSError as e:
                if family==socket.AF_INET6 and e.errno in (97,99):continue
                raise

def dispatch(backend,request):
    if backend not in ('backpack','network'):raise ValueError('invalid origin backend')
    identifier=request.get('name') if backend=='backpack' else request.get('config',{}).get('id')
    if not identifier or not ID.fullmatch(str(identifier)):return native(backend,request)
    own_unit(identifier)
    p=paths(identifier);saved=read(p['state']);action=request.get('action');cfg=request.get('config',{})
    explicit=('origin_plan' in request) if backend=='backpack' else ('origin_plan' in cfg)
    plan=(request.get('origin_plan') if backend=='backpack' else cfg.get('origin_plan')) if explicit else (saved or {}).get('plan')
    if plan:validate(plan)
    if action=='origin-probe':
        if not saved or saved['plan']['side']!='iran':raise RuntimeError('origin ingress configuration is missing')
        # An active unit can still be completing its carrier handshake. Keep
        # ingress staged until an authenticated round trip actually succeeds.
        last='';deadline=time.monotonic()+45
        while time.monotonic()<deadline:
            remaining=deadline-time.monotonic()
            if remaining<=0:break
            try:
                command([BINARY,'probe',str(p['config'])],timeout=min(15,remaining))
                return {'ok':True,'data':{'ready':True}}
            except (RuntimeError,subprocess.TimeoutExpired) as e:last=str(e)
            time.sleep(min(.5,max(0,deadline-time.monotonic())))
        raise RuntimeError('authenticated origin carrier probe failed; check the relay port and both endpoint logs: '+last[-700:])
    if action=='origin-activate':
        if not saved or saved['plan']['side']!='iran':raise RuntimeError('origin ingress is not staged')
        if saved.get('pending'):
            ready=copy.deepcopy(saved['plan']);ready['staged']=False;install(identifier,ready,True)
        return {'ok':True,'data':{'active':True if saved['plan'].get('health_only') else active(identifier)}}
    revision=str(request.get('revision',''))
    snapshot=ROOT/'backups'/revision/(identifier+'.json') if re.fullmatch(r'[a-f0-9]{16,64}',revision) else None
    if action in ('snapshot','prepare'):
        if snapshot is None:raise ValueError('origin snapshot revision missing')
        if not snapshot.exists():atomic(snapshot,{'state':saved,'enabled':boot_enabled(identifier),'active':(saved.get('enabled',False) if saved and saved['plan'].get('health_only') and saved['plan']['side']=='iran' else active(identifier) if saved else False)})
        if plan and request.get('operation') not in ('stop','delete'):preflight(identifier,plan)
        return native(backend,transform(backend,request,plan) if plan else request)
    if action=='rollback':
        before=read(snapshot) if snapshot else None
        if before is None:raise RuntimeError('origin rollback snapshot missing')
        stop(identifier);answer=native(backend,request)
        if before['state']:
            state=before['state']
            install(identifier,state['plan'],state.get('enabled',False),service_state={'active':before['active'],'enabled':before.get('enabled',state.get('enabled',False))})
            atomic(p['state'],state)
        else:remove(identifier)
        return answer
    if action=='commit':
        answer=native(backend,request)
        if snapshot:snapshot.unlink(missing_ok=True)
        return answer
    if action in ('apply','reapply','start','stop','restart','delete'):
        if saved:stop(identifier)
        if plan and action not in ('stop','delete'):preflight(identifier,plan)
        transformed=transform(backend,request,plan) if plan else copy.deepcopy(request)
        transformed.pop('origin_plan',None)
        if 'config' in transformed:transformed['config'].pop('origin_plan',None)
        answer=native(backend,transformed)
        if action=='delete' or not plan:
            if saved:remove(identifier)
        else:
            enabled=action!='stop' and (request.get('config',{}).get('enabled',True) if backend=='network' else request.get('enabled',True))
            install(identifier,plan,enabled)
        return answer
    answer=native(backend,request)
    if saved and action=='status':
        virtual=saved['plan'].get('health_only') and saved['plan']['side']=='iran'
        healthy=(not saved.get('enabled',True)) or virtual or (active(identifier) and read(p['ready'],{}).get('pid')==int(command(['systemctl','show','--property=MainPID','--value',p['unit'].name],check=False).stdout.strip() or 0))
        answer['data']['source_identity']=healthy
        answer['data']['origin_endpoint']=healthy
        if not healthy:
            if 'connected' in answer['data']:answer['data']['connected']=False
        if saved['plan']['side']=='foreign' and saved.get('enabled',True) and saved['plan'].get('tcp',True):
            targets={}
            managed={m['target_port'] for m in saved['plan']['entries']}
            wanted=set(saved['plan'].get('probe_targets',managed))
            # Native BackPack status also checks original node ports. Disabled
            # managed nodes remain protected but must not become false outages.
            native_targets=answer['data'].get('targets',{})
            for number in managed-wanted:
                native_targets.pop(str(number),None)
            for number in sorted(wanted):
                try:
                    with socket.create_connection(('127.0.0.1',number),timeout=.3):targets[str(number)]=True
                except OSError:targets[str(number)]=False
            answer['data']['source_identity_targets']=targets
            if False in targets.values() and 'connected' in answer['data']:answer['data']['connected']=False
    if saved and action=='logs':
        text=command(['journalctl','-u',p['unit'].name,'-n','40','--no-pager','-o','cat'],check=False).stdout
        answer['data']['text']=answer['data'].get('text','')+'\nSource identity:\n'+text[-6000:]
    return answer

def main():
    os.umask(0o077)
    if os.geteuid()!=0:raise RuntimeError('origin endpoint management requires root')
    request=json.load(sys.stdin) if sys.argv[2]=='-' else json.loads(Path(sys.argv[2]).read_text())
    for directory in (ROOT,ETC,RUN):private_directory(directory)
    with open(ROOT/'worker.lock','a') as f:
        fcntl.flock(f,fcntl.LOCK_EX);answer=dispatch(sys.argv[1],request)
    print(json.dumps(answer,ensure_ascii=False))

if __name__=='__main__':
    try:main()
    except Exception as e:print(json.dumps({'ok':False,'error':str(e)[:1200]},ensure_ascii=False));sys.exit(1)
