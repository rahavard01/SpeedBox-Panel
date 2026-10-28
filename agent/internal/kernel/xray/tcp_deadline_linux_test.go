//go:build linux

package xray

import (
	"bufio"
	"encoding/hex"
	"fmt"
	"io"
	"net"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/admission"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
	"golang.org/x/sys/unix"
)

func TestTCPDeadlineDisablesImplicitMPTCP(t *testing.T) {
	var lc net.ListenConfig
	if lc.MultipathTCP() {
		t.Fatal("implicit MPTCP listeners reject TCP_USER_TIMEOUT on Linux")
	}
}

// Inspect the actual embedded Xray listener and its accepted connection, rather
// than just testing generated JSON. MPTCP-capable kernels otherwise hide a
// rejected listener option behind a successful plain-TCP fallback connection.
func assertTCPDeadline(t *testing.T, port int, accepted bool) {
	t.Helper()
	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		entries, err := os.ReadDir("/proc/self/fd")
		if err != nil {
			t.Fatal(err)
		}
		for _, entry := range entries {
			fd, err := strconv.Atoi(entry.Name())
			if err != nil {
				continue
			}
			addr, err := unix.Getsockname(fd)
			if err != nil {
				continue
			}
			localPort := 0
			switch a := addr.(type) {
			case *unix.SockaddrInet4:
				localPort = a.Port
			case *unix.SockaddrInet6:
				localPort = a.Port
			}
			if localPort != port {
				continue
			}
			_, peerErr := unix.Getpeername(fd)
			if (peerErr == nil) != accepted {
				continue
			}
			for _, check := range []struct{ level, option, want int }{
				{unix.SOL_SOCKET, unix.SO_PROTOCOL, unix.IPPROTO_TCP},
				{unix.SOL_SOCKET, unix.SO_KEEPALIVE, 1},
				{unix.IPPROTO_TCP, unix.TCP_KEEPIDLE, 15},
				{unix.IPPROTO_TCP, unix.TCP_KEEPINTVL, 5},
				{unix.IPPROTO_TCP, unix.TCP_USER_TIMEOUT, 15000},
			} {
				got, err := unix.GetsockoptInt(fd, check.level, check.option)
				if err != nil || got != check.want {
					t.Fatalf("accepted=%v option=%d got=%d want=%d err=%v", accepted, check.option, got, check.want, err)
				}
			}
			return
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatalf("Xray socket not found: accepted=%v port=%d", accepted, port)
}

func TestTCPDeadlineLiveHTTPAndPlainDisconnect(t *testing.T) {
	for _, httpObfs := range []bool{false, true} {
		t.Run(fmt.Sprintf("http=%v", httpObfs), func(t *testing.T) {
			target, err := net.Listen("tcp4", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			defer target.Close()
			go func() {
				c, e := target.Accept()
				if e == nil {
					defer c.Close()
					_, _ = io.Copy(c, c)
				}
			}()
			reserve, err := net.Listen("tcp4", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			port := reserve.Addr().(*net.TCPAddr).Port
			reserve.Close()
			panel := &lifecyclePanel{leases: make(map[string]lifecycleLease)}
			x := New(config.KernelConfig{Type: "xray", LogLevel: "error"})
			x.SetConnectionAdmission(admission.New(lifecycleCaller{panel, 1}).Acquire)
			nc := &model.NodeSpec{Protocol: "vless", ListenIP: "127.0.0.1", ServerPort: port, Network: "tcp",
				CustomRoutes: []map[string]any{{"type": "field", "ip": []string{"127.0.0.1"}, "outboundTag": "direct"}}}
			if httpObfs {
				nc.NetworkSettings = map[string]any{"header": map[string]any{"type": "http"}}
			}
			if err = x.Start(nc, []model.UserSpec{{ID: 7, UUID: "11111111-1111-4111-8111-111111111111", DeviceLimit: 1}}, kernel.TLSCert{}); err != nil {
				t.Fatal(err)
			}
			defer x.Stop()
			assertTCPDeadline(t, port, false)
			c, err := net.DialTimeout("tcp4", fmt.Sprintf("127.0.0.1:%d", port), time.Second)
			if err != nil {
				t.Fatal(err)
			}
			defer c.Close()
			c.SetDeadline(time.Now().Add(3 * time.Second))
			assertTCPDeadline(t, port, true)
			if httpObfs {
				if _, err = io.WriteString(c, "GET / HTTP/1.1\r\nHost: example.com\r\n\r\n"); err != nil {
					t.Fatal(err)
				}
			}
			id, _ := hex.DecodeString("11111111111141118111111111111111")
			targetPort := target.Addr().(*net.TCPAddr).Port
			header := append([]byte{0}, id...)
			header = append(header, 0, 1, byte(targetPort>>8), byte(targetPort), 1, 127, 0, 0, 1)
			if _, err = c.Write(append(header, []byte("ping")...)); err != nil {
				t.Fatal(err)
			}
			r := bufio.NewReader(c)
			if httpObfs {
				for {
					line, e := r.ReadString('\n')
					if e != nil {
						t.Fatal(e)
					}
					if strings.TrimSpace(line) == "" {
						break
					}
				}
			}
			var reply [6]byte
			if _, err = io.ReadFull(r, reply[:]); err != nil || string(reply[2:]) != "ping" {
				t.Fatalf("echo=%x err=%v", reply, err)
			}
			if panel.count() != 1 {
				t.Fatal("authenticated connection has no lease")
			}
			c.Close()
			// Xray retains a TCP half-close for its one-second downlink grace.
			limit := time.Now().Add(3 * time.Second)
			for panel.count() != 0 && time.Now().Before(limit) {
				time.Sleep(10 * time.Millisecond)
			}
			awaitLifecycle(t, "closed HTTP/TCP inbound retained the account lease", func() bool { return panel.count() == 0 })
		})
	}
}
