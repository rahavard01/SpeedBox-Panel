package xray

import (
	"context"
	"errors"
	"reflect"
	"sync"
	"sync/atomic"
	_ "unsafe"

	xrayDispatcher "github.com/xtls/xray-core/app/dispatcher"
	"github.com/xtls/xray-core/common"
	"github.com/xtls/xray-core/common/buf"
	"github.com/xtls/xray-core/common/net"
	"github.com/xtls/xray-core/common/session"
	"github.com/xtls/xray-core/features/routing"
	"github.com/xtls/xray-core/transport"

	"github.com/cedar2025/xboard-node/internal/admission"
 "github.com/cedar2025/xboard-node/internal/nlog"
)

// Access xray's internal config creator registry so we can replace the
// default dispatcher factory with ours. This runs AFTER xray's init()
// functions because our package imports xray (dependency order guarantee).
//
//go:linkname typeCreatorRegistry github.com/xtls/xray-core/common.typeCreatorRegistry
var typeCreatorRegistry map[reflect.Type]common.ConfigCreator

var origDispatcherFactory common.ConfigCreator

// globalLimitDispatcher is set when the factory creates a LimitDispatcher.
// The Xray kernel reads it to configure limits and get connections.
var globalLimitDispatcher atomic.Pointer[LimitDispatcher]

func init() {
	configType := reflect.TypeOf((*xrayDispatcher.Config)(nil))
	origDispatcherFactory = typeCreatorRegistry[configType]
	typeCreatorRegistry[configType] = limitDispatcherFactory
}

func limitDispatcherFactory(ctx context.Context, config interface{}) (interface{}, error) {
	orig, err := origDispatcherFactory(ctx, config)
	if err != nil {
		return nil, err
	}
	inner, ok := orig.(routing.Dispatcher)
	if !ok {
		return orig, nil
	}
	ld := &LimitDispatcher{
		inner:      orig,
		innerDisp:  inner,
		limitedIPs: make(map[string]map[string]int),
	}
	globalLimitDispatcher.Store(ld)
	nlog.Core().Debug("xray: limit dispatcher installed")
	return ld, nil
}

// LimitDispatcher wraps xray's DefaultDispatcher to enforce per-user
// admission checks before a request is dispatched into xray-core.
//
// It preserves transport.Link.Reader. Xray's
// mux/XUDP close path requires the original concrete *pipe.Reader to remain
// intact, so the dispatcher is limited to gate-keeping and safe connection
// lifecycle bookkeeping.
type LimitDispatcher struct {
	inner     interface{}        // original DefaultDispatcher (Feature + Dispatcher)
	innerDisp routing.Dispatcher // same object, typed as Dispatcher

	// limitedUsers: users with device limit > 0, protected by mu.
	// Admission and refcount changes use the same lock.
	mu           sync.RWMutex
	limitedIPs   map[string]map[string]int // email → sourceIP → refcount
	deviceLimits map[string]int            // email → max devices
	emailToUID   map[string]int            // email → panel user ID

	// unlimitedIPs: users without device limit — sync.Map for lock-free access.
	// Each entry is *ipCounter{ips sync.Map}.
	unlimitedIPs sync.Map // email → *ipCounter

	admission atomic.Pointer[admission.AcquireFunc]
 connCount atomic.Int64 // total active connections tracked by dispatcher
}

// ipCounter tracks IPs for unlimited users without any lock.
type ipCounter struct {
	ips sync.Map // sourceIP → *atomic.Int64 (refcount)
}

// aliveIPs returns a snapshot of distinct IPs.
func (ic *ipCounter) aliveIPs() map[string]bool {
	result := make(map[string]bool)
	ic.ips.Range(func(key, _ interface{}) bool {
		if rv, ok := ic.ips.Load(key); ok && rv.(*atomic.Int64).Load() > 0 {
			result[key.(string)] = true
		}
		return true
	})
	return result
}

// ─── routing.Dispatcher ──────────────────────────────────────────────────────

func (d *LimitDispatcher) Dispatch(ctx context.Context, dest net.Destination) (*transport.Link, error) {
 email,ip,ticket,err:=d.admit(ctx)
 if err!=nil{return nil,err}
 link,err:=d.innerDisp.Dispatch(ctx,dest)
 if err!=nil {if email!=""{d.delConn(email,ip)};ticket.Release();return nil,err}
 if email!=""{d.trackLink(link,email,ip,true,ticket)}
 return link,nil
}
func (d *LimitDispatcher) DispatchLink(ctx context.Context, dest net.Destination, link *transport.Link) error {
 email,ip,ticket,err:=d.admit(ctx)
 if err!=nil{return err}
 var writer *closeTrackingWriter
 if email!=""{writer=d.trackLink(link,email,ip,true,ticket)}
 err=d.innerDisp.DispatchLink(ctx,dest,link)
 if err!=nil && writer!=nil {writer.Interrupt()}
 return err
}
func (d *LimitDispatcher) admit(ctx context.Context) (email,ip string,ticket *admission.Ticket,err error) {
 si:=session.InboundFromContext(ctx)
 if si==nil || si.User==nil || si.User.Email==""{return "","",nil,nil}
 email=si.User.Email;ip=si.Source.Address.IP().String()
 if fn:=d.admission.Load();fn!=nil{
  d.mu.RLock();uid:=d.emailToUID[email];d.mu.RUnlock()
  ticket,err=(*fn)(uid,ip)
  if err!=nil{return "","",nil,err}
 }
 // The central reservation is authoritative; local counts remain for reports.
 if d.checkDeviceLimit(email,ip,true){ticket.Release();return "","",nil,errors.New("connection limit exceeded")}
 return email,ip,ticket,nil
}
func (d *LimitDispatcher) trackLink(link *transport.Link,email,ip string,_ bool,tickets ...*admission.Ticket) *closeTrackingWriter {
 var ticket *admission.Ticket
 if len(tickets)>0{ticket=tickets[0]}
 d.connCount.Add(1)
 writer:=&closeTrackingWriter{Writer:link.Writer,onClose:func(){common.Interrupt(link.Reader);d.delConn(email,ip);d.connCount.Add(-1);ticket.Release()}}
 link.Writer=writer
 ticket.Bind(func(){common.Interrupt(link.Reader);writer.Interrupt()})
 return writer
}
func (d *LimitDispatcher) SetConnectionAdmission(fn admission.AcquireFunc) {d.admission.Store(&fn)}

// ─── features.Feature (delegated) ───────────────────────────────────────────

func (d *LimitDispatcher) Type() interface{} { return routing.DispatcherType() }

func (d *LimitDispatcher) Start() error {
	if s, ok := d.inner.(interface{ Start() error }); ok {
		return s.Start()
	}
	return nil
}

func (d *LimitDispatcher) Close() error {
	if c, ok := d.inner.(interface{ Close() error }); ok {
		return c.Close()
	}
	return nil
}

// ─── Limit management (called by Xray kernel) ──────────────────────────────

func (d *LimitDispatcher) UpdateLimits(emailToUID map[string]int, deviceLimits, _ map[string]int) {
	d.mu.Lock()
	d.emailToUID = emailToUID
	d.deviceLimits = deviceLimits
	d.mu.Unlock()

}

func (d *LimitDispatcher) ResetConns() {
	d.mu.Lock()
	d.limitedIPs = make(map[string]map[string]int)
	d.mu.Unlock()

	// Clear unlimited IPs
	d.unlimitedIPs.Range(func(key, _ interface{}) bool {
		d.unlimitedIPs.Delete(key)
		return true
	})

	d.connCount.Store(0)
}

// GetConnectionState returns dispatcher-tracked alive IPs and connection count.
// Traffic bytes are intentionally left to xray's built-in stats pipeline.
func (d *LimitDispatcher) GetConnectionState() (aliveIPs map[int]map[string]bool, connCount int) {
	d.mu.RLock()
	emailToUID := d.emailToUID
	limitedIPs := d.limitedIPs
 defer d.mu.RUnlock()

	aliveIPs = make(map[int]map[string]bool)

	// Collect IPs from limited users (under RLock snapshot).
	for email, ipsMap := range limitedIPs {
		uid := emailToUID[email]
		if uid == 0 {
			continue
		}
		ipSet := make(map[string]bool, len(ipsMap))
		for ip := range ipsMap {
			ipSet[ip] = true
		}
		if len(ipSet) > 0 {
            if existing:=aliveIPs[uid];existing!=nil{for ip:=range ipSet{existing[ip]=true}}else{aliveIPs[uid]=ipSet}
		}
	}

	// Collect IPs from unlimited users (lock-free).
	d.unlimitedIPs.Range(func(key, value interface{}) bool {
		email := key.(string)
		uid := emailToUID[email]
		if uid == 0 {
			return true
		}
		ic := value.(*ipCounter)
		if ips := ic.aliveIPs(); len(ips) > 0 {
			// Merge with limited IPs if any
			if existing, ok := aliveIPs[uid]; ok {
				for ip := range ips {
					existing[ip] = true
				}
			} else {
				aliveIPs[uid] = ips
			}
		}
		return true
	})

	connCount = int(d.connCount.Load())
	return
}

// ─── Internal helpers ───────────────────────────────────────────────────────

// checkDeviceLimit atomically reserves an IP and records both TCP and UDP.
// With central admission enabled, its ticket is authoritative.
func (d *LimitDispatcher) checkDeviceLimit(email,sourceIP string,_ bool) bool {
 d.mu.Lock();defer d.mu.Unlock()
 ips:=d.limitedIPs[email]
 if ips==nil {ips=make(map[string]int);d.limitedIPs[email]=ips}
 limit:=d.deviceLimits[email]
 if d.admission.Load()==nil && limit>0 && ips[sourceIP]==0 && len(ips)>=limit{return true}
 // Reserve TCP and UDP in the same critical section. Never admit a new IP by sorting it.
 ips[sourceIP]++
 if _,exists:=d.unlimitedIPs.Load(email);limit<=0 || exists {
  value,_:=d.unlimitedIPs.LoadOrStore(email,&ipCounter{})
  counter,_:=value.(*ipCounter).ips.LoadOrStore(sourceIP,&atomic.Int64{})
  counter.(*atomic.Int64).Add(1)
 }
 return false
}
func (d *LimitDispatcher) delConn(email,sourceIP string) {
 d.mu.Lock();defer d.mu.Unlock()
 if value,ok:=d.unlimitedIPs.Load(email);ok{
  ic:=value.(*ipCounter)
  if counter,ok:=ic.ips.Load(sourceIP);ok && counter.(*atomic.Int64).Add(-1)<=0{ic.ips.Delete(sourceIP)}
 }
 if ips:=d.limitedIPs[email];ips!=nil{
  ips[sourceIP]--
  if ips[sourceIP]<=0{delete(ips,sourceIP)}
  if len(ips)==0{delete(d.limitedIPs,email)}
 }
}

type closeTrackingWriter struct {
	buf.Writer
	onClose func()
	closed  atomic.Bool
}

func (w *closeTrackingWriter) Close() error {
 err:=common.Close(w.Writer)
 if w.closed.CompareAndSwap(false,true){w.onClose()}
 return err
}

func (w *closeTrackingWriter) Interrupt() {
 common.Interrupt(w.Writer)
 if w.closed.CompareAndSwap(false,true){w.onClose()}
}
