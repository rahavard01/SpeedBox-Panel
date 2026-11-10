#!/usr/bin/env python3
"""Run Go with the reviewed SS2022 fix on a private copy of the pinned Xray module.

Usage: SPEEDBOX_GO=/path/to/go python3 go_patched.py test [go test flags]
The module cache, original go.mod/go.sum and active agent are never modified.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile

AGENT = Path(__file__).resolve().parent
PATCH_DIR = AGENT / "xray-patches"
UPSTREAM = "github.com/xtls/xray-core"


def digest(data):
    return hashlib.sha256(data).hexdigest()


def fingerprint(root):
    result = {}
    for path in root.rglob("*"):
        if path.is_symlink():
            raise RuntimeError("Unexpected symlink in Xray source: " + str(path))
        if path.is_file():
            result[path.relative_to(root).as_posix()] = digest(path.read_bytes())
    return result


def prepare_module(source, cache, manifest, patch):
    original = fingerprint(source)
    for name, expected in manifest["original_files"].items():
        if original.get(name) != expected:
            raise RuntimeError("Pinned Xray source does not match reviewed patch: " + name)
    expected = dict(original)
    expected.update(manifest["patched_files"])
    if cache.exists():
        if fingerprint(cache) != expected:
            raise RuntimeError("Patched Xray cache changed; remove only this generated directory: " + str(cache))
        return cache
    cache.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="prepare-", dir=cache.parent) as temporary:
        stage = Path(temporary) / "xray"
        shutil.copytree(source, stage)
        # Go's module sources are read-only. Make only our private copy writable.
        for directory, _, _ in os.walk(stage):
            p = Path(directory)
            p.chmod(p.stat().st_mode | stat.S_IWUSR | stat.S_IXUSR)
        for name in manifest["original_files"]:
            p = stage / name
            p.chmod(p.stat().st_mode | stat.S_IWUSR)
        # A private Git root prevents discovery of the caller's repository.
        subprocess.run(["git", "init", "-q", str(stage)], check=True)
        subprocess.run(["git", "-C", str(stage), "apply", "--unidiff-zero", "--check", str(patch)], check=True)
        subprocess.run(["git", "-C", str(stage), "apply", "--unidiff-zero", str(patch)], check=True)
        shutil.rmtree(stage / ".git")
        if fingerprint(stage) != expected:
            raise RuntimeError("Patched Xray source verification failed")
        try:
            stage.rename(cache)
        except OSError:
            # Another build may have completed the same immutable copy first.
            if not cache.is_dir() or fingerprint(cache) != expected:
                raise
    return cache


def main():
    if len(sys.argv) < 2 or sys.argv[1] not in {"test", "build", "vet"}:
        raise SystemExit("Usage: python3 go_patched.py {test|build|vet} [Go arguments]")
    if any(arg.startswith(("-modfile", "-overlay")) for arg in sys.argv[2:]):
        raise SystemExit("Use the runner's verified module file; overriding it is not supported")
    manifest = json.loads((PATCH_DIR / "manifest.json").read_text())
    patch = PATCH_DIR / "ss2022-dispatchlink.patch"
    module_text = (AGENT / "go.mod").read_text()
    replacement = re.search(r"(?m)^replace github\.com/xtls/xray-core => (\S+) (\S+)[ \t]*$", module_text)
    if not replacement or replacement.groups() != (manifest["module"], manifest["version"]):
        raise RuntimeError("Xray dependency changed; review the patch before building")
    go = os.environ.get("SPEEDBOX_GO") or os.environ.get("GO_BIN") or "go"
    env = dict(os.environ, GOWORK="off")
    cache_root = AGENT.parent / ".speedbox-build"
    cache_root.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="module-", dir=cache_root) as temporary:
        module_file = Path(temporary) / "agent.mod"
        shutil.copyfile(AGENT / "go.mod", module_file)
        shutil.copyfile(AGENT / "go.sum", module_file.with_suffix(".sum"))
        result = subprocess.run(
            [go, "mod", "download", "-modfile=" + str(module_file), "-json", manifest["module"] + "@" + manifest["version"]],
            cwd=AGENT, env=env, text=True, capture_output=True, timeout=180,
        )
        if result.returncode:
            raise RuntimeError(result.stderr.strip() or result.stdout.strip() or "Go module download failed")
        info = json.loads(result.stdout)
        if info.get("Error") or not info.get("Dir"):
            raise RuntimeError("Go did not return the verified Xray module directory")
        cache_key = digest((manifest["module"] + "@" + manifest["version"]).encode() + patch.read_bytes())[:20]
        cache = prepare_module(Path(info["Dir"]), cache_root / ("xray-" + cache_key), manifest, patch)
        # Relative replacements keep build metadata independent of host paths.
        relative = Path(os.path.relpath(cache, AGENT)).as_posix()
        subprocess.run(
            [go, "mod", "edit", "-modfile=" + str(module_file), "-replace=" + UPSTREAM + "=" + relative],
            cwd=AGENT, env=env, check=True,
        )
        print("XRAY SS2022 PATCH READY: " + manifest["version"], flush=True)
        return subprocess.run(
            [go, sys.argv[1], "-modfile=" + str(module_file), "-mod=readonly", *sys.argv[2:]],
            cwd=AGENT, env=env,
        ).returncode


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, RuntimeError, ValueError, subprocess.SubprocessError) as error:
        print("SS2022 BUILD PREPARATION FAILED: " + str(error), file=sys.stderr)
        raise SystemExit(1)
