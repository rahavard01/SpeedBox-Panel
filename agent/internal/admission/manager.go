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
 mu sync.Mutex
 client Caller
 entries map[string]*entry
 stopped bool
}
type entry struct {
 key, ip, id string
 uid int
 ready chan struct{}
 pending, renewing, closed bool
 err error
 deadline, renewed time.Time
 tickets map[*Ticket]func()
}
type Ticket struct {
 manager *Manager
 entry *entry
 released atomic.Bool
}
func New(client Caller) *Manager { return &Manager{client:client, entries:make(map[string]*entry)} }

func (m *Manager) Acquire(uid int, source string) (*Ticket, error) {
 ip := net.ParseIP(source)
 if uid <= 0 || ip == nil { return nil, errors.New("invalid connection identity") }
 source = ip.String()
 key := stringKey(uid, source)
 for {
  m.mu.Lock()
  if m.stopped { m.mu.Unlock(); return nil, errors.New("admission stopped") }
  if e := m.entries[key]; e != nil {
   if e.pending {
    ready := e.ready
    m.mu.Unlock()
    <-ready
    if e.err != nil { return nil, e.err }
    continue
   }
   if !time.Now().Before(e.deadline) {
    callbacks := m.removeLocked(e)
    m.mu.Unlock()
    m.closeEntry(e, callbacks)
    continue
   }
   t := &Ticket{manager:m, entry:e}; e.tickets[t] = nil
   m.mu.Unlock(); return t, nil
  }
  b := make([]byte,32)
  if _,err := rand.Read(b); err != nil { m.mu.Unlock(); return nil,err }
  e := &entry{key:key,ip:source,uid:uid,id:hex.EncodeToString(b),ready:make(chan struct{}),pending:true,tickets:make(map[*Ticket]func())}
  m.entries[key] = e
  m.mu.Unlock()
  start := time.Now()
  allowed, ttl, err := m.call("acquire", e)
  if err == nil && (!allowed || ttl <= 5) { err = errors.New("connection limit admission denied") }
  m.mu.Lock()
  e.pending = false; e.err = err
  if err != nil || m.stopped || m.entries[key] != e {
   if err == nil { e.err = errors.New("admission stopped") }
   if m.entries[key] == e { delete(m.entries,key) }
   close(e.ready); m.mu.Unlock()
   if allowed { go m.call("release",e) }
   return nil,e.err
  }
  // Use request START time and a guard, never response arrival time.
  e.deadline = start.Add(time.Duration(ttl-5)*time.Second); e.renewed = start
  t := &Ticket{manager:m,entry:e}; e.tickets[t] = nil
  close(e.ready); m.mu.Unlock(); return t,nil
 }
}
func stringKey(uid int, ip string) string {return strconv.Itoa(uid)+"/"+ip}
func (m *Manager) call(op string, e *entry) (bool,int,error) {
 ctx,cancel:=context.WithTimeout(context.Background(),3*time.Second);defer cancel()
 return m.client.ConnectionLease(ctx,op,e.uid,e.ip,e.id)
}
// Bind arranges for the actual transport to close on revocation or expiry.
func (t *Ticket) Bind(closeFunc func()) {
 if t == nil { return }
 m:=t.manager;m.mu.Lock()
 invalid:=t.released.Load() || t.entry.closed || !time.Now().Before(t.entry.deadline)
 if !invalid { t.entry.tickets[t]=closeFunc }
 m.mu.Unlock()
 if invalid { closeFunc() }
}
func (t *Ticket) Release() {
 if t == nil || !t.released.CompareAndSwap(false,true) { return }
 m:=t.manager;e:=t.entry;m.mu.Lock()
 delete(e.tickets,t)
 last:=len(e.tickets)==0 && m.entries[e.key]==e
 if last { delete(m.entries,e.key); e.closed=true }
 m.mu.Unlock()
 if last { go m.call("release",e) }
}
func (m *Manager) removeLocked(e *entry) []func() {
 if m.entries[e.key]==e { delete(m.entries,e.key) }
 e.closed=true
 callbacks:=make([]func(),0,len(e.tickets))
 for _,fn:=range e.tickets { if fn!=nil { callbacks=append(callbacks,fn) } }
 return callbacks
}
func (m *Manager) closeEntry(e *entry, callbacks []func()) {
 for _,fn:=range callbacks { fn() }
 go m.call("release",e)
}
func (m *Manager) renew(e *entry) {
 start:=time.Now();allowed,ttl,err:=m.call("renew",e)
 m.mu.Lock();e.renewing=false
 if m.entries[e.key]!=e || e.closed {
  m.mu.Unlock();if allowed { go m.call("release",e) };return
 }
 if err==nil && (!allowed || ttl<=5) {
  callbacks:=m.removeLocked(e);m.mu.Unlock();m.closeEntry(e,callbacks);return
 }
 if err==nil { e.deadline=start.Add(time.Duration(ttl-5)*time.Second) }
 m.mu.Unlock()
}
func (m *Manager) Run(ctx context.Context) {
 ticker:=time.NewTicker(time.Second);defer ticker.Stop()
 defer func(){
  m.mu.Lock();m.stopped=true
  type closing struct{e *entry; callbacks []func()};var all []closing
  for _,e:=range m.entries { if !e.pending { all=append(all,closing{e,m.removeLocked(e)}) } }
  m.mu.Unlock();for _,c:=range all { m.closeEntry(c.e,c.callbacks) }
 }()
 for {
  select {
  case <-ctx.Done():return
  case now:=<-ticker.C:
   m.mu.Lock()
   type closing struct{e *entry; callbacks []func()};var expired []closing;var renew []*entry
   for _,e:=range m.entries {
    if e.pending { continue }
    if !now.Before(e.deadline) { expired=append(expired,closing{e,m.removeLocked(e)});continue }
    if !e.renewing && now.Sub(e.renewed)>=10*time.Second { e.renewing=true;e.renewed=now;renew=append(renew,e) }
   }
   m.mu.Unlock()
   for _,c:=range expired { m.closeEntry(c.e,c.callbacks) }
   for _,e:=range renew { go m.renew(e) }
  }
 }
}
