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
