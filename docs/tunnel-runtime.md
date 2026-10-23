# Tunnel runtime

BackPack is based on the official v1.8.5 release at commit
`ff86354bcafbd054e7888e48343145323c9b2207`. This is a modified integration
build, with isolated paths and a JSON bridge, rather than the unmodified
upstream executable. `scripts/backpack/build.py` builds both architectures;
`scripts/verify-backpack.py` verifies the source archive and executable hashes.
Upstream upgrades must select an official release and rebuild verified bundles.

## Tests and active tunnels

Tests run from `/opt/speedbox-backpack/tests/<sha256>/backpack`. Preparing test
binaries on both hosts precedes the 30-second coordinator handshake. A peer
failure is observed on the next queue continuation. Both test services must
stop before trying the other orientation. Measurement has its own time budget.

The v1.8.5 measurement sends and verifies 1 MiB in a round trip. The reported
speed counts both legs and is displayed once; it is not a directional download
or upload result. Historical directional results retain their original labels.

Existing active panel-managed engines are updated with the card's engine
maintenance action, including the connected host group and rollback. A host
which only performed tests and has no managed tunnel configs can replace its
unused engine after saving the previous executable. Standalone BackPack files
and `/usr/local/bin/backpack` are separate.

Swapped Direct/L3 configurations put forwarding ports on physical Iran while
changing only dial/listen placement. Swapped reverse carriers additionally use
a private, loopback-only UDP forward carrying an upstream L3 UDP tunnel. This
keeps user ingress in Iran for every supported reverse carrier; it does not
reinterpret a foreign listening port as an Iran entrance. Both configurations
are snapshotted, started, stopped and deleted together. Carrier test speeds
describe the tested carrier; the additional forwarding layer can affect the
final tunnel's throughput. End-to-end TCP/UDP routing is covered by
`scripts/tests/tunnel-swapped-routing.test.py` in isolated network namespaces.

GRE recovery keeps the ownership guard. Older alias-less probes are removed
only when their saved config, endpoints, GRE keys, address and sole host route
all match. Unknown interfaces remain untouched.

## Live measurements and direct SSH

BackPack uses a 30-second coordinator handshake and 30-second transport setup
budget (45 seconds for Direct methods). Its full 60-sample stability window and
verified 1 MiB round-trip throughput measurement remain intact. Setup limits do
not promise a 30-second total test. Remote preparation and measurement timing
is retained in the operation result for diagnosis.

All three engines display candidate cards immediately. Intermediate results
remain visible during measurement; construction stays unavailable until testing
and verified cleanup finish. Background refresh failures keep the last results
and retry silently, without toast notifications.

GOST/GRE measurements run in a bounded temporary worker. TCP and UDP publish
separate live echo, latency and throughput snapshots. A protocol with no reply
ends its initial attempts after about six seconds; answering paths retain all
20 samples. Throughput tests stay sequential to avoid competing with each other.
Temporary services and GRE ownership checks remain isolated from active tunnels.

Reading remote test state takes one direct SSH command, with the request on
standard input instead of transferring a temporary file. Recently verified
public host keys may be cached; every SSH/SCP session still requires the exact
pinned key. Commands are never replayed after execution starts.

SSH management uses only the server's configured host and SSH port. Reverse SSH
fallback and its setup controls have been removed. The earlier database column
is retained for migration compatibility, hidden and unused. Previously installed
standalone reverse-channel services are not automatically uninstalled from
remote machines; they no longer provide a management route to this panel.
