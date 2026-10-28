package l3

import (
	"context"
	"testing"
	"time"
)

// Real encrypted UDP peers with in-memory TUN devices. Move only the previous
// key's grace timestamp forward; no user traffic is allowed to confirm a rekey.
func TestSpeedboxIdleRekeyAcceptsListenerFirst(t *testing.T) {
	for _, encap := range []string{"ipip", "gre"} {
		t.Run(encap, func(t *testing.T) {
			p := established(t, encap, 0)
			packet := ipv4Packet(1, 2, 3, 4)
			across(t, p.dialDev, p.listenDev, packet)
			across(t, p.listenDev, p.dialDev, packet)
			for round := 0; round < 3; round++ {
				ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
				err := p.dialer.negotiate(ctx)
				cancel()
				if err != nil {
					t.Fatal(err)
				}
				p.dialer.mu.RLock()
				id := p.dialer.current.id
				p.dialer.mu.RUnlock()
				p.listener.mu.RLock()
				confirmed := p.listener.current != nil && p.listener.current.id == id && p.listener.pending == nil
				p.listener.mu.RUnlock()
				if !confirmed {
					t.Fatal("rekey returned before the listener confirmed its send keys")
				}
				p.dialer.mu.Lock()
				p.dialer.prevFrom = time.Now().Add(-previousGrace - time.Second)
				p.dialer.mu.Unlock()
				p.dialer.retireSessions(time.Now())
				// This must work without any preceding dialler-side user packet.
				across(t, p.listenDev, p.dialDev, packet)
				across(t, p.dialDev, p.listenDev, packet)
			}
		})
	}
}
