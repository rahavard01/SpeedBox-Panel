"""WatTunnel engine adapter. No upstream installer, firewall or kernel tuning."""
import hashlib,ipaddress,json,re,socket,ssl,time,urllib.request

VERSION='2.10.9-sb1'
CARRIERS=('icmp','udp','tcp','tls','ws','wss','h2','h2c','grpc')

def validate(c):
    if c.get('engine')!='wattunnel' or c.get('side') not in ('iran','foreign') or not re.fullmatch(r'sb-[a-f0-9]{32}',c.get('id','')):raise ValueError('invalid WatTunnel identity')
    if not re.fullmatch('[a-f0-9]{64}',c.get('secret','')):raise ValueError('invalid WatTunnel credential')
    s=c['settings']
    if s.get('version')!=VERSION or s.get('transport') not in CARRIERS or s.get('protocol') not in ('tcp','udp','both'):raise ValueError('invalid WatTunnel method')
    family={'ipv4':4,'ipv6':6}.get(s.get('ip_family'))
    if not family or any(ipaddress.ip_address(s[k]).version!=family for k in ('peer_host','local_endpoint')):raise ValueError('WatTunnel address family mismatch')
    for k,lo,hi in [('tunnel_port',1,65535),('metrics_port',32768,65500),('mtu',0,16384),('conns',1,64),('sessions',1,16),('lanes',1,16),('tls_frag',0,16384),('ping_every',1,300),('udp_idle',5,3600),('monitor_interval',5,300),('restart_hours',0,168)]:
        if type(s.get(k)) is not int or not lo<=s[k]<=hi:raise ValueError('invalid WatTunnel '+k)
    if s['metrics_port']!=c['subnet']:raise ValueError('invalid private metrics allocation')
    for k in ('reverse','tls_small_ch','hedge','monitor'):
        if type(s.get(k)) is not bool:raise ValueError('invalid WatTunnel '+k)
    if s['transport']=='icmp' and family!=4:raise ValueError('ICMP carrier is IPv4 only')
    if s['reverse'] and s['transport']=='udp':raise ValueError('UDP carrier cannot reverse')
    if s['sessions']>1 and (s['reverse'] or s['transport']=='icmp'):raise ValueError('sessions unsupported for this method')
    limit=1400 if s['transport'] in ('icmp','udp') else 16384
    if s['mtu'] and not 256<=s['mtu']<=limit:raise ValueError('invalid carrier MTU')
    for k,allowed in [('icmp_type',('echo','timestamp','info','mask')),('fec',('off','auto')),('tcp_cc',('system','bbr','cubic')),('fingerprint',('go','chrome','firefox','safari','edge','random'))]:
        if s.get(k) not in allowed:raise ValueError('invalid WatTunnel '+k)
    if type(s.get('max_mbps')) not in (int,float) or not 0<=s['max_mbps']<=1250:raise ValueError('invalid pacing limit')
    if not re.fullmatch(r'/[a-zA-Z0-9_/-]{1,119}',s.get('path','')):raise ValueError('invalid carrier path')
    for k in ('sni','host_header'):
        if not re.fullmatch(r'[a-zA-Z0-9.-]{0,253}',s.get(k,'')):raise ValueError('invalid TLS/HTTP hostname')
    if s.get('tls_pin') and not re.fullmatch('[a-f0-9]{64}',s['tls_pin']):raise ValueError('invalid certificate pin')
    maps=s.get('mappings');used=set()
    if not isinstance(maps,list) or not 1<=len(maps)<=40:raise ValueError('invalid WatTunnel mappings')
    for m in maps:
        for k in ('public_port','public_end','target_port','target_end'):
            if type(m.get(k)) is not int or not 1<=m[k]<=65535:raise ValueError('invalid mapping port')
        n=m['public_end']-m['public_port']
        if n<0 or m['target_end']-m['target_port']!=n or m.get('destinations'):raise ValueError('invalid mapping range')
        for p in range(m['public_port'],m['public_end']+1):
            if p in used:raise ValueError('duplicate public port')
            used.add(p)
        if m['target_port']<=s['metrics_port']<=m['target_end'] or (s['transport']!='icmp' and not s['reverse'] and m['target_port']<=s['tunnel_port']<=m['target_end']):raise ValueError('target overlaps engine listener')
    if len(used)>256 or s['metrics_port'] in used or s['metrics_port']==s['tunnel_port'] or (s['reverse'] and s['transport']!='icmp' and s['tunnel_port'] in used):raise ValueError('internal/public port collision')
    return c

def endpoint(host,port):return ('['+host+']' if ':' in host else host)+':'+str(port)

def arguments(c,key_file):
    s=c['settings'];iran=c['side']=='iran';peer=s['local_endpoint'] if s['reverse'] else s['peer_host']
    args=['-mode','client' if iran else 'server','-carrier',s['transport'],'-key-file',str(key_file),
          '-metrics','127.0.0.1:'+str(s['metrics_port']),'-mtu',str(s['mtu']),'-conns',str(s['conns']),
          '-lanes',str(s['lanes']),'-icmp-type',s['icmp_type'],'-fec',s['fec'],'-max-mbps',str(s['max_mbps']),
          '-tcp-cc',s['tcp_cc'],'-path',s['path'],'-ping-every',str(s['ping_every']),'-udp-idle',str(s['udp_idle'])+'s']
    if s['reverse']:args+=['-reverse']
    listener=iran==s['reverse']
    if s['transport']=='icmp':
        args+=['-peer',s['peer_host'] if iran else s['local_endpoint']]
    elif listener:args+=['-listen',endpoint('::' if s['ip_family']=='ipv6' else '0.0.0.0',s['tunnel_port'])]
    else:args+=['-peer',endpoint(peer,s['tunnel_port'])]
    if s['hedge']:args+=['-hedge']
    # The dialling process owns TLS options in either direction.
    if not listener:
        args+=['-fp',s['fingerprint'],'-tls-frag',str(s['tls_frag'])]
        for flag,key in [('sni','sni'),('tls-pin','tls_pin'),('host','host_header')]:
            if s.get(key):args+=['-'+flag,s[key]]
        if s['tls_small_ch']:args+=['-tls-small-ch']
    if iran:
        args+=['-bind','::' if s['ip_family']=='ipv6' else '0.0.0.0','-sessions',str(s['sessions'])]
        maps=','.join(str(m['public_port']+i)+':'+str(m['target_port']+i) for m in s['mappings'] for i in range(m['public_end']-m['public_port']+1))
        if s['protocol']!='udp':args+=['-map',maps]
        if s['protocol']!='tcp':args+=['-udp',maps]
    else:
        # An empty allowlist in upstream opens all local ports. Always enumerate both protocols.
        allow=sorted({str(p)+suffix for m in s['mappings'] for p in range(m['target_port'],m['target_end']+1) for suffix in (('', '/udp') if s['protocol']=='both' else ('/udp',) if s['protocol']=='udp' else ('',))})
        args+=['-allow-ports',','.join(allow)]
    return args

def service_text(h,c):
    p=h.paths(c['id']);key=p['config'].with_suffix('.key');s=c['settings']
    argv=[str(h.OPT/('wattun-'+VERSION)),*arguments(c,key)]
    if s['transport'] in ('tls','wss','h2','grpc') and (c['side']=='iran')==s['reverse']:
        argv+=['-tls-cert',str(p['config'].with_suffix('.crt')),'-tls-key',str(p['config'].with_suffix('.tls-key'))]
    caps='CAP_NET_BIND_SERVICE'+(' CAP_NET_RAW' if s['transport']=='icmp' else '')
    return '# speedbox-network:managed=1\n[Unit]\nDescription=SpeedBox WatTunnel '+c['id']+'\nAfter=network-online.target\nWants=network-online.target\n[Service]\nType=simple\nRestart=on-failure\nRestartSec=3\nExecStart='+' '.join(h.unit_argument(x) for x in argv)+'\nUMask=0077\nNoNewPrivileges=true\nCapabilityBoundingSet='+caps+'\nProtectSystem=strict\nProtectHome=true\nPrivateTmp=true\nLimitNOFILE=262144\n[Install]\nWantedBy=multi-user.target\n'

def snapshot(h,c,probe=True):
    p=h.paths(c['id']);s=c['settings'];old=h.read(p['runtime'],{});now=int(time.time());running=h.active_unit(c);st={}
    if running:
        try:
            # Disable proxy environment: the management endpoint must remain on this host.
            opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
            with opener.open('http://127.0.0.1:'+str(s['metrics_port'])+'/stats',timeout=2) as r:st=json.loads(r.read(262144))
        except (OSError,ValueError):pass
    known=bool(st);connected=bool(st.get('alive')) if known else None
    totals=old.get('totals',{'rx':0,'tx':0})
    raw={'rx':int(st.get('bytes_in_total',0)),'tx':int(st.get('bytes_out_total',0))}
    if known:
        for k in ('rx','tx'):
            before=old.get('raw',{}).get(k,0);totals[k]+=raw[k]-before if raw[k]>=before else raw[k]
    state='stopped' if not c.get('enabled',True) else 'offline' if not running else 'connected' if connected else 'degraded'
    certificate=p['config'].with_suffix('.crt')
    fingerprint=hashlib.sha256(ssl.PEM_cert_to_DER_cert(certificate.read_text())).hexdigest() if certificate.is_file() else None
    result={'certificate_sha256':fingerprint,'checked_at':now,'active':running,'connected':connected,'state':state,'version':VERSION,'helper_version':h.VERSION,
            'traffic_available':known,'raw':raw,'totals':totals,'destinations':{},'quota_exhausted':False,'quota_stopped':False,
            'last_restart':old.get('last_restart',now),'engine_stats':{k:st.get(k) for k in ('srtt_ms','loss_pct','streams','sessions','dgram_flows','retrans','fec_recovered','dgram_dropped_size','dgram_dropped_queue','pool_conns')}}
    h.atomic(p['runtime'],result);return result

def preflight(h,c):
    import shutil
    s=c['settings'];p=h.paths(c['id']);checks=[{'name':x,'ok':shutil.which(x) is not None} for x in ('systemctl','ss')]
    checks.append({'name':'pinned WatTunnel '+VERSION,'ok':(h.OPT/('wattun-'+VERSION)).is_file()})
    wanted={s['metrics_port']}
    if c['side']=='iran':wanted.update(p for m in s['mappings'] for p in range(m['public_port'],m['public_end']+1))
    if s['transport']!='icmp' and (c['side']=='iran')==s['reverse']:wanted.add(s['tunnel_port'])
    pid=h.run(['systemctl','show','--property=MainPID','--value',p['unit'].name],check=False).stdout.strip()
    listeners=h.run(['ss','-Hlnptu'],check=False);conflicts=[]
    for line in listeners.stdout.splitlines():
        parts=line.split();match=re.search(r':(\d+)$',parts[4]) if len(parts)>=5 else None
        if match and int(match[1]) in wanted and not (pid.isdigit() and int(pid)>0 and 'pid='+pid+',' in line):conflicts.append(match[1])
    checks.append({'name':'WatTunnel ports available '+','.join(conflicts),'ok':listeners.returncode==0 and not conflicts})
    if s['transport']=='icmp':
        try:
            raw=socket.socket(socket.AF_INET6 if s['ip_family']=='ipv6' else socket.AF_INET,socket.SOCK_RAW,socket.IPPROTO_ICMPV6 if s['ip_family']=='ipv6' else socket.IPPROTO_ICMP);raw.close();ok=True
        except OSError:ok=False
        checks.append({'name':'raw ICMP socket capability','ok':ok})
    return checks


def prepare_files(h,c):
    p=h.paths(c['id']);s=c['settings'];h.atomic(p['config'].with_suffix('.key'),c['secret']+'\n')
    if s['transport'] not in ('tls','wss','h2','grpc') or (c['side']=='iran')!=s['reverse']:return
    cert=p['config'].with_suffix('.crt');key=p['config'].with_suffix('.tls-key')
    if cert.exists()!=key.exists():raise RuntimeError('incomplete WatTunnel certificate pair')
    if cert.exists():return
    cert_tmp=cert.with_suffix('.crt.new');key_tmp=key.with_suffix('.tls-key.new')
    try:
        h.run(['openssl','req','-x509','-newkey','ec','-pkeyopt','ec_paramgen_curve:P-256','-nodes','-days','3650','-subj','/CN=SpeedBox WatTunnel','-keyout',str(key_tmp),'-out',str(cert_tmp)])
        key_tmp.chmod(0o600);cert_tmp.chmod(0o600);key_tmp.replace(key);cert_tmp.replace(cert)
    finally:
        key_tmp.unlink(missing_ok=True);cert_tmp.unlink(missing_ok=True)
