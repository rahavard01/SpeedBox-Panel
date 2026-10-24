// Package liveness bounds datagram lifetime without releasing a live socket's
// reservation. Traffic in either direction refreshes its inactivity deadline.
package liveness

import (
	"sync"
	"time"
)

const UDPIdle = 30 * time.Second

type Idle struct {
	mu      sync.Mutex
	timeout time.Duration
	last    time.Time
	timer   *time.Timer
	closed  bool
	expire  func()
}

func NewIdle(timeout time.Duration, expire func()) *Idle {
	i := &Idle{timeout: timeout, last: time.Now(), expire: expire}
	i.timer = time.AfterFunc(timeout, i.check)
	return i
}
func (i *Idle) Touch() {
	if i == nil {
		return
	}
	i.mu.Lock()
	if !i.closed {
		i.last = time.Now()
	}
	i.mu.Unlock()
}
func (i *Idle) Stop() {
	if i == nil {
		return
	}
	i.mu.Lock()
	i.closed = true
	i.timer.Stop()
	i.mu.Unlock()
}
func (i *Idle) check() {
	i.mu.Lock()
	if i.closed {
		i.mu.Unlock()
		return
	}
	if remaining := i.timeout - time.Since(i.last); remaining > 0 {
		i.timer.Reset(remaining)
		i.mu.Unlock()
		return
	}
	i.closed = true
	i.mu.Unlock()
	i.expire()
}
