package liveness

import (
	"sync"
	"sync/atomic"
	"testing"
	"testing/synctest"
	"time"
)

func TestIdleTrafficAndStop(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		var expired atomic.Int32
		i := NewIdle(80*time.Millisecond, func() { expired.Add(1) })
		defer i.Stop()
		for n := 0; n < 12; n++ {
			i.Touch()
			time.Sleep(15 * time.Millisecond)
			if expired.Load() != 0 {
				t.Fatal("active datagram flow expired")
			}
		}
		deadline := time.Now().Add(time.Second)
		for expired.Load() == 0 && time.Now().Before(deadline) {
			time.Sleep(time.Millisecond)
		}
		if expired.Load() != 1 {
			t.Fatal("inactive flow did not expire once", expired.Load())
		}
		i.Touch()
		i.Stop()
		time.Sleep(100 * time.Millisecond)
		if expired.Load() != 1 {
			t.Fatal("expired flow was restarted")
		}
		i = NewIdle(40*time.Millisecond, func() { expired.Add(1) })
		var wg sync.WaitGroup
		for n := 0; n < 20; n++ {
			wg.Add(1)
			go func() { defer wg.Done(); i.Touch(); i.Stop() }()
		}
		wg.Wait()
		time.Sleep(80 * time.Millisecond)
		if expired.Load() != 1 {
			t.Fatal("stopped flow expired")
		}
	})
}
