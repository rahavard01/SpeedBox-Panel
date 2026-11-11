package main

import (
	"context"
	"errors"
	"fmt"
	"net"
	"os"
	"syscall"
	"testing"
	"time"
)

// Hold both protocols together. A free TCP port need not be free for UDP.
// first is only used by the collision regression test; normal callers use :0.
func bindTestPair(first string) (net.Listener, net.PacketConn, error) {
	for attempt := 0; attempt < 64; attempt++ {
		address := "127.0.0.1:0"
		if attempt == 0 && first != "" {
			address = first
		}
		tcp, err := net.Listen("tcp4", address)
		if err != nil {
			if errors.Is(err, syscall.EADDRINUSE) {
				continue
			}
			return nil, nil, err
		}
		udp, err := net.ListenPacket("udp4", tcp.Addr().String())
		if err == nil {
			return tcp, udp, nil
		}
		tcp.Close()
		if !errors.Is(err, syscall.EADDRINUSE) {
			return nil, nil, err
		}
	}
	return nil, nil, fmt.Errorf("test TCP/UDP ports busy after 64 attempts: %w", syscall.EADDRINUSE)
}

// Production listeners bind their own sockets. If another process wins the
// short handoff window, stop and join this attempt before selecting a new pair.
// Retry only bind collisions, never authentication or other test failures.
func startTestEndpoint(t *testing.T, parent context.Context, target int, fullRun bool, factory func(string) (*server, error)) (*server, string) {
	t.Helper()
	for attempt := 0; attempt < 32; attempt++ {
		tcp, udp, err := bindTestPair("")
		if err != nil {
			t.Fatal(err)
		}
		address := tcp.Addr().String()
		s, err := factory(address)
		tcp.Close()
		udp.Close()
		if err != nil {
			t.Fatal(err)
		}
		ctx, cancel := context.WithCancel(parent)
		done := make(chan error, 2)
		workers, consumed := 2, 0
		var startup error
		if fullRun {
			workers = 1
			go func() { done <- s.run(ctx) }()
			deadline := time.NewTimer(3 * time.Second)
			ticker := time.NewTicker(5 * time.Millisecond)
		ready:
			for {
				select {
				case startup = <-done:
					consumed = 1
					if startup == nil {
						startup = errors.New("test endpoint exited before readiness")
					}
					break ready
				case <-deadline.C:
					startup = errors.New("test endpoint readiness timed out")
					break ready
				case <-ticker.C:
					if _, err := os.Stat(s.cfg.ReadyFile); err == nil {
						break ready
					}
				}
			}
			deadline.Stop()
			ticker.Stop()
		} else {
			bound := make(chan error, 2)
			go func() { s.tcp(ctx, address, target, bound); done <- nil }()
			go func() { s.udp(ctx, address, target, bound); done <- nil }()
			startup = errors.Join(<-bound, <-bound)
		}
		if startup == nil {
			t.Cleanup(func() {
				cancel()
				for i := 0; i < workers; i++ {
					<-done
				}
			})
			return s, address
		}
		cancel()
		for i := consumed; i < workers; i++ {
			<-done
		}
		// A mixed startup error must not hide a non-bind failure.
		if joined, ok := startup.(interface{ Unwrap() []error }); ok {
			for _, err := range joined.Unwrap() {
				if !errors.Is(err, syscall.EADDRINUSE) {
					t.Fatal(startup)
				}
			}
		} else if !errors.Is(startup, syscall.EADDRINUSE) {
			t.Fatal(startup)
		}
	}
	t.Fatal("test endpoint ports busy after 32 startup attempts")
	return nil, ""
}

func TestPortPairRetriesOccupiedUDP(t *testing.T) {
	occupied, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer occupied.Close()
	tcp, udp, err := bindTestPair(occupied.LocalAddr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer tcp.Close()
	defer udp.Close()
	if tcp.Addr().String() != udp.LocalAddr().String() || tcp.Addr().String() == occupied.LocalAddr().String() {
		t.Fatal("did not select a different shared TCP/UDP port")
	}
	client, err := net.Dial("udp4", occupied.LocalAddr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	if _, err := client.Write([]byte("ok")); err != nil {
		t.Fatal(err)
	}
	occupied.SetReadDeadline(time.Now().Add(time.Second))
	var b [2]byte
	if n, _, err := occupied.ReadFrom(b[:]); err != nil || n != 2 || string(b[:]) != "ok" {
		t.Fatalf("occupied listener was disturbed: %d %v", n, err)
	}
}

func TestEndpointStartupRetriesBindCollision(t *testing.T) {
	occupied, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer occupied.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	ready := t.TempDir() + "/run/ready"
	calls := 0
	_, address := startTestEndpoint(t, ctx, 0, true, func(candidate string) (*server, error) {
		calls++
		if calls == 1 {
			candidate = occupied.LocalAddr().String()
		}
		return newServer(Config{Role: "health", Secret: secret, Listen: candidate, Targets: []int{1}, TCP: true, UDP: true, ReadyFile: ready})
	})
	if calls < 2 || address == occupied.LocalAddr().String() {
		t.Fatal("occupied startup endpoint was not retried")
	}
}
