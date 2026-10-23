package xray

import (
	"bufio"
	"context"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	stdnet "net"
	"net/netip"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/admission"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/cedar2025/xboard-node/internal/origin"
	xnet "github.com/xtls/xray-core/common/net"
	"github.com/xtls/xray-core/common/protocol"
	"github.com/xtls/xray-core/common/session"
	"golang.org/x/net/websocket"
)

func originBroker(t *testing.T) (*origin.Registry, string) {
	t.Helper()
	dir := t.TempDir()
	os.Chmod(dir, 0700)
	path := filepath.Join(dir, "origin.sock")
	r, err := origin.Listen(path)
	if err != nil {
		if os.Getenv("SPEEDBOX_TEST_NO_UNIX") == "1" {
			t.Skip("local runtime prohibits Unix sockets; exercised in Linux CI")
		}
		t.Fatal(err)
	}
	t.Cleanup(func() { r.Close() })
	return r, path
}
func registerOrigin(t *testing.T, path string, source, target uint16, ip string) stdnet.Conn {
	t.Helper()
	c, err := stdnet.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { c.Close() })
	c.SetDeadline(time.Now().Add(2 * time.Second))
	json.NewEncoder(c).Encode(origin.Registration{Version: 1, SourcePort: source, TargetPort: target, OriginalIP: ip})
	line, err := bufio.NewReader(c).ReadString('\n')
	if err != nil || line != "OK\n" {
		t.Fatal("registration", line, err)
	}
	c.SetDeadline(time.Time{})
	return c
}
func TestTrustedOriginTenNodesPreserveGlobalOneIPLimit(t *testing.T) {
	r, path := originBroker(t)
	p := &lifecyclePanel{leases: map[string]lifecycleLease{}}
	const ip = "198.51.100.7"
	cancels := []context.CancelFunc{}
	for node := 1; node <= 10; node++ {
		d := newLifecycleDispatcher(p, node)
		d.originPolicy = origin.Policy{Required: node > 1, Port: uint16(20000 + node), Registry: r}
		source := ip
		port := uint16(40000 + node)
		if node > 1 {
			registerOrigin(t, path, port, uint16(20000+node), ip)
			source = "127.0.0.1"
		}
		ctx, cancel := context.WithCancel(context.Background())
		cancels = append(cancels, cancel)
		ctx = session.ContextWithInbound(ctx, &session.Inbound{Source: xnet.TCPDestination(xnet.ParseAddress(source), xnet.Port(port)), User: &protocol.MemoryUser{Email: userEmail(7)}})
		if _, err := d.Dispatch(ctx, xnet.TCPDestination(xnet.ParseAddress("example.test"), 443)); err != nil {
			t.Fatalf("node %d denied the same phone: %v", node, err)
		}
	}
	if p.count() != 10 {
		t.Fatal("same source failed across ten nodes", p.count())
	}
	p.mu.Lock()
	for _, lease := range p.leases {
		if lease.ip != ip {
			t.Error("proxy IP leaked into the global gate", lease.ip)
		}
	}
	p.mu.Unlock()
	d := newLifecycleDispatcher(p, 11)
	d.originPolicy = origin.Policy{Required: true, Port: 2087, Registry: r}
	registerOrigin(t, path, 55001, 2087, "203.0.113.9")
	for _, src := range []struct {
		ip   string
		port uint16
	}{{"127.0.0.1", 55001}, {"203.0.113.9", 55002}, {"127.0.0.1", 65500}} {
		ctx := session.ContextWithInbound(context.Background(), &session.Inbound{Source: xnet.TCPDestination(xnet.ParseAddress(src.ip), xnet.Port(src.port)), User: &protocol.MemoryUser{Email: userEmail(7)}})
		_, _, ticket, err := d.admit(ctx)
		ticket.Release()
		if err == nil {
			t.Fatal("second IP or unregistered proxy bypassed account limit", src)
		}
	}
	for _, cancel := range cancels {
		cancel()
	}
	awaitLifecycle(t, "disconnect retained reservations", func() bool { return p.count() == 0 })
}

// Runs against the exact bundled Wat binary in Linux CI. WSS observes a
// forwarded client IP, whereas the Wat backend accepts a loopback connection.
func TestTrustedOriginLiveWatH2AndWebSocketShareOneIP(t *testing.T) {
	binary := os.Getenv("SPEEDBOX_TEST_WATTUN")
	if binary == "" {
		t.Skip("set SPEEDBOX_TEST_WATTUN to the verified bundled Linux binary")
	}
	broker, path := originBroker(t)
	origin.SetDefault(broker)
	var clientIP string
	addrs, _ := stdnet.InterfaceAddrs()
	for _, a := range addrs {
		p, err := netip.ParsePrefix(a.String())
		if err == nil && p.Addr().Is4() && p.Addr().IsGlobalUnicast() {
			clientIP = p.Addr().String()
			break
		}
	}
	if clientIP == "" {
		t.Fatal("integration test requires a non-loopback local IPv4")
	}
	port := func() int {
		l, e := stdnet.Listen("tcp4", "0.0.0.0:0")
		if e != nil {
			t.Fatal(e)
		}
		p := l.Addr().(*stdnet.TCPAddr).Port
		l.Close()
		return p
	}
	target, err := stdnet.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer target.Close()
	go func() {
		for {
			c, e := target.Accept()
			if e != nil {
				return
			}
			go func() { defer c.Close(); io.Copy(c, c) }()
		}
	}()
	p := &lifecyclePanel{leases: map[string]lifecycleLease{}}
	nodes := []int{port(), port()}
	var watNode *Xray
	var watSpec *model.NodeSpec
	for i, np := range nodes {
		x := New(config.KernelConfig{Type: "xray", LogLevel: "error"})
		x.SetConnectionAdmission(admission.New(lifecycleCaller{p, i + 1}).Acquire)
		nc := &model.NodeSpec{Protocol: "vless", ListenIP: "0.0.0.0", ServerPort: np, Network: "ws", NetworkSettings: map[string]any{"path": "/connect-ws"}, TrustedTunnelOrigin: false, CustomRoutes: []map[string]any{{"type": "field", "ip": []string{"127.0.0.1"}, "outboundTag": "direct"}}}
		if err = x.Start(nc, []model.UserSpec{{ID: 7, UUID: "11111111-1111-4111-8111-111111111111", DeviceLimit: 1}}, kernel.TLSCert{}); err != nil {
			t.Fatal(err)
		}
		t.Cleanup(x.Stop)
		if i == 1 {
			watNode = x
			watSpec = nc
		}
	}
	carrier, public := port(), port()
	originPorts := strconv.Itoa(nodes[1])
	key := "test-identity-key-not-a-production-secret"
	start := func(name string, args ...string) {
		f, e := os.Create(filepath.Join(t.TempDir(), name+".log"))
		if e != nil {
			t.Fatal(e)
		}
		cmd := exec.Command(binary, args...)
		cmd.Stdout = f
		cmd.Stderr = f
		if e = cmd.Start(); e != nil {
			t.Fatal(e)
		}
		t.Cleanup(func() { cmd.Process.Kill(); cmd.Wait(); f.Close() })
	}
	start("wat-server", "-mode", "server", "-carrier", "h2", "-listen", fmt.Sprintf("127.0.0.1:%d", carrier), "-key", key, "-tcp-cc", "system", "-allow-ports", originPorts, "-speedbox-origin-ports", originPorts, "-speedbox-origin-socket", path)
	start("wat-client", "-mode", "client", "-carrier", "h2", "-peer", fmt.Sprintf("127.0.0.1:%d", carrier), "-key", key, "-tcp-cc", "system", "-conns", "1", "-map", fmt.Sprintf("%d:%d", public, nodes[1]), "-speedbox-origin-ports", originPorts)
	spoof := false
	dial := func(host string, np int, forwarded bool) (*websocket.Conn, error) {
		cfg, e := websocket.NewConfig(fmt.Sprintf("ws://%s:%d/connect-ws", host, np), "http://localhost/")
		if e != nil {
			return nil, e
		}
		if forwarded {
			cfg.Header.Set("X-Forwarded-For", clientIP)
		} else if spoof {
			cfg.Header.Set("X-Forwarded-For", "203.0.113.9")
		}
		c, e := websocket.DialConfig(cfg)
		if e != nil {
			return nil, e
		}
		c.PayloadType = websocket.BinaryFrame
		c.SetDeadline(time.Now().Add(2 * time.Second))
		id, _ := hex.DecodeString("11111111111141118111111111111111")
		tp := target.Addr().(*stdnet.TCPAddr).Port
		h := append([]byte{0}, id...)
		h = append(h, 0, 1, byte(tp>>8), byte(tp), 1, 127, 0, 0, 1)
		_, e = c.Write(append(h, []byte("ping")...))
		var reply [6]byte
		if e == nil {
			_, e = io.ReadFull(c, reply[:])
		}
		if e != nil || string(reply[2:]) != "ping" {
			c.Close()
			return nil, fmt.Errorf("echo %x: %v", reply, e)
		}
		return c, nil
	}
	first, err := dial("127.0.0.1", nodes[0], true)
	if err != nil {
		t.Fatal(err)
	}
	defer first.Close()
	var second *websocket.Conn
	until := time.Now().Add(25 * time.Second)
	for {
		second, err = dial(clientIP, public, false)
		if err == nil {
			break
		}
		if time.Now().After(until) {
			t.Fatal("same phone blocked on Wat while WSS active", err)
		}
		time.Sleep(100 * time.Millisecond)
	}
	defer second.Close()
	if p.count() != 2 {
		t.Fatal("expected both nodes sharing one account IP", p.count())
	}
	watSpec.TrustedTunnelOrigin = true
	if err = watNode.Reload(watSpec, []model.UserSpec{{ID: 7, UUID: "11111111-1111-4111-8111-111111111111", DeviceLimit: 1}}, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	spoof = true
	var replacement *websocket.Conn
	for i := 0; i < 32; i++ {
		next, e := dial(clientIP, public, false)
		if e != nil {
			t.Fatal("retired listener accepted a new connection under its old source policy", e)
		}
		if replacement != nil {
			replacement.Close()
		}
		replacement = next
	}
	defer replacement.Close()

	p.mu.Lock()
	for _, l := range p.leases {
		if l.ip != clientIP {
			t.Error("wrong global identity", l.ip, clientIP)
		}
	}
	p.mu.Unlock()
	first.Close()
	second.Close()
	replacement.Close()
	awaitLifecycle(t, "live Wat/WSS disconnect retained account", func() bool { return p.count() == 0 })
}
