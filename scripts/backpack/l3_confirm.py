"""Confirm new L3 keys using an authenticated probe before completing a rekey."""
from pathlib import Path


def apply(source: Path):
    path = source / 'internal/tunnel/l3/tunnel_handshake.go'
    text = path.read_text()
    before = '\t\t\tt.installDialed(sess)\n\t\t\treturn nil'
    after = '''\t\t\tt.installDialed(sess)
\t\t\t// The listener keeps its old send keys until an authenticated packet
\t\t\t// confirms the pending session. Without this probe, an idle rekey
\t\t\t// loses listener-first traffic after previousGrace has elapsed.
\t\t\tif !t.peerAnswersProbe(ctx) {
\t\t\t\tt.silentRekey.Store(true)
\t\t\t\treturn errors.New("l3: new session was not confirmed by the peer")
\t\t\t}
\t\t\treturn nil'''
    if text.count(before) != 1:
        raise RuntimeError('Pinned L3 handshake source does not match')
    path.write_text(text.replace(before, after))

    # A swapped reverse carrier forwards L3 UDP over a TCP stream. Its UDP
    # backend expires after 60 seconds without replies, even though L3 is up.
    # Only the dialler can recreate that mapping; probe it before expiry.
    text = path.read_text()
    before = "\tvar failingSince time.Time\n\tfor {"
    after = """\tvar failingSince time.Time
\tlastInternalProbe := time.Now()
\tfor {
\t\tif t.speedboxInternalCarrier() && time.Since(lastInternalProbe) >= speedboxInternalKeepalive {
\t\t\tif t.sendSession() != nil && !t.peerAnswersProbe(ctx) {
\t\t\t\tif ctx.Err() != nil { return }
\t\t\t\tt.silentRekey.Store(true)
\t\t\t\tt.log.Warn("l3: internal carrier did not answer its keepalive; recovering the session")
\t\t\t}
\t\t\tlastInternalProbe = time.Now()
\t\t}
"""
    if text.count(before) != 1:
        raise RuntimeError('Pinned L3 handshake loop does not match')
    path.write_text(text.replace(before, after))

    # Upstream already waits for asynchronous TUN counters in the new snapshot.
