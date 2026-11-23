"""Standalone tests for operator rule isolation and persistence on failed updates."""

import importlib.util
import ipaddress
import json
from pathlib import Path
from types import SimpleNamespace
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("operator_firewall", ROOT / "operator-firewall/host.py")
host = importlib.util.module_from_spec(spec)
spec.loader.exec_module(host)


class OperatorFirewallTest(unittest.TestCase):
    def setUp(self):
        self.local = {ipaddress.ip_address("192.0.2.14"), ipaddress.ip_address("2001:db8::14")}
        self.payload = {
            "ssh_port": 2222,
            "rules": {
                "11": {"port": 10040, "addresses": ["192.0.2.14", "2001:db8::14"],
                       "protocols": ["tcp"], "mode": "direct", "operators": ["mci"]},
                "12": {"port": 2044, "addresses": ["192.0.2.14"], "protocols": ["udp"],
                       "mode": "gre", "operators": ["mci", "irancell"]},
            },
            "prefixes": {"mci": ["5.22.0.0/17", "2a01:5ec0::/32"], "irancell": ["2.144.0.0/16"]},
        }

    def test_rule_scope_and_independent_removal(self):
        rules = host.render(self.payload, self.local, [])
        self.assertIn("hook prerouting priority -110", rules)
        self.assertIn("ip6 daddr { 2001:db8::14 } ip6 saddr @mci_6 tcp dport 10040", rules)
        self.assertIn('udp dport 2044 counter drop comment "node 12 irancell"', rules)
        self.assertNotIn("@irancell_4 tcp", rules)
        self.payload["rules"]["12"]["operators"] = ["irancell"]
        rules = host.render(self.payload, self.local, [])
        self.assertIn('tcp dport 10040 counter drop comment "node 11 mci"', rules)
        self.assertNotIn('"node 12 mci"', rules)

    def test_protected_ports_addresses_and_service(self):
        root = self.payload["rules"]["11"]
        for port in (22, 2222):
            root["port"] = port
            with self.assertRaisesRegex(ValueError, "protected"):
                host.render(self.payload, self.local, [])
        root["port"] = 443
        with self.assertRaisesRegex(RuntimeError, "Another service"):
            host.render(self.payload, self.local, ['tcp LISTEN 0 512 0.0.0.0:443 0.0.0.0:* users:(("nginx",pid=23,fd=5))'])
        rules = host.render(self.payload, self.local, ['tcp LISTEN 0 512 0.0.0.0:443 0.0.0.0:* users:(("xboard-node",pid=23,fd=5))'])
        self.assertIn("tcp dport 443", rules)
        root["addresses"] = ["192.0.2.199"]
        with self.assertRaisesRegex(ValueError, "does not belong"):
            host.render(self.payload, self.local, [])

    def test_removes_only_managed_table_and_reports_legacy_script(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            profile, unit = root / "rules.nft", root / "test.service"
            profile.write_text(host.OWNER + host.RESET)
            unit.write_text(host.OWNER + "existing")
            commands = []

            def run(args, *, check=True, source=None):
                commands.append((args, source))
                return subprocess.CompletedProcess(args, 1 if args[-1] == host.TABLE else 0, "", "")

            payload = {"rules": {}, "inspect": self.payload["rules"], "ssh_port": 2222}
            with mock.patch.multiple(host, ROOT=root, PROFILE=profile, UNIT=unit, run=run), \
                    mock.patch.object(host.shutil, "which", return_value="/usr/sbin/nft"), \
                    mock.patch.object(host.Path, "is_dir", return_value=True), \
                    mock.patch.object(host.Path, "is_file", return_value=False):
                self.assertEqual(host.apply(payload), [11, 12])
            self.assertEqual(profile.read_text(), host.OWNER + host.RESET)
            self.assertTrue(any(args[-1] == "mci_block" for args, _ in commands))
            for _, source in commands:
                self.assertNotIn("mci_block", source or "")
                self.assertNotIn("flush ruleset", source or "")

    def test_inspect_detects_legacy_tcp_without_panel_filters(self):
        def match(protocol, field, value):
            return {"match": {"op": "==", "left": {"payload": {
                "protocol": protocol, "field": field}}, "right": value}}

        rules = [{"rule": {"expr": [
            match("ip", "saddr", "@mci_v4"), match("tcp", "dport", {"set": [10011, 10040]}),
            {"counter": {}}, {"drop": None},
        ]}}]

        def run(args):
            objects = [{"table": {"family": "inet", "name": "mci_block"}}] if args[-1] == "tables" else rules
            return subprocess.CompletedProcess(args, 0, json.dumps({"nftables": objects}), "")

        with mock.patch.object(host.shutil, "which", return_value="/usr/sbin/nft"), mock.patch.object(host, "run", side_effect=run) as calls:
            self.assertEqual(host.inspect_manual(10011, ["tcp", "udp"]), ["tcp"])
            self.assertEqual(host.inspect_manual(10012, ["tcp", "udp"]), [])
            self.assertTrue(all(call.args[0][1:3] == ["-j", "list"] for call in calls.call_args_list))

    def test_observes_two_nodes_in_one_read_only_host_batch(self):
        def match(protocol, field, right):
            return {"match": {"op": "==", "left": {"payload": {"protocol": protocol, "field": field}}, "right": right}}

        targets = {
            "11": {"port": 10011, "protocols": ["tcp", "udp"], "mode": "direct", "addresses": ["203.0.113.1"]},
            "12": {"port": 10012, "protocols": ["tcp"], "mode": "gre", "addresses": ["203.0.113.2"]},
        }
        with mock.patch.object(host.shutil, "which", return_value=None):
            with self.assertRaisesRegex(FileNotFoundError, "missing"):
                host.observe(targets)
        tables = [{"table": {"family": "inet", "name": table}} for table in ("mci_block", host.TABLE)]
        manual = [{"rule": {"expr": [match("ip", "saddr", "@mci_v4"), match("tcp", "dport", 10011), {"drop": None}]}}]
        managed = [
            {"table": {"comment": host.COMMENT}},
            {"chain": {"name": "incoming", "hook": "prerouting"}},
            {"set": {"name": "irancell_4", "elem": ["198.51.100.0/24"]}},
            {"rule": {"chain": "incoming", "comment": "node 12 irancell", "expr": [
                match("ip", "saddr", "@irancell_4"), match("ip", "daddr", {"set": ["203.0.113.2"]}),
                match("tcp", "dport", 10012), {"drop": None},
            ]}},
        ]
        replies = [SimpleNamespace(stdout=json.dumps({"nftables": rows})) for rows in (tables, manual, managed)]
        with mock.patch.object(host.shutil, "which", return_value="/usr/bin/nft"), mock.patch.object(host, "run", side_effect=replies) as calls:
            self.assertEqual(host.observe(targets), {
                "11": {"manual": "partial", "blocked": []},
                "12": {"manual": "clear", "blocked": ["irancell"]},
            })
            self.assertEqual(calls.call_count, 3)
            self.assertTrue(all(call.args[0][1:3] == ["-j", "list"] for call in calls.call_args_list))

    def test_atomic_apply_failure_keeps_persistent_rules(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            profile, unit = root / "rules.nft", root / "test.service"
            previous = host.OWNER + host.RESET
            profile.write_text(previous)
            unit.write_text(host.OWNER + "existing")
            calls = []

            def run(args, *, check=True, source=None):
                calls.append((args, source))
                if args[-1] in {"mci_block", host.TABLE}:
                    return subprocess.CompletedProcess(args, 1, "", "")
                if args[0] == "ip":
                    return subprocess.CompletedProcess(args, 0, '[{"addr_info":[{"local":"192.0.2.14"},{"local":"2001:db8::14"}]}]', "")
                if args == ["/usr/sbin/nft", "-f", "-"]:
                    raise RuntimeError("apply failed")
                return subprocess.CompletedProcess(args, 0, "", "")

            with mock.patch.multiple(host, ROOT=root, PROFILE=profile, UNIT=unit, run=run), \
                    mock.patch.object(host.shutil, "which", return_value="/usr/sbin/nft"), \
                    mock.patch.object(host.Path, "is_dir", return_value=True):
                with self.assertRaisesRegex(RuntimeError, "apply failed"):
                    host.apply(self.payload)
            self.assertEqual(profile.read_text(), previous)
            self.assertEqual(unit.read_text(), host.OWNER + "existing")
            nft = [(args, source) for args, source in calls if source]
            self.assertEqual([args[1:] for args, _ in nft], [["-c", "-f", "-"], ["-f", "-"]])
            self.assertEqual(nft[0][1], nft[1][1])


if __name__ == "__main__":
    unittest.main()
