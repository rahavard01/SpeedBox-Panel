package xray

import (
	"context"
	"io"
	"testing"
	"testing/synctest"
	"time"

	"github.com/xtls/xray-core/common/buf"
	"github.com/xtls/xray-core/transport"
	"github.com/xtls/xray-core/transport/pipe"
)

func TestDatagramIdleClosesSocketBeforeReleasingLease(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		p := &lifecyclePanel{leases: map[string]lifecycleLease{}}
		d := newLifecycleDispatcher(p, 1)
		ctx, cancel := lifecycleContext("198.51.100.19")
		defer cancel()
		email, ip, ticket, err := d.admit(ctx)
		if err != nil {
			t.Fatal(err)
		}
		r, w := pipe.New()
		link := &transport.Link{Reader: r, Writer: w}
		writer := d.trackLink(link, email, ip, true, ticket)
		watchDatagram(link, writer, nil, 80*time.Millisecond)
		defer writer.Interrupt()
		reader := link.Reader.(*udpPipeReader)
		// Embedding the pipe preserves XUDP's temporary-EOF recovery contract.
		reader.ReturnAnError(io.EOF)
		if _, err := reader.ReadMultiBuffer(); err != io.EOF {
			t.Fatal("XUDP EOF was lost", err)
		}
		reader.Recover()
		// Incoming and outgoing payloads both extend the inactivity deadline.
		for n := 0; n < 10; n++ {
			b := buf.New()
			b.WriteString("udp")
			if n%2 == 0 {
				err = writer.WriteMultiBuffer(buf.MultiBuffer{b})
			} else {
				err = w.WriteMultiBuffer(buf.MultiBuffer{b})
			}
			if err != nil {
				t.Fatal(err)
			}
			got, err := reader.ReadMultiBufferTimeout(time.Second)
			if err != nil {
				t.Fatal(err)
			}
			buf.ReleaseMulti(got)
			time.Sleep(20 * time.Millisecond)
			if p.count() != 1 {
				t.Fatal("active UDP lost its global reservation")
			}
		}
		awaitLifecycle(t, "inactive UDP retained reservation", func() bool { return p.count() == 0 })
		b := buf.New()
		b.WriteString("late")
		if err = w.WriteMultiBuffer(buf.MultiBuffer{b}); err == nil {
			t.Fatal("expired UDP remained writable after quota release")
		}
		_, count := d.GetConnectionState()
		if count != 0 {
			t.Fatal("expired UDP remained online", count)
		}
	})
}

func TestDatagramDispatchLinkTimeoutCancelsBlockedOutbound(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		r, w := pipe.New()
		link := &transport.Link{Reader: r, Writer: w}
		ctx, cancel := context.WithCancel(context.Background())
		defer cancel()
		writer := &closeTrackingWriter{Writer: w}
		watchDatagram(link, writer, cancel, 20*time.Millisecond)
		defer writer.Interrupt()
		select {
		case <-ctx.Done():
		case <-time.After(time.Second):
			t.Fatal("UDP deadline did not cancel outbound")
		}
	})
}

func TestDatagramRevocationCancelsBlockedOutbound(t *testing.T) {
	r, w := pipe.New()
	link := &transport.Link{Reader: r, Writer: w}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	writer := &closeTrackingWriter{Writer: w}
	watchDatagram(link, writer, cancel, time.Hour)
	writer.Interrupt()
	select {
	case <-ctx.Done():
	default:
		t.Fatal("revoked datagram outbound remained active")
	}
}
