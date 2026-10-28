// Linux endpoint adapter for managed tunnel forwards. User traffic and source
// identity share one authenticated flow; identity is never inferred from leases.
package main

import (
	"bufio"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"crypto/tls"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"net/netip"
	"os"
	"os/signal"
	"path/filepath"
	"strconv"
	"sync"
	"syscall"
	"time"
)

const magic = "SPEEDBOX-ORIGIN2\n"
const brokerPath = "/run/speedbox-origin/broker.sock"
const maxFlows = 32768

var flowIdle = 30 * time.Second
var collision = errors.New("origin tuple is busy")

type Route struct {
	Listen string `json:"listen"`
	Target int    `json:"target"`
}
type Config struct {
	ReadyFile string   `json:"ready_file,omitempty"`
	Role      string   `json:"role"`
	Secret    string   `json:"secret"`
	Backend   string   `json:"backend"`
	Listen    string   `json:"listen"`
	Routes    []Route  `json:"routes"`
	Targets   []int    `json:"targets"`
	TCP       bool     `json:"tcp"`
	UDP       bool     `json:"udp"`
	Allowed   []string `json:"allowed"`
	Mark      int      `json:"mark"`
	Broker    string   `json:"broker,omitempty"`
}
type server struct {
	brokerDial func() (net.Conn, error)
	original   func(net.Addr) (netip.AddrPort, error)
	clientTLS  *tls.Config
	serverTLS  *tls.Config
	cfg        Config
	key        []byte
	allowed    []netip.Prefix
	targets    map[int]bool
	mu         sync.Mutex
	flows      map[[16]byte]*udpFlow
	seen       map[[16]byte]time.Time
	conns      map[net.Conn]bool
}

func newServer(c Config) (*server, error) {
	key, err := hex.DecodeString(c.Secret)
	if err != nil || len(key) != 32 || (c.Role != "ingress" && c.Role != "egress" && c.Role != "health") || (!c.TCP && !c.UDP) {
		return nil, errors.New("invalid origin configuration")
	}
	s := &server{cfg: c, key: key, targets: map[int]bool{}, flows: map[[16]byte]*udpFlow{}, seen: map[[16]byte]time.Time{}, conns: map[net.Conn]bool{}}
	s.original = addr
	s.clientTLS, s.serverTLS = s.tlsConfig(false), s.tlsConfig(true)
	if s.cfg.Broker == "" {
		s.cfg.Broker = brokerPath
	}
	s.brokerDial = func() (net.Conn, error) { return net.DialTimeout("unix", s.cfg.Broker, 2*time.Second) }
	for _, p := range c.Targets {
		if p < 1 || p > 65535 {
			return nil, errors.New("invalid target")
		}
		s.targets[p] = true
	}
	for _, v := range c.Allowed {
		p, e := netip.ParsePrefix(v)
		if e != nil {
			return nil, e
		}
		s.allowed = append(s.allowed, p)
	}
	if c.Role != "ingress" && (c.Listen == "" || len(s.targets) == 0) {
		return nil, errors.New("missing egress targets")
	}
	if c.Role == "ingress" {
		if c.Backend == "" || len(c.Routes) == 0 {
			return nil, errors.New("missing ingress routes")
		}
		for _, r := range c.Routes {
			if _, _, e := net.SplitHostPort(r.Listen); e != nil || r.Target < 1 || r.Target > 65535 {
				return nil, errors.New("invalid ingress route")
			}
		}
	}
	return s, nil
}
func (s *server) mac(parts ...[]byte) []byte {
	h := hmac.New(sha256.New, s.key)
	for _, p := range parts {
		h.Write(p)
	}
	return h.Sum(nil)
}
func (s *server) admits(ip netip.Addr) bool {
	ip = ip.Unmap()
	if !ip.IsGlobalUnicast() || ip.Zone() != "" {
		return false
	}
	if len(s.allowed) == 0 {
		return true
	}
	for _, p := range s.allowed {
		if p.Contains(ip) {
			return true
		}
	}
	return false
}
func addr(a net.Addr) (netip.AddrPort, error) {
	p, e := netip.ParseAddrPort(a.String())
	if e == nil {
		p = netip.AddrPortFrom(p.Addr().Unmap(), p.Port())
	}
	return p, e
}
func tune(c net.Conn) {
	if t, ok := c.(*net.TCPConn); ok {
		_ = t.SetKeepAliveConfig(net.KeepAliveConfig{Enable: true, Idle: 15 * time.Second, Interval: 5 * time.Second, Count: 3})
		if raw, e := t.SyscallConn(); e == nil {
			_ = raw.Control(func(fd uintptr) { _ = syscall.SetsockoptInt(int(fd), syscall.IPPROTO_TCP, 18, 15000) })
		}
	}
}
func dial(ctx context.Context, network, address string, mark int) (net.Conn, error) {
	d := net.Dialer{Timeout: 5 * time.Second}
	d.SetMultipathTCP(false)
	if mark != 0 {
		d.ControlContext = func(_ context.Context, _, _ string, raw syscall.RawConn) error {
			var err error
			e := raw.Control(func(fd uintptr) { err = syscall.SetsockoptInt(int(fd), syscall.SOL_SOCKET, syscall.SO_MARK, mark) })
			if e != nil {
				return e
			}
			return err
		}
	}
	c, e := d.DialContext(ctx, network, address)
	if e == nil {
		tune(c)
	}
	return c, e
}
func (s *server) track(c net.Conn) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(s.conns) >= maxFlows {
		return false
	}
	s.conns[c] = true
	return true
}
func (s *server) forget(c net.Conn) { s.mu.Lock(); delete(s.conns, c); s.mu.Unlock(); c.Close() }
func pipe(a, b net.Conn) {
	done := make(chan struct{}, 2)
	copyOne := func(dst, src net.Conn) {
		_, e := io.Copy(dst, src)
		if e != nil {
			a.Close()
			b.Close()
		} else if w, ok := dst.(interface{ CloseWrite() error }); ok {
			_ = w.CloseWrite()
		} else {
			dst.Close()
		}
		done <- struct{}{}
	}
	go copyOne(a, b)
	go copyOne(b, a)
	<-done
	<-done
}

type hello struct {
	Target int    `json:"target"`
	IP     string `json:"ip"`
	Port   uint16 `json:"port"`
	Probe  bool   `json:"probe,omitempty"`
}

func (s *server) sendHello(c net.Conn, target int, src netip.AddrPort) error {
	return s.sendMetadata(c, hello{Target: target, IP: src.Addr().Unmap().String(), Port: src.Port()})
}
func (s *server) sendMetadata(c net.Conn, h hello) error {
	_ = c.SetDeadline(time.Now().Add(5 * time.Second))
	defer c.SetDeadline(time.Time{})
	if _, e := io.WriteString(c, magic); e != nil {
		return e
	}
	b, e := json.Marshal(h)
	if e != nil {
		return e
	}
	wire := make([]byte, 2, len(b)+2)
	binary.BigEndian.PutUint16(wire, uint16(len(b)))
	wire = append(wire, b...)
	if _, e = c.Write(wire); e != nil {
		return e
	}
	return nil
}
func (s *server) readHello(c net.Conn) (hello, error) {
	var h hello
	_ = c.SetDeadline(time.Now().Add(5 * time.Second))
	defer c.SetDeadline(time.Time{})
	var prefix [len(magic)]byte
	if _, e := io.ReadFull(c, prefix[:]); e != nil || string(prefix[:]) != magic {
		return h, errors.New("missing authenticated origin")
	}
	var size [2]byte
	if _, e := io.ReadFull(c, size[:]); e != nil {
		return h, e
	}
	n := int(binary.BigEndian.Uint16(size[:]))
	if n < 1 || n > 512 {
		return h, errors.New("origin header too large")
	}
	b := make([]byte, n)
	if _, e := io.ReadFull(c, b); e != nil {
		return h, e
	}
	if e := json.Unmarshal(b, &h); e != nil {
		return h, e
	}
	ip, e := netip.ParseAddr(h.IP)
	if e != nil || !ip.Unmap().IsGlobalUnicast() || ip.Zone() != "" || h.Port == 0 || !s.targets[h.Target] {
		return h, errors.New("origin not allowed")
	}
	h.IP = ip.Unmap().String()
	return h, nil
}
func (s *server) tcp(ctx context.Context, listen string, target int, bound chan<- error) {
	var lc net.ListenConfig
	lc.SetMultipathTCP(false)
	ln, e := lc.Listen(ctx, "tcp", listen)
	bound <- e
	if e != nil {
		return
	}
	defer ln.Close()
	go func() { <-ctx.Done(); ln.Close() }()
	for {
		c, e := ln.Accept()
		if e != nil {
			return
		}
		if !s.track(c) {
			c.Close()
			continue
		}
		tune(c)
		go func() {
			defer s.forget(c)
			if s.cfg.Role == "ingress" {
				src, e := s.original(c.RemoteAddr())
				if e != nil || !s.admits(src.Addr()) {
					return
				}
				out, e := dial(ctx, "tcp", s.cfg.Backend, s.cfg.Mark)
				if e != nil {
					return
				}
				defer out.Close()
				secure := tls.Client(out, s.clientTLS)
				_ = secure.SetDeadline(time.Now().Add(5 * time.Second))
				if e = secure.HandshakeContext(ctx); e != nil {
					return
				}
				out = secure
				stop := context.AfterFunc(ctx, func() { c.Close(); out.Close() })
				defer stop()
				if e = s.sendHello(out, target, src); e != nil {
					return
				}
				pipe(c, out)
			} else {
				secure := tls.Server(c, s.serverTLS)
				_ = secure.SetDeadline(time.Now().Add(5 * time.Second))
				if e := secure.HandshakeContext(ctx); e != nil {
					return
				}
				c = secure
				h, e := s.readHello(c)
				if e != nil {
					return
				}
				if h.Probe {
					if s.cfg.Role != "health" {
						if e := s.probeIdentity(h.Target, h.IP); e != nil {
							return
						}
					}
					c.SetWriteDeadline(time.Now().Add(2 * time.Second))
					io.WriteString(c, probeAck)
					return
				}
				if s.cfg.Role == "health" {
					return
				}
				out, e := s.backendTCP(ctx, h.Target, h.IP)
				if e != nil {
					return
				}
				defer out.Close()
				stop := context.AfterFunc(ctx, func() { c.Close(); out.Close() })
				defer stop()
				pipe(c, out)
			}
		}()
	}
}
func (s *server) register(source, target int, ip string) (net.Conn, error) {
	c, e := s.brokerDial()
	if e != nil {
		return nil, e
	}
	_ = c.SetDeadline(time.Now().Add(3 * time.Second))
	req := struct {
		Version int    `json:"version"`
		Source  int    `json:"source_port"`
		Target  int    `json:"target_port"`
		IP      string `json:"original_ip"`
	}{1, source, target, ip}
	if e = json.NewEncoder(c).Encode(req); e != nil {
		c.Close()
		return nil, e
	}
	line, e := bufio.NewReader(c).ReadString('\n')
	if line == "BUSY\n" {
		c.Close()
		return nil, collision
	}
	if e != nil || line != "OK\n" {
		c.Close()
		return nil, errors.New("origin broker rejected registration")
	}
	c.SetDeadline(time.Time{})
	return c, nil
}

type guarded struct {
	net.Conn
	identity net.Conn
	once     sync.Once
}

func (c *guarded) Close() error {
	var e error
	c.once.Do(func() { e = c.Conn.Close(); c.identity.Close() })
	return e
}
func (c *guarded) CloseWrite() error {
	if w, ok := c.Conn.(interface{ CloseWrite() error }); ok {
		return w.CloseWrite()
	}
	return nil
}
func guard(c, identity net.Conn) net.Conn {
	g := &guarded{Conn: c, identity: identity}
	go func() { var b [1]byte; identity.Read(b[:]); g.Close() }()
	return g
}
func (s *server) backendTCP(ctx context.Context, target int, ip string) (net.Conn, error) {
	var last error
	for i := 0; i < 8; i++ {
		var identity net.Conn
		d := net.Dialer{Timeout: 5 * time.Second}
		d.SetMultipathTCP(false)
		d.ControlContext = func(_ context.Context, _, _ string, raw syscall.RawConn) error {
			var err error
			e := raw.Control(func(fd uintptr) {
				if err = syscall.Bind(int(fd), &syscall.SockaddrInet4{Addr: [4]byte{127, 0, 0, 1}}); err != nil {
					return
				}
				a, e := syscall.Getsockname(int(fd))
				if e != nil {
					err = e
					return
				}
				identity, err = s.register(a.(*syscall.SockaddrInet4).Port, target, ip)
			})
			if e != nil {
				return e
			}
			return err
		}
		c, e := d.DialContext(ctx, "tcp4", net.JoinHostPort("127.0.0.1", strconv.Itoa(target)))
		if e == nil {
			tune(c)
			return guard(c, identity), nil
		}
		if identity != nil {
			identity.Close()
		}
		last = e
		if !errors.Is(e, collision) {
			break
		}
	}
	return nil, last
}
func (s *server) backendUDP(target int, ip string) (net.Conn, error) {
	var last error
	for i := 0; i < 8; i++ {
		c, e := net.DialUDP("udp4", nil, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: target})
		if e != nil {
			return nil, e
		}
		id, e := s.register(c.LocalAddr().(*net.UDPAddr).Port, target, ip)
		if e == nil {
			return guard(c, id), nil
		}
		c.Close()
		last = e
		if !errors.Is(e, collision) {
			break
		}
	}
	return nil, last
}
func (s *server) run(ctx context.Context) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	defer func() {
		s.mu.Lock()
		defer s.mu.Unlock()
		for c := range s.conns {
			c.Close()
		}
		for _, f := range s.flows {
			f.close()
		}
	}()
	go s.reap(ctx)
	n := 1
	if s.cfg.Role == "ingress" {
		n = len(s.cfg.Routes)
	}
	if s.cfg.TCP && s.cfg.UDP {
		n *= 2
	}
	bound := make(chan error, n)
	launch := func(listen string, target int) {
		if s.cfg.TCP {
			go s.tcp(ctx, listen, target, bound)
		}
		if s.cfg.UDP {
			go s.udp(ctx, listen, target, bound)
		}
	}
	if s.cfg.Role == "ingress" {
		for _, r := range s.cfg.Routes {
			launch(r.Listen, r.Target)
		}
	} else {
		launch(s.cfg.Listen, 0)
	}
	for i := 0; i < n; i++ {
		if e := <-bound; e != nil {
			return e
		}
	}
	if s.cfg.ReadyFile != "" {
		// /run is cleared at boot, and the node broker may start after us.
		dir := filepath.Dir(s.cfg.ReadyFile)
		if e := os.MkdirAll(dir, 0700); e != nil {
			return e
		}
		st, e := os.Lstat(dir)
		if e != nil || !st.IsDir() || st.Mode().Perm() != 0700 || st.Sys().(*syscall.Stat_t).Uid != uint32(os.Geteuid()) {
			return errors.New("origin runtime directory is not private")
		}
		b, _ := json.Marshal(map[string]int{"pid": os.Getpid()})
		if e := os.WriteFile(s.cfg.ReadyFile+".new", b, 0600); e != nil {
			return e
		}
		if e := os.Rename(s.cfg.ReadyFile+".new", s.cfg.ReadyFile); e != nil {
			return e
		}
		defer os.Remove(s.cfg.ReadyFile)
	}
	<-ctx.Done()
	return nil
}
func main() {
	probe := len(os.Args) == 3 && os.Args[1] == "probe"
	if len(os.Args) != 2 && !probe {
		log.Fatal("usage: tunnel-origin [probe] /private/config.json")
	}
	raw, e := os.ReadFile(os.Args[len(os.Args)-1])
	if e != nil {
		log.Fatal(e)
	}
	var cfg Config
	if e = json.Unmarshal(raw, &cfg); e != nil {
		log.Fatal(e)
	}
	s, e := newServer(cfg)
	if e != nil {
		log.Fatal(e)
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	if probe {
		if e = s.probe(ctx); e != nil {
			log.Fatal(e)
		}
		fmt.Println("AUTHENTICATED ORIGIN PATH READY")
		return
	}
	if e = s.run(ctx); e != nil {
		fmt.Fprintln(os.Stderr, e)
		os.Exit(1)
	}
}
