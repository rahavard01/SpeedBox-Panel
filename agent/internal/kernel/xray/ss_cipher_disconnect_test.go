package xray

import (
	"bytes"
	"context"
	"encoding/base64"
	"fmt"
	"io"
	"net"
	"testing"
	"time"

	shadowsocks "github.com/sagernet/sing-shadowsocks"
	"github.com/sagernet/sing-shadowsocks/shadowaead"
	"github.com/sagernet/sing-shadowsocks/shadowaead_2022"
	singM "github.com/sagernet/sing/common/metadata"

	"github.com/cedar2025/xboard-node/internal/admission"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
)

// Diagnostic only: real loopback sockets and the embedded Xray inbound, with
// the existing in-memory panel fixture. No production node or panel is used.
// Check a legacy cipher separately from SS2022; they have different copy paths.
func TestAdmissionLiveShadowsocksCipherDisconnect(t *testing.T) {
	for _, tc := range []struct {
		cipher  string
		keySize int
	}{
		{"aes-128-gcm", 0},
		{"2022-blake3-aes-128-gcm", 16},
		{"2022-blake3-aes-256-gcm", 32},
	} {
		t.Run(tc.cipher, func(t *testing.T) {
			const credential = "11111111-1111-4111-8111-111111111111"
			target, err := net.Listen("tcp4", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			t.Cleanup(func() { target.Close() })
			go func() {
				for {
					c, err := target.Accept()
					if err != nil {
						return
					}
					go func() {
						defer c.Close()
						var header [4]byte
						if _, err := io.ReadFull(c, header[:]); err != nil {
							return
						}
						if string(header[:]) == "HALF" {
							for n := 0; n < 4; n++ {
								time.Sleep(100 * time.Millisecond)
								if _, err := c.Write(bytes.Repeat([]byte{'x'}, 32)); err != nil {
									return
								}
							}
							return
						}
						if _, err := c.Write(header[:]); err != nil {
							return
						}
						_, _ = io.Copy(c, c)
					}()
				}
			}()

			// Match the panel's SS2022 key derivation: truncate/pad UUID bytes.
			var method shadowsocks.Method
			var serverKey string
			if tc.keySize != 0 {
				serverKey = base64.StdEncoding.EncodeToString(bytes.Repeat([]byte{0x42}, tc.keySize))
				userKey := make([]byte, tc.keySize)
				copy(userKey, credential)
				method, err = shadowaead_2022.NewWithPassword(tc.cipher,
					serverKey+":"+base64.StdEncoding.EncodeToString(userKey), nil)
			} else {
				method, err = shadowaead.New(tc.cipher, nil, credential)
			}
			if err != nil {
				t.Fatal("create test client:", err)
			}

			reserved, err := net.Listen("tcp4", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			port := reserved.Addr().(*net.TCPAddr).Port
			reserved.Close()
			panel := &lifecyclePanel{leases: make(map[string]lifecycleLease)}
			gate := admission.New(lifecycleCaller{panel, 1})
			gateCtx, stopGate := context.WithCancel(context.Background())
			gateDone := make(chan struct{})
			go func() { defer close(gateDone); gate.Run(gateCtx) }()
			x := New(config.KernelConfig{Type: "xray", LogLevel: "error"})
			x.SetConnectionAdmission(gate.Acquire)
			t.Cleanup(func() {
				x.Stop()
				stopGate()
				<-gateDone
			})
			nc := &model.NodeSpec{
				Protocol: "shadowsocks", Cipher: tc.cipher, ServerKey: serverKey,
				ListenIP: "127.0.0.1", ServerPort: port,
				CustomRoutes: []map[string]any{{
					"type": "field", "ip": []string{"127.0.0.1"}, "outboundTag": "direct",
				}},
			}
			if err := x.Start(nc, []model.UserSpec{{ID: 7, UUID: credential, DeviceLimit: 1}}, kernel.TLSCert{}); err != nil {
				t.Fatal("start embedded Xray:", err)
			}
			dial := func() (net.Conn, net.Conn) {
				t.Helper()
				raw, err := net.DialTimeout("tcp4", fmt.Sprintf("127.0.0.1:%d", port), 3*time.Second)
				if err != nil {
					t.Fatal("dial test node:", err)
				}
				t.Cleanup(func() { raw.Close() })
				if err := raw.SetDeadline(time.Now().Add(3 * time.Second)); err != nil {
					t.Fatal(err)
				}
				conn, err := method.DialConn(raw, singM.ParseSocksaddr(target.Addr().String()))
				if err != nil {
					t.Fatal("SS handshake:", err)
				}
				return conn, raw
			}
			exchange := func(conn net.Conn, payload string) {
				t.Helper()
				if err := conn.SetDeadline(time.Now().Add(3 * time.Second)); err != nil {
					t.Fatal(err)
				}
				if _, err := io.WriteString(conn, payload); err != nil {
					t.Fatal("write payload:", err)
				}
				reply := make([]byte, len(payload))
				if _, err := io.ReadFull(conn, reply); err != nil {
					t.Fatal("read payload:", err)
				}
				if string(reply) != payload {
					t.Fatalf("echo mismatch: got %q", reply)
				}
				if err := conn.SetDeadline(time.Time{}); err != nil {
					t.Fatal(err)
				}
			}
			awaitState := func(stage string, wantConns int64, wantLeases int) {
				t.Helper()
				deadline := time.Now().Add(3 * time.Second)
				for {
					_, conns := x.limitDispatcher.GetConnectionState()
					leases := panel.count()
					if int64(conns) == wantConns && leases == wantLeases {
						t.Logf("%s: connections=%d leases=%d", stage, conns, leases)
						return
					}
					if !time.Now().Before(deadline) {
						t.Errorf("%s: retained state: connections=%d leases=%d; want connections=%d leases=%d",
							stage, conns, leases, wantConns, wantLeases)
						return
					}
					time.Sleep(10 * time.Millisecond)
				}
			}

			first, firstTCP := dial()
			exchange(first, "first-live")
			second, secondTCP := dial()
			exchange(second, "second-live")
			awaitState("two sockets share one reservation", 2, 1)
			time.Sleep(2 * time.Second)
			awaitState("healthy idle sockets remain reserved", 2, 1)
			exchange(second, "live-after-idle")
			// Close the actual TCP transport. The SS2022 client wrapper's Close
			// can report a duplicate-close error from its reader/writer cleanup.
			if err := firstTCP.Close(); err != nil {
				t.Fatal(err)
			}
			awaitState("first socket closed", 1, 1)
			exchange(second, "second-must-remain-live")
			if err := secondTCP.Close(); err != nil {
				t.Fatal(err)
			}
			awaitState("last socket closed", 0, 0)
			if tc.keySize != 0 {
				half, halfTCP := dial()
				if _, err := io.WriteString(half, "HALF"); err != nil {
					t.Fatal(err)
				}
				tcp, ok := halfTCP.(*net.TCPConn)
				if !ok {
					t.Fatal("test requires a real TCP connection")
				}
				if err := tcp.CloseWrite(); err != nil {
					t.Fatal(err)
				}
				reply := make([]byte, 128)
				if _, err := io.ReadFull(half, reply[:32]); err != nil {
					t.Fatal("half-closed TCP lost first response:", err)
				}
				awaitState("half-closed TCP still downloading", 1, 1)
				if _, err := io.ReadFull(half, reply[32:]); err != nil {
					t.Fatal("half-closed TCP lost remaining response:", err)
				}
				if !bytes.Equal(reply, bytes.Repeat([]byte{'x'}, 128)) {
					t.Fatal("half-close response was corrupted")
				}
				if err := halfTCP.Close(); err != nil {
					t.Fatal(err)
				}
				awaitState("half-closed response finished", 0, 0)
			}
		})
	}
}
