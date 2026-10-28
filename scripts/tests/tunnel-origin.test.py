#!/usr/bin/env python3
"""Identity endpoint routing, ownership and rollback; no system mutations."""
import copy,importlib.util,json,subprocess,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
o=module('origin_host','scripts/tunnel-origin/host.py')
h=module('network_host','scripts/network-tunnels/host.py')
defaults=json.loads((ROOT/'scripts/network-tunnels/defaults.json').read_text())
IDENT='sb-'+'a'*32
def plan(side='iran'):
    return {'version':2,'side':side,'port':25001,'secret':'b'*64,'tcp':True,'udp':True,'entries':[{'public_port':7798,'target_port':7798}]}
def config(engine='gost'):
    return {'id':IDENT,'engine':engine,'side':'iran','secret':'b'*64,'enabled':True,'subnet':33000,'origin_plan':plan(),
            'settings':{**copy.deepcopy(defaults),'engine':engine,'transport':'gre' if engine=='gre' else 'tcp','peer_host':'192.0.2.2','local_endpoint':'192.0.2.1','public_host':'192.0.2.1','inner_iran':'10.226.0.1/30','inner_foreign':'10.226.0.2/30','protocol':'both','mappings':[{'public_port':7798,'public_end':7798,'target_port':7798,'target_end':7798},{'public_port':8443,'public_end':8443,'target_port':443,'target_end':443}]}}

class Identity(unittest.TestCase):
    def test_carrier_readiness_can_recover_after_three_short_attempts(self):
        clock=[0.0];attempts=[]
        def command(argv,timeout):
            attempts.append(timeout);clock[0]+=4
            if len(attempts)<4:raise RuntimeError('carrier handshake pending')
            return subprocess.CompletedProcess(argv,0,'READY','')
        with tempfile.TemporaryDirectory() as d,patch.object(o,'ROOT',Path(d)),patch.object(o,'UNITS',Path(d)/'units'),patch.object(o,'command',side_effect=command),patch.object(o.time,'monotonic',side_effect=lambda:clock[0]),patch.object(o.time,'sleep',side_effect=lambda n:clock.__setitem__(0,clock[0]+n)),patch.object(o,'native') as native,patch.object(o,'install') as install:
            p={**plan(),'staged':True};old={'plan':p,'enabled':False,'pending':True};o.atomic(o.paths(IDENT)['state'],old)
            result=o.dispatch('backpack',{'action':'origin-probe','name':IDENT})
            self.assertTrue(result['data']['ready']);self.assertEqual(len(attempts),4);self.assertEqual(o.read(o.paths(IDENT)['state']),old);native.assert_not_called();install.assert_not_called()

    def test_disabled_node_is_not_a_false_native_backpack_target_outage(self):
        with tempfile.TemporaryDirectory() as d,patch.object(o,'ROOT',Path(d)/'state'),patch.object(o,'RUN',Path(d)/'run'),patch.object(o,'UNITS',Path(d)/'units'),patch.object(o,'active',return_value=True),patch.object(o,'command',return_value=subprocess.CompletedProcess([],0,'123\n','')):
            p={**plan('foreign'),'probe_targets':[]};o.atomic(o.paths(IDENT)['state'],{'plan':p,'enabled':True});o.atomic(o.paths(IDENT)['ready'],{'pid':123})
            reply={'ok':True,'data':{'active':True,'targets':{'7798':False,'8443':False},'metrics':{'connected':True}}}
            with patch.object(o,'native',return_value=reply):out=o.dispatch('backpack',{'action':'status','name':IDENT})['data']
            self.assertEqual(out['targets'],{'8443':False});self.assertEqual(out['source_identity_targets'],{});self.assertTrue(out['origin_endpoint'])

    def test_rollback_preserves_origin_boot_activity_and_pending_policy(self):
        for active in (False,True):
            for enabled in (False,True):
                with self.subTest(active=active,enabled=enabled),tempfile.TemporaryDirectory() as d,patch.object(o,'ROOT',Path(d)/'state'),patch.object(o,'ETC',Path(d)/'etc'),patch.object(o,'RUN',Path(d)/'run'),patch.object(o,'UNITS',Path(d)/'units'),patch.object(o,'active',return_value=active),patch.object(o,'preflight'),patch.object(o,'native',return_value={'ok':True,'data':{}}):
                    old={'plan':plan(),'enabled':True,'pending':False};p=o.paths(IDENT);o.atomic(p['state'],old);o.atomic(p['unit'],o.MARKER+'[Service]\n');calls=[]
                    def command(argv,**kw):
                        calls.append(argv)
                        if argv[1]=='restart':o.atomic(p['ready'],{'pid':123})
                        return subprocess.CompletedProcess(argv,(0 if enabled else 1) if argv[1]=='is-enabled' else 0,'123\n' if argv[1]=='show' else '','')
                    with patch.object(o,'command',side_effect=command):
                        r={'action':'snapshot','name':IDENT,'revision':'d'*32};o.dispatch('backpack',r);calls.clear();o.dispatch('backpack',{**r,'action':'rollback'})
                    self.assertEqual(o.read(p['state']),old,'runtime outage must not rewrite desired endpoint state')
                    self.assertIn(['systemctl','enable' if enabled else 'disable',p['unit'].name],calls)
                    self.assertIn(['systemctl','restart' if active else 'stop',p['unit'].name],calls)
        with tempfile.TemporaryDirectory() as d,patch.object(o,'ROOT',Path(d)/'state'),patch.object(o,'ETC',Path(d)/'etc'),patch.object(o,'RUN',Path(d)/'run'),patch.object(o,'UNITS',Path(d)/'units'),patch.object(o,'active',return_value=False),patch.object(o,'preflight'),patch.object(o,'command',return_value=subprocess.CompletedProcess([],1,'','')),patch.object(o,'native',return_value={'ok':True,'data':{}}):
            old={'plan':{**plan(),'staged':True},'enabled':False,'pending':True};o.atomic(o.paths(IDENT)['state'],old)
            r={'action':'snapshot','name':IDENT,'revision':'d'*32};o.dispatch('backpack',r);o.dispatch('backpack',{**r,'action':'rollback'})
            self.assertEqual(o.read(o.paths(IDENT)['state']),old,'pending activation survives rollback')

    def test_health_only_has_no_ingress_service_and_roundtrips_virtual_snapshot(self):
        with tempfile.TemporaryDirectory() as d,patch.object(o,'ROOT',Path(d)/'state'),patch.object(o,'ETC',Path(d)/'etc'),patch.object(o,'RUN',Path(d)/'run'),patch.object(o,'UNITS',Path(d)/'units'),patch.object(o,'command') as command,patch.object(o,'native',return_value={'ok':True,'data':{'active':True}}),patch.object(o,'preflight'):
            p={**plan(),'entries':[],'health_only':True,'staged':True};o.install(IDENT,p,True)
            saved=o.read(o.paths(IDENT)['state']);self.assertTrue(saved['enabled']);self.assertFalse(saved['pending']);self.assertFalse(o.paths(IDENT)['unit'].exists());command.assert_not_called()
            r={'action':'snapshot','name':IDENT,'revision':'c'*32};o.dispatch('backpack',r)
            o.install(IDENT,p,False);o.dispatch('backpack',{**r,'action':'rollback'})
            self.assertTrue(o.read(o.paths(IDENT)['state'])['enabled']);self.assertFalse(o.paths(IDENT)['unit'].exists())
            c=o.config_for({**p,'side':'foreign'},IDENT);self.assertEqual(c['role'],'health');self.assertEqual(c['targets'],[1]);self.assertNotIn('routes',c)

    def test_full_unmanaged_range_stays_one_native_mapping_plus_private_probe(self):
        c=config();c['settings']['mappings']=[{'public_port':10000,'public_end':10255,'target_port':11000,'target_end':11255}]
        p={**plan(),'entries':[],'health_only':True};out=o.transform('network',{'action':'apply','config':c},p)['config'];h.validate(out)
        self.assertEqual(out['settings']['mappings'][0],c['settings']['mappings'][0]);self.assertEqual(len(out['settings']['mappings']),2);self.assertEqual(out['health_probe'],2)

    def test_all_backpack_carriers_keep_non_node_maps_and_physical_roles(self):
        for kind,transports in [('reverse',['tcp','tcpmux','stealth','pck','ws','wss','wsmux','wssmux','kcp','quic','udp']),('direct',['xdi','pck','udp','quic','sni'])]:
            for transport in transports:
                for swapped in (False,True):
                    r={'action':'apply','name':IDENT,'kind':kind,'transport':transport,'swapped':swapped,'role':'server','token':'b'*64,'ports':'7798=7798,8443=443','origin_plan':plan()};before=copy.deepcopy(r)
                    out=o.transform('backpack',r,plan())
                    self.assertEqual(out['ports'],'8443=443,25001=25001');self.assertEqual(out['swapped'],swapped);self.assertEqual(out['transport'],transport);self.assertEqual(out['role'],'server');self.assertNotIn('origin_plan',out);self.assertEqual(r,before)

    def test_gost_versions_and_tcp_udp_grpc_carriers_use_same_identity_contract(self):
        for version in ('2.11.5','3.0.0'):
            for proto in ('tcp','udp','both'):
                for transport in ('tcp','grpc') if version=='3.0.0' else ('tcp',):
                    c=config();c['settings'].update(version=version,transport=transport,protocol=proto);r=o.transform('network',{'action':'reapply','config':c},plan())
                    h.validate(r['config']);self.assertEqual(r['config']['settings']['transport'],transport);self.assertEqual(r['config']['settings']['protocol'],proto);self.assertEqual(c['settings']['mappings'][0]['public_port'],7798)
                    self.assertIn('25001',str(h.gost_arguments(r['config'])))

    def test_gre_variants_keep_interface_nat_quota_and_owned_local_accounting(self):
        for transport in ('gre','gretap','ip6gre','ip6gretap'):
            c=config('gre');c['settings']['transport']=transport
            if transport.startswith('ip6'):c['settings'].update(peer_host='2001:db8::2',local_endpoint='2001:db8::1')
            out=o.transform('network',{'action':'apply','config':c},plan())['config'];h.validate(out);rules=h.nft_rules(out)
            self.assertIn('chain origin_mark',rules);self.assertIn('chain origin_tx',rules);self.assertIn('chain origin_rx',rules);self.assertEqual(rules.count('counter traffic_rx {}'),1);self.assertNotIn('flush ruleset',rules)
            self.assertIn(str((33000<<12)+4095),rules);self.assertEqual(out['settings']['inner_foreign'],c['settings']['inner_foreign']);self.assertEqual(out['settings']['nat'],c['settings']['nat'])

    def test_ranges_split_only_managed_node_ports(self):
        c=config();c['settings']['mappings']=[{'public_port':7797,'public_end':7799,'target_port':7797,'target_end':7799}]
        maps=o.transform('network',{'action':'apply','config':c},plan())['config']['settings']['mappings']
        self.assertEqual([m['public_port'] for m in maps],[7797,7799,25001]);self.assertEqual([m['target_port'] for m in maps],[7797,7799,25001])

    def test_target_allowlist_and_network_policy_survive_rendering(self):
        p=plan();p['allowed']=['192.0.2.0/24'];p['backend']='[2001:db8::2]:25001';p['mark']=33000<<12|4095
        ingress=o.config_for(p,IDENT);self.assertEqual(ingress['routes'],[{'listen':':7798','target':7798}]);self.assertEqual(ingress['backend'],'[2001:db8::2]:25001');self.assertEqual(ingress['allowed'],p['allowed']);self.assertEqual(ingress['mark'],p['mark'])
        p['side']='foreign';egress=o.config_for(p,IDENT);self.assertEqual(egress['targets'],[7798]);self.assertEqual(egress['listen'],':25001');self.assertNotIn('routes',egress)

    def test_invalid_owner_and_port_cannot_reach_native_worker(self):
        for mutate in [lambda p:p.update(port=7798),lambda p:p.update(secret='guess'),lambda p:p.update(side='internet'),lambda p:p['entries'].append(p['entries'][0])]:
            p=plan();mutate(p)
            with self.assertRaises(ValueError):o.validate(p)
        with self.assertRaises(ValueError):o.paths('../../etc/shadow')

    def test_snapshot_is_idempotent_and_rollback_stops_front_before_native_restore(self):
        with tempfile.TemporaryDirectory() as d,patch.object(o,'ROOT',Path(d)/'state'),patch.object(o,'ETC',Path(d)/'etc'),patch.object(o,'RUN',Path(d)/'run'),patch.object(o,'UNITS',Path(d)/'units'):
            old=plan();old['secret']='c'*64;o.atomic(o.paths(IDENT)['state'],{'plan':old,'enabled':False});events=[]
            def native(backend,r):events.append(('native',r['action']));return {'ok':True,'data':{'active':True}}
            with patch.object(o,'native',side_effect=native),patch.object(o,'active',return_value=False),patch.object(o,'preflight'),patch.object(o,'stop',side_effect=lambda _:events.append(('stop',None))),patch.object(o,'install',side_effect=lambda _,p,e,**kw:events.append(('restore',p['secret'],e))):
                r={'action':'snapshot','name':IDENT,'revision':'d'*32};o.dispatch('backpack',r)
                o.atomic(o.paths(IDENT)['state'],{'plan':plan(),'enabled':True});o.dispatch('backpack',r)
                o.dispatch('backpack',{**r,'action':'rollback'})
            self.assertLess(events.index(('stop',None)),events.index(('native','rollback')));self.assertIn(('restore','c'*64,False),events)

    def test_stopped_backpack_is_not_enabled_by_identity_upgrade(self):
        with tempfile.TemporaryDirectory() as d,patch.object(o,'ROOT',Path(d)),patch.object(o,'preflight'),patch.object(o,'install') as install,patch.object(o,'native',return_value={'ok':True,'data':{'active':False}}) as native:
            r={'action':'apply','name':IDENT,'origin_plan':plan(),'ports':'7798=7798','enabled':False};o.dispatch('backpack',r)
            self.assertEqual(native.call_args_list[-1].args[1]['action'],'apply');self.assertFalse(native.call_args.args[1]['enabled']);self.assertFalse(install.call_args.args[2])

    def test_wat_and_unmanaged_configs_do_not_gain_an_identity_frontend(self):
        c=config();c['origin_plan']=None;c['engine']='wattunnel';r={'action':'apply','config':c}
        with tempfile.TemporaryDirectory() as d,patch.object(o,'ROOT',Path(d)),patch.object(o,'native',return_value={'ok':True,'data':{}}) as native,patch.object(o,'install') as install:
            o.dispatch('network',r);install.assert_not_called();out=native.call_args.args[1];self.assertEqual(out['config']['settings'],c['settings']);self.assertNotIn('origin_plan',out['config'])

    def test_staged_ingress_stays_stopped_until_explicit_activation(self):
        with tempfile.TemporaryDirectory() as d,patch.object(o,'ROOT',Path(d)/'state'),patch.object(o,'ETC',Path(d)/'etc'),patch.object(o,'RUN',Path(d)/'run'),patch.object(o,'UNITS',Path(d)/'units'):
            p=plan();p['staged']=True;events=[]
            def command(argv,**kwargs):
                events.append(argv)
                if argv[1]=='restart':o.atomic(o.paths(IDENT)['ready'],{'pid':123})
                return subprocess.CompletedProcess(argv,0,'123\n' if argv[1]=='show' else '','')
            with patch.object(o,'command',side_effect=command),patch.object(o,'native',return_value={'ok':True,'data':{'active':True}}) as native,patch.object(o,'preflight'):
                o.dispatch('backpack',{'action':'apply','name':IDENT,'ports':'7798=7798','origin_plan':p})
                saved=o.read(o.paths(IDENT)['state']);self.assertTrue(saved['pending']);self.assertFalse(saved['enabled'])
                self.assertFalse(any(v[1] in ('enable','restart') for v in events))
                before=native.call_count
                result=o.dispatch('backpack',{'action':'origin-activate','name':IDENT})
                self.assertTrue(result['data']['active']);self.assertEqual(native.call_count,before)
                saved=o.read(o.paths(IDENT)['state']);self.assertFalse(saved['pending']);self.assertTrue(saved['enabled'])
                self.assertEqual(sum(v[1]=='restart' for v in events),1)
                o.dispatch('backpack',{'action':'origin-activate','name':IDENT})
                self.assertEqual(sum(v[1]=='restart' for v in events),1)

    def test_unmanaged_service_cannot_be_stopped_or_overwritten(self):
        with tempfile.TemporaryDirectory() as d,patch.object(o,'UNITS',Path(d)),patch.object(o,'ROOT',Path(d)/'state'),patch.object(o,'native') as native,patch.object(o,'command') as command:
            unit=o.paths(IDENT)['unit'];unit.write_text('[Service]\nExecStart=/unrelated\n')
            for action in ('apply','stop','delete','rollback'):
                with self.assertRaisesRegex(RuntimeError,'unmanaged'):o.dispatch('backpack',{'action':action,'name':IDENT})
            self.assertEqual(unit.read_text(),'[Service]\nExecStart=/unrelated\n');native.assert_not_called();command.assert_not_called()
            unit.unlink();unit.symlink_to(Path(d)/'absent')
            with self.assertRaisesRegex(RuntimeError,'unmanaged'):o.stop(IDENT)

    def test_identity_directories_are_private_and_do_not_follow_symlinks(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);private=root/'private';o.private_directory(private)
            self.assertEqual(private.stat().st_mode&0o777,0o700)
            private.chmod(0o755)
            with self.assertRaisesRegex(RuntimeError,'not private'):o.private_directory(private)
            private.chmod(0o700);link=root/'alias';link.symlink_to(private,target_is_directory=True)
            with self.assertRaisesRegex(RuntimeError,'not private'):o.private_directory(link)

    def test_blocked_carrier_probe_leaves_ingress_staged_and_never_calls_native(self):
        clock=[0.0]
        def failure(argv,timeout):clock[0]+=min(12,timeout);raise RuntimeError('blocked relay')
        with tempfile.TemporaryDirectory() as d,patch.object(o,'ROOT',Path(d)),patch.object(o,'UNITS',Path(d)/'units'),patch.object(o,'command',side_effect=failure) as command,patch.object(o,'native') as native,patch.object(o,'install') as install,patch.object(o.time,'monotonic',side_effect=lambda:clock[0]),patch.object(o.time,'sleep',side_effect=lambda n:clock.__setitem__(0,clock[0]+n)):
            p=plan();p['staged']=True;state={'plan':p,'enabled':False,'pending':True};o.atomic(o.paths(IDENT)['state'],state)
            with self.assertRaisesRegex(RuntimeError,'carrier probe failed'):
                o.dispatch('backpack',{'action':'origin-probe','name':IDENT})
            self.assertEqual(clock[0],45);self.assertEqual(command.call_count,4);self.assertTrue(all(0<c.kwargs['timeout']<=15 for c in command.call_args_list));self.assertEqual(o.read(o.paths(IDENT)['state']),state)
            native.assert_not_called();install.assert_not_called()

if __name__=='__main__':unittest.main(verbosity=2)
