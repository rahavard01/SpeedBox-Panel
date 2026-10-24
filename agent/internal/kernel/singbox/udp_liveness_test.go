package singbox

import (
	"context"
	"testing"
	"testing/synctest"
	"time"

	"github.com/cedar2025/xboard-node/internal/liveness"
	"github.com/sagernet/sing/common/buf"
	singM "github.com/sagernet/sing/common/metadata"
)

func TestUDPIdleTracksBothDirectionsAndZeroCopy(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		tracker := NewConnTracker(0)
		tracker.SetUserMap(map[string]int{"user": 7})
		raw := &originPacket{}
		c := tracker.RoutedPacketConnection(context.Background(), raw, testInboundContext("user", "198.51.100.7"), nil, nil).(*trackedPacketConn)
		defer c.Close()
		for n := 0; n < 8; n++ {
			switch n % 4 {
			case 0:
				b := buf.New()
				_, err := c.ReadPacket(b)
				b.Release()
				if err != nil {
					t.Fatal(err)
				}
			case 1:
				if err := c.WritePacket(buf.As([]byte("reply")), singM.ParseSocksaddr("198.51.100.8:53")); err != nil {
					t.Fatal(err)
				}
			case 2:
				_, counts := c.UnwrapPacketReader()
				for _, count := range counts {
					count(3)
				}
			case 3:
				_, counts := c.UnwrapPacketWriter()
				for _, count := range counts {
					count(5)
				}
			}
			time.Sleep(liveness.UDPIdle / 2)
			if c.closed.Load() {
				t.Fatal("traffic in either direction must keep UDP alive")
			}
		}
		time.Sleep(liveness.UDPIdle)
		synctest.Wait()
		if !c.closed.Load() || !raw.closed {
			t.Fatal("idle UDP socket was not closed")
		}
		_, alive, _ := tracker.GetUserTraffic()
		if len(alive[7]) != 0 {
			t.Fatal("closed UDP still online", alive)
		}
	})
}
