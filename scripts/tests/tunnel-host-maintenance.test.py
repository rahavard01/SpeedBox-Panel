"""Execute the SSH helper against a simulated kernel/systemd in temporary files."""
import gzip
import hashlib
import importlib.util
import json
import tempfile
import tarfile
import re
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('maintenance', ROOT/'scripts/backpack/host-maintenance.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class HostMaintenanceTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        root = Path(self.temp.name)
        self.engine = root/'engine/backpack'
        self.saved = root/'sysctl.conf'
        self.limits = root/'limits.conf'
        self.reserve = root/'reserved.json'
        self.kernel = {'net.ipv4.ip_local_reserved_ports': '40000-40002'}
        self.fail_key = None
        self.fail_restart = False
        self.restarts = []
        self.unit = 'speedbox-backpack-sb-'+'a'*32+'.service'
        self.running = [self.unit]
        self.patches = [patch.object(m, 'ENGINE', self.engine), patch.object(m, 'STATE', root/'engine/installed.json'),
            patch.object(m, 'SYSCTL', self.saved), patch.object(m, 'LIMITS', self.limits),
            patch.object(m, 'UPDATES', root/'engine/updates'), patch.object(m, 'RESERVATIONS', self.reserve),
            patch.object(m, 'run', side_effect=self.run_command), patch.object(m, 'boot_settings', side_effect=self.boot),
            patch.object(m, 'bridge_version', return_value={'protocol': 1, 'upstream': 'v1.8.4'})]
        for p in self.patches:p.start()
        self.tuning = json.loads((ROOT/'scripts/backpack/tuning.json').read_text())
        self.archive = root/'package.gz'
        self.binary = b'new verified integration executable'
        self.archive.write_bytes(gzip.compress(self.binary))
        self.request = {'action':'status', 'ports':[8000,8744], 'tuning':self.tuning,
            'archive':str(self.archive), 'revision':'b'*32,
            'expected':{'version':'v1.9.0','protocol':1,'binary_sha256':hashlib.sha256(self.binary).hexdigest(),
                'archive_sha256':m.digest(self.archive)}}

    def tearDown(self):
        for p in reversed(self.patches):p.stop()
        self.temp.cleanup()

    def boot(self):
        out = {}
        if self.saved.exists():
            for line in self.saved.read_text().splitlines():
                if '=' in line:
                    k,v=line.split('=',1);out[k.strip()]=m.normalized(v)
        return out

    def run_command(self, args, timeout=25):
        if args[0]=='sysctl':
            if args[1]=='-n':return (args[2] in self.kernel, self.kernel.get(args[2], 'unknown key'))
            k,v=args[2].split('=',1)
            if k==self.fail_key:return False, 'permission denied: '+k
            self.kernel[k]=m.normalized(v);return True, k+' = '+v
        if args[0]=='ss':return True,'tcp LISTEN 0 128 0.0.0.0:22 0.0.0.0:*\nudp UNCONN 0 0 [::]:2053 [::]:*'
        if args[0]=='modprobe':return True,''
        if args[0]=='systemctl':
            if args[1]=='list-units':return True,'\n'.join(n+' loaded active running tunnel' for n in self.running)
            if args[1]=='is-active':return args[-1] in self.running,''
            if args[1]=='show':return True,'999999'
            if args[1]=='restart':
                self.restarts.append(args[-1]);return not self.fail_restart,'restart result'
        if args[0].endswith('/next'):
            return True,json.dumps({'ok':True,'data':{'protocol':1}})
        raise AssertionError(args)

    def test_tuning_table_matches_the_actual_pinned_upstream_optimize(self):
        with tarfile.open(ROOT/'scripts/backpack/upstream-source.tar.gz') as archive:
            source=archive.extractfile('BackPack/internal/optimize/optimize.go').read().decode()
        table=source[source.index('var sysctls ='):source.index('// engineStartupKeys')]
        expected=dict(re.findall(r'\{"([^"]+)", "([^"]+)"\}',table))
        self.assertEqual(self.tuning,expected)

    def test_current_file_with_old_running_process_still_needs_update(self):
        self.engine.parent.mkdir();self.engine.write_bytes(self.binary)
        self.assertFalse(m.execute(self.request)['status']['up_to_date'])
        self.running=[]
        self.assertTrue(m.execute(self.request)['status']['up_to_date'])

    def test_upgrade_does_not_start_stopped_services(self):
        self.engine.parent.mkdir();self.engine.write_bytes(b'old engine')
        self.running=[]
        m.execute({**self.request,'action':'stage'})
        m.execute({**self.request,'action':'activate'})
        self.assertEqual(self.restarts,[])

    def test_status_does_not_write_any_configuration(self):
        before=list(Path(self.temp.name).rglob('*'))
        answer=m.execute(self.request)
        self.assertFalse(answer['status']['optimized'])
        self.assertEqual(before,list(Path(self.temp.name).rglob('*')))

    def test_optimize_matches_pinned_table_and_preserves_foreign_reservations(self):
        result=m.execute({**self.request,'action':'optimize'})
        self.assertTrue(result['status']['optimized'])
        self.assertEqual(set(self.tuning),set(r['key'] for r in result['status']['parameters']))
        self.assertTrue({22,2053,8000,8744,40000,40001,40002}<=m.ports(self.kernel['net.ipv4.ip_local_reserved_ports']))
        self.assertIn('32768 60999',self.kernel['net.ipv4.ip_local_port_range'])
        self.assertGreater(len(result['logs']),len(self.tuning))

    def test_rejected_kernel_parameter_is_not_reported_as_optimized(self):
        self.fail_key='net.ipv4.tcp_congestion_control'
        result=m.execute({**self.request,'action':'optimize'})
        self.assertFalse(result['status']['optimized'])
        self.assertTrue(any('permission denied' in line for line in result['logs']))

    def test_shared_host_new_ports_are_merged_and_only_owned_old_ports_removed(self):
        m.execute({**self.request,'action':'optimize'})
        m.execute({**self.request,'action':'reserve','ports':[2053,9000,8745]})
        reserved=m.ports(self.kernel['net.ipv4.ip_local_reserved_ports'])
        self.assertTrue({22,2053,9000,8745,40000,40001,40002}<=reserved)
        self.assertNotIn(8000,reserved)
        self.assertNotIn(8744,reserved)

    def test_port_refresh_does_not_optimize_an_untouched_host(self):
        m.execute({**self.request,'action':'reserve'})
        self.assertFalse(self.saved.exists())
        self.assertNotIn('net.core.default_qdisc',self.kernel)

    def test_external_drift_invalidates_previously_optimized_state(self):
        m.execute({**self.request,'action':'optimize'})
        self.kernel['net.core.default_qdisc']='pfifo_fast'
        self.assertFalse(m.execute(self.request)['status']['optimized'])

    def test_checksum_failure_does_not_replace_installed_engine(self):
        self.engine.parent.mkdir();self.engine.write_bytes(b'old engine')
        self.archive.write_bytes(b'invalid')
        with self.assertRaisesRegex(RuntimeError,'checksum'):m.execute({**self.request,'action':'stage'})
        self.assertEqual(self.engine.read_bytes(),b'old engine')

    def test_upgrade_verifies_stages_restarts_only_active_units_and_rolls_back(self):
        self.engine.parent.mkdir();self.engine.write_bytes(b'old engine')
        m.execute({**self.request,'action':'stage'})
        self.assertEqual(self.engine.read_bytes(),b'old engine')
        m.execute({**self.request,'action':'activate'})
        self.assertEqual(self.engine.read_bytes(),self.binary)
        self.assertEqual(self.restarts,[self.unit])
        m.execute({**self.request,'action':'rollback'})
        self.assertEqual(self.engine.read_bytes(),b'old engine')
        self.assertEqual(self.restarts,[self.unit,self.unit])

    def test_interrupted_restart_keeps_recoverable_backup(self):
        self.engine.parent.mkdir();self.engine.write_bytes(b'old engine')
        m.execute({**self.request,'action':'stage'})
        self.fail_restart=True
        with self.assertRaises(RuntimeError):m.execute({**self.request,'action':'activate'})
        self.fail_restart=False
        m.execute({**self.request,'action':'rollback'})
        self.assertEqual(self.engine.read_bytes(),b'old engine')

    def test_protocol_mismatch_is_rejected_before_staging(self):
        self.engine.parent.mkdir();self.engine.write_bytes(b'old engine')
        with patch.object(m,'bridge_version',return_value={'protocol':999}):
            with self.assertRaisesRegex(RuntimeError,'compatible'):m.execute({**self.request,'action':'stage'})
        self.assertEqual(self.engine.read_bytes(),b'old engine')

    def test_reserved_port_ranges_are_validated_and_compacted(self):
        self.assertEqual(m.port_text({1,2,3,5,7,8}), '1-3,5,7-8')
        for bad in ['0','65536','6-2','10;rm -rf /']:
            with self.assertRaises(ValueError):m.ports(bad)


if __name__=='__main__':unittest.main()
