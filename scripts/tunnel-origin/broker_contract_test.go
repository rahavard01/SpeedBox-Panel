package main

import (
	"bufio"
	"encoding/json"
	"errors"
	"net"
	"net/netip"
	"sync"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/origin"
)

// Restricted runners can prohibit AF_UNIX while allowing loopback TCP. Keep
// transport tests runnable there using the actual broker's JSON contract. On
// normal Linux hosts makeFixture always selects the real production registry.
type contractBroker struct {
	mu      sync.Mutex
	entries map[origin.Key]netip.Addr
	conns   map[net.Conn]bool
	ln      net.Listener
}

func newContractBroker(t *testing.T) *contractBroker {
	t.Helper()
	ln, e := net.Listen("tcp4", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	b := &contractBroker{ln: ln, entries: map[origin.Key]netip.Addr{}, conns: map[net.Conn]bool{}}
	go func() {
		for {
			c, e := ln.Accept()
			if e != nil {
				return
			}
			b.mu.Lock()
			b.conns[c] = true
			b.mu.Unlock()
			go func() {
				defer func() { c.Close(); b.mu.Lock(); delete(b.conns, c); b.mu.Unlock() }()
				var r origin.Registration
				if json.NewDecoder(bufio.NewReader(c)).Decode(&r) != nil || r.Version != 1 || r.SourcePort == 0 || r.TargetPort == 0 {
					return
				}
				ip, e := netip.ParseAddr(r.OriginalIP)
				if e != nil || !ip.IsGlobalUnicast() {
					return
				}
				key := origin.Key{SourcePort: r.SourcePort, TargetPort: r.TargetPort}
				b.mu.Lock()
				if _, ok := b.entries[key]; ok {
					b.mu.Unlock()
					c.Write([]byte("BUSY\n"))
					return
				}
				b.entries[key] = ip.Unmap()
				b.mu.Unlock()
				defer func() { b.mu.Lock(); delete(b.entries, key); b.mu.Unlock() }()
				c.Write([]byte("OK\n"))
				var q [1]byte
				c.Read(q[:])
			}()
		}
	}()
	return b
}
func (b *contractBroker) dial() (net.Conn, error) {
	return net.DialTimeout("tcp", b.ln.Addr().String(), time.Second)
}
func (b *contractBroker) lookup(src, target uint16) (string, error) {
	b.mu.Lock()
	defer b.mu.Unlock()
	ip, ok := b.entries[origin.Key{SourcePort: src, TargetPort: target}]
	if !ok {
		return "", errors.New("origin missing")
	}
	return ip.String(), nil
}
func (b *contractBroker) close() {
	b.ln.Close()
	b.mu.Lock()
	defer b.mu.Unlock()
	for c := range b.conns {
		c.Close()
	}
}
