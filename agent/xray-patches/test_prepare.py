#!/usr/bin/env python3
"""Offline integrity tests for the private dependency preparation step."""
import difflib
from contextlib import redirect_stdout
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("patched_go", Path(__file__).resolve().parents[1] / "go_patched.py")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class Prepare(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="ss2022-prepare-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / "module-cache"
        self.source.mkdir()
        self.name = "proxy/inbound.go"
        p = self.source / self.name
        p.parent.mkdir()
        self.old = "package fixture\n\nfunc transport() string { return \"old\" }\n"
        self.new = self.old.replace('"old"', '"fixed"')
        p.write_text(self.old)
        (self.source / "unchanged.go").write_text("package fixture\n")
        self.patch_file = self.root / "fix.patch"
        self.patch_file.write_text("".join(difflib.unified_diff(
            self.old.splitlines(True), self.new.splitlines(True),
            fromfile="a/" + self.name, tofile="b/" + self.name,
        )))
        self.manifest = {
            "original_files": {self.name: runner.digest(self.old.encode())},
            "patched_files": {self.name: runner.digest(self.new.encode())},
        }
        self.cache = self.root / "generated" / "xray"

    def prepare(self):
        return runner.prepare_module(self.source, self.cache, self.manifest, self.patch_file)

    def test_private_copy_is_patched_and_module_cache_stays_unchanged(self):
        before = runner.fingerprint(self.source)
        self.prepare()
        self.assertEqual((self.cache / self.name).read_text(), self.new)
        self.assertEqual(runner.fingerprint(self.source), before)
        self.assertFalse((self.cache / ".git").exists())

    def test_same_copy_is_reused_without_rewriting_it(self):
        self.prepare()
        stamp = (self.cache / self.name).stat().st_mtime_ns
        self.prepare()
        self.assertEqual((self.cache / self.name).stat().st_mtime_ns, stamp)

    def test_unreviewed_source_is_rejected(self):
        (self.source / self.name).write_text(self.old + "// changed\n")
        with self.assertRaisesRegex(RuntimeError, "does not match reviewed"):
            self.prepare()
        self.assertFalse(self.cache.exists())

    def test_changes_to_an_unpatched_cached_file_are_rejected(self):
        self.prepare()
        (self.cache / "unchanged.go").write_text("package altered\n")
        with self.assertRaisesRegex(RuntimeError, "cache changed"):
            self.prepare()

    def test_unexpected_patch_output_cannot_be_promoted(self):
        self.manifest["patched_files"][self.name] = "0" * 64
        with self.assertRaisesRegex(RuntimeError, "verification failed"):
            self.prepare()
        self.assertFalse(self.cache.exists())

    def test_go_uses_alternate_module_and_preserves_original_module_files(self):
        agent = self.root / "project" / "agent"
        patch_dir = agent / "xray-patches"
        patch_dir.mkdir(parents=True)
        manifest = dict(self.manifest, module="example.invalid/xray", version="v1.0.0")
        (patch_dir / "manifest.json").write_text(json.dumps(manifest))
        (patch_dir / "ss2022-dispatchlink.patch").write_bytes(self.patch_file.read_bytes())
        original_mod = "module fixture\n\ngo 1.26\n\nreplace github.com/xtls/xray-core => example.invalid/xray v1.0.0\n"
        (agent / "go.mod").write_text(original_mod)
        (agent / "go.sum").write_text("fixture-checksum\n")
        fake_go = self.root / "go-fixture"
        fake_go.write_text("#!" + sys.executable + "\n" + r'''
import json, os, re, sys
from pathlib import Path
a = sys.argv[1:]
if a[:2] == ['mod', 'download']:
    p = Path(next(x.split('=', 1)[1] for x in a if x.startswith('-modfile=')))
    p.with_suffix('.sum').write_text(p.with_suffix('.sum').read_text() + 'download-added-sum\n')
    print(json.dumps({'Dir': os.environ['FIXTURE_SOURCE']}))
elif a[:2] == ['mod', 'edit']:
    p = Path(next(x.split('=', 1)[1] for x in a if x.startswith('-modfile=')))
    replacement = next(x.split('=', 1)[1] for x in a if x.startswith('-replace='))
    old, new = replacement.split('=', 1)
    p.write_text(re.sub(r'(?m)^replace .+$', 'replace ' + old + ' => ' + new, p.read_text()))
elif a[0] == 'test':
    assert '-mod=readonly' in a
    assert os.environ['GOWORK'] == 'off'
    p = Path(next(x.split('=', 1)[1] for x in a if x.startswith('-modfile=')))
    text = p.read_text()
    destination = re.search(r'(?m)^replace .+ => (.+)$', text).group(1)
    assert not Path(destination).is_absolute()
    copied = Path.cwd() / destination
    assert '"fixed"' in (copied / 'proxy/inbound.go').read_text()
    assert p.with_suffix('.sum').read_text() == 'fixture-checksum\ndownload-added-sum\n'
    (Path.cwd().parent / 'go-test-used-fixed-source').write_text('ok')
else:
    raise SystemExit('unexpected Go operation: ' + repr(a))
''')
        fake_go.chmod(0o755)
        with patch.object(runner, "AGENT", agent), patch.object(runner, "PATCH_DIR", patch_dir), \
             patch.dict(os.environ, SPEEDBOX_GO=str(fake_go), FIXTURE_SOURCE=str(self.source)), \
             patch.object(sys, "argv", ["go_patched.py", "test", "./internal/kernel/xray"]), \
             redirect_stdout(io.StringIO()):
            self.assertEqual(runner.main(), 0)
        self.assertTrue((agent.parent / "go-test-used-fixed-source").exists())
        self.assertEqual((agent / "go.mod").read_text(), original_mod)
        self.assertEqual((agent / "go.sum").read_text(), "fixture-checksum\n")
        self.assertFalse(list((agent.parent / ".speedbox-build").glob("module-*")))


if __name__ == "__main__":
    unittest.main(verbosity=2)
