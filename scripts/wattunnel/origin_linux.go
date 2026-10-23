//go:build linux

package main

import (
	"bufio"
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"net/netip"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/JohnWatsoon/WatTunnel/wat"
)

// This envelope is inside Wat's authenticated, encrypted session, never a
// client-provided forwarding header. Only opted-in target ports use this format.
const speedboxOriginSize = 23

var speedboxOriginCollision = errors.New("origin tuple already reserved")

var speedboxOriginFlag = flag.String("speedbox-origin-ports", "", "SpeedBox: preserve client identity for these target ports (both peers)")
var speedboxBrokerFlag = flag.String("speedbox-origin-socket", "/run/speedbox-origin/broker.sock", "SpeedBox: private local node origin broker")
var speedboxOriginPorts = map[int]bool{}

func speedboxConfigureOrigin() error {
	for _, v := range strings.Split(*speedboxOriginFlag, ",") {
		if v == "" {
			continue
		}
		p, err := strconv.Atoi(v)
		if err != nil || p < 1 || p > 65535 {
			return fmt.Errorf("invalid origin port %q", v)
		}
		speedboxOriginPorts[p] = true
	}
	return nil
}
func speedboxEncodeOrigin(src netip.AddrPort) ([]byte, error) {
	ip := src.Addr().Unmap()
	if !ip.IsGlobalUnicast() || ip.Zone() != "" || src.Port() == 0 {
		return nil, errors.New("invalid original source")
	}
	b := make([]byte, speedboxOriginSize)
	copy(b, "SBO1")
	if ip.Is4() {
		b[4] = 4
	} else {
		b[4] = 6
	}
	raw := ip.As16()
	copy(b[5:21], raw[:])
	binary.BigEndian.PutUint16(b[21:], src.Port())
	return b, nil
}
func speedboxDecodeOrigin(b []byte) (netip.AddrPort, error) {
	if len(b) < speedboxOriginSize || string(b[:4]) != "SBO1" {
		return netip.AddrPort{}, errors.New("missing tunnel origin")
	}
	var raw [16]byte
	copy(raw[:], b[5:21])
	ip := netip.AddrFrom16(raw).Unmap()
	if (b[4] != 4 && b[4] != 6) || (b[4] == 4) != ip.Is4() || !ip.IsGlobalUnicast() || binary.BigEndian.Uint16(b[21:23]) == 0 {
		return netip.AddrPort{}, errors.New("invalid tunnel origin")
	}
	return netip.AddrPortFrom(ip, binary.BigEndian.Uint16(b[21:23])), nil
}
func speedboxWriteOrigin(w io.Writer, a net.Addr) error {
	src, err := netip.ParseAddrPort(a.String())
	if err != nil {
		return err
	}
	b, err := speedboxEncodeOrigin(src)
	if err != nil {
		return err
	}
	_, err = w.Write(b)
	return err
}
func speedboxReadOrigin(st *wat.Stream) (netip.AddrPort, error) {
	// Stream has no deadline API. Reset uncompleted metadata independently of
	// the long-lived carrier so a faulty peer cannot pin a backend-open goroutine.
	timer := time.AfterFunc(5*time.Second, st.Reset)
	defer timer.Stop()
	var b [speedboxOriginSize]byte
	if _, err := io.ReadFull(st, b[:]); err != nil {
		return netip.AddrPort{}, err
	}
	return speedboxDecodeOrigin(b[:])
}
func speedboxRegister(sourcePort, target int, src netip.AddrPort) (net.Conn, error) {
	c, err := net.DialTimeout("unix", *speedboxBrokerFlag, 2*time.Second)
	if err != nil {
		return nil, err
	}
	c.SetDeadline(time.Now().Add(3 * time.Second))
	req := struct {
		Version    int    `json:"version"`
		SourcePort int    `json:"source_port"`
		TargetPort int    `json:"target_port"`
		OriginalIP string `json:"original_ip"`
	}{1, sourcePort, target, src.Addr().Unmap().String()}
	if err = json.NewEncoder(c).Encode(req); err != nil {
		c.Close()
		return nil, err
	}
	reply, err := bufio.NewReader(c).ReadString('\n')
	if err == nil && reply == "BUSY\n" {
		c.Close()
		return nil, speedboxOriginCollision
	}
	if err != nil || reply != "OK\n" {
		c.Close()
		return nil, errors.New("origin registration rejected")
	}
	c.SetDeadline(time.Time{})
	return c, nil
}

type speedboxOriginConn struct {
	net.Conn
	identity net.Conn
	once     sync.Once
}

func (c *speedboxOriginConn) Close() error {
	var err error
	c.once.Do(func() { err = c.Conn.Close(); c.identity.Close() })
	return err
}
func (c *speedboxOriginConn) CloseWrite() error {
	if w, ok := c.Conn.(interface{ CloseWrite() error }); ok {
		return w.CloseWrite()
	}
	return nil
}
func speedboxGuard(conn net.Conn, identity net.Conn) net.Conn {
	c := &speedboxOriginConn{Conn: conn, identity: identity}
	go func() { var b [1]byte; identity.Read(b[:]); c.Close() }()
	return c
}
func speedboxDialOriginTCP(port int, src netip.AddrPort) (net.Conn, error) {
	var last error
	for attempt := 0; attempt < 8; attempt++ {
		var identity net.Conn
		// Bind and reserve the source port BEFORE registering, and register BEFORE
		// connect. The node cannot race ahead of its source-identity registration.
		d := net.Dialer{Timeout: 5 * time.Second, ControlContext: func(ctx context.Context, network, address string, raw syscall.RawConn) error {
			var err error
			controlErr := raw.Control(func(fd uintptr) {
				err = syscall.Bind(int(fd), &syscall.SockaddrInet4{Addr: [4]byte{127, 0, 0, 1}})
				if err != nil {
					return
				}
				sa, e := syscall.Getsockname(int(fd))
				if e != nil {
					err = e
					return
				}
				identity, err = speedboxRegister(sa.(*syscall.SockaddrInet4).Port, port, src)
			})
			if controlErr != nil {
				return controlErr
			}
			return err
		}}
		c, err := d.Dial("tcp4", net.JoinHostPort("127.0.0.1", strconv.Itoa(port)))
		if err == nil {
			return speedboxGuard(c, identity), nil
		}
		if identity != nil {
			identity.Close()
		}
		last = err
		if !errors.Is(err, speedboxOriginCollision) {
			return nil, err
		}
	}
	return nil, last
}

// UDP never sends before registration is acknowledged. Keep the broker
// connection until the forwarding socket is closed, including idle/session reap.
var speedboxUDPOrigins sync.Map // *net.UDPConn -> net.Conn
func speedboxDialOriginUDP(port int, src netip.AddrPort) (*net.UDPConn, error) {
	var last error
	for attempt := 0; attempt < 8; attempt++ {
		c, err := net.DialUDP("udp4", nil, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: port})
		if err != nil {
			return nil, err
		}
		identity, err := speedboxRegister(c.LocalAddr().(*net.UDPAddr).Port, port, src)
		if err != nil {
			c.Close()
			last = err
			if !errors.Is(err, speedboxOriginCollision) {
				return nil, err
			}
			continue
		}
		speedboxUDPOrigins.Store(c, identity)
		go func() { var b [1]byte; identity.Read(b[:]); speedboxCloseUDP(c) }()
		return c, nil
	}
	return nil, last
}
func speedboxCloseUDP(c *net.UDPConn) {
	c.Close()
	if identity, ok := speedboxUDPOrigins.LoadAndDelete(c); ok {
		identity.(net.Conn).Close()
	}
}
