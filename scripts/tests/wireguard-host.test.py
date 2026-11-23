#!/usr/bin/env python3
import base64, copy, importlib.util, pathlib, tempfile, unittest
from unittest.mock import patch
spec=importlib.util.spec_from_file_location('host',pathlib.Path(__file__).parents[1]/'wireguard/host.py');host=importlib.util.module_from_spec(spec);spec.loader.exec_module(host)
K=base64.b64encode(bytes(range(32))).decode();K2=base64.b64encode(bytes(range(1,33))).decode()
def policy():
 return {'owner_id':'11111111-1111-1111-1111-111111111111','interface':'sbw1','network':'10.87.1.0/24','listen_port':51820,'mtu':1380,'enabled':True,'lease_until':1200,'accounts':{'1':{'enabled':True,'expires_at':1100,'ceiling':1000,'speed_mbps':10}},'peers':[{'user_id':1,'public_key':K,'preshared_key':K2,'address':'10.87.1.2','enabled':True}]}
class Native(unittest.TestCase):
 def test_validated_private_network_and_key(self):
  self.assertEqual(host.validate(policy())['interface'],'sbw1')
  for field,value in [('interface','wg0;rm -rf /'),('network','8.8.8.0/24'),('network','10.87.1.0/8'),('listen_port',22),('mtu',9000)]:
   p=policy();p[field]=value
   with self.assertRaises((ValueError,KeyError)):host.validate(p)
 def test_empty_php_policy_can_start_before_first_account(self):
  p=policy();p['peers']=[];p['accounts']=[];p['retired']=[];host.validate(p)
  with patch.object(host,'run',return_value=''):host.shaping(p)
  self.assertEqual(host.wanted(p,{'peers':{}},1000),set())
 def test_duplicate_or_escaped_peer_cannot_claim_addresses(self):
  for value in ['10.87.1.1','10.87.2.2','10.87.1.255']:
   p=policy();p['peers'][0]['address']=value
   with self.assertRaises(ValueError):host.validate(p)
  p=policy();p['peers'].append(copy.deepcopy(p['peers'][0]))
  with self.assertRaises(ValueError):host.validate(p)
 def test_quota_expiry_ban_and_disable_enforced_without_panel(self):
  ledger={'peers':{K:{'rx':500,'tx':499}}};p=policy()
  self.assertEqual(host.wanted(p,ledger,1000),{K})
  self.assertEqual(host.wanted(p,ledger,1100),set())
  p['accounts']['1']['expires_at']=0
  self.assertEqual(host.wanted(p,ledger,1200),{K})
  for target in ['enabled','peer','account','quota']:
   q=copy.deepcopy(p)
   if target=='enabled':q['enabled']=False
   if target=='peer':q['peers'][0]['enabled']=False
   if target=='account':q['accounts']['1']['enabled']=False
   if target=='quota':q['accounts']['1']['ceiling']=999
   self.assertEqual(host.wanted(q,ledger,1000),set())
 def test_multi_peer_and_rotated_keys_share_one_quota(self):
  p=policy();p['retired']={K2:1};ledger={'peers':{K:{'rx':400,'tx':100},K2:{'rx':500,'tx':0}}}
  self.assertEqual(host.wanted(p,ledger,1000),set())
  p['accounts']['1']['ceiling']=2000
  self.assertEqual(host.wanted(p,ledger,1000),{K})
 def test_durable_delta_no_double_charge_and_restart(self):
  with tempfile.TemporaryDirectory() as d:
   folder=pathlib.Path(d);dump=lambda rx,tx:f'private\tpublic\t51820\toff\n{K}\tpsk\t(endpoint)\t10.87.1.2/32\t1000\t{rx}\t{tx}\t0'
   with patch.object(host,'run',return_value=dump(100,200)):
    self.assertEqual(host.snapshot(folder,'sbw1')['peers'][K]['rx'],100)
    self.assertEqual(host.snapshot(folder,'sbw1')['peers'][K]['rx'],100)
   with patch.object(host,'run',return_value=dump(150,240)):
    state=host.snapshot(folder,'sbw1');self.assertEqual(state['peers'][K]['tx'],240)
   # Graceful stop snapshots and clears the current kernel epoch, retaining totals.
   with patch.object(host,'run',return_value=''):host.remove_peer(folder,'sbw1',K,state)
   with patch.object(host,'run',return_value=dump(10,20)):
    state=host.snapshot(folder,'sbw1');self.assertEqual((state['peers'][K]['rx'],state['peers'][K]['tx']),(160,260))
   with patch.object(host,'run',return_value=dump(0,0)):
    with self.assertRaisesRegex(RuntimeError,'regressed'):host.snapshot(folder,'sbw1')
 def test_only_managed_interface_is_shaped_both_directions(self):
  calls=[]
  with patch.object(host,'run',side_effect=lambda *a,**k:calls.append(a) or ''):host.shaping(policy())
  filters=[a for a in calls if a[:2]==('tc','filter')]
  self.assertTrue(any('sbw1' in a and 'dst' in a for a in filters))
  self.assertTrue(any('sbi1' in a and 'src' in a for a in filters))
  self.assertFalse(any('eth0' in a for a in calls))
if __name__=='__main__':unittest.main()
