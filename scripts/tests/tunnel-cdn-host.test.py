#!/usr/bin/env python3
"""Certificate staging, PID-bound edge checks and WatTunnel routing; no SSH/DNS/ACME writes."""
import copy,importlib.util,pathlib,socket,subprocess,tempfile,time,unittest
from unittest.mock import patch
ROOT=pathlib.Path(__file__).resolve().parents[2]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
h=load('cdn_host','scripts/tunnel-cdn/host.py')
w=load('wat_cdn','scripts/network-tunnels/wat.py')
ID='sb-'+'a'*32
class Certificates(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=pathlib.Path(self.tmp.name)
        self.paths=patch.multiple(h,ROOT=self.root/'state',ETC=self.root/'etc',UNITS=self.root/'units');self.paths.start()
        self.root.joinpath('units').mkdir()
        self.cert=self.root/'cert.pem';self.key=self.root/'key.pem'
        subprocess.run(['openssl','req','-x509','-newkey','ec','-pkeyopt','ec_paramgen_curve:P-256','-nodes','-days','3','-subj','/CN=tunnel.example.com','-addext','subjectAltName=DNS:tunnel.example.com','-keyout',str(self.key),'-out',str(self.cert)],check=True,capture_output=True)
        self.r={'id':ID,'engine':'backpack','cdn':{'domain':'tunnel.example.com','port':8443,'cert_config':{'cert_mode':'content','cert_content':self.cert.read_text(),'key_content':self.key.read_text()}}}
    def tearDown(self):self.paths.stop();self.tmp.cleanup()
    def test_valid_import_is_private_and_unchanged_second_time(self):
        first=h.certificate(self.r);self.assertTrue(first['changed']);self.assertGreater(first['expires_at'],time.time())
        self.assertEqual(pathlib.Path(first['key_file']).stat().st_mode&0o777,0o600)
        self.assertFalse(h.certificate(self.r)['changed'])
    def test_bad_domain_preserves_installed_pair(self):
        first=h.certificate(self.r);before=pathlib.Path(first['cert_file']).read_bytes()
        bad=copy.deepcopy(self.r);bad['cdn']['domain']='other.example.com'
        with self.assertRaises(ValueError):h.certificate(bad)
        self.assertEqual(pathlib.Path(first['cert_file']).read_bytes(),before)
    def test_mismatched_key_is_rejected(self):
        other=self.root/'other.key';subprocess.run(['openssl','genpkey','-algorithm','EC','-pkeyopt','ec_paramgen_curve:P-256','-out',str(other)],check=True,capture_output=True)
        bad=copy.deepcopy(self.r);bad['cdn']['cert_config']['key_content']=other.read_text()
        with self.assertRaises(ValueError):h.certificate(bad)
        self.assertFalse((h.ETC/ID/'fullchain.pem').exists())
    def test_file_mode_copies_validated_pair(self):
        self.r['cdn']['cert_config']={'cert_mode':'file','cert_file':str(self.cert),'key_file':str(self.key)}
        info=h.certificate(self.r);self.assertNotEqual(info['cert_file'],str(self.cert))
    def test_revision_restores_certificate_and_does_not_touch_source(self):
        first=h.certificate(self.r);revision={'id':ID,'revision':'b'*32}
        h.revision({**revision,'action':'snapshot'});pathlib.Path(first['cert_file']).write_text('damaged')
        with patch.object(h,'run',return_value=''):h.revision({**revision,'action':'rollback'})
        self.assertEqual(pathlib.Path(first['cert_file']).read_text(),self.cert.read_text())
        h.revision({**revision,'action':'commit'});self.assertFalse((h.ROOT/'revisions'/(ID+'-'+'b'*32)).exists())
    def test_import_does_not_install_acme_timer(self):
        h.certificate(self.r)
        with patch.object(h,'run',return_value=''):h.renewal(self.r)
        self.assertFalse((h.ETC/ID/'renew.json').exists());self.assertFalse(list(h.UNITS.glob('*.timer')))

class Route(unittest.TestCase):
    def setUp(self):self.r={'id':ID,'engine':'backpack','cdn':{'domain':'tunnel.example.com','port':8443},'origins':['192.0.2.1','192.0.2.2']}
    def dns(self,host,*a,**kw):
        ip={'tunnel.example.com':'185.143.234.132'}.get(host,host)
        return [(socket.AF_INET,socket.SOCK_STREAM,6,'',(ip,8443))]
    def test_origin_dns_rejected(self):
        with patch.object(socket,'getaddrinfo',side_effect=lambda *a,**kw:[(2,1,6,'',('192.0.2.1',8443))]):
            with self.assertRaises(ValueError):h.resolve(self.r['cdn'],self.r['origins'])
    def test_edge_must_be_in_current_dns(self):
        with patch.object(socket,'getaddrinfo',side_effect=self.dns):
            self.assertEqual(h.resolve(self.r['cdn'],self.r['origins'])['addresses'],['185.143.234.132'])
            with self.assertRaises(ValueError):h.resolve({**self.r['cdn'],'edge_ip':'1.1.1.1'},self.r['origins'])
    def test_only_engine_pid_sockets_verify(self):
        def fake(args,**kw):
            if args[0]=='systemctl':return '1442'
            return subprocess.CompletedProcess(args,0,'ESTAB 0 0 5.75.199.114:52078 185.143.234.132:8443 users:(("backpack",pid=1442,fd=9))\nESTAB 0 0 5.75.199.114:52079 193.151.150.51:8443 users:(("xboard-node",pid=799,fd=9))','')
        with patch.object(socket,'getaddrinfo',side_effect=self.dns),patch.object(h,'run',side_effect=fake):
            self.assertTrue(h.peers(self.r)['cdn_verified'])
        with patch.object(socket,'getaddrinfo',side_effect=self.dns),patch.object(h,'run',side_effect=lambda args,**kw:'999' if args[0]=='systemctl' else fake(args,**kw)):
            self.assertFalse(h.peers(self.r)['cdn_verified'])
    def test_stopped_service_is_unverified(self):
        with patch.object(socket,'getaddrinfo',side_effect=self.dns),patch.object(h,'run',return_value='0'):
            self.assertFalse(h.peers(self.r)['cdn_verified'])

class WatRouting(unittest.TestCase):
    def test_both_directions_and_three_http_carriers(self):
        settings={'version':w.VERSION,'transport':'wss','protocol':'both','peer_host':'192.0.2.2','local_endpoint':'192.0.2.1','ip_family':'ipv4','metrics_port':45000,'tunnel_port':18443,'mtu':0,'conns':8,'sessions':1,'lanes':4,'icmp_type':'echo','fec':'off','max_mbps':0,'tcp_cc':'system','path':'/wat','ping_every':2,'udp_idle':60,'hedge':False,'fingerprint':'go','tls_frag':0,'sni':'old.example.com','host_header':'old.example.com','tls_pin':'','tls_small_ch':False,'connection_mode':'cdn','cdn':{'domain':'tunnel.example.com','port':8443},'mappings':[{'public_port':25443,'public_end':25443,'target_port':443,'target_end':443}]}
        for transport in ('ws','wss','grpc'):
            for reverse in (False,True):
                for side in ('iran','foreign'):
                    s={**settings,'transport':transport,'reverse':reverse};c={'id':ID,'engine':'wattunnel','side':side,'settings':s,'secret':'a'*64,'subnet':45000}
                    args=w.arguments(c,'/private/key');listener=(side=='iran')==reverse
                    if listener:self.assertEqual(args[args.index('-listen')+1],'0.0.0.0:18443');self.assertNotIn('-peer',args)
                    else:
                        self.assertEqual(args[args.index('-peer')+1],'tunnel.example.com:8443');self.assertEqual(args[args.index('-sni')+1],'tunnel.example.com');self.assertEqual(args[args.index('-host')+1],'tunnel.example.com')
    def test_direct_peer_and_port_unchanged(self):
        # No CDN data is consulted in direct mode (full direct adapter suite covers the rest).
        c={'side':'foreign','settings':{'reverse':False,'local_endpoint':'192.0.2.1','peer_host':'192.0.2.2','transport':'tcp','metrics_port':45000,'mtu':0,'conns':8,'lanes':4,'icmp_type':'echo','fec':'off','max_mbps':0,'tcp_cc':'system','path':'/wat','ping_every':2,'udp_idle':60,'ip_family':'ipv4','tunnel_port':18443,'hedge':False,'mappings':[{'target_port':443,'target_end':443}],'protocol':'tcp'}}
        args=w.arguments(c,'key');self.assertEqual(args[args.index('-listen')+1],'0.0.0.0:18443')

if __name__=='__main__':unittest.main()
