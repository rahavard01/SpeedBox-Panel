# Tunnel runtime and reverse SSH

BackPack is based on the official v1.8.5 release at commit
`ff86354bcafbd054e7888e48343145323c9b2207`. This is a modified integration
build, with isolated paths and a JSON bridge, rather than the unmodified
upstream executable. `scripts/backpack/build.py` builds both architectures;
`scripts/verify-backpack.py` verifies the source archive and executable hashes.
Upstream upgrades must select an official release and rebuild verified bundles.

## Tests and active tunnels

Tests run from `/opt/speedbox-backpack/tests/<sha256>/backpack`. Preparing test
binaries on both hosts precedes the 60-second coordinator handshake. A peer
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

## Optional reverse SSH management route

This route is independent from tunnel test orientation. A persistent foreign
to panel SSH channel must be provisioned once. It then supports commands and
SCP with the existing managed server credentials and its original SSH host key.
It does not change the server's physical host, panel callback URL or node host.

Replace the panel address below and run as root on the **panel server**:

```bash
cd /path/to/panel
python3 scripts/reverse-ssh.py --panel-host 192.0.2.10 --reverse-port 45221 --target-ssh-port 22
```

If the temporary SSH test is still occupying port 45221, stop that foreground
test first, or choose another unused port. The helper creates a dedicated
restricted account and a root-only file:
`/var/lib/speedbox-reverse-ssh/45221/foreign-setup.sh`.
Copy that file's contents to the intended **foreign server** and execute it
there as root. It contains the channel's dedicated private key; treat it as a
credential. The generated systemd service reconnects automatically. Use a
different local port for each managed server.

In the managed server's edit form, set the local reverse SSH port and the
**foreign** server's verified SHA256 host fingerprint. Obtain that fingerprint
on the foreign server itself with:

```bash
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256
```

The channel account allows only remote forwarding to its assigned loopback
port, has no shell/session access, and cannot authenticate with a password.
Only its own SSH drop-in is written; unrelated accounts remain configured as
before. `sshd -t` must pass before reloading SSH. Administrator AllowUsers or
AllowGroups restrictions may need to permit the generated `sbrs<port>` user.

Before any mutating command or upload, the executor chooses the direct route
using a harmless SSH command. On a network connection failure it can choose
the loopback route. Authentication failures and host-key mismatches never
trigger fallback. After execution starts, a failed command is not replayed.
The destination's exact host key is pinned on either route.

No root panel password is put into the generated service. Removing a server
record does not uninstall a separately provisioned channel: stop its generated
systemd unit on the foreign host and remove its dedicated panel account/drop-in
when retiring the channel.
