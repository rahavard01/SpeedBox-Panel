//go:build linux

package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"github.com/JohnWatsoon/WatTunnel/wat"
	"io"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"
)

func TestSpeedboxOriginEnvelope(t *testing.T) {
	for _, source := range []string{"198.51.100.7:51999", "[2001:db8::7]:32001"} {
		original := netip.MustParseAddrPort(source)
		b, err := speedboxEncodeOrigin(original)
		if err != nil {
			t.Fatal(err)
		}
		got, err := speedboxDecodeOrigin(b)
		if err != nil || got != original {
			t.Fatal(got, err)
		}
		payload := bytes.Repeat([]byte{0x8f}, 65507)
		packet := append(b, payload...)
		got, err = speedboxDecodeOrigin(packet)
		if err != nil || got != original || !bytes.Equal(packet[speedboxOriginSize:], payload) {
			t.Fatal("UDP payload changed")
		}
	}
	for _, src := range []string{"127.0.0.1:5000", "0.0.0.0:1000", "198.51.100.7:0"} {
		if _, err := speedboxEncodeOrigin(netip.MustParseAddrPort(src)); err == nil {
			t.Fatal("invalid origin", src)
		}
	}
	for n := 0; n < speedboxOriginSize; n++ {
		if _, err := speedboxDecodeOrigin(make([]byte, n)); err == nil {
			t.Fatal("truncated envelope accepted")
		}
	}
}

// A real local socket broker verifies that the origin record is acknowledged
// before TCP Accept / the first UDP packet. Closing it must close the backend.
func TestSpeedboxOriginTCPUDPOrderingAndFailClosed(t *testing.T) {
	path := filepath.Join(t.TempDir(), "origin.sock")
	ln, err := net.Listen("unix", path)
	if err != nil {
		if os.Getenv("SPEEDBOX_TEST_NO_UNIX") == "1" {
			t.Skip("local runtime prohibits Unix sockets; exercised in Linux CI")
		}
		t.Fatal(err)
	}
	defer ln.Close()
	old := *speedboxBrokerFlag
	*speedboxBrokerFlag = path
	defer func() { *speedboxBrokerFlag = old }()
	type entry struct {
		SourcePort int    `json:"source_port"`
		TargetPort int    `json:"target_port"`
		OriginalIP string `json:"original_ip"`
	}
	var mu sync.Mutex
	records := map[int]entry{}
	seenTargets := map[int]bool{}
	control := make(chan net.Conn, 2)
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go func() {
				var e entry
				if json.NewDecoder(bufio.NewReader(c)).Decode(&e) != nil {
					c.Close()
					return
				}
				mu.Lock()
				if !seenTargets[e.TargetPort] {
					seenTargets[e.TargetPort] = true
					mu.Unlock()
					c.Write([]byte("BUSY\n"))
					c.Close()
					return
				}
				records[e.SourcePort] = e
				mu.Unlock()
				c.Write([]byte("OK\n"))
				control <- c
			}()
		}
	}()
	src := netip.MustParseAddrPort("198.51.100.7:52001")
	tcp, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer tcp.Close()
	port := tcp.Addr().(*net.TCPAddr).Port
	client, err := speedboxDialOriginTCP(port, src)
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	backend, err := tcp.Accept()
	if err != nil {
		t.Fatal(err)
	}
	defer backend.Close()
	mu.Lock()
	e, ok := records[backend.RemoteAddr().(*net.TCPAddr).Port]
	mu.Unlock()
	if !ok || e.TargetPort != port || e.OriginalIP != src.Addr().String() {
		t.Fatal("TCP arrived before registration", e)
	}
	client.Write([]byte("payload"))
	var b [7]byte
	io.ReadFull(backend, b[:])
	if string(b[:]) != "payload" {
		t.Fatal("origin metadata leaked into backend")
	}
	(<-control).Close()
	backend.SetReadDeadline(time.Now().Add(time.Second))
	if n, _ := backend.Read(b[:]); n != 0 {
		t.Fatal("TCP remained open without identity broker")
	}
	udp, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer udp.Close()
	port = udp.LocalAddr().(*net.UDPAddr).Port
	uc, err := speedboxDialOriginUDP(port, src)
	if err != nil {
		t.Fatal(err)
	}
	defer speedboxCloseUDP(uc)
	uc.Write([]byte("payload"))
	udp.SetReadDeadline(time.Now().Add(time.Second))
	n, peer, err := udp.ReadFromUDP(b[:])
	if err != nil || string(b[:n]) != "payload" {
		t.Fatal(err)
	}
	mu.Lock()
	e, ok = records[peer.Port]
	mu.Unlock()
	if !ok || e.TargetPort != port || e.OriginalIP != src.Addr().String() {
		t.Fatal("UDP arrived before registration", e)
	}
	(<-control).Close()
	deadline := time.Now().Add(time.Second)
	for {
		if _, err = uc.Write([]byte("closed")); err != nil {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("UDP remained open without identity broker")
		}
		time.Sleep(time.Millisecond)
	}
}

// Reuse the same authenticated datagram flow after its broker connection is
// revoked. Continued client traffic must not keep a dead UDP socket alive.
func TestSpeedboxOriginUDPRecoversAfterBrokerRestart(t *testing.T) {
	path := filepath.Join(t.TempDir(), "origin.sock")
	broker, err := net.Listen("unix", path)
	if err != nil {
		if os.Getenv("SPEEDBOX_TEST_NO_UNIX") == "1" {
			t.Skip("local runtime prohibits Unix sockets; exercised in Linux CI")
		}
		t.Fatal(err)
	}
	defer broker.Close()
	oldPath := *speedboxBrokerFlag
	*speedboxBrokerFlag = path
	controls := make(chan net.Conn, 8)
	go func() {
		for {
			c, e := broker.Accept()
			if e != nil {
				return
			}
			go func() {
				var req map[string]any
				if json.NewDecoder(c).Decode(&req) != nil {
					c.Close()
					return
				}
				c.Write([]byte("OK\n"))
				controls <- c
			}()
		}
	}()
	port := echoUDP(t)
	oldPorts := speedboxOriginPorts
	speedboxOriginPorts = map[int]bool{port: true}
	us, err := wat.NewUDPServer("127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	key := []byte("origin-udp-recovery-test")
	srv := wat.NewServer(wat.Config{Key: key}, us)
	done := make(chan struct{})
	go func() {
		for s := range srv.New {
			go func(s *wat.Session) { serveDgrams(s, 1, 65535, allowUDP(port), time.Minute); close(done) }(s)
		}
	}()
	uc, err := wat.NewUDPClient(us.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	session, err := wat.NewSession(wat.Config{Key: key}, uc)
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		session.Close()
		srv.Close()
		select {
		case <-done:
		case <-time.After(time.Second):
			t.Error("UDP server did not stop")
		}
		speedboxOriginPorts = oldPorts
		*speedboxBrokerFlag = oldPath
		for {
			select {
			case c := <-controls:
				c.Close()
			default:
				return
			}
		}
	}()
	header, _ := speedboxEncodeOrigin(netip.MustParseAddrPort("198.51.100.7:50001"))
	exchange := func(payload string) {
		t.Helper()
		deadline := time.After(3 * time.Second)
		tick := time.NewTicker(15 * time.Millisecond)
		defer tick.Stop()
		for {
			session.SendDatagram(17, port, append(append([]byte(nil), header...), payload...))
			select {
			case d := <-session.Datagrams():
				if string(d.Data) == payload {
					return
				}
			case <-tick.C:
			case <-deadline:
				t.Fatal("UDP flow did not recover while packets kept arriving")
			}
		}
	}
	exchange("before")
	var first net.Conn
	select {
	case first = <-controls:
	case <-time.After(time.Second):
		t.Fatal("origin was not registered")
	}
	first.Close()
	until := time.Now().Add(time.Second)
	for {
		count := 0
		speedboxUDPOrigins.Range(func(_, _ any) bool { count++; return true })
		if count == 0 {
			break
		}
		if time.Now().After(until) {
			t.Fatal("broker revocation did not close UDP")
		}
		time.Sleep(time.Millisecond)
	}
	exchange("after")
}

// Test the published bridge with real TCP FIN and the exact origin wrapper.
func TestSpeedboxOriginBridgeForwardsClientFIN(t *testing.T) {
	for _, wrapped := range []bool{false, true} {
		name := "raw"
		if wrapped {
			name = "origin-wrapped"
		}
		t.Run(name, func(t *testing.T) {
			key := []byte("origin-close-regression")
			carrier, err := wat.NewUDPServer("127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			server := wat.NewServer(wat.Config{Key: key}, carrier)
			defer server.Close()
			uc, err := wat.NewUDPClient(carrier.Addr().String())
			if err != nil {
				t.Fatal(err)
			}
			client, err := wat.NewSession(wat.Config{Key: key}, uc)
			if err != nil {
				t.Fatal(err)
			}
			defer client.Close()
			waitFor(t, "alive", 3*time.Second, client.Alive)
			cs, err := client.OpenStream()
			if err != nil {
				t.Fatal(err)
			}
			timer := time.AfterFunc(3*time.Second, client.Close)
			defer timer.Stop()
			if _, err = cs.Write([]byte("hello")); err != nil {
				t.Fatal(err)
			}
			var session *wat.Session
			select {
			case session = <-server.New:
			case <-time.After(3 * time.Second):
				t.Fatal("no server session")
			}
			ss, err := session.Accept()
			if err != nil {
				t.Fatal(err)
			}
			ln, err := net.Listen("tcp4", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			defer ln.Close()
			conn, err := net.Dial("tcp4", ln.Addr().String())
			if err != nil {
				t.Fatal(err)
			}
			defer conn.Close()
			backend, err := ln.Accept()
			if err != nil {
				t.Fatal(err)
			}
			defer backend.Close()
			if wrapped {
				identity, peer := net.Pipe()
				defer identity.Close()
				defer peer.Close()
				conn = &speedboxOriginConn{Conn: conn, identity: identity}
			}
			done := make(chan struct{})
			go func() {
				bridge(conn, ss, "origin-close", portReg.get("s", ln.Addr().(*net.TCPAddr).Port), true)
				close(done)
			}()
			defer func() {
				backend.Close()
				conn.Close()
				select {
				case <-done:
				case <-time.After(time.Second):
					t.Error("bridge did not close")
				}
			}()
			backend.SetReadDeadline(time.Now().Add(time.Second))
			var b [5]byte
			if _, err = io.ReadFull(backend, b[:]); err != nil || string(b[:]) != "hello" {
				t.Fatal("payload", err)
			}
			cs.CloseWrite()
			backend.SetReadDeadline(time.Now().Add(600 * time.Millisecond))
			n, err := backend.Read(b[:])
			if n != 0 || err != io.EOF {
				t.Fatalf("client FIN was not delivered to backend: n=%d err=%v", n, err)
			}
			// A FIN ends only upload; the backend can still finish its response.
			if _, err = backend.Write([]byte("reply")); err != nil {
				t.Fatal(err)
			}
			if _, err = io.ReadFull(cs, b[:]); err != nil || string(b[:]) != "reply" {
				t.Fatal("half-close response", err)
			}
			backend.(*net.TCPConn).CloseWrite()
		})
	}
}

func TestSpeedboxOriginBridgeForwardsClientReset(t *testing.T) {
	key := []byte("origin-reset-regression")
	carrier, err := wat.NewUDPServer("127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	server := wat.NewServer(wat.Config{Key: key}, carrier)
	defer server.Close()
	uc, err := wat.NewUDPClient(carrier.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	client, err := wat.NewSession(wat.Config{Key: key}, uc)
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	waitFor(t, "alive", 3*time.Second, client.Alive)
	cs, err := client.OpenStream()
	if err != nil {
		t.Fatal(err)
	}
	if _, err = cs.Write([]byte("hello")); err != nil {
		t.Fatal(err)
	}
	var session *wat.Session
	select {
	case session = <-server.New:
	case <-time.After(3 * time.Second):
		t.Fatal("no server session")
	}
	ss, err := session.Accept()
	if err != nil {
		t.Fatal(err)
	}
	ln, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	conn, err := net.Dial("tcp4", ln.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	backend, err := ln.Accept()
	if err != nil {
		t.Fatal(err)
	}
	defer backend.Close()
	identity, peer := net.Pipe()
	defer peer.Close()
	defer identity.Close()
	wrapped := &speedboxOriginConn{Conn: conn, identity: identity}
	done := make(chan struct{})
	go func() {
		bridge(wrapped, ss, "origin-reset", portReg.get("s", ln.Addr().(*net.TCPAddr).Port), true)
		close(done)
	}()
	defer wrapped.Close()
	backend.SetReadDeadline(time.Now().Add(time.Second))
	var payload [5]byte
	if _, err = io.ReadFull(backend, payload[:]); err != nil {
		t.Fatal(err)
	}
	cs.Reset()
	backend.SetReadDeadline(time.Now().Add(time.Second))
	if n, err := backend.Read(payload[:]); n != 0 || err == nil {
		t.Fatal("reset did not close backend", n, err)
	}
	peer.SetReadDeadline(time.Now().Add(time.Second))
	if _, err := peer.Read(payload[:]); err != io.EOF {
		t.Fatal("reset retained origin registration", err)
	}
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("reset retained bridge")
	}
}
