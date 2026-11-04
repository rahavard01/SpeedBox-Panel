package admission

import (
	"container/heap"
	"context"
	"errors"
	"math/rand/v2"
	"sync"
	"sync/atomic"
	"time"
)

// Local backpressure is never an admission grant or a change to a lease deadline.
var ErrAdmissionBusy = errors.New("connection admission temporarily busy")
var ErrTransientLease = errors.New("temporary panel lease failure")

type pressureKeyer interface{ AdmissionPressureKey() string }
type pressureLimits struct {
	concurrent, pending, releaseCapacity int
	rate, burst                          float64
	wait, retryBase, retryMax            time.Duration
}

var defaultPressureLimits = pressureLimits{2, 64, 2048, 8, 4, 200 * time.Millisecond, 500 * time.Millisecond, 5 * time.Second}

var pressureRegistry = struct {
	sync.Mutex
	panels   map[string]*pressureGate
	overflow *pressureGate
}{panels: make(map[string]*pressureGate)}

func pressureFor(client Caller) *pressureGate {
	keyer, ok := client.(pressureKeyer)
	if !ok || keyer.AdmissionPressureKey() == "" {
		return newPressureGate(defaultPressureLimits)
	}
	key := keyer.AdmissionPressureKey()
	pressureRegistry.Lock()
	defer pressureRegistry.Unlock()
	if g := pressureRegistry.panels[key]; g != nil {
		return g
	}
	// Configuration reloads cannot grow an unbounded per-panel registry.
	if len(pressureRegistry.panels) >= 32 {
		if pressureRegistry.overflow == nil {
			pressureRegistry.overflow = newPressureGate(defaultPressureLimits)
		}
		return pressureRegistry.overflow
	}
	g := newPressureGate(defaultPressureLimits)
	pressureRegistry.panels[key] = g
	return g
}

type pressureGate struct {
	mu                                                                          sync.Mutex
	limits                                                                      pressureLimits
	slots, pending                                                              chan struct{}
	tokens                                                                      float64
	refilled, retryUntil                                                        time.Time
	failures                                                                    uint
	active                                                                      atomic.Int64
	releases                                                                    releaseHeap
	releaseIDs                                                                  map[string]bool
	wake                                                                        chan struct{}
	running                                                                     bool
	ctx                                                                         context.Context
	cancel                                                                      context.CancelFunc
	workers                                                                     sync.WaitGroup
	limited, cooldowns, calls, releaseOverflow, releaseExpired, releaseFailures atomic.Uint64
}

func newPressureGate(limits pressureLimits) *pressureGate {
	ctx, cancel := context.WithCancel(context.Background())
	return &pressureGate{limits: limits, slots: make(chan struct{}, limits.concurrent), pending: make(chan struct{}, limits.pending),
		tokens: limits.burst, refilled: time.Now(), releaseIDs: make(map[string]bool), wake: make(chan struct{}, 1), ctx: ctx, cancel: cancel}
}

func (g *pressureGate) reserve() error {
	g.mu.Lock()
	blocked := time.Now().Before(g.retryUntil)
	g.mu.Unlock()
	if blocked {
		g.limited.Add(1)
		return ErrAdmissionBusy
	}
	select {
	case g.pending <- struct{}{}:
		return nil
	default:
		g.limited.Add(1)
		return ErrAdmissionBusy
	}
}

// The 200ms queue budget is part of, not in addition to, the existing 3s RPC budget.
func (g *pressureGate) enter(ctx context.Context, stop <-chan struct{}, queued time.Time) error {
	wait, cancel := context.WithDeadline(ctx, queued.Add(g.limits.wait))
	defer cancel()
	select {
	case g.slots <- struct{}{}:
	case <-wait.Done():
		g.limited.Add(1)
		return ErrAdmissionBusy
	case <-stop:
		return errors.New("admission stopped")
	}
	for {
		now := time.Now()
		g.mu.Lock()
		blocked := now.Before(g.retryUntil)
		g.tokens = min(g.limits.burst, g.tokens+now.Sub(g.refilled).Seconds()*g.limits.rate)
		g.refilled = now
		ready := !blocked && g.tokens >= 1 && wait.Err() == nil
		if ready {
			g.tokens--
			g.mu.Unlock()
			return nil
		}
		delay := time.Duration((1 - g.tokens) / g.limits.rate * float64(time.Second))
		g.mu.Unlock()
		if blocked || wait.Err() != nil {
			<-g.slots
			g.limited.Add(1)
			return ErrAdmissionBusy
		}
		if delay < time.Microsecond {
			delay = time.Microsecond
		}
		timer := time.NewTimer(delay)
		select {
		case <-timer.C:
		case <-wait.Done():
			timer.Stop()
			<-g.slots
			g.limited.Add(1)
			return ErrAdmissionBusy
		case <-stop:
			timer.Stop()
			<-g.slots
			return errors.New("admission stopped")
		}
	}
}

func (g *pressureGate) observe(err error) {
	g.mu.Lock()
	defer g.mu.Unlock()
	if err == nil {
		g.failures = 0
		return
	}
	if !errors.Is(err, ErrTransientLease) && !errors.Is(err, context.DeadlineExceeded) {
		return
	}
	if g.failures < 5 {
		g.failures++
	}
	delay := min(g.limits.retryMax, g.limits.retryBase*time.Duration(1<<(g.failures-1)))
	delay = min(g.limits.retryMax, delay+time.Duration(rand.Int64N(int64(g.limits.retryBase/2)+1)))
	until := time.Now().Add(delay)
	if until.After(g.retryUntil) {
		g.retryUntil = until
	}
	g.cooldowns.Add(1)
}

func (g *pressureGate) slow() {
	g.mu.Lock()
	defer g.mu.Unlock()
	until := time.Now().Add(250*time.Millisecond + time.Duration(rand.Int64N(int64(250*time.Millisecond))))
	if until.After(g.retryUntil) {
		g.retryUntil = until
	}
}

type releaseCall struct {
	client       Caller
	uid          int
	ip, id       string
	due, expires time.Time
	attempt      int
}
type releaseHeap []*releaseCall

func (h releaseHeap) Len() int           { return len(h) }
func (h releaseHeap) Less(i, j int) bool { return h[i].due.Before(h[j].due) }
func (h releaseHeap) Swap(i, j int)      { h[i], h[j] = h[j], h[i] }
func (h *releaseHeap) Push(v any)        { *h = append(*h, v.(*releaseCall)) }
func (h *releaseHeap) Pop() any {
	a := *h
	n := len(a)
	v := a[n-1]
	a[n-1] = nil
	*h = a[:n-1]
	return v
}

func (g *pressureGate) release(client Caller, e *entry) {
	expires := e.deadline.Add(5 * time.Second)
	if e.deadline.IsZero() {
		expires = e.started.Add(60 * time.Second)
	}
	if expires.Before(time.Now()) {
		g.releaseExpired.Add(1)
		return
	}
	g.mu.Lock()
	if g.ctx.Err() != nil {
		g.mu.Unlock()
		return
	}
	if g.releaseIDs[e.id] {
		g.mu.Unlock()
		return
	}
	if len(g.releaseIDs) >= g.limits.releaseCapacity {
		g.mu.Unlock()
		g.releaseOverflow.Add(1)
		return
	}
	g.releaseIDs[e.id] = true
	heap.Push(&g.releases, &releaseCall{client: client, uid: e.uid, ip: e.ip, id: e.id, due: time.Now(), expires: expires})
	if !g.running {
		g.running = true
		g.workers.Add(1)
		go g.releaseLoop()
	}
	g.mu.Unlock()
	select {
	case g.wake <- struct{}{}:
	default:
	}
}

func (g *pressureGate) releaseLoop() {
	defer g.workers.Done()
	for {
		if g.ctx.Err() != nil {
			return
		}
		g.mu.Lock()
		if len(g.releases) == 0 {
			g.running = false
			g.mu.Unlock()
			return
		}
		next := g.releases[0]
		delay := time.Until(next.due)
		if delay > 0 {
			g.mu.Unlock()
			timer := time.NewTimer(delay)
			select {
			case <-g.ctx.Done():
				timer.Stop()
				return
			case <-g.wake:
				timer.Stop()
			case <-timer.C:
			}
			continue
		}
		job := heap.Pop(&g.releases).(*releaseCall)
		g.mu.Unlock()
		var err error
		if time.Now().Before(job.expires) {
			ctx, cancel := context.WithDeadline(g.ctx, minTime(time.Now().Add(3*time.Second), job.expires))
			_, _, err = job.client.ConnectionLease(ctx, "release", job.uid, job.ip, job.id)
			cancel()
			g.observe(err)
		} else {
			g.releaseExpired.Add(1)
		}
		g.mu.Lock()
		if g.ctx.Err() == nil && (errors.Is(err, ErrTransientLease) || errors.Is(err, context.DeadlineExceeded)) && job.attempt < 2 && time.Until(job.expires) > time.Second {
			job.attempt++
			job.due = time.Now().Add(time.Duration(job.attempt)*500*time.Millisecond + time.Duration(rand.Int64N(int64(250*time.Millisecond))))
			heap.Push(&g.releases, job)
		} else {
			delete(g.releaseIDs, job.id)
			if err != nil {
				g.releaseFailures.Add(1)
			}
		}
		g.mu.Unlock()
	}
}
func minTime(a, b time.Time) time.Time {
	if a.Before(b) {
		return a
	}
	return b
}

// Only aggregate counters, with no account IDs, addresses, URLs or credentials.
type PressureStats struct {
	AcquireInFlight int    `json:"acquire_in_flight"`
	AcquirePending  int    `json:"acquire_pending"`
	ReleasePending  int    `json:"release_pending"`
	AcquireCalls    uint64 `json:"acquire_calls"`
	Limited         uint64 `json:"limited"`
	Cooldowns       uint64 `json:"cooldowns"`
	ReleaseOverflow uint64 `json:"release_overflow"`
	ReleaseExpired  uint64 `json:"release_expired"`
	ReleaseFailures uint64 `json:"release_failures"`
}

func (g *pressureGate) stats() PressureStats {
	g.mu.Lock()
	n := len(g.releaseIDs)
	g.mu.Unlock()
	return PressureStats{int(g.active.Load()), len(g.pending), n, g.calls.Load(), g.limited.Load(), g.cooldowns.Load(), g.releaseOverflow.Load(), g.releaseExpired.Load(), g.releaseFailures.Load()}
}
func PressureSnapshot() PressureStats {
	pressureRegistry.Lock()
	defer pressureRegistry.Unlock()
	var out PressureStats
	all := make([]*pressureGate, 0, len(pressureRegistry.panels)+1)
	for _, g := range pressureRegistry.panels {
		all = append(all, g)
	}
	if pressureRegistry.overflow != nil {
		all = append(all, pressureRegistry.overflow)
	}
	for _, g := range all {
		s := g.stats()
		out.AcquireInFlight += s.AcquireInFlight
		out.AcquirePending += s.AcquirePending
		out.ReleasePending += s.ReleasePending
		out.AcquireCalls += s.AcquireCalls
		out.Limited += s.Limited
		out.Cooldowns += s.Cooldowns
		out.ReleaseOverflow += s.ReleaseOverflow
		out.ReleaseExpired += s.ReleaseExpired
		out.ReleaseFailures += s.ReleaseFailures
	}
	return out
}

func (g *pressureGate) shutdown() { g.mu.Lock(); g.cancel(); g.mu.Unlock(); g.workers.Wait() }
