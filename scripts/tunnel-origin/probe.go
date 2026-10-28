package main

import (
	"context"
	"crypto/rand"
	"crypto/tls"
	"errors"
	"fmt"
	"io"
	"net"
	"net/netip"
	"time"
)

const probeAck = "ORIGIN READY\n"

// The broker contract is transport-independent. Reserve a local source tuple
// and exercise registration without requiring a disabled node to be listening.
func (s *server) probeIdentity(target int, ip string) error {
	var last error
	for i := 0; i < 8; i++ {
		l, e := net.Listen("tcp4", "127.0.0.1:0")
		if e != nil {
			return e
		}
		identity, e := s.register(l.Addr().(*net.TCPAddr).Port, target, ip)
		if identity != nil {
			identity.Close()
		}
		l.Close()
		if e == nil {
			return nil
		}
		last = e
		if !errors.Is(e, collision) {
			break
		}
	}
	return last
}

// Exercise the actual carrier and the target's private origin broker before
// opening ingress. No application/user bytes are sent to the backend node.
func (s *server) probe(ctx context.Context) error {
	if s.cfg.Role != "ingress" {
		return errors.New("only ingress can probe an origin path")
	}
	// One allowed target verifies this pair's shared carrier/broker. Node
	// policy readiness separately checks every enabled mapped node listener.
	target := s.cfg.Routes[0].Target
	source := netip.MustParseAddrPort("198.51.100.1:1")
	if s.cfg.TCP {
		c, e := dial(ctx, "tcp", s.cfg.Backend, s.cfg.Mark)
		if e != nil {
			return fmt.Errorf("TCP carrier unavailable: %w", e)
		}
		e = func() error {
			defer c.Close()
			secure := tls.Client(c, s.clientTLS)
			secure.SetDeadline(time.Now().Add(4 * time.Second))
			if e := secure.HandshakeContext(ctx); e != nil {
				return e
			}
			if e := s.sendMetadata(secure, hello{Target: target, IP: source.Addr().String(), Port: source.Port(), Probe: true}); e != nil {
				return e
			}
			secure.SetReadDeadline(time.Now().Add(4 * time.Second))
			b := make([]byte, len(probeAck))
			if _, e := io.ReadFull(secure, b); e != nil {
				return e
			}
			if string(b) != probeAck {
				return errors.New("origin probe rejected")
			}
			return nil
		}()
		if e != nil {
			return fmt.Errorf("authenticated TCP origin path for target %d failed: %w", target, e)
		}
	}
	if s.cfg.UDP {
		c, e := dial(ctx, "udp", s.cfg.Backend, s.cfg.Mark)
		if e != nil {
			return fmt.Errorf("UDP carrier unavailable: %w", e)
		}
		e = func() error {
			defer c.Close()
			var id [16]byte
			if _, e := rand.Read(id[:]); e != nil {
				return e
			}
			request := s.packet(datagram{kind: 3, id: id, seq: 1, target: target, source: source})
			b := make([]byte, 4096)
			for attempt := 0; attempt < 3; attempt++ {
				c.SetDeadline(time.Now().Add(time.Second))
				if _, e := c.Write(request); e != nil {
					return e
				}
				n, e := c.Read(b)
				if e != nil {
					continue
				}
				d, e := s.parse(b[:n])
				if e == nil && d.kind == 4 && d.id == id && d.target == target && d.source == source && string(d.data) == probeAck {
					return nil
				}
			}
			return errors.New("authenticated UDP origin probe timed out")
		}()
		if e != nil {
			return fmt.Errorf("UDP origin path for target %d failed: %w", target, e)
		}
	}
	return nil
}
