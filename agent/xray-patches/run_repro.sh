#!/usr/bin/env bash
set -euo pipefail

AGENT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
GO="${SPEEDBOX_GO:-${GO_BIN:-go}}"
command -v "$GO" >/dev/null || { echo 'Set SPEEDBOX_GO to the installed Go executable.' >&2; exit 1; }
cd "$AGENT_DIR"

GOTOOLCHAIN=local "$GO" version
python3 -B xray-patches/test_prepare.py

LOG="${SPEEDBOX_TEST_LOG:-$AGENT_DIR/../result-ss2022-fix.log}"
FILTER='^(TestAdmissionLiveShadowsocksCipherDisconnect|TestAdmissionLifecycle.*|TestDatagram.*|TestAdmissionLiveWebSocketNodeSwitchAndDisconnect|TestAdmissionLiveXUDPDisconnectReleasesReservation|TestTCPDeadline.*)$'

set +e
nice -n 15 env GOTOOLCHAIN=local GOMAXPROCS=1 GOMEMLIMIT=512MiB SPEEDBOX_GO="$GO" \
  python3 go_patched.py test -p 1 \
    -tags 'with_quic with_utls with_wireguard with_acme with_clash_api' \
    -run "$FILTER" -count=1 -timeout=180s -v ./internal/kernel/xray \
    2>&1 | tee "$LOG"
STATUSES=("${PIPESTATUS[@]}")
TEST_CODE="${STATUSES[0]}"
if [ "${STATUSES[1]}" -ne 0 ]; then
  echo 'Could not write the complete test log.' >&2
  exit "${STATUSES[1]}"
fi
echo "TEST EXIT CODE: $TEST_CODE"
echo "LOG: $LOG"
exit "$TEST_CODE"
