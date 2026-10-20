package kernel_test

import (
	"bufio"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/kernel/singbox"
	"github.com/cedar2025/xboard-node/internal/kernel/xray"
	"github.com/cedar2025/xboard-node/internal/model"
	"golang.org/x/net/proxy"
)

// Real kernel sockets, with a local SOCKS stand-in instead of a Cloudflare account.
// Both same-protocol nodes live at the same time; no production host is contacted.
func TestWarpLiveRoutingToggleAndFailureIsolation(t *testing.T) {
	for _, name := range []string{"xray", "singbox"} {
		t.Run(name, func(t *testing.T) {
			upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = io.WriteString(w, "DIRECT") }))
			defer upstream.Close()
			_, targetPort, _ := net.SplitHostPort(strings.TrimPrefix(upstream.URL, "http://"))
			target := "localhost:" + targetPort
			listener, err := net.Listen("tcp4", "127.0.0.1:40000")
			if err != nil {
				t.Fatalf("WARP test port 40000 must be free: %v", err)
			}
			defer listener.Close()
			var proxyHits atomic.Int32
			go func() {
				for {
					conn, err := listener.Accept()
					if err != nil {
						return
					}
					go func() {
						defer conn.Close()
						_ = conn.SetDeadline(time.Now().Add(4 * time.Second))
						var greeting [2]byte
						if _, err := io.ReadFull(conn, greeting[:]); err != nil {
							return
						}
						_, _ = io.CopyN(io.Discard, conn, int64(greeting[1]))
						_, _ = conn.Write([]byte{5, 0})
						var head [4]byte
						if _, err := io.ReadFull(conn, head[:]); err != nil {
							return
						}
						n := 4
						if head[3] == 3 {
							var length [1]byte
							_, _ = io.ReadFull(conn, length[:])
							n = int(length[0])
						}
						if head[3] == 4 {
							n = 16
						}
						_, _ = io.CopyN(io.Discard, conn, int64(n+2))
						_, _ = conn.Write([]byte{5, 0, 0, 1, 127, 0, 0, 1, 0, 0})
						if _, err := http.ReadRequest(bufio.NewReader(conn)); err != nil {
							return
						}
						proxyHits.Add(1)
						_, _ = io.WriteString(conn, "HTTP/1.1 200 OK\r\nContent-Length: 4\r\nConnection: close\r\n\r\nWARP")
					}()
				}
			}()
			create := func() kernel.Kernel {
				if name == "xray" {
					return xray.New(config.KernelConfig{Type: name, LogLevel: "error"})
				}
				return singbox.New(config.KernelConfig{Type: name, LogLevel: "error"})
			}
			port := func() int {
				l, err := net.Listen("tcp4", "127.0.0.1:0")
				if err != nil {
					t.Fatal(err)
				}
				p := l.Addr().(*net.TCPAddr).Port
				_ = l.Close()
				return p
			}
			warpSpec := &model.NodeSpec{Protocol: "socks", ListenIP: "127.0.0.1", ServerPort: port(), WarpEnabled: true}
			directSpec := &model.NodeSpec{Protocol: "socks", ListenIP: "127.0.0.1", ServerPort: port()}
			users := []model.UserSpec{{ID: 1, UUID: "b75df261-8aa4-4368-ac45-d23f22536270"}}
			warpNode, directNode := create(), create()
			if err := warpNode.Start(warpSpec, users, kernel.TLSCert{}); err != nil {
				if name == "singbox" && strings.Contains(err.Error(), "operation not permitted") {
					// Compare with WARP disabled before attributing a sandbox limitation.
					baseline := create()
					if baselineErr := baseline.Start(directSpec, users, kernel.TLSCert{}); baselineErr != nil && strings.Contains(baselineErr.Error(), "operation not permitted") {
						t.Skip("container denies Sing-box network-monitor startup with and without WARP; run this socket test on the local host")
					}
					baseline.Stop()
				}
				t.Fatal(err)
			}
			defer warpNode.Stop()
			if err := directNode.Start(directSpec, users, kernel.TLSCert{}); err != nil {
				t.Fatal(err)
			}
			defer directNode.Stop()
			request := func(port int) (string, error) {
				dialer, err := proxy.SOCKS5("tcp", net.JoinHostPort("127.0.0.1", strconv.Itoa(port)), &proxy.Auth{User: users[0].UUID, Password: users[0].UUID}, &net.Dialer{Timeout: 2 * time.Second})
				if err != nil {
					return "", err
				}
				conn, err := dialer.Dial("tcp", target)
				if err != nil {
					return "", err
				}
				defer conn.Close()
				_ = conn.SetDeadline(time.Now().Add(3 * time.Second))
				_, err = fmt.Fprintf(conn, "GET / HTTP/1.1\r\nHost: %s\r\nConnection: close\r\n\r\n", target)
				if err != nil {
					return "", err
				}
				response, err := http.ReadResponse(bufio.NewReader(conn), nil)
				if err != nil {
					return "", err
				}
				defer response.Body.Close()
				body, err := io.ReadAll(response.Body)
				return string(body), err
			}
			if body, err := request(warpSpec.ServerPort); err != nil || body != "WARP" {
				t.Fatalf("enabled node: %q %v", body, err)
			}
			if body, err := request(directSpec.ServerPort); err != nil || body != "DIRECT" {
				t.Fatalf("other same-protocol node: %q %v", body, err)
			}
			if proxyHits.Load() != 1 {
				t.Fatalf("other node leaked into WARP: %d hits", proxyHits.Load())
			}
			warpSpec.WarpEnabled = false
			if err := warpNode.Reload(warpSpec, users, kernel.TLSCert{}); err != nil {
				t.Fatal(err)
			}
			var disabledBody string
			var disabledErr error
			for attempt := 0; attempt < 20; attempt++ {
				disabledBody, disabledErr = request(warpSpec.ServerPort)
				if disabledErr == nil && disabledBody == "DIRECT" {
					break
				}
				time.Sleep(50 * time.Millisecond)
			}
			if disabledErr != nil || disabledBody != "DIRECT" {
				t.Fatalf("disable: %q %v", disabledBody, disabledErr)
			}
			if proxyHits.Load() != 1 {
				t.Fatal("disabled node still uses WARP")
			}
			_ = listener.Close()
			warpSpec.WarpEnabled = true
			if err := warpNode.Reload(warpSpec, users, kernel.TLSCert{}); err != nil {
				t.Fatal(err)
			}
			time.Sleep(100 * time.Millisecond) // let the old instance finish closing its listener
			if body, err := request(warpSpec.ServerPort); err == nil {
				t.Fatalf("WARP failure unexpectedly succeeded: %q", body)
			}
			if body, err := request(directSpec.ServerPort); err != nil || body != "DIRECT" {
				t.Fatalf("WARP failure affected other node: %q %v", body, err)
			}
		})
	}
}
