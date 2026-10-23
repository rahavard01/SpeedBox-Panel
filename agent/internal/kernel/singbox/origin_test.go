package singbox

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/admission"
	"github.com/cedar2025/xboard-node/internal/origin"
	"github.com/sagernet/sing-box/adapter"
	"github.com/sagernet/sing/common/buf"
	singM "github.com/sagernet/sing/common/metadata"
	"google.golang.org/grpc/peer"
)

func singOriginBroker(t *testing.T) *origin.Registry {
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
	for i, ip := range []string{"198.51.100.7", "203.0.113.9"} {
		c, e := net.Dial("unix", path)
		if e != nil {
			t.Fatal(e)
		}
		t.Cleanup(func() { c.Close() })
		c.SetDeadline(time.Now().Add(time.Second))
		json.NewEncoder(c).Encode(origin.Registration{Version: 1, SourcePort: uint16(43001 + i), TargetPort: 2087, OriginalIP: ip})
		line, e := bufio.NewReader(c).ReadString('\n')
		if e != nil || line != "OK\n" {
			t.Fatal(line, e)
		}
		c.SetDeadline(time.Time{})
	}
	return r
}

type originPacket struct{ testConn }

func (c *originPacket) ReadPacket(b *buf.Buffer) (singM.Socksaddr, error) {
	b.Write([]byte("abc"))
	return singM.ParseSocksaddr("198.51.100.20:53"), nil
}
func (c *originPacket) WritePacket(b *buf.Buffer, _ singM.Socksaddr) error { b.Release(); return nil }
func TestTrustedOriginSingboxTCPUDPQuotaAndTraffic(t *testing.T) {
	r := singOriginBroker(t)
	tracker := NewConnTracker(0)
	tracker.originPolicy = origin.Policy{Required: true, Port: 2087, Registry: r}
	tracker.SetUserMap(map[string]int{"user": 1})
	tracker.SetConnectionAdmission(func(uid int, ip string) (*admission.Ticket, error) {
		if uid != 1 || ip != "198.51.100.7" {
			return nil, errors.New("limit exceeded")
		}
		return nil, nil
	})
	meta := adapter.InboundContext{User: "user", Source: singM.Socksaddr{Addr: netip.MustParseAddr("127.0.0.1"), Port: 43001}}
	tcp := tracker.RoutedConnection(context.Background(), &testConn{reads: [][]byte{[]byte("abc")}}, meta, nil, nil)
	tcp.Read(make([]byte, 3))
	tcp.Write([]byte("de"))
	rawUDP := &originPacket{}
	udp := tracker.RoutedPacketConnection(context.Background(), rawUDP, meta, nil, nil)
	b := buf.New()
	udp.ReadPacket(b)
	b.Release()
	udp.WritePacket(buf.As([]byte("de")), singM.ParseSocksaddr("198.51.100.20:53"))
	traffic, alive, _ := tracker.GetUserTraffic()
	if traffic[1] != [2]int64{6, 4} {
		t.Fatal("identity restoration changed traffic counters", traffic)
	}
	if !alive[1]["198.51.100.7"] || alive[1]["127.0.0.1"] {
		t.Fatal("wrong online identity", alive)
	}
	meta.Source.Port = 43002
	denied := &testConn{}
	tracker.RoutedConnection(context.Background(), denied, meta, nil, nil)
	if !denied.closed {
		t.Fatal("second source bypassed the gate")
	}
	meta.Source.Port = 60000
	unknown := &originPacket{}
	tracker.RoutedPacketConnection(context.Background(), unknown, meta, nil, nil)
	if !unknown.closed {
		t.Fatal("missing UDP origin accepted")
	}
	tcp.Close()
	udp.Close()
	_, alive, _ = tracker.GetUserTraffic()
	if len(alive[1]) != 0 {
		t.Fatal("closed links left online state", alive)
	}
}

type originPeer struct{ ctx context.Context }

func (c originPeer) Context() context.Context { return c.ctx }

type originUpstream struct{ value any }

func (c originUpstream) UpstreamWriter() any { return c.value }
func TestTrustedOriginSingboxGRPCIgnoresForgedForwardingHeader(t *testing.T) {
	r := singOriginBroker(t)
	tracker := NewConnTracker(0)
	tracker.originPolicy = origin.Policy{Required: true, Port: 2087, Registry: r}
	tracker.originTransport = "grpc"
	meta := adapter.InboundContext{Source: singM.ParseSocksaddr("203.0.113.9:43002")}
	real := originPeer{peer.NewContext(context.Background(), &peer.Peer{Addr: &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 43001}})}
	ip, err := tracker.sourceIdentity(originUpstream{real}, meta)
	if err != nil || ip != "198.51.100.7" {
		t.Fatal("public header replaced the physical peer", ip, err)
	}
	if _, err = tracker.sourceIdentity(struct{}{}, meta); err == nil {
		t.Fatal("unverifiable gRPC metadata accepted")
	}
}
