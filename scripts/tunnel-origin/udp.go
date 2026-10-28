package main

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"encoding/binary"
	"errors"
	"net"
	"net/netip"
	"sync"
	"sync/atomic"
	"time"
)

// Header and payload are authenticated together. Direction prevents reflection;
// nonce, sequence window and retired-nonce retention prevent flow resurrection.
const udpHeader = 58

type datagram struct {
	kind   byte
	id     [16]byte
	seq    uint64
	target int
	source netip.AddrPort
	data   []byte
}
type window struct{ high, bits uint64 }

func (w *window) accept(n uint64) bool {
	if n == 0 {
		return false
	}
	if n > w.high {
		shift := n - w.high
		if shift >= 64 {
			w.bits = 0
		} else {
			w.bits <<= shift
		}
		w.high = n
		w.bits |= 1
		return true
	}
	shift := w.high - n
	if shift >= 64 || w.bits&(uint64(1)<<shift) != 0 {
		return false
	}
	w.bits |= uint64(1) << shift
	return true
}
func (s *server) packet(d datagram) []byte {
	b := make([]byte, udpHeader, udpHeader+len(d.data)+32)
	copy(b, "SBO2")
	b[4] = d.kind
	copy(b[5:21], d.id[:])
	binary.BigEndian.PutUint64(b[21:29], d.seq)
	binary.BigEndian.PutUint64(b[29:37], uint64(time.Now().Unix()))
	binary.BigEndian.PutUint16(b[37:39], uint16(d.target))
	ip := d.source.Addr().Unmap()
	v := ip.As16()
	copy(b[39:55], v[:])
	if ip.Is4() {
		b[55] = 4
	} else {
		b[55] = 6
	}
	binary.BigEndian.PutUint16(b[56:58], d.source.Port())
	b = append(b, d.data...)
	return append(b, s.mac([]byte("udp"), b)...)
}
func (s *server) parse(b []byte) (datagram, error) {
	var d datagram
	if len(b) < udpHeader+32 || len(b) > 65507 || string(b[:4]) != "SBO2" || b[4] > 4 {
		return d, errors.New("invalid datagram")
	}
	body := b[:len(b)-32]
	if !hmac.Equal(b[len(b)-32:], s.mac([]byte("udp"), body)) {
		return d, errors.New("invalid datagram authentication")
	}
	ts := int64(binary.BigEndian.Uint64(b[29:37]))
	diff := time.Now().Unix() - ts
	if diff < -60 || diff > 60 {
		return d, errors.New("expired datagram")
	}
	d.kind = b[4]
	copy(d.id[:], b[5:21])
	d.seq = binary.BigEndian.Uint64(b[21:29])
	d.target = int(binary.BigEndian.Uint16(b[37:39]))
	var raw [16]byte
	copy(raw[:], b[39:55])
	ip := netip.AddrFrom16(raw).Unmap()
	port := binary.BigEndian.Uint16(b[56:58])
	if d.seq == 0 || d.target == 0 || port == 0 || !ip.IsGlobalUnicast() || (b[55] != 4 && b[55] != 6) || (b[55] == 4) != ip.Is4() {
		return d, errors.New("invalid original datagram address")
	}
	d.source = netip.AddrPortFrom(ip, port)
	d.data = body[udpHeader:]
	return d, nil
}

type udpFlow struct {
	id     [16]byte
	target int
	source netip.AddrPort
	mu     sync.Mutex
	conn   net.Conn
	peer   net.Addr
	rx     window
	tx     uint64
	last   atomic.Int64
	once   sync.Once
	dead   chan struct{}
	queue  chan []byte
}

func newFlow(id [16]byte, target int, source netip.AddrPort) *udpFlow {
	f := &udpFlow{id: id, target: target, source: source, dead: make(chan struct{}), queue: make(chan []byte, 64)}
	f.last.Store(time.Now().UnixNano())
	return f
}
func (f *udpFlow) close() {
	f.once.Do(func() {
		close(f.dead)
		f.mu.Lock()
		if f.conn != nil {
			f.conn.Close()
		}
		f.mu.Unlock()
	})
}
func (f *udpFlow) install(c net.Conn) bool {
	f.mu.Lock()
	defer f.mu.Unlock()
	select {
	case <-f.dead:
		c.Close()
		return false
	default:
		f.conn = c
		return true
	}
}
func (f *udpFlow) next(kind byte, payload []byte) datagram {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.tx++
	return datagram{kind, f.id, f.tx, f.target, f.source, payload}
}
func (s *server) retire(f *udpFlow) {
	s.mu.Lock()
	if s.flows[f.id] == f {
		delete(s.flows, f.id)
		s.seen[f.id] = time.Now().Add(2 * time.Minute)
	}
	s.mu.Unlock()
	f.close()
}
func (s *server) reap(ctx context.Context) {
	tick := time.NewTicker(time.Second)
	defer tick.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case now := <-tick.C:
			var stale []*udpFlow
			s.mu.Lock()
			for _, f := range s.flows {
				if now.Sub(time.Unix(0, f.last.Load())) >= flowIdle {
					stale = append(stale, f)
				}
			}
			for id, until := range s.seen {
				if s.flows[id] == nil && now.After(until) {
					delete(s.seen, id)
				}
			}
			s.mu.Unlock()
			for _, f := range stale {
				s.retire(f)
			}
		}
	}
}
func (s *server) udp(ctx context.Context, listen string, target int, bound chan<- error) {
	c, e := net.ListenPacket("udp", listen)
	bound <- e
	if e != nil {
		return
	}
	defer c.Close()
	go func() { <-ctx.Done(); c.Close() }()
	if s.cfg.Role == "ingress" {
		s.udpIngress(ctx, c, target)
	} else {
		s.udpEgress(ctx, c)
	}
}
func (s *server) udpIngress(ctx context.Context, c net.PacketConn, target int) {
	clients := map[string]*udpFlow{}
	var cm sync.Mutex
	b := make([]byte, 65536)
	for {
		n, peer, e := c.ReadFrom(b)
		if e != nil {
			return
		}
		src, e := s.original(peer)
		if e != nil || !s.admits(src.Addr()) || n > 65507-udpHeader-32 {
			continue
		}
		key := peer.String()
		cm.Lock()
		f := clients[key]
		if f != nil {
			select {
			case <-f.dead:
				delete(clients, key)
				f = nil
			default:
			}
		}
		if f == nil {
			var id [16]byte
			if _, e = rand.Read(id[:]); e != nil {
				cm.Unlock()
				continue
			}
			f = newFlow(id, target, src)
			f.peer = peer
			out, e := dial(ctx, "udp", s.cfg.Backend, s.cfg.Mark)
			if e != nil {
				cm.Unlock()
				continue
			}
			f.conn = out
			s.mu.Lock()
			if len(s.flows) >= maxFlows {
				s.mu.Unlock()
				out.Close()
				cm.Unlock()
				continue
			}
			s.flows[id] = f
			s.mu.Unlock()
			clients[key] = f
			go func(f *udpFlow, key string) {
				defer func() {
					s.retire(f)
					cm.Lock()
					if clients[key] == f {
						delete(clients, key)
					}
					cm.Unlock()
				}()
				reply := make([]byte, 65536)
				for {
					n, e := f.conn.Read(reply)
					if e != nil {
						return
					}
					d, e := s.parse(reply[:n])
					if e != nil || d.id != f.id || d.target != f.target || d.source != f.source || (d.kind != 1 && d.kind != 2) {
						continue
					}
					if d.kind == 2 {
						return
					}
					f.mu.Lock()
					ok := f.rx.accept(d.seq)
					f.mu.Unlock()
					if !ok {
						continue
					}
					if _, e = c.WriteTo(d.data, f.peer); e != nil {
						return
					}
					f.last.Store(time.Now().UnixNano())
				}
			}(f, key)
		}
		cm.Unlock()
		if _, e = f.conn.Write(s.packet(f.next(0, b[:n]))); e == nil {
			f.last.Store(time.Now().UnixNano())
		}
	}
}
func (s *server) udpEgress(ctx context.Context, c net.PacketConn) {
	b := make([]byte, 65536)
	for {
		n, peer, e := c.ReadFrom(b)
		if e != nil {
			return
		}
		d, e := s.parse(b[:n])
		if e != nil || !s.targets[d.target] {
			continue
		}
		if d.kind == 3 {
			// This authenticated management probe sends no user payload.
			var e error
			if s.cfg.Role != "health" {
				e = s.probeIdentity(d.target, d.source.Addr().String())
			}
			if e == nil {
				d.kind = 4
				d.data = []byte(probeAck)
				c.WriteTo(s.packet(d), peer)
			}
			continue
		}
		if s.cfg.Role == "health" {
			continue
		}
		if d.kind != 0 {
			continue
		}
		s.mu.Lock()
		f := s.flows[d.id]
		if f == nil {
			_, retired := s.seen[d.id]
			if retired || len(s.flows) >= maxFlows || len(s.seen) >= maxFlows*2 {
				s.mu.Unlock()
				d.kind = 2
				d.data = nil
				c.WriteTo(s.packet(d), peer)
				continue
			}
			f = newFlow(d.id, d.target, d.source)
			f.peer = peer
			s.flows[d.id] = f
			s.seen[d.id] = time.Now().Add(2 * time.Minute)
			go s.udpBackend(ctx, c, f)
		}
		s.mu.Unlock()
		if f.target != d.target || f.source != d.source {
			continue
		}
		f.mu.Lock()
		ok := f.rx.accept(d.seq)
		if ok {
			f.peer = peer
		}
		f.mu.Unlock()
		if !ok {
			continue
		}
		payload := append([]byte(nil), d.data...)
		select {
		case f.queue <- payload:
		case <-f.dead:
		default:
		}
	}
}
func (s *server) udpBackend(ctx context.Context, wire net.PacketConn, f *udpFlow) {
	defer func() {
		f.mu.Lock()
		peer := f.peer
		f.mu.Unlock()
		wire.WriteTo(s.packet(f.next(2, nil)), peer)
		s.retire(f)
	}()
	backend, e := s.backendUDP(f.target, f.source.Addr().String())
	if e != nil {
		return
	}
	if !f.install(backend) {
		return
	}
	defer backend.Close()
	readDone := make(chan struct{})
	go func() {
		defer close(readDone)
		defer f.close()
		b := make([]byte, 65536)
		for {
			n, e := backend.Read(b)
			if e != nil {
				return
			}
			if n > 65507-udpHeader-32 {
				continue
			}
			f.mu.Lock()
			peer := f.peer
			f.mu.Unlock()
			if _, e = wire.WriteTo(s.packet(f.next(1, b[:n])), peer); e != nil {
				return
			}
			f.last.Store(time.Now().UnixNano())
		}
	}()
	defer func() { backend.Close(); <-readDone }()
	for {
		select {
		case <-ctx.Done():
			return
		case <-f.dead:
			return
		case b := <-f.queue:
			if _, e = backend.Write(b); e != nil {
				return
			}
			f.last.Store(time.Now().UnixNano())
		}
	}
}
