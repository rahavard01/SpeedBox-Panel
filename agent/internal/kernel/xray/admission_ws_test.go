package xray

import (
	"encoding/binary"
	"encoding/hex"
	"fmt"
	"io"
	stdnet "net"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/admission"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/xtls/xray-core/common/buf"
	"github.com/xtls/xray-core/common/mux"
	xnet "github.com/xtls/xray-core/common/net"
	"golang.org/x/net/websocket"
)

// Real VLESS/WebSocket sockets with two embedded Xray instances sharing one
// account limit. No production endpoint or external network is involved.
func TestAdmissionLiveWebSocketNodeSwitchAndDisconnect(t *testing.T) {
	target, err := stdnet.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer target.Close()
	go func() {
		for {
			c, err := target.Accept()
			if err != nil {
				return
			}
			go func() { defer c.Close(); _, _ = io.Copy(c, c) }()
		}
	}()
	p := &lifecyclePanel{leases: make(map[string]lifecycleLease)}
	const credential = "11111111-1111-4111-8111-111111111111"
	newNode := func(id int) int {
		l, err := stdnet.Listen("tcp4", "127.0.0.1:0")
		if err != nil {
			t.Fatal(err)
		}
		port := l.Addr().(*stdnet.TCPAddr).Port
		l.Close()
		x := New(config.KernelConfig{Type: "xray", LogLevel: "error"})
		x.SetConnectionAdmission(admission.New(lifecycleCaller{p, id}).Acquire)
		nc := &model.NodeSpec{Protocol: "vless", ListenIP: "127.0.0.1", ServerPort: port,
			Network: "ws", NetworkSettings: map[string]any{"path": "/connect-ws"},
			CustomRoutes: []map[string]any{{"type": "field", "ip": []string{"127.0.0.1"}, "outboundTag": "direct"}}}
		if err := x.Start(nc, []model.UserSpec{{ID: 7, UUID: credential, DeviceLimit: 1}}, kernel.TLSCert{}); err != nil {
			t.Fatal(err)
		}
		t.Cleanup(x.Stop)
		return port
	}
	a, b := newNode(1), newNode(2)
	dial := func(port int) *websocket.Conn {
		c, err := websocket.Dial(fmt.Sprintf("ws://127.0.0.1:%d/connect-ws", port), "", "http://localhost/")
		if err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() { c.Close() })
		c.PayloadType = websocket.BinaryFrame
		c.SetDeadline(time.Now().Add(3 * time.Second))
		id, _ := hex.DecodeString("11111111111141118111111111111111")
		targetPort := target.Addr().(*stdnet.TCPAddr).Port
		header := append([]byte{0}, id...)
		header = append(header, 0, 1, byte(targetPort>>8), byte(targetPort), 1, 127, 0, 0, 1)
		if _, err = c.Write(append(header, []byte("ping")...)); err != nil {
			t.Fatal(err)
		}
		var reply [6]byte // VLESS response version/addon length followed by echo.
		if _, err = io.ReadFull(c, reply[:]); err != nil {
			t.Fatal(err)
		}
		if string(reply[2:]) != "ping" {
			t.Fatalf("unexpected response: %x", reply)
		}
		return c
	}
	first := dial(a)
	second := dial(b)
	if p.count() != 2 {
		t.Fatal("same source could not keep both nodes active")
	}
	first.Close()
	awaitLifecycle(t, "closed first WS node retained its lease", func() bool { return p.count() == 1 })
	if _, err := second.Write([]byte("live")); err != nil {
		t.Fatal(err)
	}
	var echo [4]byte
	if _, err := io.ReadFull(second, echo[:]); err != nil || string(echo[:]) != "live" {
		t.Fatalf("other node was disrupted: %q %v", echo, err)
	}
	second.Close()
	awaitLifecycle(t, "all WS clients closed but the account remained reserved", func() bool { return p.count() == 0 })
	third := dial(a)
	third.Close()
	awaitLifecycle(t, "reconnected WS client did not release", func() bool { return p.count() == 0 })
}

// XUDP intentionally keeps its backend UDP association for reconnection after
// the WebSocket is gone. It must not keep the account's admission reservation
// alive after that authenticated inbound has ended.
func TestAdmissionLiveXUDPDisconnectReleasesReservation(t *testing.T) {
	target, err := stdnet.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer target.Close()
	go func() {
		var packet [256]byte
		for {
			n, peer, err := target.ReadFrom(packet[:])
			if err != nil {
				return
			}
			_, _ = target.WriteTo(packet[:n], peer)
		}
	}()
	l, err := stdnet.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := l.Addr().(*stdnet.TCPAddr).Port
	l.Close()
	p := &lifecyclePanel{leases: make(map[string]lifecycleLease)}
	x := New(config.KernelConfig{Type: "xray", LogLevel: "error"})
	x.SetConnectionAdmission(admission.New(lifecycleCaller{p, 1}).Acquire)
	nc := &model.NodeSpec{Protocol: "vless", ListenIP: "127.0.0.1", ServerPort: port,
		Network: "ws", NetworkSettings: map[string]any{"path": "/connect-ws"},
		CustomRoutes: []map[string]any{{"type": "field", "ip": []string{"127.0.0.1"}, "outboundTag": "direct"}}}
	users := []model.UserSpec{{ID: 7, UUID: "11111111-1111-4111-8111-111111111111", DeviceLimit: 1}}
	if err := x.Start(nc, users, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	defer x.Stop()
	c, err := websocket.Dial(fmt.Sprintf("ws://127.0.0.1:%d/connect-ws", port), "", "http://localhost/")
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	c.PayloadType = websocket.BinaryFrame
	c.SetDeadline(time.Now().Add(3 * time.Second))
	id, _ := hex.DecodeString("11111111111141118111111111111111")
	header := append([]byte{0}, id...)
	header = append(header, 0, 3) // Mux command has no destination header.
	udp := xnet.UDPDestination(xnet.ParseAddress("127.0.0.1"), xnet.Port(target.LocalAddr().(*stdnet.UDPAddr).Port))
	frame := buf.New()
	defer frame.Release()
	frame.UDP = &udp
	meta := mux.FrameMetadata{Target: udp, SessionID: 1, SessionStatus: mux.SessionStatusNew,
		Option: mux.OptionData, GlobalID: [8]byte{1, 2, 3, 4, 5, 6, 7, 8}}
	if err = meta.WriteTo(frame); err != nil {
		t.Fatal(err)
	}
	request := append(header, frame.Bytes()...)
	request = append(request, 0, 4, 'p', 'i', 'n', 'g')
	if _, err = c.Write(request); err != nil {
		t.Fatal(err)
	}
	var response [2]byte
	if _, err = io.ReadFull(c, response[:]); err != nil {
		t.Fatal(err)
	}
	var reply mux.FrameMetadata
	if err = reply.Unmarshal(c, false); err != nil {
		t.Fatal(err)
	}
	if !reply.Option.Has(mux.OptionData) {
		t.Fatal("XUDP echo has no payload")
	}
	if _, err = io.ReadFull(c, response[:]); err != nil {
		t.Fatal(err)
	}
	payload := make([]byte, binary.BigEndian.Uint16(response[:]))
	if _, err = io.ReadFull(c, payload); err != nil || string(payload) != "ping" {
		t.Fatalf("XUDP echo = %q, %v", payload, err)
	}
	if p.count() != 1 {
		t.Fatal("authenticated XUDP connection has no reservation")
	}
	c.Close()
	awaitLifecycle(t, "XUDP reservation survived closing the authenticated WebSocket", func() bool { return p.count() == 0 })
}
