#!/usr/bin/env python3
"""Offline regressions for the release runner's verified Go download fallback."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import urllib.error
import http.client

spec = importlib.util.spec_from_file_location('release_runtime', Path(__file__).with_name('agent-release-runtime.test.py'))
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
PRIMARY, REGIONAL = runner.OFFICIAL_DOWNLOADS
FILENAME = f'go{runner.VERSION}.linux-amd64.tar.gz'


def archive(extra=None):
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode='w:gz') as source:
        for name in ['go/bin/go', 'go/bin/gofmt', 'go/pkg/tool/linux_amd64/compile']:
            data = b'fixture executable'; entry = tarfile.TarInfo(name)
            entry.mode = 0o755; entry.size = len(data)
            source.addfile(entry, io.BytesIO(data))
        if extra is not None:
            source.addfile(extra, io.BytesIO(b'') if extra.isfile() else None)
    return output.getvalue()


class Bootstrap(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='bootstrap-fixture-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.payload = archive()
        self.calls = []
        self.artifact = {'filename': FILENAME, 'size': len(self.payload), 'sha256': hashlib.sha256(self.payload).hexdigest()}

    def response(self, url):
        if '?mode=' in url:
            return io.BytesIO(json.dumps([{'version': 'go' + runner.VERSION, 'files': [self.artifact]}]).encode())
        return io.BytesIO(self.payload)

    def run_download(self, behavior=None):
        def request(url, **kwargs):
            self.calls.append(url)
            return behavior(url) if behavior else self.response(url)
        with patch.object(runner.urllib.request, 'urlopen', side_effect=request):
            return runner.prepare_go(self.root, 'amd64')

    @staticmethod
    def not_found(url):
        raise urllib.error.HTTPError(url, 404, 'Not Found', {}, None)

    def test_primary_success_needs_no_fallback(self):
        executable = self.run_download()
        self.assertEqual(Path(executable).read_bytes(), b'fixture executable')
        self.assertTrue(Path(executable).stat().st_mode & 0o100)
        self.assertFalse(any(url.startswith(REGIONAL) for url in self.calls))

    def test_primary_404_uses_verified_regional_archive(self):
        self.run_download(lambda url: self.not_found(url) if url == PRIMARY + FILENAME else self.response(url))
        self.assertIn(REGIONAL + FILENAME, self.calls)
        self.assertTrue((self.root / 'go/bin/go').is_file())

    def test_metadata_also_has_an_official_fallback(self):
        self.run_download(lambda url: self.not_found(url) if url.startswith(PRIMARY) else self.response(url))
        self.assertIn(REGIONAL + '?mode=json&include=all', self.calls)
        self.assertIn(REGIONAL + FILENAME, self.calls)

    def test_interrupted_transfer_uses_fallback(self):
        def request(url):
            if url == PRIMARY + FILENAME:
                raise http.client.IncompleteRead(b'partial transfer')
            return self.response(url)
        self.run_download(request)
        self.assertIn(REGIONAL + FILENAME, self.calls)

    def test_corrupt_primary_stops_without_fallback(self):
        def request(url):
            return self.response(url) if '?mode=' in url else io.BytesIO(b'corrupt')
        with self.assertRaisesRegex(RuntimeError, 'verification failed'):
            self.run_download(request)
        self.assertFalse(any(url.startswith(REGIONAL) for url in self.calls))
        self.assertFalse((self.root / 'go').exists())

    def test_corrupt_fallback_cannot_be_extracted(self):
        def request(url):
            if url == PRIMARY + FILENAME:
                return self.not_found(url)
            return self.response(url) if '?mode=' in url else io.BytesIO(b'corrupt')
        with self.assertRaisesRegex(RuntimeError, 'verification failed'):
            self.run_download(request)
        self.assertFalse((self.root / FILENAME).exists())
        self.assertFalse((self.root / 'go').exists())

    def test_archive_length_is_checked(self):
        self.artifact['size'] += 1
        with self.assertRaisesRegex(RuntimeError, 'verification failed'):
            self.run_download()
        self.assertFalse((self.root / 'go').exists())

    def test_both_download_failures_are_reported(self):
        def request(url):
            return self.response(url) if '?mode=' in url else self.not_found(url)
        with self.assertRaisesRegex(RuntimeError, 'official endpoints') as failure:
            self.run_download(request)
        self.assertIn(PRIMARY, str(failure.exception))
        self.assertIn(REGIONAL, str(failure.exception))

    def test_no_metadata_stops_before_archive_download(self):
        with self.assertRaisesRegex(RuntimeError, 'metadata'):
            self.run_download(self.not_found)
        self.assertEqual(len(self.calls), 2)
        self.assertTrue(all('?mode=' in url for url in self.calls))

    def test_traversal_and_symlinks_are_rejected(self):
        for symlink in [False, True]:
            with self.subTest(symlink=symlink):
                entry = tarfile.TarInfo('go/unsafe' if symlink else '../outside')
                if symlink:
                    entry.type = tarfile.SYMTYPE; entry.linkname = '/tmp/outside'
                self.payload = archive(entry)
                self.artifact.update(size=len(self.payload), sha256=hashlib.sha256(self.payload).hexdigest())
                with self.assertRaisesRegex(RuntimeError, 'Unsafe Go archive'):
                    self.run_download()
                self.assertFalse((self.root / 'go').exists())


class InstalledSDK(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='installed-go-fixture-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        (self.root / 'agent').mkdir()
        (self.root / 'agent/go.mod').write_text('module fixture\n\ngo 1.26\n')
        self.env = dict(os.environ, PATH=str(self.root / 'empty-bin'), GOTOOLCHAIN='auto')
        for name in ['GO_BIN', 'GOROOT', 'GOMODCACHE', 'GOPATH']:
            self.env.pop(name, None)

    def sdk(self, path, version):
        executable = self.root / path
        executable.parent.mkdir(parents=True, exist_ok=True)
        # Probe the real subprocess path and confirm automatic toolchain
        # downloads and a stale GOROOT cannot reach the installed SDK.
        executable.write_text('#!/bin/sh\n[ "$GOTOOLCHAIN" = local ] || exit 91\n[ -z "${GOROOT:-}" ] || exit 92\nprintf "%s\\n" "go version go' + version + ' linux/amd64"\n')
        executable.chmod(0o755)
        return executable

    def test_project_sdk_need_not_be_in_path_or_match_download_pin(self):
        executable = self.sdk('storage/app/tools/go/bin/go', '1.26.0')
        go, version, checked = runner.installed_go(self.root, self.env)
        self.assertEqual(go, str(executable)); self.assertIn('go1.26.0', version)
        self.assertEqual(len(checked), 1)

    def test_path_sdk_is_reused_when_project_sdk_is_absent(self):
        executable = self.sdk('path-bin/go', '1.27.0')
        self.env['PATH'] = str(executable.parent)
        self.assertEqual(runner.installed_go(self.root, self.env)[0], str(executable))

    def test_explicit_go_bin_is_preferred(self):
        self.sdk('storage/app/tools/go/bin/go', '1.26.0')
        executable = self.sdk('custom/go', '1.26.1')
        self.env['GO_BIN'] = str(executable)
        self.assertEqual(runner.installed_go(self.root, self.env)[0], str(executable))

    def test_minimum_patch_version_is_respected(self):
        (self.root / 'agent/go.mod').write_text('module fixture\n\ngo 1.26.2\n')
        self.sdk('storage/app/tools/go/bin/go', '1.26.0')
        executable = self.sdk('path-bin/go', '1.26.10')
        self.env['PATH'] = str(executable.parent)
        self.assertEqual(runner.installed_go(self.root, self.env)[0], str(executable))

    def test_too_old_explicit_sdk_is_not_silently_replaced(self):
        executable = self.sdk('custom/go', '1.25.9')
        self.env['GO_BIN'] = str(executable)
        go, _, checked = runner.installed_go(self.root, self.env)
        self.assertIsNone(go); self.assertIn('go1.25.9', checked[0])

    def test_stale_goroot_does_not_mix_sdk_versions(self):
        executable = self.sdk('storage/app/tools/go/bin/go', '1.26.0')
        self.env['GOROOT'] = str(self.root / 'obsolete-sdk')
        self.assertEqual(runner.installed_go(self.root, self.env)[0], str(executable))

    def test_no_sdk_stops_before_network_or_artifact_commands(self):
        with patch.dict(os.environ, self.env, clear=True), patch.object(runner, 'go_candidates', return_value=[]), \
             patch.object(runner, 'prepare_go') as download, patch.object(runner.urllib.request, 'urlopen') as network, \
             patch.object(runner.subprocess, 'run') as command, \
             patch('sys.argv', ['runner', '--root', str(self.root)]):
            with self.assertRaises(SystemExit) as result:
                runner.main()
            self.assertEqual(result.exception.code, 2)
            download.assert_not_called(); network.assert_not_called(); command.assert_not_called()

    def test_check_go_reports_selection_without_running_tests(self):
        executable = self.sdk('storage/app/tools/go/bin/go', '1.26.0')
        with patch.dict(os.environ, self.env, clear=True), patch.object(runner, 'prepare_go') as download, \
             patch.object(runner.urllib.request, 'urlopen') as network, \
             patch('sys.argv', ['runner', '--root', str(self.root), '--check-go']):
            self.assertIsNone(runner.main())
            download.assert_not_called(); network.assert_not_called()
        self.assertTrue(executable.is_file())


if __name__ == '__main__':
    unittest.main(verbosity=2)
