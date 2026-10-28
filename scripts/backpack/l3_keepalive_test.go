package l3

import (
	"context"
	"net"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// Reproduce the forwarded-UDP backend's idle expiry using real sockets. A
// listener's first packet cannot reopen a mapping: only dialler traffic can.
type speedboxIdleBridge struct {
	edge    *net.UDPConn
	target  *net.UDPAddr
	idle    time.Duration
	mu      sync.Mutex
	backend *net.UDPConn
	closed  bool
	created atomic.Int64
	expired atomic.Int64
}

func speedboxNewIdleBridge(t *testing.T, target net.Addr) *speedboxIdleBridge {
	t.Helper()
	edge, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	b := &speedboxIdleBridge{edge: edge, target: target.(*net.UDPAddr), idle: 300 * time.Millisecond}
	t.Cleanup(func() {
		b.mu.Lock()
		b.closed = true
		if b.backend != nil {
			b.backend.Close()
		}
		b.mu.Unlock()
		edge.Close()
	})
	go func() {
		buf := make([]byte, 65535)
		for {
			n, peer, err := edge.ReadFromUDP(buf)
			if err != nil {
				return
			}
			b.mu.Lock()
			if b.closed {
				b.mu.Unlock()
				return
			}
			if b.backend == nil {
				c, err := net.DialUDP("udp4", nil, b.target)
				if err != nil {
					b.mu.Unlock()
					return
				}
				b.backend = c
				b.created.Add(1)
				go b.replies(c, peer)
			}
			c := b.backend
			b.mu.Unlock()
			_, _ = c.Write(buf[:n])
		}
	}()
	return b
}

func (b *speedboxIdleBridge) replies(c *net.UDPConn, peer *net.UDPAddr) {
	buf := make([]byte, 65535)
	defer c.Close()
	for {
		_ = c.SetReadDeadline(time.Now().Add(b.idle))
		n, err := c.Read(buf)
		if err != nil {
			b.mu.Lock()
			if b.backend == c {
				b.backend = nil
			}
			b.mu.Unlock()
			if e, ok := err.(net.Error); ok && e.Timeout() {
				b.expired.Add(1)
			}
			return
		}
		_, _ = b.edge.WriteToUDP(buf[:n], peer)
	}
}

func speedboxPairAcrossIdleBridge(t *testing.T) (*pair, *speedboxIdleBridge) {
	t.Helper()
	p := &pair{dialDev: newFakeDevice(1400), listenDev: newFakeDevice(1400)}
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	var err error
	p.listener, err = New(Config{Mode: ModeListen, Addr: "127.0.0.1:0", Token: "idle-bridge-test", Encap: "gre", LocalIP: "10.10.0.2/30", PeerIP: "10.10.0.1", MTU: 1400}, quietLogger())
	if err != nil {
		t.Fatal(err)
	}
	p.listener.openDevice = func(deviceSpec) (packetDevice, error) { return p.listenDev, nil }
	start(t, ctx, cancel, p.listener, p.listenDev)
	b := speedboxNewIdleBridge(t, awaitBind(t, p.listener))
	p.dialer, err = New(Config{Mode: ModeDial, Addr: b.edge.LocalAddr().String(), Token: "idle-bridge-test", Encap: "gre", LocalIP: "10.10.0.1/30", PeerIP: "10.10.0.2", MTU: 1400}, quietLogger())
	if err != nil {
		t.Fatal(err)
	}
	p.dialer.openDevice = func(deviceSpec) (packetDevice, error) { return p.dialDev, nil }
	start(t, ctx, cancel, p.dialer, p.dialDev)
	awaitSession(t, p.dialer, 5*time.Second)
	return p, b
}

func TestSpeedboxInternalCarrierSurvivesIdleAndRecovers(t *testing.T) {
	oldCheck, oldKeepalive := rekeyCheck, speedboxInternalKeepalive
	rekeyCheck, speedboxInternalKeepalive = 10*time.Millisecond, 50*time.Millisecond
	t.Cleanup(func() { rekeyCheck, speedboxInternalKeepalive = oldCheck, oldKeepalive })
	p, b := speedboxPairAcrossIdleBridge(t)
	packet := ipv4Packet(1, 2, 3, 4)
	across(t, p.dialDev, p.listenDev, packet)
	across(t, p.listenDev, p.dialDev, packet)
	// Four mapping lifetimes without user traffic, well before any routine
	// two-minute rekey. The same backend must still receive listener-first data.
	time.Sleep(1200 * time.Millisecond)
	if n := b.expired.Load(); n != 0 {
		t.Fatalf("idle bridge expired %d times", n)
	}
	if n := b.created.Load(); n != 1 {
		t.Fatalf("bridge recreated %d times", n)
	}
	across(t, p.listenDev, p.dialDev, packet)
	// Simulate a lost carrier mapping. Authenticated keepalives must reopen it
	// and update the listener's peer without any user packet from the dialler.
	b.mu.Lock()
	if b.backend != nil {
		b.backend.Close()
		b.backend = nil
	}
	b.mu.Unlock()
	deadline := time.Now().Add(time.Second)
	for b.created.Load() < 2 && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	if b.created.Load() < 2 {
		t.Fatal("mapping did not recover without user traffic")
	}
	time.Sleep(100 * time.Millisecond)
	across(t, p.listenDev, p.dialDev, packet)
}

func TestSpeedboxKeepaliveIsLimitedToLoopbackUDP(t *testing.T) {
	for _, tc := range []struct {
		carrier, addr string
		want          bool
	}{
		{CarrierUDP, "127.0.0.1:46992", true},
		{CarrierUDP, "[::1]:46992", true},
		{CarrierUDP, "198.51.100.1:46992", false},
		{CarrierUDP, "localhost:46992", false},
		{CarrierPck, "127.0.0.1:46992", false},
	} {
		tun := &Tunnel{cfg: Config{Carrier: tc.carrier, Addr: tc.addr}}
		if got := tun.speedboxInternalCarrier(); got != tc.want {
			t.Fatalf("%s %s: got %v", tc.carrier, tc.addr, got)
		}
	}
}
