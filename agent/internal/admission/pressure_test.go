package admission

import (
	"context"
	"errors"
	"fmt"
	"sync"
	"sync/atomic"
	"testing"
	"testing/synctest"
	"time"
)

type pressureCaller struct {
	fn func(context.Context, string, int, string, string) (bool, int, error)
}

func (c pressureCaller) ConnectionLease(ctx context.Context, op string, uid int, ip, id string) (bool, int, error) {
	return c.fn(ctx, op, uid, ip, id)
}
func pressureTestManager(t *testing.T, c Caller) *Manager {
	t.Helper()
	limits := defaultPressureLimits
	limits.rate = 10000
	limits.burst = 10000
	g := newPressureGate(limits)
	t.Cleanup(func() { g.shutdown() })
	return newManager(c, g)
}
func peak(counter *atomic.Int64, n int64) {
	for old := counter.Load(); n > old; old = counter.Load() {
		if counter.CompareAndSwap(old, n) {
			break
		}
	}
}

func TestPressure700ReconnectsBoundPendingAndRPC(t *testing.T) {
	var active, maximum, calls atomic.Int64
	block := make(chan struct{})
	c := pressureCaller{fn: func(ctx context.Context, op string, _ int, _, _ string) (bool, int, error) {
		if op != "acquire" {
			return true, 0, nil
		}
		calls.Add(1)
		n := active.Add(1)
		peak(&maximum, n)
		defer active.Add(-1)
		select {
		case <-ctx.Done():
			return false, 0, ctx.Err()
		case <-block:
			return true, 60, nil
		}
	}}
	m := pressureTestManager(t, c)
	var wg sync.WaitGroup
	var refused atomic.Int64
	start := make(chan struct{})
	for i := 0; i < 700; i++ {
		wg.Add(1)
		go func(uid int) {
			defer wg.Done()
			<-start
			_, err := m.Acquire(uid, "198.18.0.1")
			if errors.Is(err, ErrAdmissionBusy) {
				refused.Add(1)
			} else if err != nil {
				t.Errorf("unexpected acquire error: %v", err)
			}
		}(i + 1)
	}
	close(start)
	await(t, func() bool { return calls.Load() == 2 })
	if n := len(m.pressure.pending); n > 64 {
		t.Fatalf("pending=%d", n)
	}
	time.Sleep(250 * time.Millisecond)
	close(block)
	wg.Wait()
	if maximum.Load() > 2 {
		t.Fatalf("in-flight=%d", maximum.Load())
	}
	if refused.Load() < 600 {
		t.Fatalf("unbounded storm admitted: refused=%d", refused.Load())
	}
	if len(m.pressure.pending) != 0 || len(m.pressure.slots) != 0 {
		t.Fatal("queue reservations leaked")
	}
}

func TestPressureQueueWaitIsWithin200msAndShared3sBudget(t *testing.T) {
	entered := make(chan struct{}, 1)
	block := make(chan struct{})
	var budget atomic.Int64
	c := pressureCaller{fn: func(ctx context.Context, op string, uid int, _, _ string) (bool, int, error) {
		if op != "acquire" {
			return true, 0, nil
		}
		if uid == 1 {
			entered <- struct{}{}
			select {
			case <-block:
			case <-ctx.Done():
				return false, 0, ctx.Err()
			}
		}
		if uid == 3 {
			deadline, _ := ctx.Deadline()
			budget.Store(int64(time.Until(deadline)))
		}
		return true, 60, nil
	}}
	m := pressureTestManager(t, c)
	m.pressure.slots = make(chan struct{}, 1)
	done := make(chan struct{})
	go func() { defer close(done); m.Acquire(1, "198.18.0.1") }()
	<-entered
	start := time.Now()
	_, err := m.Acquire(2, "198.18.0.2")
	elapsed := time.Since(start)
	if !errors.Is(err, ErrAdmissionBusy) || elapsed < 150*time.Millisecond || elapsed > 700*time.Millisecond {
		t.Fatalf("queue wait=%v err=%v", elapsed, err)
	}
	go func() { time.Sleep(100 * time.Millisecond); close(block) }()
	if _, err = m.Acquire(3, "198.18.0.3"); err != nil {
		t.Fatal(err)
	}
	<-done
	if left := time.Duration(budget.Load()); left > 2950*time.Millisecond || left < 2400*time.Millisecond {
		t.Fatalf("queue was added to RPC budget: %v", left)
	}
}

func TestPressureNormalConnectionAndSameIPReuseSkipQueue(t *testing.T) {
	var calls atomic.Int64
	c := pressureCaller{fn: func(context.Context, string, int, string, string) (bool, int, error) {
		calls.Add(1)
		return true, 60, nil
	}}
	m := pressureTestManager(t, c)
	a, err := m.Acquire(1, "198.18.1.1")
	if err != nil {
		t.Fatal(err)
	}
	// Saturate all slots and place the panel in a retry pause. Existing identity still works.
	m.pressure.slots <- struct{}{}
	m.pressure.slots <- struct{}{}
	m.pressure.observe(ErrTransientLease)
	start := time.Now()
	b, err := m.Acquire(1, "198.18.1.1")
	if err != nil || b.entry != a.entry || calls.Load() != 1 || time.Since(start) > 100*time.Millisecond {
		t.Fatalf("existing identity queued: %v", err)
	}
	<-m.pressure.slots
	<-m.pressure.slots
}

func TestPressureSameIdentityAcquisitionsStillCoalesce(t *testing.T) {
	block := make(chan struct{})
	var calls atomic.Int64
	c := pressureCaller{fn: func(ctx context.Context, op string, _ int, _, _ string) (bool, int, error) {
		if op == "acquire" {
			calls.Add(1)
			select {
			case <-block:
			case <-ctx.Done():
				return false, 0, ctx.Err()
			}
		}
		return true, 60, nil
	}}
	m := pressureTestManager(t, c)
	var wg sync.WaitGroup
	for i := 0; i < 100; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			_, err := m.Acquire(1, "198.18.1.1")
			if err != nil {
				t.Error(err)
			}
		}()
	}
	await(t, func() bool { return calls.Load() == 1 })
	close(block)
	wg.Wait()
	if calls.Load() != 1 || len(m.pressure.pending) != 0 {
		t.Fatal("identity coalescing was lost")
	}
}

func TestPressureRateLimitAndTransientCooldownDoNotChangeDenials(t *testing.T) {
	var calls atomic.Int64
	c := pressureCaller{fn: func(context.Context, string, int, string, string) (bool, int, error) {
		calls.Add(1)
		return false, 0, nil
	}}
	g := newPressureGate(defaultPressureLimits)
	t.Cleanup(func() { g.shutdown() })
	m := newManager(c, g)
	for i := 1; i <= 4; i++ {
		if _, err := m.Acquire(i, "198.18.2.1"); err == nil {
			t.Fatal("denied grant admitted")
		}
	}
	if g.cooldowns.Load() != 0 {
		t.Fatal("business denial treated as network failure")
	}
	start := time.Now()
	m.Acquire(5, "198.18.2.1")
	if elapsed := time.Since(start); elapsed < 70*time.Millisecond || elapsed > 700*time.Millisecond {
		t.Fatalf("rate spacing=%v", elapsed)
	}
	g.observe(ErrTransientLease)
	before := calls.Load()
	if _, err := m.Acquire(6, "198.18.2.1"); !errors.Is(err, ErrAdmissionBusy) {
		t.Fatalf("cooldown=%v", err)
	}
	if calls.Load() != before {
		t.Fatal("cooldown sent another RPC")
	}
	g.mu.Lock()
	until := g.retryUntil
	g.mu.Unlock()
	if left := time.Until(until); left < 400*time.Millisecond || left > 800*time.Millisecond {
		t.Fatalf("first jitter pause=%v", left)
	}
}

func TestPressureRenewalBypassesSaturatedAcquireAndReleaseLanes(t *testing.T) {
	f := &batchFixture{size: 64, batch: func(in []Renewal) ([]RenewalResult, error) { return goodResults(in), nil }}
	m, tickets := dueFixture(t, f, 120)
	var closed atomic.Int64
	for _, ticket := range tickets {
		ticket.Bind(func() { closed.Add(1) })
	}
	m.pressure.slots <- struct{}{}
	m.pressure.slots <- struct{}{}
	m.pressure.observe(ErrTransientLease)
	old := tickets[0].entry.deadline
	m.renewDue(time.Now())
	await(t, func() bool { return idle(m) })
	if f.items.Load() != 120 || closed.Load() != 0 {
		t.Fatalf("renewals starved: items=%d closed=%d", f.items.Load(), closed.Load())
	}
	if !tickets[0].entry.deadline.After(old) {
		t.Fatal("valid renewal did not extend normal deadline")
	}
	<-m.pressure.slots
	<-m.pressure.slots
}

func TestPressureReleasesBoundedDeduplicatedAndIndependent(t *testing.T) {
	block := make(chan struct{})
	var releaseActive, maximum, calls atomic.Int64
	c := pressureCaller{fn: func(ctx context.Context, op string, _ int, _, _ string) (bool, int, error) {
		if op == "release" {
			calls.Add(1)
			n := releaseActive.Add(1)
			peak(&maximum, n)
			defer releaseActive.Add(-1)
			select {
			case <-block:
			case <-ctx.Done():
				return false, 0, ctx.Err()
			}
		}
		return true, 60, nil
	}}
	m := pressureTestManager(t, c)
	tickets := make([]*Ticket, 700)
	for i := range tickets {
		ticket, err := m.Acquire(i+1, "198.18.3.1")
		if err != nil {
			t.Fatal(err)
		}
		tickets[i] = ticket
	}
	m.pressure.slots <- struct{}{}
	m.pressure.slots <- struct{}{}
	for _, ticket := range tickets {
		ticket.Release()
		m.pressure.release(c, ticket.entry)
	}
	await(t, func() bool { return calls.Load() == 1 })
	if n := m.pressure.stats().ReleasePending; n != 700 {
		t.Fatalf("release queue=%d", n)
	}
	if m.pressure.releaseOverflow.Load() != 0 {
		t.Fatal("700 releases overflowed the bounded queue")
	}
	close(block)
	await(t, func() bool { return m.pressure.stats().ReleasePending == 0 })
	if maximum.Load() != 1 || calls.Load() != 700 {
		t.Fatalf("releases duplicated/unbounded: max=%d calls=%d", maximum.Load(), calls.Load())
	}
	<-m.pressure.slots
	<-m.pressure.slots
}

func TestPressureReleaseOverflowAndRetryAreBounded(t *testing.T) {
	block := make(chan struct{})
	var calls atomic.Int64
	c := pressureCaller{fn: func(ctx context.Context, _ string, _ int, _, _ string) (bool, int, error) {
		calls.Add(1)
		select {
		case <-block:
		case <-ctx.Done():
			return false, 0, ctx.Err()
		}
		return false, 0, ErrTransientLease
	}}
	limits := defaultPressureLimits
	limits.releaseCapacity = 4
	g := newPressureGate(limits)
	t.Cleanup(func() { g.shutdown() })
	for i := 0; i < 100; i++ {
		g.release(c, &entry{uid: i + 1, id: fmt.Sprint(i), ip: "198.18.4.1", started: time.Now()})
	}
	if n := g.stats().ReleasePending; n != 4 {
		t.Fatalf("queue not bounded: %d", n)
	}
	if g.releaseOverflow.Load() != 96 {
		t.Fatalf("overflow invisible: %d", g.releaseOverflow.Load())
	}
	close(block)
	await(t, func() bool { return calls.Load() >= 4 })
	// Cancel cleanup rather than waiting for retries; the worker context must terminate.
	g.shutdown()
	if calls.Load() > 12 {
		t.Fatal("unbounded release retry")
	}
}

func TestPressureStoppedManagerAndLateAcquireCannotGrant(t *testing.T) {
	entered := make(chan struct{})
	block := make(chan struct{})
	var releases atomic.Int64
	c := pressureCaller{fn: func(ctx context.Context, op string, _ int, _, _ string) (bool, int, error) {
		if op == "release" {
			releases.Add(1)
			return true, 0, nil
		}
		close(entered)
		<-block
		return true, 60, nil
	}}
	m := pressureTestManager(t, c)
	ctx, cancel := context.WithCancel(context.Background())
	runDone := make(chan struct{})
	go func() { m.Run(ctx); close(runDone) }()
	acquired := make(chan error, 1)
	go func() { _, err := m.Acquire(1, "198.18.5.1"); acquired <- err }()
	<-entered
	cancel()
	<-runDone
	close(block)
	if err := <-acquired; err == nil {
		t.Fatal("stopped manager granted a connection")
	}
	await(t, func() bool { return releases.Load() == 1 })
	fast := make(chan struct{})
	go func() {
		defer close(fast)
		if _, err := m.Acquire(2, "198.18.5.2"); err == nil {
			t.Error("stopped manager admitted")
		}
	}()
	select {
	case <-fast:
	case <-time.After(100 * time.Millisecond):
		t.Fatal("stopped manager blocked on a reservation it does not own")
	}
}

func TestPressureOldLeaseCleanupPreservesNewOwner(t *testing.T) {
	var mu sync.Mutex
	owner := ""
	releaseGate := make(chan struct{})
	var first atomic.Bool
	c := pressureCaller{fn: func(ctx context.Context, op string, _ int, _, id string) (bool, int, error) {
		if op == "release" {
			if first.CompareAndSwap(false, true) {
				select {
				case <-releaseGate:
				case <-ctx.Done():
					return false, 0, ctx.Err()
				}
			}
			mu.Lock()
			if owner == id {
				owner = ""
			}
			mu.Unlock()
			return true, 0, nil
		}
		mu.Lock()
		owner = id
		mu.Unlock()
		return true, 60, nil
	}}
	m := pressureTestManager(t, c)
	old, err := m.Acquire(1, "198.18.6.1")
	if err != nil {
		t.Fatal(err)
	}
	old.Release()
	fresh, err := m.Acquire(1, "198.18.6.1")
	if err != nil {
		t.Fatal(err)
	}
	close(releaseGate)
	await(t, func() bool { return m.pressure.stats().ReleasePending == 0 })
	mu.Lock()
	defer mu.Unlock()
	if owner != fresh.entry.id || fresh.entry.id == old.entry.id {
		t.Fatal("delayed cleanup removed the new owner")
	}
}

type pressureBatchCaller struct {
	pressureCaller
	batch func([]Renewal) ([]RenewalResult, error)
}

func (c pressureBatchCaller) LeaseBatchSize() int { return 64 }
func (c pressureBatchCaller) RenewConnectionLeases(_ context.Context, in []Renewal) ([]RenewalResult, error) {
	return c.batch(in)
}

func TestPressureThreeSecondOutageDuring700ReconnectsKeepsEstablishedLeases(t *testing.T) {
	var newCalls, closed atomic.Int64
	c := pressureBatchCaller{pressureCaller: pressureCaller{fn: func(ctx context.Context, op string, uid int, _, _ string) (bool, int, error) {
		if op == "acquire" && uid >= 1000 {
			newCalls.Add(1)
			<-ctx.Done()
			return false, 0, errors.Join(ErrTransientLease, ctx.Err())
		}
		return true, 60, nil
	}}, batch: func(in []Renewal) ([]RenewalResult, error) { return goodResults(in), nil }}
	m := pressureTestManager(t, c)
	tickets := make([]*Ticket, 120)
	for i := range tickets {
		ticket, err := m.Acquire(i+1, "198.18.8.1")
		if err != nil {
			t.Fatal(err)
		}
		tickets[i] = ticket
		ticket.Bind(func() { closed.Add(1) })
	}
	m.mu.Lock()
	for _, e := range m.entries {
		e.renewed = time.Now().Add(-11 * time.Second)
		e.deadline = time.Now().Add(1500 * time.Millisecond)
	}
	m.mu.Unlock()
	var wg sync.WaitGroup
	for i := 0; i < 700; i++ {
		wg.Add(1)
		go func(uid int) {
			defer wg.Done()
			if _, err := m.Acquire(uid, "198.18.8.2"); err == nil {
				t.Error("outage granted a connection")
			}
		}(1000 + i)
	}
	await(t, func() bool { return newCalls.Load() == 2 })
	m.renewDue(time.Now())
	await(t, func() bool { return idle(m) })
	wg.Wait()
	if closed.Load() != 0 {
		t.Fatal("established connections were closed by acquire pressure")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if len(m.entries) != 120 {
		t.Fatalf("entries=%d", len(m.entries))
	}
	for _, ticket := range tickets {
		if time.Until(ticket.entry.deadline) < 45*time.Second {
			t.Fatal("renewal was starved by reconnects")
		}
	}
	if newCalls.Load() != 2 {
		t.Fatalf("timeout amplified into new RPCs: %d", newCalls.Load())
	}
}

func TestPressureIdleReleaserExitsAndRestartsWithoutLeaking(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		g := newPressureGate(defaultPressureLimits)
		var calls atomic.Int64
		c := pressureCaller{fn: func(context.Context, string, int, string, string) (bool, int, error) {
			calls.Add(1)
			return true, 0, nil
		}}
		for i := 0; i < 2; i++ {
			g.release(c, &entry{uid: 1, ip: "198.18.9.1", id: fmt.Sprint(i), started: time.Now()})
			synctest.Wait()
			g.mu.Lock()
			running := g.running
			g.mu.Unlock()
			if running || g.stats().ReleasePending != 0 {
				t.Fatal("empty cleanup worker stayed alive")
			}
		}
		if calls.Load() != 2 {
			t.Fatal("idle worker could not restart")
		}
		// No shutdown call: leaving the bubble itself verifies there is no parked worker.
	})
}
