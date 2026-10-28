package main

import (
	"bufio"
	"context"
	"crypto/rand"
	"crypto/tls"
	"errors"
	"fmt"
	"io"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/origin"
)

const secret = "1111111111111111111111111111111111111111111111111111111111111111"

func eventually(t *testing.T, fn func() bool) {
	t.Helper()
	until := time.Now().Add(3 * time.Second)
	for time.Now().Before(until) {
		if fn() {
			return
		}
		time.Sleep(5 * time.Millisecond)
	}
	t.Fatal("condition did not become true")
}
func freePort(t *testing.T, host string) string {
	t.Helper()
	l, e := net.Listen("tcp", net.JoinHostPort(host, "0"))
	if e != nil {
		t.Fatal(e)
	}
	a := l.Addr().String()
	l.Close()
	return a
}

type quota struct {
	mu  sync.Mutex
	ips map[string]int
}

func (q *quota) acquire(ip string) bool {
	q.mu.Lock()
	defer q.mu.Unlock()
	if q.ips[ip] == 0 && len(q.ips) >= 1 {
		return false
	}
	q.ips[ip]++
	return true
}
func (q *quota) release(ip string) {
	q.mu.Lock()
	defer q.mu.Unlock()
	q.ips[ip]--
	if q.ips[ip] == 0 {
		delete(q.ips, ip)
	}
}
func (q *quota) counts() (int, int) {
	q.mu.Lock()
	defer q.mu.Unlock()
	refs := 0
	for _, n := range q.ips {
		refs += n
	}
	return len(q.ips), refs
}

type fixture struct {
	stub          *contractBroker
	closeBroker   func()
	r             *origin.Registry
	broker        string
	gate          *quota
	port          int
	tcp           net.Listener
	udp           net.PacketConn
	cancel        context.CancelFunc
	egress        *server
	ingress       *server
	public, relay string
	original      string
}

func makeFixture(t *testing.T, original ...string) *fixture {
	t.Helper()
	f := &fixture{gate: &quota{ips: map[string]int{}}, original: "198.51.100.2"}
	if len(original) > 0 {
		f.original = original[0]
	}
	var e error
	f.broker = filepath.Join(t.TempDir(), "origin", "broker.sock")
	f.r, e = origin.Listen(f.broker)
	if e != nil {
		if !errors.Is(e, syscall.EPERM) || os.Getenv("SPEEDBOX_ORIGIN_REQUIRE_UNIX") == "1" {
			t.Fatal(e)
		}
		t.Log("AF_UNIX prohibited: loopback broker-contract fixture; target-host selftest uses real registry")
		f.stub = newContractBroker(t)
		f.closeBroker = f.stub.close
	} else {
		f.closeBroker = func() { f.r.Close() }
	}
	f.tcp, e = net.Listen("tcp4", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	f.port = f.tcp.Addr().(*net.TCPAddr).Port
	f.udp, e = net.ListenPacket("udp4", fmt.Sprintf("127.0.0.1:%d", f.port))
	if e != nil {
		t.Fatal(e)
	}
	policy := origin.Policy{Required: true, Port: uint16(f.port), Registry: f.r}
	resolve := func(src netip.AddrPort) (string, error) {
		if f.stub != nil {
			return f.stub.lookup(src.Port(), uint16(f.port))
		}
		return policy.Resolve(src.Addr(), src.Port())
	}
	go func() {
		for {
			c, e := f.tcp.Accept()
			if e != nil {
				return
			}
			go func() {
				defer c.Close()
				src, _ := addr(c.RemoteAddr())
				ip, e := resolve(src)
				if e != nil {
					return
				}
				if !f.gate.acquire(ip) {
					io.WriteString(c, "DENY\n")
					return
				}
				defer f.gate.release(ip)
				io.WriteString(c, "OK "+ip+"\n")
				io.Copy(c, c)
			}()
		}
	}()
	go func() {
		b := make([]byte, 65536)
		for {
			n, a, e := f.udp.ReadFrom(b)
			if e != nil {
				return
			}
			src, _ := addr(a)
			ip, e := resolve(src)
			if e != nil {
				continue
			}
			if f.gate.acquire(ip) {
				f.udp.WriteTo(append([]byte(ip+":"), b[:n]...), a)
				f.gate.release(ip)
			} else {
				f.udp.WriteTo([]byte("DENY"), a)
			}
		}
	}()
	f.relay = freePort(t, "127.0.0.1")
	f.public = freePort(t, "127.0.0.1")
	f.egress, e = newServer(Config{Role: "egress", Secret: secret, Listen: f.relay, Targets: []int{f.port}, TCP: true, UDP: true, Broker: f.broker})
	if e != nil {
		t.Fatal(e)
	}
	f.ingress, e = newServer(Config{Role: "ingress", Secret: secret, Backend: f.relay, Routes: []Route{{f.public, f.port}}, TCP: true, UDP: true})
	if e != nil {
		t.Fatal(e)
	}
	if f.stub != nil {
		f.egress.brokerDial = func() (net.Conn, error) {
			if strings.HasSuffix(f.egress.cfg.Broker, "missing.sock") {
				return nil, errors.New("missing broker")
			}
			return f.stub.dial()
		}
	}
	// The runner permits loopback sockets only; model the authenticated public
	// listener's RemoteAddr while exercising real TCP/UDP and the real broker.
	f.ingress.original = func(a net.Addr) (netip.AddrPort, error) {
		p, e := addr(a)
		return netip.AddrPortFrom(netip.MustParseAddr(f.original), p.Port()), e
	}
	ctx, cancel := context.WithCancel(context.Background())
	f.cancel = cancel
	bound := make(chan error, 4)
	go f.egress.tcp(ctx, f.relay, 0, bound)
	go f.egress.udp(ctx, f.relay, 0, bound)
	go f.ingress.tcp(ctx, f.public, f.port, bound)
	go f.ingress.udp(ctx, f.public, f.port, bound)
	for i := 0; i < 4; i++ {
		if e = <-bound; e != nil {
			t.Fatal(e)
		}
	}
	go f.ingress.reap(ctx)
	go f.egress.reap(ctx)
	t.Cleanup(func() {
		cancel()
		f.tcp.Close()
		f.udp.Close()
		f.ingress.mu.Lock()
		for c := range f.ingress.conns {
			c.Close()
		}
		for _, v := range f.ingress.flows {
			v.close()
		}
		f.ingress.mu.Unlock()
		f.egress.mu.Lock()
		for c := range f.egress.conns {
			c.Close()
		}
		for _, v := range f.egress.flows {
			v.close()
		}
		f.egress.mu.Unlock()
		f.closeBroker()
	})
	return f
}
func openTCP(t *testing.T, address string) (net.Conn, *bufio.Reader, string) {
	t.Helper()
	c, e := net.DialTimeout("tcp", address, time.Second)
	if e != nil {
		t.Fatal(e)
	}
	c.SetDeadline(time.Now().Add(3 * time.Second))
	r := bufio.NewReader(c)
	line, e := r.ReadString('\n')
	if e != nil {
		c.Close()
		t.Fatal(e)
	}
	return c, r, line
}
func (f *fixture) authenticated(t *testing.T, ip string, peer *server) (net.Conn, string) {
	t.Helper()
	c, e := net.DialTimeout("tcp", f.relay, time.Second)
	if e != nil {
		t.Fatal(e)
	}
	tc := tls.Client(c, peer.clientTLS)
	tc.SetDeadline(time.Now().Add(3 * time.Second))
	if e = tc.Handshake(); e != nil {
		c.Close()
		t.Fatal(e)
	}
	if e = peer.sendHello(tc, f.port, netip.AddrPortFrom(netip.MustParseAddr(ip), 32123)); e != nil {
		c.Close()
		t.Fatal(e)
	}
	tc.SetDeadline(time.Now().Add(3 * time.Second))
	line, e := bufio.NewReader(tc).ReadString('\n')
	if e != nil {
		c.Close()
		t.Fatal(e)
	}
	return tc, line
}
func TestSameIPAcrossDirectAndTunnelKeepsOneQuotaAndRejectsSecondIP(t *testing.T) {
	f := makeFixture(t)
	direct, e := f.egress.backendTCP(context.Background(), f.port, f.original)
	if e != nil {
		t.Fatal(e)
	}
	direct.SetDeadline(time.Now().Add(3 * time.Second))
	line, e := bufio.NewReader(direct).ReadString('\n')
	if e != nil {
		t.Fatal(e)
	}
	defer direct.Close()
	if line != "OK "+f.original+"\n" {
		t.Fatal(line)
	}
	tunnel, r, line := openTCP(t, f.public)
	defer tunnel.Close()
	if line != "OK "+f.original+"\n" {
		t.Fatalf("tunnel lost real address: %q", line)
	}
	if ips, refs := f.gate.counts(); ips != 1 || refs != 2 {
		t.Fatalf("quota %d IPs / %d refs", ips, refs)
	}
	if _, e := tunnel.Write([]byte("payload\n")); e != nil {
		t.Fatal(e)
	}
	if line, e := r.ReadString('\n'); e != nil || line != "payload\n" {
		t.Fatalf("payload corrupted: %q %v", line, e)
	}
	other, line := f.authenticated(t, "203.0.113.20", f.ingress)
	other.Close()
	if line != "DENY\n" {
		t.Fatalf("second real IP bypassed limit: %q", line)
	}
	tunnel.Close()
	eventually(t, func() bool { ips, refs := f.gate.counts(); return ips == 1 && refs == 1 })
	direct.Close()
	eventually(t, func() bool { ips, refs := f.gate.counts(); return ips == 0 && refs == 0 })
}
func TestTCPRejectsWrongCredentialAndUnregisteredOrigin(t *testing.T) {
	f := makeFixture(t)
	bad, e := newServer(Config{Role: "ingress", Secret: strings.Repeat("2", 64), Backend: f.relay, Routes: []Route{{f.public, f.port}}, TCP: true})
	if e != nil {
		t.Fatal(e)
	}
	raw, e := net.DialTimeout("tcp", f.relay, time.Second)
	if e != nil {
		t.Fatal(e)
	}
	defer raw.Close()
	c := tls.Client(raw, bad.clientTLS)
	c.SetDeadline(time.Now().Add(time.Second))
	if e = c.Handshake(); e == nil {
		t.Fatal("wrong tunnel secret was accepted")
	}
	if ips, _ := f.gate.counts(); ips != 0 {
		t.Fatal("unauthenticated traffic reached backend")
	}
	f.egress.cfg.Broker = filepath.Join(t.TempDir(), "missing.sock")
	raw, e = net.DialTimeout("tcp", f.relay, time.Second)
	if e != nil {
		t.Fatal(e)
	}
	defer raw.Close()
	c = tls.Client(raw, f.ingress.clientTLS)
	if e = c.Handshake(); e != nil {
		t.Fatal(e)
	}
	if e = f.ingress.sendHello(c, f.port, netip.MustParseAddrPort("203.0.113.5:12345")); e != nil {
		t.Fatal(e)
	}
	c.SetDeadline(time.Now().Add(time.Second))
	var reply [1]byte
	if n, e := c.Read(reply[:]); n != 0 || e == nil {
		t.Fatal("missing broker failed open")
	}
}
func TestTCPHalfCloseAndBrokerRevocation(t *testing.T) {
	f := makeFixture(t)
	c, r, line := openTCP(t, f.public)
	defer c.Close()
	if !strings.HasPrefix(line, "OK ") {
		t.Fatal(line)
	}
	c.Write([]byte("before-fin\n"))
	if line, e := r.ReadString('\n'); e != nil || line != "before-fin\n" {
		t.Fatal(line, e)
	}
	if e := c.(*net.TCPConn).CloseWrite(); e != nil {
		t.Fatal(e)
	}
	if _, e := r.ReadByte(); e != io.EOF {
		t.Fatalf("FIN did not propagate: %v", e)
	}
	eventually(t, func() bool { ips, _ := f.gate.counts(); return ips == 0 })
	c, r, _ = openTCP(t, f.public)
	defer c.Close()
	f.closeBroker()
	if _, e := r.ReadByte(); e == nil {
		t.Fatal("broker loss kept connection alive")
	}
	eventually(t, func() bool { ips, _ := f.gate.counts(); return ips == 0 })
}
func TestUDPRealOriginBidirectionalPayloadAndReplayProtection(t *testing.T) {
	f := makeFixture(t)
	c, e := net.Dial("udp", f.public)
	if e != nil {
		t.Fatal(e)
	}
	defer c.Close()
	c.SetDeadline(time.Now().Add(3 * time.Second))
	payload := []byte("udp-data")
	c.Write(payload)
	b := make([]byte, 4096)
	n, e := c.Read(b)
	if e != nil {
		t.Fatal(e)
	}
	if string(b[:n]) != f.original+":"+string(payload) {
		t.Fatalf("UDP origin/data lost: %q", b[:n])
	}
	var id [16]byte
	rand.Read(id[:])
	d := datagram{kind: 0, id: id, seq: 1, target: f.port, source: netip.MustParseAddrPort("203.0.113.8:32123"), data: payload}
	wire := f.ingress.packet(d)
	if _, e = f.egress.parse(wire); e != nil {
		t.Fatal(e)
	}
	wire[udpHeader] ^= 1
	if _, e = f.egress.parse(wire); e == nil {
		t.Fatal("UDP payload tampering accepted")
	}
	var w window
	for _, v := range []uint64{1, 3, 2, 65, 64} {
		if !w.accept(v) {
			t.Fatalf("fresh sequence %d refused", v)
		}
	}
	for _, v := range []uint64{0, 2, 3, 65, 64} {
		if w.accept(v) {
			t.Fatalf("replay %d accepted", v)
		}
	}
}
func TestUDPSecondIPIsDeniedWhileDirectNodeHoldsOneSlot(t *testing.T) {
	f := makeFixture(t)
	c, e := f.egress.backendTCP(context.Background(), f.port, f.original)
	if e != nil {
		t.Fatal(e)
	}
	c.SetDeadline(time.Now().Add(3 * time.Second))
	if _, e = bufio.NewReader(c).ReadString('\n'); e != nil {
		t.Fatal(e)
	}
	defer c.Close()
	udp, e := net.Dial("udp", f.relay)
	if e != nil {
		t.Fatal(e)
	}
	defer udp.Close()
	udp.SetDeadline(time.Now().Add(3 * time.Second))
	var id [16]byte
	rand.Read(id[:])
	d := datagram{kind: 0, id: id, seq: 1, target: f.port, source: netip.MustParseAddrPort("203.0.113.8:32123"), data: []byte("probe")}
	udp.Write(f.ingress.packet(d))
	b := make([]byte, 4096)
	n, e := udp.Read(b)
	if e != nil {
		t.Fatal(e)
	}
	reply, e := f.ingress.parse(b[:n])
	if e != nil || string(reply.data) != "DENY" {
		t.Fatalf("second UDP IP accepted: %#v %v", reply, e)
	}
}

func TestIPv6OriginalIsCanonicalAcrossTCPAndUDP(t *testing.T) {
	f := makeFixture(t, "2001:db8::1234")
	c, _, line := openTCP(t, f.public)
	defer c.Close()
	if line != "OK "+f.original+"\n" {
		t.Fatalf("IPv6 identity lost: %q", line)
	}
	u, e := net.Dial("udp", f.public)
	if e != nil {
		t.Fatal(e)
	}
	defer u.Close()
	u.SetDeadline(time.Now().Add(3 * time.Second))
	u.Write([]byte("ipv6"))
	b := make([]byte, 4096)
	n, e := u.Read(b)
	if e != nil {
		t.Fatal(e)
	}
	if string(b[:n]) != f.original+":ipv6" {
		t.Fatalf("IPv6 UDP identity lost: %q", b[:n])
	}
}

func TestRetiredUDPFlowCannotReopenAnOldIdentity(t *testing.T) {
	f := makeFixture(t)
	u, e := net.Dial("udp", f.relay)
	if e != nil {
		t.Fatal(e)
	}
	defer u.Close()
	u.SetDeadline(time.Now().Add(3 * time.Second))
	var id [16]byte
	rand.Read(id[:])
	d := datagram{kind: 0, id: id, seq: 1, target: f.port, source: netip.MustParseAddrPort("203.0.113.9:33333"), data: []byte("first")}
	wire := f.ingress.packet(d)
	u.Write(wire)
	b := make([]byte, 4096)
	n, e := u.Read(b)
	if e != nil {
		t.Fatal(e)
	}
	got, e := f.ingress.parse(b[:n])
	if e != nil || got.kind != 1 {
		t.Fatalf("first flow failed: %#v %v", got, e)
	}
	f.egress.mu.Lock()
	flow := f.egress.flows[id]
	f.egress.mu.Unlock()
	if flow == nil {
		t.Fatal("flow missing")
	}
	f.egress.retire(flow)
	u.Write(wire)
	n, e = u.Read(b)
	if e != nil {
		t.Fatal(e)
	}
	got, e = f.ingress.parse(b[:n])
	if e != nil || got.kind != 2 {
		t.Fatalf("retired flow was not reset: %#v %v", got, e)
	}
	f.egress.mu.Lock()
	_, exists := f.egress.flows[id]
	f.egress.mu.Unlock()
	if exists {
		t.Fatal("retired nonce resurrected backend")
	}
}

func TestBootReadinessCreatesPrivateRuntimeDirectoryAndCleansUp(t *testing.T) {
	ready := filepath.Join(t.TempDir(), "fresh-run", "endpoint.ready")
	s, e := newServer(Config{Role: "egress", Secret: secret, Listen: "127.0.0.1:0", Targets: []int{12345}, TCP: true, ReadyFile: ready})
	if e != nil {
		t.Fatal(e)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- s.run(ctx) }()
	eventually(t, func() bool { _, e := os.Stat(ready); return e == nil })
	st, e := os.Stat(filepath.Dir(ready))
	if e != nil || st.Mode().Perm() != 0700 {
		t.Fatalf("runtime directory permissions: %v %v", st, e)
	}
	cancel()
	if e := <-done; e != nil {
		t.Fatal(e)
	}
	if _, e := os.Stat(ready); !os.IsNotExist(e) {
		t.Fatal("stale readiness survived shutdown")
	}
	if e := os.Chmod(filepath.Dir(ready), 0755); e != nil {
		t.Fatal(e)
	}
	if e := s.run(context.Background()); e == nil || !strings.Contains(e.Error(), "not private") {
		t.Fatalf("unsafe runtime directory accepted: %v", e)
	}
}

func TestConfiguredCarrierProbeRequiresAuthenticatedPeerAndBroker(t *testing.T) {
	f := makeFixture(t)
	if e := f.ingress.probe(context.Background()); e != nil {
		t.Fatal(e)
	}
	eventually(t, func() bool { n, refs := f.gate.counts(); return n == 0 && refs == 0 })
	f.egress.mu.Lock()
	flows := len(f.egress.flows)
	f.egress.mu.Unlock()
	if flows != 0 {
		t.Fatal("management probe created a user UDP flow")
	}
	f.tcp.Close()
	f.udp.Close()
	if e := f.ingress.probe(context.Background()); e != nil {
		t.Fatalf("disabled mapped node blocked carrier-only probe: %v", e)
	}
	cfg := f.ingress.cfg
	cfg.Secret = strings.Repeat("2", 64)
	wrong, e := newServer(cfg)
	if e != nil {
		t.Fatal(e)
	}
	if e := wrong.probe(context.Background()); e == nil {
		t.Fatal("probe accepted another pair credential")
	}
	f.egress.cfg.Broker = filepath.Join(t.TempDir(), "missing.sock")
	if e := f.ingress.probe(context.Background()); e == nil {
		t.Fatal("probe accepted unavailable origin broker")
	}
	cfg = f.ingress.cfg
	cfg.TCP = false
	cfg.Backend = freePort(t, "127.0.0.1")
	blocked, e := newServer(cfg)
	if e != nil {
		t.Fatal(e)
	}
	if e := blocked.probe(context.Background()); e == nil {
		t.Fatal("probe accepted blocked UDP path")
	}
}

func TestHealthEndpointAuthenticatesBothProtocolsWithoutOpeningNodePorts(t *testing.T) {
	relay := freePort(t, "127.0.0.1")
	ready := filepath.Join(t.TempDir(), "run", "health.ready")
	h, e := newServer(Config{Role: "health", Secret: secret, Listen: relay, Targets: []int{1}, TCP: true, UDP: true, ReadyFile: ready})
	if e != nil {
		t.Fatal(e)
	}
	h.brokerDial = func() (net.Conn, error) { panic("health-only endpoint must not register or connect to nodes") }
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- h.run(ctx) }()
	eventually(t, func() bool { _, e := os.Stat(ready); return e == nil })
	cfg := Config{Role: "ingress", Secret: secret, Backend: relay, Routes: []Route{{"127.0.0.1:0", 1}}, TCP: true, UDP: true}
	client, e := newServer(cfg)
	if e != nil {
		t.Fatal(e)
	}
	if e := client.probe(ctx); e != nil {
		t.Fatal(e)
	}
	cfg.Secret = strings.Repeat("2", 64)
	wrong, e := newServer(cfg)
	if e != nil {
		t.Fatal(e)
	}
	if e := wrong.probe(ctx); e == nil {
		t.Fatal("another pair was accepted")
	}
	raw, e := net.Dial("tcp", relay)
	if e != nil {
		t.Fatal(e)
	}
	secure := tls.Client(raw, client.clientTLS)
	secure.SetDeadline(time.Now().Add(time.Second))
	if e := secure.HandshakeContext(ctx); e != nil {
		t.Fatal(e)
	}
	if e := client.sendHello(secure, 1, netip.MustParseAddrPort("198.51.100.2:12345")); e != nil {
		t.Fatal(e)
	}
	secure.SetReadDeadline(time.Now().Add(time.Second))
	var b [1]byte
	if _, e := secure.Read(b[:]); e == nil {
		t.Fatal("health endpoint forwarded application traffic")
	}
	secure.Close()
	cancel()
	if e := <-done; e != nil {
		t.Fatal(e)
	}
}
