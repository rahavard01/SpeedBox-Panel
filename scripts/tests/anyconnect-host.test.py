#!/usr/bin/env python3
import importlib.util
import json
import pathlib
import re
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch

root = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('anyconnect_host', root / 'anyconnect' / 'host.py')
host = importlib.util.module_from_spec(spec)
spec.loader.exec_module(host)


class HostTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.base = pathlib.Path(self.directory.name)
        self.policy = {
            'id': 26, 'network': '10.91.8.0/24', 'tunneled': True, 'link_network': '10.251.6.0/30',
            'link_port': 51980, 'endpoint_port': 443, 'listen_port': 9443, 'side': 'iran',
        }
        self.account = {
            'id': 7, 'username': 'alice@rep.com', 'hash': '$2y$12$' + 'A' * 53, 'uploaded': 0, 'downloaded': 0,
            'quota': 10000, 'used': 0, 'expires': None, 'banned': False, 'connections': 2, 'speed': 5,
        }
        self.addCleanup(patch.stopall)
        patch.object(host, 'BASE', self.base).start()
        host.folder(26).mkdir()
        import os
        host.initialize_db(self.policy, os.getgid())
        host.update_accounts(self.policy, [self.account])

    def send_accounting(self, session, up, down):
        return host.radius_request(26, 'accounting', {
            'User-Name': self.account['username'], 'Acct-Session-Id': session,
            'Acct-Input-Octets': str(up), 'Acct-Output-Octets': str(down),
        })

    def test_accounting_duplicates_reordering_and_new_sessions(self):
        self.send_accounting('a', 30, 90)
        self.send_accounting('a', 30, 90)
        self.send_accounting('a', 25, 100)
        self.send_accounting('b', 35, 70)
        with host.sqlite(host.store(self.policy)) as db:
            self.assertEqual(tuple(host.totals(db, 7)), (65, 170))
        with patch.object(host, 'online', return_value={self.account['username']: 1}):
            self.assertEqual(host.enforce(self.policy)[7], {'uploaded': 65, 'downloaded': 170, 'online': 1})

    def test_local_authorization_expires_even_without_panel(self):
        with patch('builtins.print') as output:
            self.assertEqual(host.radius_request(26, 'authenticate', {'Tmp-String-0': self.account['username']}), 0)
            output.assert_called_once()
        host.update_accounts(self.policy, [self.account | {'expires': 1}])
        self.assertEqual(host.radius_request(26, 'authenticate', {'Tmp-String-0': self.account['username']}), 1)

    def test_when_unreported_bytes_exhaust_quota_active_session_is_disconnected(self):
        self.send_accounting('a', 6000, 4000)
        with patch.object(host, 'online', return_value={self.account['username']: 1}), patch.object(host, 'occtl') as ctl:
            self.assertEqual(host.enforce(self.policy)[7]['online'], 0)
            ctl.assert_called_once_with(self.policy, 'disconnect', 'user', self.account['username'], check=False)
        self.assertEqual(host.radius_request(26, 'authenticate', {'Tmp-String-0': self.account['username']}), 1)

    def test_only_established_sessions_are_counted(self):
        response = '[{"Username":"alice@rep.com","State":"connected"}, {"Username":"alice@rep.com","State":"authenticating"}]'
        with patch.object(host, 'occtl', return_value=response):
            self.assertEqual(host.online(self.policy), {self.account['username']: 1})

    def test_alias_and_legacy_session_use_the_same_account(self):
        alias, legacy = 'alice-rep', self.account['username']
        host.update_accounts(self.policy, [self.account | {'login': alias}])
        for name in (alias, legacy):
            with patch('builtins.print'):
                self.assertEqual(0, host.radius_request(26, 'authenticate', {'Tmp-String-0': name}))
        with patch.object(host, 'online', return_value={alias: 1, legacy: 1}), patch.object(host, 'occtl'):
            self.assertEqual(2, host.enforce(self.policy)[self.account['id']]['online'])
        host.radius_request(26, 'accounting', {
            'User-Name': alias, 'Acct-Session-Id': 'alias-session', 'Acct-Input-Octets': '8',
        })
        with host.sqlite(host.store(self.policy)) as db:
            self.assertEqual(8, host.totals(db, self.account['id'])[0])

    def certificate_fixture(self, folder, domain):
        folder.mkdir(exist_ok=True)
        cert = folder / 'fullchain.pem'
        key = folder / 'privkey.pem'
        host.run('openssl', 'req', '-x509', '-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:prime256v1',
                 '-nodes', '-days', '2', '-subj', f'/CN={domain}', '-addext', f'subjectAltName=DNS:{domain}',
                 '-keyout', str(key), '-out', str(cert))
        return {'mode': 'content', 'cert_content': cert.read_text(), 'key_content': key.read_text()}

    def test_import_validates_pair_and_keeps_serving_previous_pair_on_failure(self):
        policy = self.policy | {'endpoint': 'vpn.example.test'}
        payload = self.certificate_fixture(self.base / 'fixture', policy['endpoint'])
        real_run = host.run
        def run(*args, **kwargs):
            return 'inactive' if args[0] == 'systemctl' else real_run(*args, **kwargs)
        with patch.object(host, 'run', side_effect=run):
            host.prepare_certificate(policy, self.base, payload)
            cert, key = host.certificate(policy, self.base)
            self.assertEqual(cert.read_text(), payload['cert_content'])
            self.assertEqual(key.stat().st_mode & 0o777, 0o600)
            wrong = self.certificate_fixture(self.base / 'wrong', 'wrong.example.test')
            with self.assertRaisesRegex(ValueError, 'پوشش'):
                host.prepare_certificate(policy, self.base, wrong)
            with self.assertRaisesRegex(ValueError, 'متناظر'):
                host.prepare_certificate(policy, self.base, payload | {'key_content': wrong['key_content']})
            self.assertEqual(cert.read_text(), payload['cert_content'])

    def test_dns_issuance_uses_stdin_and_preserves_pair_on_failed_renewal(self):
        policy = self.policy | {'endpoint': 'vpn.example.test'}
        payload = self.certificate_fixture(self.base / 'certificate-acme', policy['endpoint'])
        settings = {'mode': 'dns', 'dns_provider': 'cloudflare', 'dns_env': {'CLOUDFLARE_DNS_API_TOKEN': 'fixture-secret'}, 'email': ''}
        real_subprocess = host.subprocess.run
        def invoke(args, **kwargs):
            if args == [str(host.ACME_HELPER)]:
                self.assertIn('fixture-secret', kwargs['input'])
                self.assertNotIn('fixture-secret', str(args))
                return SimpleNamespace(returncode=invoke.returncode, stderr='fixture-secret')
            if args[0] == 'systemctl':
                return SimpleNamespace(returncode=0, stdout='inactive', stderr='')
            return real_subprocess(args, **kwargs)
        invoke.returncode = 0
        with patch.object(host.subprocess, 'run', side_effect=invoke):
            host.prepare_certificate(policy, self.base, settings)
            invoke.returncode = 1
            with self.assertRaisesRegex(RuntimeError, 'صدور یا تمدید') as failure:
                host.prepare_certificate(policy, self.base, settings)
            self.assertNotIn('fixture-secret', str(failure.exception))
            self.assertEqual(host.certificate(policy, self.base)[0].read_text(), payload['cert_content'])

    def test_http_issues_on_public_ingress_and_installs_the_pair_on_egress(self):
        domain = 'vpn.example.test'
        policy = self.policy | {
            'endpoint': domain, 'dns': ['1.1.1.1'], 'mtu': 1300,
            'owner': 'cb6cced5-f09e-4702-84bc-70090e006e84',
            'certificate': {'mode': 'http', 'email': 'owner@example.test'},
        }
        fixture = self.certificate_fixture(self.base / 'fixture', domain)
        real_subprocess = host.subprocess.run
        def invoke(args, **kwargs):
            if args == [str(host.ACME_HELPER)]:
                request = json.loads(kwargs['input'])
                self.assertEqual(request['cert_mode'], 'http')
                self.assertEqual(request['http_port'], invoke.expected_port)
                self.assertEqual(request['domain'], domain)
                self.assertNotIn('dns_env', request)
                destination = pathlib.Path(request['state_dir'])
                host.save(destination / 'fullchain.pem', fixture['cert_content'])
                host.save(destination / 'privkey.pem', fixture['key_content'])
                return SimpleNamespace(returncode=0)
            if args[0] == 'systemctl':
                return SimpleNamespace(returncode=0, stdout='inactive', stderr='')
            return real_subprocess(args, **kwargs)

        invoke.expected_port = 80
        ingress = self.base / 'ingress'
        with patch.object(host, 'BASE', ingress), patch.object(host.subprocess, 'run', side_effect=invoke):
            first = host.control({'action': 'issue_http_certificate', 'policy': policy})
            second = host.control({'action': 'issue_http_certificate', 'policy': policy})
            self.assertEqual(first, second)
            self.assertEqual(first['cert_content'], fixture['cert_content'])
            with patch.object(host, 'manage_link'):
                host.control({'action': 'remove_link', 'policy': policy})
            self.assertFalse((ingress / str(policy['id']) / 'certificate-acme-ingress').exists())

        with patch.object(host.subprocess, 'run', side_effect=invoke):
            exit_root = self.base / 'egress'
            exit_root.mkdir()
            host.prepare_certificate(policy, exit_root, {'mode': 'content'} | first)
            cert, key = host.certificate(policy, exit_root)
            self.assertEqual(cert.read_text(), fixture['cert_content'])
            self.assertEqual(key.read_text(), fixture['key_content'])
            # A direct service issues and installs on the same host.
            with patch.object(host, 'BASE', exit_root):
                invoke.expected_port = 8080
                host.prepare_certificate(policy, exit_root, policy['certificate'] | {'http_port': 8080})
            self.assertTrue((exit_root / 'certificate-acme-http' / 'fullchain.pem').exists())

    def test_http_acme_error_keeps_only_safe_diagnostic_fields(self):
        response = {'ok': False, 'stage': 'certificate order', 'code': 'rateLimited', 'detail': 'private-trace'}
        message = host.http_acme_failure(json.dumps(response))
        self.assertIn('rateLimited', message)
        self.assertIn('تأیید دامنه', message)
        self.assertNotIn('private-trace', message)
        self.assertIn('429', host.http_acme_failure('{"status":429}'))
        self.assertNotIn('private-trace', host.http_acme_failure('{"code":"private-trace"}'))
        self.assertIn('connection refused', host.http_acme_failure('{"diagnostic":"connection refused"}'))

    def test_host_preflight_rejects_other_listeners_and_overlapping_routes(self):
        with patch.object(host, 'run', return_value='tcp LISTEN users:(("nginx",pid=123,fd=6))'):
            with self.assertRaisesRegex(RuntimeError, '443'):
                host.check_port(443, 'tu')
            host.check_port(443, 'tu', {'123'})
        with patch.object(host, 'run', return_value='[{"dst":"default"},{"dst":"10.90.1.64/26","dev":"wg0"}]'):
            with self.assertRaisesRegex(RuntimeError, '10.90.1.64/26'):
                host.check_network(host.ipaddress.ip_network('10.90.1.0/24'), re.compile('acp26'))
            host.check_network(host.ipaddress.ip_network('10.90.2.0/24'), re.compile('acp26'))

    def test_tunnel_forwards_both_transports_with_the_return_path(self):
        with patch.object(host, 'fw') as firewall:
            host.link_rules(self.policy, True)
        rules = [call.args for call in firewall.call_args_list]
        for proto in ('tcp', 'udp'):
            self.assertTrue(any('-j' in rule and 'DNAT' in args and proto in args for _, _, args, _ in rules for rule in [args]))
            self.assertTrue(any('SNAT' in args and proto in args for _, _, args, _ in rules))
        self.assertTrue(any('ESTABLISHED,RELATED' in args for _, _, args, _ in rules))


if __name__ == '__main__':
    unittest.main()
