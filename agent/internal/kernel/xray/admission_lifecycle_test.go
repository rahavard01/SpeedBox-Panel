package xray

import (
	"context"
	"fmt"
	"sync"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/admission"
	"github.com/xtls/xray-core/common/net"
	"github.com/xtls/xray-core/common/protocol"
	"github.com/xtls/xray-core/common/session"
	"github.com/xtls/xray-core/features/routing"
	"github.com/xtls/xray-core/transport"
	"github.com/xtls/xray-core/transport/pipe"
)

// Exercise the real admission manager and dispatcher together. The panel
// fixture enforces the same global, distinct-source-IP limit of one.
type lifecyclePanel struct {
	mu     sync.Mutex
	leases map[string]lifecycleLease
}

type lifecycleLease struct {
	uid    int
	ip, id string
}
type lifecycleCaller struct {
	panel *lifecyclePanel
	node  int
}

func (c lifecycleCaller) ConnectionLease(_ context.Context, op string, uid int, ip, id string) (bool, int, error) {
	p := c.panel
	p.mu.Lock()
	defer p.mu.Unlock()
	key := fmt.Sprintf("%d/%d/%s", c.node, uid, ip)
	old, exists := p.leases[key]
	if op == "release" {
		if exists && old.id == id {
			delete(p.leases, key)
		}
		return true, 0, nil
	}
	if op == "renew" && (!exists || old.id != id) {
		return false, 0, nil
	}
	for _, lease := range p.leases {
		if lease.uid == uid && lease.ip != ip {
			return false, 0, nil
		}
	}
	p.leases[key] = lifecycleLease{uid, ip, id}
	return true, 60, nil
}

func (p *lifecyclePanel) count() int {
	p.mu.Lock()
	defer p.mu.Unlock()
	return len(p.leases)
}

// A terminal outbound may return without closing the supplied writer. The
// inbound context still ends, which must release the connection reservation.
type lifecycleDispatcher struct{ routing.Dispatcher }

func (lifecycleDispatcher) Dispatch(context.Context, net.Destination) (*transport.Link, error) {
	r, w := pipe.New()
	return &transport.Link{Reader: r, Writer: w}, nil
}

func (lifecycleDispatcher) DispatchLink(context.Context, net.Destination, *transport.Link) error {
	return nil
}

func newLifecycleDispatcher(p *lifecyclePanel, node int) *LimitDispatcher {
	d := newTestDispatcher()
	d.innerDisp = lifecycleDispatcher{}
	d.UpdateLimits(map[string]int{userEmail(7): 7}, map[string]int{userEmail(7): 1}, nil)
	d.SetConnectionAdmission(admission.New(lifecycleCaller{p, node}).Acquire)
	return d
}

func lifecycleContext(ip string) (context.Context, context.CancelFunc) {
	ctx, cancel := context.WithCancel(context.Background())
	return session.ContextWithInbound(ctx, &session.Inbound{
		User:   &protocol.MemoryUser{Email: userEmail(7)},
		Source: net.TCPDestination(net.ParseAddress(ip), 12345),
	}), cancel
}

func awaitLifecycle(t *testing.T, description string, fn func() bool) {
	t.Helper()
	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		if fn() {
			return
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatal(description)
}

func TestAdmissionLifecycleCancelledInboundReleasesReservation(t *testing.T) {
	p := &lifecyclePanel{leases: make(map[string]lifecycleLease)}
	d := newLifecycleDispatcher(p, 1)
	ctx, cancel := lifecycleContext("198.51.100.19")
	defer cancel()
	link, err := d.Dispatch(ctx, net.TCPDestination(net.ParseAddress("192.0.2.1"), 443))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { link.Writer.(*closeTrackingWriter).Interrupt() })
	if _, ok := link.Reader.(*pipe.Reader); !ok {
		t.Fatal("XUDP pipe reader was replaced")
	}
	if p.count() != 1 {
		t.Fatal("active connection has no reservation")
	}
	cancel() // The transport has ended; no explicit writer close follows.
	awaitLifecycle(t, "ended inbound kept its reservation", func() bool { return p.count() == 0 })
	_, count := d.GetConnectionState()
	if count != 0 {
		t.Fatalf("connection count after cancellation = %d", count)
	}
}

func TestAdmissionLifecycleSuccessfulDispatchLinkReleasesReservation(t *testing.T) {
	p := &lifecyclePanel{leases: make(map[string]lifecycleLease)}
	d := newLifecycleDispatcher(p, 1)
	ctx, cancel := lifecycleContext("198.51.100.19")
	defer cancel()
	r, w := pipe.New()
	link := &transport.Link{Reader: r, Writer: w}
	t.Cleanup(func() { link.Writer.(*closeTrackingWriter).Interrupt() })
	if err := d.DispatchLink(ctx, net.TCPDestination(net.ParseAddress("192.0.2.1"), 443), link); err != nil {
		t.Fatal(err)
	}
	awaitLifecycle(t, "completed DispatchLink kept its reservation", func() bool { return p.count() == 0 })
}

func TestAdmissionLifecycleOneCancelledLinkKeepsOtherLinkReserved(t *testing.T) {
	p := &lifecyclePanel{leases: make(map[string]lifecycleLease)}
	d := newLifecycleDispatcher(p, 1)
	ctx1, cancel1 := lifecycleContext("198.51.100.19")
	ctx2, cancel2 := lifecycleContext("198.51.100.19")
	defer cancel1()
	defer cancel2()
	dest := net.TCPDestination(net.ParseAddress("192.0.2.1"), 443)
	a, err := d.Dispatch(ctx1, dest)
	if err != nil {
		t.Fatal(err)
	}
	b, err := d.Dispatch(ctx2, dest)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { a.Writer.(*closeTrackingWriter).Interrupt(); b.Writer.(*closeTrackingWriter).Interrupt() })
	cancel1()
	awaitLifecycle(t, "cancelled link was still counted", func() bool { _, n := d.GetConnectionState(); return n == 1 })
	if p.count() != 1 {
		t.Fatal("active second link lost its reservation")
	}
	other, stop := lifecycleContext("198.51.100.20")
	defer stop()
	if _, err := d.Dispatch(other, dest); err == nil {
		t.Fatal("second IP bypassed the global limit")
	}
	cancel2()
	awaitLifecycle(t, "last ended link kept its reservation", func() bool { return p.count() == 0 })
}

func TestAdmissionLifecycleTenNodesAndConcurrentClose(t *testing.T) {
	p := &lifecyclePanel{leases: make(map[string]lifecycleLease)}
	var links []*transport.Link
	var cancels []context.CancelFunc
	var dispatchers []*LimitDispatcher
	dest := net.TCPDestination(net.ParseAddress("192.0.2.1"), 443)
	for i := 1; i <= 10; i++ {
		d := newLifecycleDispatcher(p, i)
		ctx, cancel := lifecycleContext("198.51.100.19")
		link, err := d.Dispatch(ctx, dest)
		if err != nil {
			cancel()
			t.Fatal(err)
		}
		links = append(links, link)
		cancels = append(cancels, cancel)
		dispatchers = append(dispatchers, d)
	}
	t.Cleanup(func() {
		for i, l := range links {
			cancels[i]()
			l.Writer.(*closeTrackingWriter).Interrupt()
		}
	})
	if p.count() != 10 {
		t.Fatal("one IP was not admitted across all ten nodes")
	}
	var wg sync.WaitGroup
	for i, link := range links {
		w := link.Writer.(*closeTrackingWriter)
		wg.Add(3)
		go func() { defer wg.Done(); cancels[i]() }()
		go func() { defer wg.Done(); w.Close() }()
		go func() { defer wg.Done(); w.Interrupt() }()
	}
	wg.Wait()
	awaitLifecycle(t, "reservations survived concurrent shutdown", func() bool { return p.count() == 0 })
	for _, d := range dispatchers {
		_, n := d.GetConnectionState()
		if n != 0 {
			t.Fatalf("double cleanup corrupted count: %d", n)
		}
	}
}
