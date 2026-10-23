#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AGENT_DIR="${ROOT_DIR}/agent"
OUTPUT_DIR="${ROOT_DIR}/storage/app/agent"
GO_VERSION="1.26.0"
TOOLS_DIR="${ROOT_DIR}/storage/app/tools"

USE_SYSTEM_GO=0
if command -v go >/dev/null 2>&1; then
  FOUND_GO=$(GOTOOLCHAIN=local go env GOVERSION)
  if [[ "$FOUND_GO" =~ ^go([0-9]+)\.([0-9]+) ]] && (( BASH_REMATCH[1] > 1 || (BASH_REMATCH[1] == 1 && BASH_REMATCH[2] >= 26) )); then
    USE_SYSTEM_GO=1
  fi
fi
if [ "$USE_SYSTEM_GO" -eq 0 ]; then
  TOOL_ARCH="$(uname -m)"
  case "$TOOL_ARCH" in x86_64) TOOL_ARCH=amd64 ;; aarch64) TOOL_ARCH=arm64 ;; *) echo "Unsupported panel build architecture" >&2; exit 1 ;; esac
  mkdir -p "$TOOLS_DIR"
  ARCHIVE="go${GO_VERSION}.linux-${TOOL_ARCH}.tar.gz"
  curl -fLsS --retry 3 "https://go.dev/dl/?mode=json&include=all" -o "$TOOLS_DIR/releases.json"
  CHECKSUM="$(php -r '$data=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); foreach($data as $release) foreach($release["files"] as $file) if($file["filename"]===$argv[2]) {echo $file["sha256"];exit;} exit(1);' "$TOOLS_DIR/releases.json" "$ARCHIVE")"
  curl -fLsS --retry 3 "https://dl.google.com/go/$ARCHIVE" -o "$TOOLS_DIR/$ARCHIVE"
  printf '%s  %s\n' "$CHECKSUM" "$TOOLS_DIR/$ARCHIVE" | sha256sum -c -
  tar -xzf "$TOOLS_DIR/$ARCHIVE" -C "$TOOLS_DIR"
  export PATH="$TOOLS_DIR/go/bin:$PATH"
fi

command -v python3 >/dev/null || { echo "python3 is required to package the release" >&2; exit 1; }
mkdir -p "${OUTPUT_DIR}"
BUILD_DIR=$(mktemp -d "${OUTPUT_DIR}/.build.XXXXXX")
trap 'rm -rf "$BUILD_DIR"' EXIT
cd "${AGENT_DIR}"
for arch in amd64 arm64; do
  CGO_ENABLED=0 GOOS=linux GOARCH="${arch}" go build -p 2 -trimpath -ldflags "-s -w -X main.version=panel-speedbox-http-obfs-uuid-v1-nodes-v4-trusted-origin" -tags "with_quic with_utls with_wireguard with_acme with_clash_api" -o "${BUILD_DIR}/xboard-node-linux-${arch}" ./cmd/xboard-node
  CGO_ENABLED=0 GOOS=linux GOARCH="${arch}" go build -p 2 -trimpath -ldflags "-s -w -X main.version=panel-speedbox-http-obfs-uuid-v1-nodes-v4-trusted-origin" -o "${BUILD_DIR}/xbctl-linux-${arch}" ./cmd/xbctl
done

chmod 0755 "${BUILD_DIR}"/*
for artifact in "${BUILD_DIR}"/*; do mv -f "$artifact" "${OUTPUT_DIR}/"; done
echo "SpeedBox agent binaries are ready in ${OUTPUT_DIR}."

python3 "${ROOT_DIR}/scripts/package-agent.py"

