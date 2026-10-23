// Package origin restores source identity for authenticated local tunnel forwards.
// Registration is local-only (a private Unix socket), scoped to the lifetime of
// the forwarding socket, and completed before the backend receives any bytes.
package origin

import (
	"bufio"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"sync"
	"sync/atomic"
	"syscall"
	"time"
)

const SocketPath = "/run/speedbox-origin/broker.sock"
const MaxRegistrations = 65536

// Destination ports are included so a record cannot identify a different node.
// TCP and UDP share this key: muxed UDP can retain its outer TCP source tuple.
// A collision is rejected; the forwarder retries with a new bound source port.
type Key struct{ SourcePort, TargetPort uint16 }
type Registration struct {
	Operation  string `json:"operation,omitempty"`
	Version    int    `json:"version"`
	SourcePort uint16 `json:"source_port"`
	TargetPort uint16 `json:"target_port"`
	OriginalIP string `json:"original_ip"`
}
type Registry struct {
	mu          sync.RWMutex
	entries     map[Key]netip.Addr
	connections map[net.Conn]struct{}
	listener    net.Listener
	closed      bool
}

var active atomic.Pointer[Registry]

// Listen owns a 0700 directory and a 0600 socket. Never take over a live broker
// or follow a pre-existing symlink. Only this OS account can register identities.
func Listen(path string) (*Registry, error) {
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0700); err != nil {
		return nil, err
	}
	info, err := os.Lstat(dir)
	if err != nil {
		return nil, err
	}
	if !info.IsDir() || info.Mode().Perm() != 0700 || info.Sys().(*syscall.Stat_t).Uid != uint32(os.Geteuid()) {
		return nil, errors.New("origin directory must be private (0700)")
	}
	if info, err = os.Lstat(path); err == nil {
		if info.Mode()&os.ModeSocket == 0 {
			return nil, errors.New("origin path is not a socket")
		}
		if c, e := net.DialTimeout("unix", path, time.Second); e == nil {
			c.Close()
			return nil, errors.New("origin broker already running")
		}
		if err = os.Remove(path); err != nil {
			return nil, err
		}
	} else if !os.IsNotExist(err) {
		return nil, err
	}
	ln, err := net.Listen("unix", path)
	if err != nil {
		return nil, err
	}
	if err = os.Chmod(path, 0600); err != nil {
		ln.Close()
		return nil, err
	}
	r := &Registry{entries: make(map[Key]netip.Addr), connections: make(map[net.Conn]struct{}), listener: ln}
	go r.serve()
	return r, nil
}
func SetDefault(r *Registry) { active.Store(r) }
func (r *Registry) serve() {
	for {
		c, err := r.listener.Accept()
		if err != nil {
			return
		}
		r.mu.Lock()
		if r.closed || len(r.connections) >= MaxRegistrations {
			r.mu.Unlock()
			c.Close()
			continue
		}
		r.connections[c] = struct{}{}
		r.mu.Unlock()
		go r.register(c)
	}
}
func (r *Registry) register(c net.Conn) {
	defer func() { c.Close(); r.mu.Lock(); delete(r.connections, c); r.mu.Unlock() }()
	c.SetDeadline(time.Now().Add(5 * time.Second))
	reader := bufio.NewReaderSize(io.LimitReader(c, 1025), 128)
	line, err := reader.ReadBytes('\n')
	if err != nil || len(line) > 1024 {
		return
	}
	var req Registration
	if json.Unmarshal(line, &req) != nil || req.Version != 1 {
		return
	}
	if req.Operation == "ping" {
		c.Write([]byte("ORIGIN1\n"))
		return
	}
	if req.Operation != "" || req.SourcePort == 0 || req.TargetPort == 0 {
		return
	}
	ip, err := netip.ParseAddr(req.OriginalIP)
	if err != nil || ip.Zone() != "" || !ip.IsGlobalUnicast() {
		return
	}
	ip = ip.Unmap()
	key := Key{req.SourcePort, req.TargetPort}
	r.mu.Lock()
	_, exists := r.entries[key]
	if exists || r.closed {
		r.mu.Unlock()
		if exists {
			c.Write([]byte("BUSY\n"))
		}
		return
	}
	r.entries[key] = ip
	r.mu.Unlock()
	defer func() { r.mu.Lock(); delete(r.entries, key); r.mu.Unlock() }()
	if _, err = c.Write([]byte("OK\n")); err != nil {
		return
	}
	c.SetDeadline(time.Time{})
	// No renewals and no time-based aliases. EOF removes exactly this record.
	var b [1]byte
	c.Read(b[:])
}
func (r *Registry) Lookup(source, target uint16) (netip.Addr, bool) {
	if r == nil {
		return netip.Addr{}, false
	}
	r.mu.RLock()
	defer r.mu.RUnlock()
	ip, ok := r.entries[Key{source, target}]
	return ip, ok
}
func (r *Registry) Close() error {
	active.CompareAndSwap(r, nil)
	r.mu.Lock()
	r.closed = true
	cs := make([]net.Conn, 0, len(r.connections))
	for c := range r.connections {
		cs = append(cs, c)
	}
	r.mu.Unlock()
	err := r.listener.Close()
	for _, c := range cs {
		c.Close()
	}
	return err
}

type Policy struct {
	Required bool
	Port     uint16
	Registry *Registry
}

func (p Policy) Resolve(ip netip.Addr, port uint16) (string, error) {
	ip = ip.Unmap()
	if !ip.IsValid() {
		return "", errors.New("invalid source address")
	}
	if p.Required && port == 0 {
		return "", errors.New("unverifiable forwarded source")
	}
	if !ip.IsLoopback() {
		return ip.String(), nil
	}
	r := p.Registry
	if r == nil {
		r = active.Load()
	}
	if original, ok := r.Lookup(port, p.Port); ok {
		return original.String(), nil
	}
	if p.Required {
		return "", fmt.Errorf("trusted tunnel origin missing on port %d", p.Port)
	}
	return ip.String(), nil
}
