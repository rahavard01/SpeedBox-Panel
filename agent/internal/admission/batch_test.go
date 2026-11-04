package admission

import (
	"context"
	"errors"
	"fmt"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

type batchFixture struct {
	size    int
	singles atomic.Int64
	active  atomic.Int64
	peak    atomic.Int64
	calls   atomic.Int64
	items   atomic.Int64
	batch   func([]Renewal) ([]RenewalResult, error)
	release chan string
}

func (f *batchFixture) ConnectionLease(_ context.Context, op string, _ int, _ string, id string) (bool, int, error) {
	if op == "renew" {
		f.singles.Add(1)
	}
	if op == "release" && f.release != nil {
		f.release <- id
	}
	return true, 60, nil
}
func (f *batchFixture) LeaseBatchSize() int { return f.size }
func (f *batchFixture) RenewConnectionLeases(_ context.Context, in []Renewal) ([]RenewalResult, error) {
	n := f.active.Add(1)
	defer f.active.Add(-1)
	for old := f.peak.Load(); n > old; old = f.peak.Load() {
		if f.peak.CompareAndSwap(old, n) {
			break
		}
	}
	f.calls.Add(1)
	f.items.Add(int64(len(in)))
	if len(in) > 64 {
		panic("oversized batch")
	}
	return f.batch(in)
}
func goodResults(in []Renewal) []RenewalResult {
	out := make([]RenewalResult, len(in))
	for i, item := range in {
		out[len(in)-i-1] = RenewalResult{item, true, 60}
	}
	return out
}
func await(t *testing.T, fn func() bool) {
	t.Helper()
	until := time.Now().Add(2 * time.Second)
	for time.Now().Before(until) {
		if fn() {
			return
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatal("condition timed out")
}
func dueFixture(t *testing.T, f *batchFixture, n int) (*Manager, []*Ticket) {
	t.Helper()
	m := pressureTestManager(t, f)
	tickets := make([]*Ticket, n)
	for i := range tickets {
		ticket, err := m.Acquire(i+1, fmt.Sprintf("198.18.%d.%d", i/250, i%250+1))
		if err != nil {
			t.Fatal(err)
		}
		tickets[i] = ticket
	}
	m.mu.Lock()
	for _, e := range m.entries {
		e.renewed = time.Now().Add(-11 * time.Second)
	}
	m.mu.Unlock()
	return m, tickets
}
func idle(m *Manager) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	for _, e := range m.entries {
		if e.renewing {
			return false
		}
	}
	return true
}

func TestRenewBatchBoundsAndCadence(t *testing.T) {
	gate := make(chan struct{})
	f := &batchFixture{size: 999, batch: func(in []Renewal) ([]RenewalResult, error) { <-gate; return goodResults(in), nil }}
	m, _ := dueFixture(t, f, 150)
	m.renewDue(time.Now())
	await(t, func() bool { return f.active.Load() == 2 })
	if f.peak.Load() > 2 {
		t.Fatal("unbounded concurrency")
	}
	close(gate)
	await(t, func() bool { return idle(m) })
	if f.calls.Load() != 3 || f.items.Load() != 150 || f.singles.Load() != 0 {
		t.Fatalf("calls=%d items=%d singles=%d", f.calls.Load(), f.items.Load(), f.singles.Load())
	}
	m.renewDue(time.Now())
	if f.calls.Load() != 3 {
		t.Fatal("renewed earlier than 10 seconds")
	}
}
func TestRenewBatchErrorsPreserveDeadlineAndNeverFanOut(t *testing.T) {
	for _, mode := range []string{"network", "missing", "duplicate", "foreign", "ttl"} {
		t.Run(mode, func(t *testing.T) {
			f := &batchFixture{size: 64, batch: func(in []Renewal) ([]RenewalResult, error) {
				r := goodResults(in)
				switch mode {
				case "network":
					return nil, errors.New("timeout")
				case "missing":
					return r[:1], nil
				case "duplicate":
					r[1] = r[0]
				case "foreign":
					r[0].UserID = 999
				case "ttl":
					r[0].TTL = 600
				}
				return r, nil
			}}
			m, tickets := dueFixture(t, f, 2)
			old := tickets[0].entry.deadline
			m.renewDue(time.Now())
			await(t, func() bool { return idle(m) })
			m.mu.Lock()
			if len(m.entries) != 2 || !tickets[0].entry.deadline.Equal(old) {
				t.Error("protocol failure changed existing grants")
			}
			m.mu.Unlock()
			if f.singles.Load() != 0 {
				t.Fatal("transient error fanned out")
			}
			m.renewDue(time.Now().Add(61 * time.Second))
			m.mu.Lock()
			defer m.mu.Unlock()
			if len(m.entries) != 0 {
				t.Fatal("failed renewals bypassed expiry")
			}
		})
	}
}
func TestRenewBatchDenialIsPerIdentity(t *testing.T) {
	f := &batchFixture{size: 64, batch: func(in []Renewal) ([]RenewalResult, error) {
		r := goodResults(in)
		for i := range r {
			if r[i].UserID == 1 {
				r[i].Allowed = false
				r[i].TTL = 0
			}
		}
		return r, nil
	}}
	m, tickets := dueFixture(t, f, 2)
	var closed atomic.Int64
	tickets[0].Bind(func() { closed.Add(1) })
	tickets[1].Bind(func() { closed.Add(100) })
	m.renewDue(time.Now())
	await(t, func() bool { return idle(m) })
	if closed.Load() != 1 {
		t.Fatalf("wrong tickets revoked %d", closed.Load())
	}
}
func TestRenewBatchLateGrantCannotReviveReplacedLease(t *testing.T) {
	entered := make(chan struct{})
	gate := make(chan struct{})
	f := &batchFixture{size: 64, release: make(chan string, 8), batch: func(in []Renewal) ([]RenewalResult, error) { close(entered); <-gate; return goodResults(in), nil }}
	m, tickets := dueFixture(t, f, 1)
	old := tickets[0].entry
	m.renewDue(time.Now())
	<-entered
	tickets[0].Release()
	fresh, err := m.Acquire(old.uid, old.ip)
	if err != nil {
		t.Fatal(err)
	}
	deadline := fresh.entry.deadline
	close(gate)
	await(t, func() bool { m.mu.Lock(); defer m.mu.Unlock(); return !old.renewing })
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.entries[old.key] != fresh.entry || !fresh.entry.deadline.Equal(deadline) || fresh.entry.id == old.id {
		t.Fatal("late batch replaced fresh ownership")
	}
}
func TestRenewBatchExplicitUnsupportedFallsBack(t *testing.T) {
	f := &batchFixture{size: 64, batch: func([]Renewal) ([]RenewalResult, error) { return nil, ErrBatchUnsupported }}
	m, _ := dueFixture(t, f, 3)
	m.renewDue(time.Now())
	await(t, func() bool { return idle(m) && f.singles.Load() == 3 })
}
func TestRenewBatchLateResponseDoesNotExtendExpiredGrant(t *testing.T) {
	f := &batchFixture{size: 64}
	m, tickets := dueFixture(t, f, 1)
	e := tickets[0].entry
	var once sync.Once
	f.batch = func(in []Renewal) ([]RenewalResult, error) {
		m.mu.Lock()
		e.deadline = time.Now().Add(-time.Second)
		m.mu.Unlock()
		return goodResults(in), nil
	}
	var closed atomic.Bool
	tickets[0].Bind(func() { once.Do(func() { closed.Store(true) }) })
	m.renewDue(time.Now())
	await(t, func() bool { return closed.Load() })
}
