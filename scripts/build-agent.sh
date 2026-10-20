#!/usr/bin/env bash
set -Eeuo pipefail

# Install the release bundled with this source. No Go, curl or network fallback.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
PHP="${PHP_BIN:-php}"
"$PHP" "$ROOT/scripts/agent-artifacts.php" verify "$ROOT"
[[ "${1:-}" != --verify ]] || exit 0
[[ $# = 0 ]] || { echo 'Usage: bash scripts/build-agent.sh [--verify]' >&2; exit 1; }
case "$(uname -m)" in
  x86_64) ARCH=amd64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  *) echo 'Unsupported Linux server architecture.' >&2; exit 1 ;;
esac
OUTPUT="$ROOT/storage/app/agent"
[[ "$(realpath -m "$OUTPUT")" = "$OUTPUT" ]] || { echo 'Agent output must not be a symlink.' >&2; exit 1; }
mkdir -p "$OUTPUT"
STAGE="$(mktemp -d "$OUTPUT/.release-XXXXXXXX")"
trap 'rm -rf -- "$STAGE"' EXIT
for arch in amd64 arm64; do
  tar --no-same-owner --no-same-permissions -xzf "$ROOT/agent-dist/linux-$arch.tar.gz" -C "$STAGE" -- "xboard-node-linux-$arch" "xbctl-linux-$arch"
done
"$PHP" "$ROOT/scripts/agent-artifacts.php" extracted "$ROOT" "$STAGE"
chmod 0755 "$STAGE"/*
"$STAGE/xboard-node-linux-$ARCH" -v | grep -Fq 'panel-speedbox-http-obfs-uuid-v1'
"$STAGE/xbctl-linux-$ARCH" version | grep -Fq 'panel-speedbox-http-obfs-uuid-v1'
for name in xboard-node-linux-amd64 xbctl-linux-amd64 xboard-node-linux-arm64 xbctl-linux-arm64; do
  [[ ! -L "$OUTPUT/$name" && ( ! -e "$OUTPUT/$name" || -f "$OUTPUT/$name" ) ]] || { echo "Unsafe agent output: $name" >&2; exit 1; }
done
for name in xboard-node-linux-amd64 xbctl-linux-amd64 xboard-node-linux-arm64 xbctl-linux-arm64; do
  mv -f -- "$STAGE/$name" "$OUTPUT/$name"
done
echo 'AGENT PREBUILT READY: 4 verified binaries; no Go download or build.'

