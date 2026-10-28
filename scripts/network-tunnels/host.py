#!/usr/bin/env python3
"""SpeedBox private network worker. JSON file in, JSON out; no shell or web API.

Independent implementation of Linux GRE management and official GOST services.
No Tunnel-Panel or Gost-ip6 installer code is redistributed or executed.
"""
import contextlib
import concurrent.futures
import datetime as dt
import fcntl
import hashlib
import ipaddress
import json
import os
import re
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path('/var/lib/speedbox-network')
ETC = Path('/etc/speedbox-network')
OPT = Path('/opt/speedbox-network')
UNITS = Path('/etc/systemd/system')
IDENTITY = re.compile(r'^sb-[a-f0-9]{32}$')
VERSION = '1.0.0'


# Import next to this versioned helper, including importlib-based isolated tests.
sys.path.insert(0,str(Path(__file__).resolve().parent))
import wat
class _Host:
    def __getattr__(self,key):return globals()[key]
def _host():return _Host()

def run(argv, timeout=20, check=True, stdin=None):
    result = subprocess.run([str(x) for x in argv], input=stdin, capture_output=True, text=True, timeout=timeout)
    if check and result.returncode:
        raise RuntimeError((result.stderr or result.stdout or 'command failed')[-1600:])
    return result.stdout.strip() if check else result


def atomic(path, value, mode=0o600):
    path.parent.mkdir(parents=True, exist_ok=True)
    data = json.dumps(value, ensure_ascii=False, sort_keys=True) if not isinstance(value, str) else value
    fd, name = tempfile.mkstemp(prefix='.'+path.name, dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(name, mode)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def read(path, default=None):
    try:
        return json.loads(path.read_text())
    except FileNotFoundError:
        return default


def valid_id(value):
    if not isinstance(value, str) or not IDENTITY.fullmatch(value):
        raise ValueError('invalid managed tunnel id')
    return value


def paths(identifier):
    valid_id(identifier)
    short = identifier[3:14]
    return {'config': ETC/(identifier+'.json'), 'unit': UNITS/('speedbox-net-'+identifier+'.service'),
            'timer': UNITS/('speedbox-net-'+identifier+'.timer'), 'iface': 'sbg'+short,
            'table': 'sbn_'+short, 'runtime': ROOT/(identifier+'.json')}


def ip(value):
    return ipaddress.ip_address(str(value))


def port(value):
    if isinstance(value, bool) or not isinstance(value, int) or not 1 <= value <= 65535:
        raise ValueError('invalid port')
    return value


def endpoint(host, number):
    return ('['+host+']' if ':' in host else host)+':'+str(port(number))


def validate(config):
    if config.get('engine')=='wattunnel': return wat.validate(config)
    valid_id(config.get('id'))
    if not isinstance(config,dict) or not isinstance(config.get('settings'),dict): raise ValueError('invalid configuration')
    if config.get('engine') not in ('gost', 'gre') or config.get('side') not in ('iran', 'foreign'):
        raise ValueError('invalid engine or side')
    s = config['settings']
    if config.get('origin_mark') is not None and config['origin_mark'] != (config['subnet']<<12)+4095:
        raise ValueError('invalid owned origin connection mark')
    if s.get('protocol') not in ('tcp', 'udp', 'both') or s.get('transport') not in ('tcp', 'grpc', 'gre', 'gretap', 'ip6gre', 'ip6gretap'):
        raise ValueError('invalid protocol')
    if not isinstance(config.get('subnet'),int) or not 32768<=config['subnet']<=65500: raise ValueError('invalid connection mark allocation')
    if s.get('proxy_protocol'): raise ValueError('pinned GOST releases cannot send PROXY headers')
    if s.get('persistence')!='systemd': raise ValueError('unsupported persistence backend')
    if s.get('max_connections') or s.get('connection_rate'): raise ValueError('per-source limits are not supported by this release')
    if s.get('version') not in ('2.11.5', '3.0.0'):
        raise ValueError('unsupported pinned GOST version')
    if not re.fullmatch(r'[a-f0-9]{64}', config.get('secret', '')):
        raise ValueError('invalid relay credential')
    for key in ('peer_host', 'local_endpoint'):
        value = s.get(key, '')
        if value and not re.fullmatch(r'[a-zA-Z0-9.:_-]{1,253}', value):
            raise ValueError('invalid endpoint')
    family = s.get('ip_family')
    if family is not None:
        if family not in ('ipv4', 'ipv6'):
            raise ValueError('invalid IP family')
        wanted = 6 if family == 'ipv6' else 4
        if ip(s['peer_host']).version != wanted:
            raise ValueError('destination IP family mismatch')
        if config['engine'] == 'gre' and s['transport'].startswith('ip6') != (wanted == 6):
            raise ValueError('GRE IP family mismatch')
        if config['engine'] == 'gost':
            for mapping in s.get('mappings', []):
                for dest in mapping.get('destinations', []):
                    if ip(dest['address']).version != wanted:
                        raise ValueError('destination IP family mismatch')
    internal=bool(config.get('origin_mark')) or (config['engine']=='gost' and config.get('health_probe')==2)
    if not isinstance(s.get('mappings'), list) or not 1 <= len(s['mappings']) <= (257 if internal else 40):
        raise ValueError('invalid mapping list')
    for key,low,high in [('mtu',576,9000),('ttl',0,255),('gre_key',0,4294967295),('tos',0,255),('tx_queue',0,100000),('hop_limit',0,255),('encap_limit',0,255),('flow_label',0,1048575),('failure_threshold',1,20),('recovery_threshold',1,20),('restart_hours',0,168)]:
        if type(s.get(key)) is not int or not low<=s[key]<=high: raise ValueError('invalid '+key)
    if s.get('balance') not in ('round_robin','source_hash','weighted'): raise ValueError('invalid selection strategy')
    if s.get('quota_period') not in ('total','daily','weekly','monthly') or s.get('quota_direction') not in ('both','rx','tx') or s.get('quota_mode') not in ('warn','enforce'): raise ValueError('invalid quota policy')
    if type(s.get('quota_gb')) not in (int,float) or not 0<=s['quota_gb']<=10000000: raise ValueError('invalid quota')
    if config['engine']=='gost' and s['quota_gb']>0: raise ValueError('GOST quota unavailable')
    if not re.fullmatch(r'[a-zA-Z0-9_.:-]{0,15}',s.get('bind_device','')): raise ValueError('invalid bind device')
    used = set()
    for mapping in s['mappings']:
        for key in ('public_port', 'target_port', 'public_end', 'target_end'):
            port(mapping[key])
        count = mapping['public_end']-mapping['public_port']+1
        if count < 1 or mapping['target_end']-mapping['target_port']+1 != count:
            raise ValueError('invalid mapping range')
        for number in range(mapping['public_port'], mapping['public_end']+1):
            if number in used:
                raise ValueError('duplicate input port')
            used.add(number)
        for destination in mapping.get('destinations', []):
            host = destination['address']
            if not re.fullmatch(r'[a-zA-Z0-9.:_-]{1,253}', host):
                raise ValueError('invalid destination')
            port(destination['port'])
            if not 1 <= int(destination['weight']) <= 100:
                raise ValueError('invalid weight')
            if destination['port']+count-1>65535: raise ValueError('destination range overflow')
    if len(used) > (257 if internal else 256):
        raise ValueError('at most 256 input ports per tunnel')
    for cidr in s.get('allowed_sources', []):
        ipaddress.ip_network(cidr, strict=False)
    if not 5 <= int(s['monitor_interval']) <= 300 or not 1 <= int(s['timeout']) <= 10:
        raise ValueError('invalid monitoring interval')
    if config['engine'] == 'gre':
        if s['transport'] not in ('gre', 'gretap', 'ip6gre', 'ip6gretap'):
            raise ValueError('invalid GRE kind')
        family = 6 if s['transport'].startswith('ip6') else 4
        if ip(s['local_endpoint']).version != family or ip(s['peer_host']).version != family:
            raise ValueError('underlay family mismatch')
        if not 576 <= int(s['mtu']) <= 9000 or not 0 <= int(s['gre_key']) <= 4294967295:
            raise ValueError('invalid GRE parameters')
        for key in ('inner_iran', 'inner_foreign'):
            address = ipaddress.ip_interface(s[key])
            if address.ip.is_unspecified or address.ip.is_multicast or address.ip.is_loopback:
                raise ValueError('invalid tunnel address')
        a, b = [ipaddress.ip_interface(s[k]) for k in ('inner_iran', 'inner_foreign')]
        if a.ip == b.ip or a.network != b.network:
            raise ValueError('inner endpoints must be distinct in the same subnet')
        for pair in s.get('addresses', []):
            a, b = [ipaddress.ip_interface(pair[k]) for k in ('iran', 'foreign')]
            if a.ip == b.ip or a.network != b.network:
                raise ValueError('additional address pair mismatch')
        if s['nat'] not in ('masquerade', 'snat', 'none'):
            raise ValueError('invalid NAT mode')
        if s['nat'] == 'snat':
            ip(s['snat_address'])
        for mapping in s['mappings']:
            for dest in destinations(config, mapping):
                if ip(dest['address']).version != ip(s['inner_iran'].split('/')[0]).version:
                    raise ValueError('forwarding family translation is not supported')
    elif s['transport'] not in ('tcp', 'grpc') or (s['transport'] == 'grpc' and s['version'] != '3.0.0'):
        raise ValueError('gRPC needs GOST 3')
    return config


def destinations(config, mapping):
    s = config['settings']
    default_host = s['inner_foreign'].split('/')[0] if config['engine'] == 'gre' else (s.get('target_host') or s['peer_host'])
    return mapping.get('destinations') or [{'address': default_host, 'port': mapping['target_port'], 'weight': 1, 'health_port': s.get('monitor_port')}]


def unit_argument(value):
    return '"'+str(value).replace('\\','\\\\').replace('"','\\"').replace('%','%%').replace('$','$$')+'"'


def gost_arguments(config, health=None):
    s = config['settings']
    result = []
    if s['transport'] == 'grpc' and config['side'] == 'foreign':
        return ['-L=relay+grpc://sb:'+config['secret']+'@:'+str(port(s['tunnel_port']))]
    if config['side'] != 'iran':
        return []
    protocols = ['tcp', 'udp'] if s['protocol'] == 'both' else [s['protocol']]
    for index, mapping in enumerate(s['mappings']):
        candidates = destinations(config, mapping)
        if s.get('failover') and health is not None:
            candidates = [d for d in candidates if health.get(str(index)+':'+d['address']+':'+str(d['port']), {}).get('healthy', True)]
        for offset in range(mapping['public_end']-mapping['public_port']+1):
            targets = []
            for dest in candidates:
                targets += [endpoint(dest['address'], dest['port']+offset)] * (int(dest['weight']) if s['balance'] == 'weighted' else 1)
            if not targets:
                continue
            for protocol in protocols:
                query = '?strategy='+('hash' if s['balance'] == 'source_hash' else 'round')
                if s.get('proxy_protocol'):
                    raise ValueError('sending PROXY protocol requires a newer GOST release')
                bind='127.0.0.1' if mapping.get('origin_relay') else ''
                result.append('-L='+protocol+'://'+bind+':'+str(mapping['public_port']+offset)+'/'+','.join(targets)+query)
    if s['transport'] == 'grpc' and result:
        result.append('-F=relay+grpc://sb:'+config['secret']+'@'+endpoint(s['peer_host'], s['tunnel_port']))
    if not result and config['side']=='iran': result=['-L=tcp://127.0.0.1:0/127.0.0.1:9']
    if sum(len(x) for x in result)>100000: raise ValueError('forwarding command exceeds the supported configuration size')
    return result


def gre_spec(config):
    s = config['settings']
    local, remote = (s['local_endpoint'], s['peer_host']) if config['side']=='iran' else (s['peer_host'], s['local_endpoint'])
    args = ['ip', 'link', 'add', paths(config['id'])['iface'], 'type', s['transport'], 'local', local, 'remote', remote]
    if s.get('bind_device'):
        args += ['dev', s['bind_device']]
    args += ['key', str(s['gre_key'])]
    if s['transport'].startswith('ip6'):
        args += ['hoplimit', str(s['hop_limit']), 'encaplimit', str(s['encap_limit']), 'tclass', str(s['tos']), 'flowlabel', str(s['flow_label'])]
    else:
        args += ['ttl', str(s['ttl']), 'tos', str(s['tos']), 'pmtudisc' if s['pmtud'] else 'nopmtudisc']
        if s['ignore_df']:
            args += ['ignore-df']
    if s['checksum']:
        args += ['csum']
    if s['sequence']:
        args += ['seq']
    return args


def gre_commands(config):
    s = config['settings']
    interface = paths(config['id'])['iface']
    commands = [gre_spec(config), ['ip','link','set','dev',interface,'mtu',str(s['mtu']),'txqueuelen',str(s['tx_queue'])]]
    addresses = [s['inner_'+config['side']]] + [pair[config['side']] for pair in s.get('addresses', [])]
    commands += [['ip','address','add',a,'dev',interface] for a in addresses]
    commands += [['ip','link','set','dev',interface,'up']]
    return commands


def nft_rules(config, health=None):
    s=config['settings'];family='ip6' if ip(s['inner_iran'].split('/')[0]).version==6 else 'ip'
    table=paths(config['id'])['table'];base=config['subnet']<<12;mask=0xfffff000
    iface=paths(config['id'])['iface'];protocols=['tcp','udp'] if s['protocol']=='both' else [s['protocol']]
    lines=['table '+family+' '+table+' {','comment "speedbox:'+config['id']+'";','counter traffic_rx {}','counter traffic_tx {}']
    if s.get('allowed_sources'):
        if any(ipaddress.ip_network(c,strict=False).version!=(6 if family=='ip6' else 4) for c in s['allowed_sources']): raise ValueError('source address family mismatch')
        lines+=['set allowed { type '+('ipv6_addr' if family=='ip6' else 'ipv4_addr')+'; flags interval; auto-merge; elements = { '+', '.join(s['allowed_sources'])+' }; }']
    select=[];nat=[]
    for index,mapping in enumerate(s['mappings']):
        candidates=destinations(config,mapping)
        if s.get('failover') and health is not None:
            candidates=[d for d in candidates if health.get(str(index)+':'+d['address']+':'+str(d['port']),{}).get('healthy',True)]
        weighted=[(n,d) for n,d in enumerate(candidates) for _ in range(int(d['weight']) if s['balance']=='weighted' else 1)]
        for offset in range(mapping['public_end']-mapping['public_port']+1):
            for proto in protocols:
                match='fib daddr type local iifname != "'+iface+'" '+proto+' dport '+str(mapping['public_port']+offset)+' '
                if s.get('allowed_sources'): select.append(match+family+' saddr != @allowed drop')
                if not weighted:
                    select.append(match+'drop');continue
                expr=('jhash '+family+' saddr' if s['balance']=='source_hash' else 'numgen inc')+' mod '+str(len(weighted))
                choices=', '.join(str(n)+' : '+str(base+index*16+j+1) for n,(j,d) in enumerate(weighted))
                # One selector per packet: evaluating numgen again for each destination breaks round robin.
                select.append(match+'ct state new ct mark 0 ct mark set '+expr+' map { '+choices+' }')
                for j,dest in enumerate(candidates):
                    mark=base+index*16+j+1
                    nat.append(proto+' dport '+str(mapping['public_port']+offset)+' ct mark '+str(mark)+' dnat to '+endpoint(dest['address'],dest['port']+offset))
    lines+=['chain select_destination { type filter hook prerouting priority -102; policy accept;',*select,'}', 'chain prerouting { type nat hook prerouting priority -101; policy accept;',*nat,'}', 'chain postrouting { type nat hook postrouting priority 101; policy accept;']
    owned='ct mark & '+hex(mask)+' == '+str(base)+' '
    if s['nat']=='masquerade': lines.append(owned+'masquerade')
    elif s['nat']=='snat': lines.append(owned+'snat to '+s['snat_address'])
    lines+=['}', 'chain accounting { type filter hook forward priority -1; policy accept;',owned+'ct direction original counter name traffic_rx',owned+'ct direction reply counter name traffic_tx']
    if s['clamp_mss']: lines.append(owned+'meta l4proto tcp tcp flags & (syn | rst) == syn tcp option maxseg size set rt mtu')
    lines+=['}']
    # Identity endpoints create local sockets through the GRE interface. Keep
    # their bytes in this tunnel's existing quota/accounting namespace; public
    # client sockets and unrelated local connections never receive this mark.
    if config.get('origin_mark'):
        mark=str(config['origin_mark'])
        lines+=['chain origin_mark { type filter hook output priority -102; policy accept;',
                'meta mark '+mark+' ct mark set '+mark,'}',
                'chain origin_tx { type filter hook output priority -1; policy accept;',owned+'ct direction original counter name traffic_rx','}',
                'chain origin_rx { type filter hook input priority -1; policy accept;',owned+'ct direction reply counter name traffic_tx','}']
    lines+=['}']
    return '\n'.join(lines)+'\n'


def service_text(config, health=None):
    if config['engine']=='wattunnel': return wat.service_text(_host(),config)
    p=paths(config['id']);s=config['settings']
    if config['engine']=='gost':
        argv=gost_arguments(config,health)
        if not argv:
            return None
        binary=OPT/('gost-'+s['version'])
        start=[str(binary),*argv]
        service='Type=simple\nRestart=on-failure\nRestartSec=3\n'
        stop=''
    else:
        start=['/usr/bin/python3',str(OPT/'host.py'),'boot',str(p['config'])]
        service='Type=oneshot\nRemainAfterExit=yes\n'
        stop='ExecStop='+ ' '.join(unit_argument(v) for v in ['/usr/bin/python3',str(OPT/'host.py'),'down',str(p['config'])])+'\n'
    return '# speedbox-network:managed=1\n[Unit]\nDescription=SpeedBox '+config['engine']+' '+config['id']+'\nAfter=network-online.target\nWants=network-online.target\n[Service]\n'+service+'ExecStart='+' '.join(unit_argument(v) for v in start)+'\n'+stop+'UMask=0077\nLimitNOFILE=1048576\n[Install]\nWantedBy=multi-user.target\n'


def active_unit(config):
    return run(['systemctl','is-active',paths(config['id'])['unit'].name],check=False).returncode==0

def enabled_unit(config):
    return run(['systemctl','is-enabled','--quiet',paths(config['id'])['unit'].name],check=False).returncode==0


def own_interface(config):
    name=paths(config['id'])['iface']
    result=run(['ip','-j','-d','link','show','dev',name],check=False)
    if result.returncode:
        return None
    data=json.loads(result.stdout)[0]
    if data.get('ifalias')!='speedbox:'+config['id']:
        raise RuntimeError('interface name belongs to an unmanaged device')
    return data


def apply_nft(config, health=None):
    if config['engine']!='gre' or config['side']!='iran':
        return
    if not shutil.which('nft'):
        raise RuntimeError('nftables is required for managed GRE forwarding')
    family='ip6' if ':' in config['settings']['inner_iran'] else 'ip'
    table=paths(config['id'])['table']
    profile=Path('/etc/sysctl.d/90-speedbox-network-forwarding.conf')
    if profile.exists() and not profile.read_text().startswith('# speedbox-network:managed=1'): raise RuntimeError('forwarding profile path is not owned by SpeedBox')
    existing=run(['nft','-j','list','table',family,table],check=False)
    exists=existing.returncode==0
    if exists and not any(x.get('table',{}).get('comment')=='speedbox:'+config['id'] for x in json.loads(existing.stdout).get('nftables',[])): raise RuntimeError('nft table is not owned by this connection')
    rules=('delete table '+family+' '+table+'\n' if exists else '')+nft_rules(config,health)
    run(['nft','--check','--file','-'],stdin=rules)
    run(['nft','--file','-'],stdin=rules)
    key='net.ipv6.conf.all.forwarding' if family=='ip6' else 'net.ipv4.ip_forward'
    profile=ROOT/'forwarding.json';saved=read(profile,{'values':{},'applied':{}})
    desired={key:'1'}
    if family=='ip6':
        # Linux router mode must not silently stop accepting RA on the physical default path.
        for route in json.loads(run(['ip','-j','-6','route','show','default'])):
            dev=route.get('dev','')
            if re.fullmatch(r'[a-zA-Z0-9_.:-]{1,15}',dev):
                ra='net.ipv6.conf.'+dev+'.accept_ra'
                if run(['sysctl','-n',ra])=='1': desired[ra]='2'
    for item,value in desired.items():
        if item not in saved['values']: saved['values'][item]=run(['sysctl','-n',item])
        saved['applied'][item]=value
    atomic(profile,saved)
    for item,value in desired.items(): run(['sysctl','-w',item+'='+value])
    atomic(Path('/etc/sysctl.d/90-speedbox-network-forwarding.conf'),'# speedbox-network:managed=1\n'+'\n'.join(k+'='+v for k,v in saved['applied'].items())+'\n',0o644)


def boot(config):
    validate(config)
    if config['engine']!='gre':
        return
    old=own_interface(config)
    if old:
        run(['ip','link','delete',paths(config['id'])['iface']])
    commands=gre_commands(config)
    run(commands[0])
    # Mark immediately; failure cleanup must never remove an unowned interface.
    run(['ip','link','set','dev',paths(config['id'])['iface'],'alias','speedbox:'+config['id']])
    for command in commands[1:]:
        run(command)
    apply_nft(config)
    observed=own_interface(config)
    if not observed or 'UP' not in observed.get('flags',[]) or observed.get('mtu')!=config['settings']['mtu']:
        raise RuntimeError('GRE configuration was not verified in the kernel')


def down(config):
    if config['engine']=='gre':
        if own_interface(config):
            run(['ip','link','delete',paths(config['id'])['iface']])
        family='ip6' if ':' in config['settings']['inner_iran'] else 'ip'
        table=paths(config['id'])['table'];existing=run(['nft','-j','list','table',family,table],check=False)
        if existing.returncode==0:
            if not any(x.get('table',{}).get('comment')=='speedbox:'+config['id'] for x in json.loads(existing.stdout).get('nftables',[])): raise RuntimeError('refusing to remove an unmanaged table')
            run(['nft','delete','table',family,table])


def install(config, health=None, service_state=None):
    validate(config)
    p=paths(config['id']);text=service_text(config,health)
    if config['engine']=='wattunnel':wat.prepare_files(_host(),config)
    if p['unit'].exists() and '# speedbox-network:managed=1' not in p['unit'].read_text(): raise RuntimeError('unit is not owned by SpeedBox')
    if text:
        atomic(p['unit'],text,0o600)
    elif p['unit'].exists():
        run(['systemctl','stop',p['unit'].name],check=False)
        run(['systemctl','disable',p['unit'].name],check=False)
        p['unit'].unlink()
    atomic(p['config'],config)
    run(['systemctl','daemon-reload'])
    if text and service_state is not None:
        # Rollback restores observed service state without overwriting policy.
        run(['systemctl','enable' if service_state['enabled'] else 'disable',p['unit'].name])
        run(['systemctl','restart' if service_state['active'] else 'stop',p['unit'].name],timeout=45)
        if active_unit(config)!=service_state['active']:
            raise RuntimeError('restored service activity was not verified')
    elif text and config.get('enabled',True):
        run(['systemctl','enable',p['unit'].name])
        run(['systemctl','restart',p['unit'].name],timeout=45)
        if not active_unit(config):
            raise RuntimeError('managed service is not active')
    elif text:
        run(['systemctl','disable','--now',p['unit'].name])
        if active_unit(config):
            raise RuntimeError('managed service did not stop')
    if config['engine']=='gre' and config['side']=='foreign' and config.get('enabled',True):
        own_interface(config)


def remove(config):
    p=paths(config['id'])
    if p['unit'].exists() and '# speedbox-network:managed=1' not in p['unit'].read_text():
        raise RuntimeError('unit is not owned by SpeedBox')
    run(['systemctl','disable','--now',p['unit'].name],check=False)
    down(config)
    if config['engine']=='wattunnel':p['config'].with_suffix('.key').unlink(missing_ok=True)
    for key in ('config','unit','timer','runtime'):
        p[key].unlink(missing_ok=True)
    run(['systemctl','daemon-reload'])
    if not any(read(path,{}).get('engine')=='gre' for path in ETC.glob('sb-*.json')):
        saved=read(ROOT/'forwarding.json',{})
        for key,value in saved.get('values',{}).items():
            if run(['sysctl','-n',key])==saved.get('applied',{}).get(key): run(['sysctl','-w',key+'='+value])
        profile=Path('/etc/sysctl.d/90-speedbox-network-forwarding.conf')
        if profile.exists() and profile.read_text().startswith('# speedbox-network:managed=1'): profile.unlink()
        (ROOT/'forwarding.json').unlink(missing_ok=True)


def database():
    ROOT.mkdir(parents=True,exist_ok=True,mode=0o700)
    db=sqlite3.connect(str(ROOT/'telemetry.sqlite'),timeout=20)
    db.execute('PRAGMA journal_mode=WAL')
    db.execute('CREATE TABLE IF NOT EXISTS samples(id TEXT, taken INTEGER, snapshot TEXT, PRIMARY KEY(id,taken))')
    db.execute('CREATE TABLE IF NOT EXISTS events(id TEXT, taken INTEGER, message TEXT)')
    db.execute('CREATE INDEX IF NOT EXISTS samples_time ON samples(taken)')
    return db


def tcp_check(host, number, timeout, source=None):
    started=time.monotonic()
    try:
        with socket.create_connection((host,port(number)),timeout,source_address=(source,0) if source else None):
            return {'ok':True,'rtt_ms':round((time.monotonic()-started)*1000,2)}
    except (OSError,ValueError) as error:
        return {'ok':False,'error':str(error)[:180]}


def counters(config):
    p=paths(config['id'])
    if config['engine']=='gre':
        values={}
        for key in ('rx','tx'):
            try:
                values[key]=int(Path('/sys/class/net',p['iface'],'statistics',key+'_bytes').read_text())
            except FileNotFoundError:
                values[key]=0
        return values
    # Process /proc/net/dev counters are host-wide and must not be called tunnel traffic.
    return None


def period_key(settings, now):
    # Fixed UTC storage; explicit timezone controls the accounting window.
    now=dt.datetime.fromtimestamp(now,dt.timezone(dt.timedelta(minutes=int(settings.get('timezone_minutes',210)))))
    period=settings['quota_period']
    if period=='daily': return now.strftime('%Y-%m-%d')
    if period=='weekly': return '%s-%s'%now.isocalendar()[:2]
    if period=='monthly': return now.strftime('%Y-%m')
    return 'total'


def snapshot(config, probe=True):
    if config['engine']=='wattunnel':return wat.snapshot(_host(),config,probe)
    p=paths(config['id']);s=config['settings'];now=int(time.time())
    previous=read(p['runtime'],{})
    health=previous.get('destinations',{})
    running=active_unit(config) if service_text(config) else True
    if config['engine']=='gre' and running:
        observed=own_interface(config)
        running=bool(observed and 'UP' in observed.get('flags',[]))
    source=s['inner_'+config['side']].split('/')[0] if config['engine']=='gre' else None
    check_results=[]
    if probe and config.get('enabled',True) and config['side']=='iran':
        jobs=[]
        for index,mapping in enumerate(s['mappings']):
            if mapping.get('origin_relay'):continue
            for destination in destinations(config,mapping):
                key=str(index)+':'+destination['address']+':'+str(destination['port'])
                target_port=destination.get('health_port') or s.get('monitor_port') or destination['port']
                jobs.append((key,destination,target_port))
        def check(item):
            key,destination,target_port=item
            if s['protocol']=='udp' and not destination.get('health_port') and not s.get('monitor_port'): return key,{'ok':None,'reason':'UDP needs a TCP health port or a client test'}
            return key,tcp_check(destination['address'],target_port,s['timeout'],source)
        with concurrent.futures.ThreadPoolExecutor(max_workers=16) as pool:
            for key,result in pool.map(check,jobs):
                old=health.get(key,{})
                if result['ok'] is not None:
                    failures=(old.get('failures',0)+1) if not result['ok'] else 0
                    recoveries=(old.get('recoveries',0)+1) if result['ok'] else 0
                    healthy=old.get('healthy',True)
                    if failures>=s['failure_threshold']: healthy=False
                    if recoveries>=s['recovery_threshold']: healthy=True
                    history=(old.get('recent',[])+[{'ok':result['ok'],'rtt_ms':result.get('rtt_ms')}])[-20:]
                    health[key]={**result,'failures':failures,'recoveries':recoveries,'healthy':healthy,'checked_at':now,'recent':history,'loss_percent':round(100*sum(not x['ok'] for x in history)/len(history),1)}
                else: health[key]={**old,**result,'checked_at':now}
                check_results.append(result)
    # Outside GRE, only the side owning the forwards performs destination checks.
    known=[x for x in check_results if x.get('ok') is not None]
    target_available=all(x['ok'] for x in known) if known else None
    connected=target_available
    path_verified=None
    if config['engine']=='gost' and config['side']=='iran' and config.get('enabled',True):
        if config.get('health_probe')==2:
            try:
                run(['/opt/speedbox-origin/tunnel-origin','probe','/etc/speedbox-origin/'+config['id']+'.json'],timeout=12)
                path_verified=True
            except (RuntimeError,subprocess.TimeoutExpired,OSError):path_verified=False
        # A TCP accept proves neither the relay credentials nor payload delivery.
        connected=False if connected is False or path_verified is False else (True if path_verified is True and connected is not False else None)
    if config['engine']=='gre' and running and config.get('enabled',True) and (config['side']=='foreign' or s.get('keepalive')):
        peer=s['inner_iran' if config['side']=='foreign' else 'inner_foreign'].split('/')[0]
        pong=run(['ping','-n','-c','2','-W',str(s['timeout']),'-I',p['iface'],peer],timeout=s['timeout']*2+5,check=False)
        if config['side']=='foreign': connected=pong.returncode==0
    raw=counters(config);total=previous.get('totals',{'rx':0,'tx':0})
    if raw is not None:
        for key in ('rx','tx'):
            before=previous.get('raw',{}).get(key,0)
            total[key]+=raw[key]-before if raw[key]>=before else raw[key]
    period=period_key(s,now);base=previous.get('quota_base',{'rx':0,'tx':0}) if previous.get('period')==period else dict(total)
    used={key:max(0,total[key]-base[key]) for key in ('rx','tx')}
    volume=sum(used.values()) if s['quota_direction']=='both' else used[s['quota_direction']]
    exhausted=raw is not None and s['quota_gb']>0 and volume>=s['quota_gb']*1073741824
    state='stopped' if not config.get('enabled',True) else ('offline' if not running else ('connected' if connected is True else 'degraded'))
    result={'checked_at':now,'active':running,'connected':connected,'state':state,'destinations':health,
            'path_verified':path_verified,'target_available':target_available,
            'traffic_available':raw is not None,'raw':raw or {},'totals':total,'period':period,'quota_base':base,
            'quota_used':used,'quota_exhausted':exhausted,'quota_stopped':previous.get('quota_stopped',False),
            'helper_version':VERSION,'interface':p['iface'] if config['engine']=='gre' else None,
            'version':s['version'] if config['engine']=='gost' else 'Linux GRE','last_restart':previous.get('last_restart',now),
            'last_daemon_at':previous.get('last_daemon_at',0),'recovery':previous.get('recovery',{})}
    atomic(p['runtime'],result)
    return result


def daemon_tick(config):
    p=paths(config['id']);old=read(p['runtime'],{})
    if int(time.time())-old.get('last_daemon_at',0)<config['settings']['monitor_interval']:
        return
    result=snapshot(config,probe=config['settings']['monitor'])
    s=config['settings']
    result['last_daemon_at']=int(time.time())
    if config['engine']=='gre' and config.get('enabled',True) and not (result['quota_exhausted'] and s['quota_mode']=='enforce'):
        if result['active']:
            result['recovery']={}
        elif int(time.time())>=old.get('recovery',{}).get('next_at',0):
            attempts=min(16,old.get('recovery',{}).get('attempts',0)+1)
            result['recovery']={'attempts':attempts,'next_at':int(time.time())+min(300,5*2**min(attempts-1,6))}
            try:
                install(config,result['destinations'])
                recovered=snapshot(config,probe=False)
                result.update(active=recovered['active'],state=recovered['state'],connected=recovered['connected'])
                if recovered['active']:result['recovery']={}
            except Exception:
                result['recovery']['error']='owned GRE startup retry failed'
    if s.get('failover') and config['side']=='iran':
        before={k:v.get('healthy') for k,v in old.get('destinations',{}).items()}
        after={k:v.get('healthy') for k,v in result['destinations'].items()}
        if before!=after:
            if config['engine']=='gre': apply_nft(config,result['destinations'])
            else: install(config,result['destinations'])
    if result['quota_exhausted'] and s['quota_mode']=='enforce' and config.get('enabled',True):
        run(['systemctl','stop',p['unit'].name],check=False)
        result['quota_stopped']=True
    elif old.get('quota_stopped') and not result['quota_exhausted'] and config.get('enabled',True):
        install(config,result['destinations']);result['quota_stopped']=False
    if s['restart_hours'] and config.get('enabled',True) and not result['quota_stopped'] and time.time()-old.get('last_restart',time.time())>=s['restart_hours']*3600:
        install(config,result['destinations']);result['last_restart']=int(time.time())
    atomic(p['runtime'],result)
    with database() as db:
        db.execute('INSERT OR REPLACE INTO samples VALUES(?,?,?)',(config['id'],result['checked_at'],json.dumps(result)))
        if old.get('state')!=result['state']:
            db.execute('INSERT INTO events VALUES(?,?,?)',(config['id'],result['checked_at'],result['state']))
        db.execute('DELETE FROM samples WHERE taken < ?',(int(time.time())-31*86400,))
        db.execute('DELETE FROM events WHERE taken < ?',(int(time.time())-31*86400,))


def diagnostics(config, kind):
    p=paths(config['id']);s=config['settings'];target=s['inner_foreign' if config['side']=='iran' else 'inner_iran'].split('/')[0] if config['engine']=='gre' else s['peer_host']
    source=['-I',p['iface']] if config['engine']=='gre' else []
    if kind=='ping':
        command=['ping','-n','-c','5','-W',str(s['timeout']),*source,target]
    elif kind=='traceroute':
        command=['tracepath','-n',target]
    elif kind=='mtu':
        if config['engine']!='gre': raise ValueError('MTU probing is for the GRE path')
        low,high=576,min(9000,s['mtu']);best=None
        while low<=high:
            middle=(low+high)//2;payload=middle-(48 if ':' in target else 28)
            ok=run(['ping','-n','-c','1','-W',str(s['timeout']),'-M','do',*source,'-s',str(payload),target],timeout=s['timeout']+3,check=False).returncode==0
            if ok: best=middle;low=middle+1
            else: high=middle-1
        return {'mtu':best,'verified':best is not None,'note':'No ICMP reply cannot determine the path MTU.'}
    elif kind=='connections':
        if config['engine']=='gre':
            command=['conntrack','-L','--mark',str(config['subnet']<<12)+'/0xfffff000']
        else:
            pid=run(['systemctl','show','--property=MainPID','--value',p['unit'].name],check=False).stdout.strip()
            out=run(['ss','-Hntup'],check=False)
            return {'text':'\n'.join(line for line in out.stdout.splitlines() if re.search(r'pid='+re.escape(pid)+r',',line))[-20000:] if pid.isdigit() and int(pid)>0 else ''}
    elif kind=='analyze':
        return {'snapshot':snapshot(config),'checks':preflight(config),'note':'Client protocol, authentication and UDP delivery need a real client test.'}
    else:
        raise ValueError('unsupported diagnostic')
    output=run(command,timeout=35,check=False)
    return {'ok':output.returncode==0,'text':output.stdout[-20000:]+output.stderr[-2000:]}


def preflight(config):
    if config['engine']=='wattunnel':return wat.preflight(_host(),config)
    s=config['settings'];checks=[]
    for program in (['ip','systemctl','nft','ping'] if config['engine']=='gre' else ['systemctl','ss']):
        checks.append({'name':program,'ok':shutil.which(program) is not None})
    if config['engine']=='gre':
        checks.append({'name':'GRE kernel module','ok':run(['modprobe','-n','ip6_gre' if s['transport'].startswith('ip6') else 'ip_gre'],check=False).returncode==0})
        primary=run(['ip','-j','route','show','default'],check=False)
        checks.append({'name':'default route preserved','ok':primary.returncode==0})
        own_interface(config)
        addresses=json.loads(run(['ip','-j','address','show']))
        desired=[ipaddress.ip_interface(s['inner_'+config['side']]).network]+[ipaddress.ip_interface(pair[config['side']]).network for pair in s.get('addresses',[])]
        conflicts=[]
        for link in addresses:
            if link.get('ifname')==paths(config['id'])['iface']: continue
            for addr in link.get('addr_info',[]):
                network=ipaddress.ip_network(addr['local']+'/'+str(addr['prefixlen']),strict=False)
                if any(network.version==wanted.version and network.overlaps(wanted) for wanted in desired): conflicts.append(link['ifname'])
        checks.append({'name':'inner address does not overlap '+','.join(conflicts),'ok':not conflicts})
        if s.get('bind_device'): checks.append({'name':'bind device exists','ok':run(['ip','link','show','dev',s['bind_device']],check=False).returncode==0})
    else:
        binary=OPT/('gost-'+s['version'])
        checks.append({'name':'pinned GOST '+s['version'],'ok':binary.is_file()})
    if config['side']=='iran' or (config['engine']=='gost' and s['transport']=='grpc'):
        wanted={n for m in s['mappings'] for n in range(m['public_port'],m['public_end']+1)} if config['side']=='iran' else {s['tunnel_port']}
        pid=run(['systemctl','show','--property=MainPID','--value',paths(config['id'])['unit'].name],check=False).stdout.strip()
        listeners=run(['ss','-Hlnptu'],check=False)
        conflicts=[]
        for line in listeners.stdout.splitlines():
            parts=line.split()
            if len(parts)<5: continue
            number=re.search(r':(\d+)$',parts[4])
            if number and int(number.group(1)) in wanted and not (pid.isdigit() and int(pid)>0 and 'pid='+pid+',' in line): conflicts.append(number.group(1))
        checks.append({'name':'input ports are available '+','.join(conflicts),'ok':listeners.returncode==0 and not conflicts})
    return checks


def dispatch(request):
    action=request.get('action');config=request.get('config')
    if action=='inventory':
        return {'interfaces':json.loads(run(['ip','-j','-d','link','show'])),'owned':[p.stem for p in ETC.glob('sb-*.json')]}
    if action=='history':
        request['id']=request.get('id') or (config or {}).get('id')
        valid_id(request['id'])
        with database() as db:
            rows=db.execute('SELECT taken,snapshot FROM samples WHERE id=? ORDER BY taken DESC LIMIT 288',(request['id'],)).fetchall()
            events=db.execute('SELECT taken,message FROM events WHERE id=? ORDER BY taken DESC LIMIT 100',(request['id'],)).fetchall()
        return {'samples':[{'taken':t,**json.loads(v)} for t,v in rows],'events':[{'taken':t,'state':v} for t,v in events]}
    validate(config);p=paths(config['id'])
    revision=str(request.get('revision',''))
    if action in ('prepare','rollback','commit') and not re.fullmatch(r'[a-f0-9]{16,64}',revision):
        raise ValueError('invalid revision')
    backup=ROOT/'backups'/revision/(config['id']+'.json')
    if action=='prepare':
        if backup.exists(): return {'prepared':True,'checks':[] if request.get('operation') in ('delete','stop') else preflight(config)}
        current=read(p['config'])
        atomic(backup,{'old':current,'runtime':read(p['runtime']), 'active':active_unit(config),'enabled':enabled_unit(config)})
        return {'prepared':True,'checks':[] if request.get('operation') in ('delete','stop') else preflight(config)}
    if action=='preview':
        return {'checks':preflight(config),'commands':gre_commands(config) if config['engine']=='gre' else [config['engine']+' '+config['settings']['version']+' with validated forwarding configuration'], 'rules':nft_rules(config) if config['engine']=='gre' and config['side']=='iran' else None}
    if action=='rollback':
        saved=read(backup)
        if saved is None: raise RuntimeError('rollback snapshot is missing')
        if saved['old']:
            old=saved['old'];install(old,service_state={'active':saved['active'],'enabled':saved.get('enabled',old.get('enabled',True))})
            if saved['runtime']: atomic(p['runtime'],saved['runtime'])
        else: remove(config)
        return {'restored':True}
    if action=='commit':
        if config['engine']=='wattunnel' and not p['config'].exists():
            for suffix in ('.crt','.tls-key'):p['config'].with_suffix(suffix).unlink(missing_ok=True)
        backup.unlink(missing_ok=True)
        return {'committed':True}
    if action in ('apply','start','stop','restart','reapply'):
        if action in ('start','stop'): config['enabled']=action=='start'
        install(config)
        return snapshot(config)
    if action=='delete':
        remove(config)
        return {'deleted':True}
    if action=='status':
        return snapshot(read(p['config'],config))
    if action=='logs':
        out=run(['journalctl','--unit',p['unit'].name,'--no-pager','-n','100','-o','cat'],check=False)
        text=re.sub(r'(://)[^\s/@:]+:[^\s/@]+@',r'\1***@',out.stdout).replace(config['secret'],'***')
        return {'text':text[-20000:]}
    if action=='reset-quota':
        current=read(p['runtime'],{})
        current['quota_base']=dict(current.get('totals',{'rx':0,'tx':0}));current['quota_exhausted']=False
        atomic(p['runtime'],current)
        return {'reset':True}
    if action=='diagnose': return diagnostics(config,request.get('kind'))
    raise ValueError('unsupported action')


def main():
    os.umask(0o077)
    if len(sys.argv)>1 and sys.argv[1]=='daemon':
        ROOT.mkdir(parents=True,exist_ok=True)
        while True:
            for path in ETC.glob('sb-*.json'):
                try:
                    with open(ROOT/'worker.lock','a') as lock:
                        fcntl.flock(lock,fcntl.LOCK_EX)
                        daemon_tick(validate(read(path)))
                except Exception as error:
                    print(json.dumps({'id':path.stem,'error':str(error)[:500]}),flush=True)
            time.sleep(1)
    elif len(sys.argv)>2 and sys.argv[1] in ('boot','down'):
        # Called by systemd while the parent request holds its mutation lock.
        config=validate(read(Path(sys.argv[2])))
        boot(config) if sys.argv[1]=='boot' else down(config)
    else:
        if os.geteuid()!=0: raise RuntimeError('root or passwordless sudo is required')
        ROOT.mkdir(parents=True,exist_ok=True,mode=0o700)
        request=json.loads(Path(sys.argv[1]).read_text())
        with open(ROOT/'worker.lock','a') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX)
            answer=dispatch(request)
        print(json.dumps({'ok':True,'data':answer},ensure_ascii=False))


if __name__=='__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'ok':False,'error':str(error)},ensure_ascii=False))
        sys.exit(1)

