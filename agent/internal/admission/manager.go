// Package admission owns panel-issued, per-user/source-IP leases shared by all
// links on this node. The panel serializes admission across nodes and kernels.
package admission

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"net"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

type Caller interface {
	ConnectionLease(context.Context, string, int, string, string) (bool, int, error)
}
type AcquireFunc func(int, string) (*Ticket, error)
type Manager struct {
	mu         sync.Mutex
	client     Caller
	entries    map[string]*entry
	stopped    bool
	batchSlots chan struct{}
	pressure   *pressureGate
	done       chan struct{}
}
type entry struct {
	key, ip, id                string
	uid                        int
	ready                      chan struct{}
	pending, renewing, closed  bool
	err                        error
	deadline, renewed, started time.Time
	tickets                    map[*Ticket]func()
}
type Ticket struct {
	manager  *Manager
	entry    *entry
	released atomic.Bool
}

func New(client Caller) *Manager {
	return newManager(client, pressureFor(client))
}

func newManager(client Caller, pressure *pressureGate) *Manager {
	return &Manager{client: client, entries: make(map[string]*entry), batchSlots: make(chan struct{}, 2), pressure: pressure, done: make(chan struct{})}
}

func (m *Manager) Acquire(uid int, source string) (*Ticket, error) {
	ip := net.ParseIP(source)
	if uid <= 0 || ip == nil {
		return nil, errors.New("invalid connection identity")
	}
	source = ip.String()
	key := stringKey(uid, source)
	for {
		m.mu.Lock()
		if m.stopped {
			m.mu.Unlock()
			return nil, errors.New("admission stopped")
		}
		if e := m.entries[key]; e != nil {
			if e.pending {
				ready := e.ready
				m.mu.Unlock()
				select {
				case <-ready:
				case <-m.done:
					return nil, errors.New("admission stopped")
				}
				if e.err != nil {
					return nil, e.err
				}
				continue
			}
			if !time.Now().Before(e.deadline) {
				callbacks := m.removeLocked(e)
				m.mu.Unlock()
				m.closeEntry(e, callbacks)
				continue
			}
			t := &Ticket{manager: m, entry: e}
			e.tickets[t] = nil
			m.mu.Unlock()
			return t, nil
		}
		if err := m.pressure.reserve(); err != nil {
			m.mu.Unlock()
			return nil, err
		}
		b := make([]byte, 32)
		if _, err := rand.Read(b); err != nil {
			m.mu.Unlock()
			<-m.pressure.pending
			return nil, err
		}
		e := &entry{key: key, ip: source, uid: uid, id: hex.EncodeToString(b), ready: make(chan struct{}), pending: true, started: time.Now(), tickets: make(map[*Ticket]func())}
		m.entries[key] = e
		m.mu.Unlock()
		start, allowed, ttl, err := m.acquire(e)
		if err == nil && (!allowed || ttl <= 5 || ttl > 60 || !time.Now().Before(start.Add(time.Duration(ttl-5)*time.Second))) {
			err = errors.New("connection limit admission denied")
		}
		m.mu.Lock()
		e.pending = false
		e.err = err
		if err != nil || m.stopped || m.entries[key] != e {
			if err == nil {
				e.err = errors.New("admission stopped")
			}
			if m.entries[key] == e {
				delete(m.entries, key)
			}
			close(e.ready)
			m.mu.Unlock()
			if allowed || (!start.IsZero() && (errors.Is(err, ErrTransientLease) || errors.Is(err, context.DeadlineExceeded))) {
				m.pressure.release(m.client, e)
			}
			return nil, e.err
		}
		// Use request START time and a guard, never response arrival time.
		e.deadline = start.Add(time.Duration(ttl-5) * time.Second)
		e.renewed = start
		t := &Ticket{manager: m, entry: e}
		e.tickets[t] = nil
		close(e.ready)
		m.mu.Unlock()
		return t, nil
	}
}
func (m *Manager) acquire(e *entry) (time.Time, bool, int, error) {
	ctx, cancel := context.WithDeadline(context.Background(), e.started.Add(3*time.Second))
	defer cancel()
	defer func() { <-m.pressure.pending }()
	if err := m.pressure.enter(ctx, m.done, e.started); err != nil {
		return time.Time{}, false, 0, err
	}
	defer func() { <-m.pressure.slots }()
	select {
	case <-m.done:
		return time.Time{}, false, 0, errors.New("admission stopped")
	default:
	}
	start := time.Now()
	m.pressure.calls.Add(1)
	m.pressure.active.Add(1)
	defer m.pressure.active.Add(-1)
	allowed, ttl, err := m.client.ConnectionLease(ctx, "acquire", e.uid, e.ip, e.id)
	if ctx.Err() != nil {
		err = ctx.Err()
	}
	m.pressure.observe(err)
	if time.Since(start) >= 750*time.Millisecond {
		m.pressure.slow()
	}
	return start, allowed, ttl, err
}

func stringKey(uid int, ip string) string { return strconv.Itoa(uid) + "/" + ip }
func (m *Manager) call(op string, e *entry) (bool, int, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	allowed, ttl, err := m.client.ConnectionLease(ctx, op, e.uid, e.ip, e.id)
	m.pressure.observe(err)
	return allowed, ttl, err
}

// Bind arranges for the actual transport to close on revocation or expiry.
func (t *Ticket) Bind(closeFunc func()) {
	if t == nil {
		return
	}
	m := t.manager
	m.mu.Lock()
	invalid := t.released.Load() || t.entry.closed || !time.Now().Before(t.entry.deadline)
	if !invalid {
		t.entry.tickets[t] = closeFunc
	}
	m.mu.Unlock()
	if invalid {
		closeFunc()
	}
}
func (t *Ticket) Release() {
	if t == nil || !t.released.CompareAndSwap(false, true) {
		return
	}
	m := t.manager
	e := t.entry
	m.mu.Lock()
	delete(e.tickets, t)
	last := len(e.tickets) == 0 && m.entries[e.key] == e
	if last {
		delete(m.entries, e.key)
		e.closed = true
	}
	m.mu.Unlock()
	if last {
		m.pressure.release(m.client, e)
	}
}
func (m *Manager) removeLocked(e *entry) []func() {
	if m.entries[e.key] == e {
		delete(m.entries, e.key)
	}
	e.closed = true
	callbacks := make([]func(), 0, len(e.tickets))
	for _, fn := range e.tickets {
		if fn != nil {
			callbacks = append(callbacks, fn)
		}
	}
	return callbacks
}
func (m *Manager) closeEntry(e *entry, callbacks []func()) {
	for _, fn := range callbacks {
		fn()
	}
	m.pressure.release(m.client, e)
}
func (m *Manager) renew(e *entry) {
	start := time.Now()
	allowed, ttl, err := m.call("renew", e)
	m.finishRenew(e, start, allowed, ttl, err)
}
func (m *Manager) finishRenew(e *entry, start time.Time, allowed bool, ttl int, err error) {
	m.mu.Lock()
	e.renewing = false
	if m.entries[e.key] != e || e.closed {
		m.mu.Unlock()
		if allowed {
			m.pressure.release(m.client, e)
		}
		return
	}
	if !time.Now().Before(e.deadline) || (err == nil && (!allowed || ttl <= 5 || ttl > 60 || !time.Now().Before(start.Add(time.Duration(ttl-5)*time.Second)))) {
		callbacks := m.removeLocked(e)
		m.mu.Unlock()
		m.closeEntry(e, callbacks)
		return
	}
	if err == nil {
		e.deadline = start.Add(time.Duration(ttl-5) * time.Second)
	}
	m.mu.Unlock()
}
func (m *Manager) Run(ctx context.Context) {
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	defer func() {
		m.mu.Lock()
		if !m.stopped {
			m.stopped = true
			close(m.done)
		}
		type closing struct {
			e         *entry
			callbacks []func()
		}
		var all []closing
		for _, e := range m.entries {
			if !e.pending {
				all = append(all, closing{e, m.removeLocked(e)})
			}
		}
		m.mu.Unlock()
		for _, c := range all {
			m.closeEntry(c.e, c.callbacks)
		}
	}()
	for {
		select {
		case <-ctx.Done():
			return
		case now := <-ticker.C:
			m.renewDue(now)
		}
	}
}
