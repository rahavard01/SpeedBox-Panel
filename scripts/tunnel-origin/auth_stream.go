package main

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"encoding/binary"
	"errors"
	"hash"
	"io"
	"net"
	"sync"
	"time"
)

// Experimental authenticated PLAINTEXT transport. TLS remains the default.
// The two fresh 256-bit nonces bind both proofs and directional record keys to
// this connection. A header-only MAC would not protect later user bytes.
const authMagic = "SBOX-HMAC-TCP1\n"
const authRecordMax = 16 * 1024

var errAuthStream = errors.New("invalid authenticated origin stream")

func writeFull(w io.Writer, b []byte) error {
	for len(b) > 0 {
		n, e := w.Write(b)
		if e != nil {
			return e
		}
		if n <= 0 || n > len(b) {
			return io.ErrShortWrite
		}
		b = b[n:]
	}
	return nil
}

func (s *server) wrapTCP(ctx context.Context, c net.Conn, serverSide bool) (net.Conn, error) {
	_ = c.SetDeadline(time.Now().Add(5 * time.Second))
	stop := context.AfterFunc(ctx, func() { c.Close() })
	defer stop()
	defer c.SetDeadline(time.Time{})
	if s.cfg.TCPSecurity == "hmac-stream-v1" {
		out, e := s.authTCP(c, serverSide)
		if e != nil {
			c.Close()
		}
		return out, e
	}
	var out *tls.Conn
	if serverSide {
		out = tls.Server(c, s.serverTLS)
	} else {
		out = tls.Client(c, s.clientTLS)
	}
	if e := out.HandshakeContext(ctx); e != nil {
		c.Close()
		return nil, e
	}
	return out, nil
}

func (s *server) authTCP(c net.Conn, serverSide bool) (net.Conn, error) {
	var clientNonce, serverNonce [32]byte
	// Labels include protocol version and are distinct from TLS and UDP keys.
	proof := func(label string) []byte {
		return s.mac([]byte("speedbox-origin-hmac-stream-v1\x00"+label+"\x00"), clientNonce[:], serverNonce[:])
	}
	if serverSide {
		var prefix [len(authMagic)]byte
		if _, e := io.ReadFull(c, prefix[:]); e != nil {
			return nil, e
		}
		if string(prefix[:]) != authMagic {
			return nil, errAuthStream
		}
		if _, e := io.ReadFull(c, clientNonce[:]); e != nil {
			return nil, e
		}
		if _, e := rand.Read(serverNonce[:]); e != nil {
			return nil, e
		}
		reply := append(serverNonce[:32:32], proof("server-proof")...)
		if e := writeFull(c, reply); e != nil {
			return nil, e
		}
		var tag [32]byte
		if _, e := io.ReadFull(c, tag[:]); e != nil {
			return nil, e
		}
		if !hmac.Equal(tag[:], proof("client-proof")) {
			return nil, errAuthStream
		}
	} else {
		if _, e := rand.Read(clientNonce[:]); e != nil {
			return nil, e
		}
		if e := writeFull(c, append([]byte(authMagic), clientNonce[:]...)); e != nil {
			return nil, e
		}
		var reply [64]byte
		if _, e := io.ReadFull(c, reply[:]); e != nil {
			return nil, e
		}
		copy(serverNonce[:], reply[:32])
		if !hmac.Equal(reply[32:], proof("server-proof")) {
			return nil, errAuthStream
		}
		if e := writeFull(c, proof("client-proof")); e != nil {
			return nil, e
		}
	}
	readKey, writeKey := proof("server-records"), proof("client-records")
	if serverSide {
		readKey, writeKey = writeKey, readKey
	}
	return &authStream{Conn: c, readMAC: hmac.New(sha256.New, readKey), writeMAC: hmac.New(sha256.New, writeKey)}, nil
}

// Each record authenticates sequence, type, size and the complete payload
// before releasing any bytes. FIN is authenticated too: a raw EOF is truncation.
// Independent locks preserve full duplex and serialize concurrent net.Conn calls.
type authStream struct {
	net.Conn
	readMu, writeMu            sync.Mutex
	readMAC, writeMAC          hash.Hash
	readSeq, writeSeq          uint64
	readBuf, pending, writeBuf []byte
	readErr, writeErr          error
	readFIN, writeFIN          bool
}

func recordTag(h hash.Hash, seq uint64, record []byte, dst []byte) []byte {
	h.Reset()
	var counter [8]byte
	binary.BigEndian.PutUint64(counter[:], seq)
	h.Write(counter[:])
	h.Write(record)
	return h.Sum(dst)
}

func (c *authStream) Read(p []byte) (int, error) {
	c.readMu.Lock()
	defer c.readMu.Unlock()
	if len(p) == 0 {
		return 0, nil
	}
	if len(c.pending) > 0 {
		n := copy(p, c.pending)
		c.pending = c.pending[n:]
		return n, nil
	}
	if c.readErr != nil {
		return 0, c.readErr
	}
	if c.readFIN {
		return 0, io.EOF
	}
	fail := func(e error) (int, error) {
		c.readErr = e
		c.Conn.Close()
		return 0, e
	}
	var head [3]byte
	if _, e := io.ReadFull(c.Conn, head[:]); e != nil {
		if e == io.EOF {
			e = io.ErrUnexpectedEOF
		}
		return fail(e)
	}
	n := int(binary.BigEndian.Uint16(head[1:]))
	if c.readSeq == ^uint64(0) || n > authRecordMax || (head[0] != 0 && head[0] != 1) || (head[0] == 1 && n != 0) || (head[0] == 0 && n == 0) {
		return fail(errAuthStream)
	}
	if c.readBuf == nil {
		c.readBuf = make([]byte, 3+authRecordMax+sha256.Size)
	}
	b := c.readBuf[:3+n+sha256.Size]
	copy(b, head[:])
	if _, e := io.ReadFull(c.Conn, b[3:]); e != nil {
		return fail(e)
	}
	var tag [sha256.Size]byte
	if !hmac.Equal(b[3+n:], recordTag(c.readMAC, c.readSeq, b[:3+n], tag[:0])) {
		return fail(errAuthStream)
	}
	c.readSeq++
	if head[0] == 1 {
		c.readFIN = true
		return 0, io.EOF
	}
	c.pending = b[3 : 3+n]
	n = copy(p, c.pending)
	c.pending = c.pending[n:]
	return n, nil
}

// Caller holds writeMu. A partial record write poisons and closes the stream.
func (c *authStream) writeRecord(kind byte, p []byte) error {
	if c.writeErr != nil {
		return c.writeErr
	}
	if c.writeFIN {
		return net.ErrClosed
	}
	if c.writeSeq == ^uint64(0) {
		return c.failWrite(errAuthStream)
	}
	if c.writeBuf == nil {
		c.writeBuf = make([]byte, 3+authRecordMax+sha256.Size)
	}
	b := c.writeBuf[:3+len(p)]
	b[0] = kind
	binary.BigEndian.PutUint16(b[1:3], uint16(len(p)))
	copy(b[3:], p)
	b = recordTag(c.writeMAC, c.writeSeq, b, b)
	if e := writeFull(c.Conn, b); e != nil {
		return c.failWrite(e)
	}
	c.writeSeq++
	return nil
}

func (c *authStream) failWrite(e error) error {
	c.writeErr = e
	c.Conn.Close()
	return e
}

func (c *authStream) Write(p []byte) (int, error) {
	c.writeMu.Lock()
	defer c.writeMu.Unlock()
	if c.writeErr != nil {
		return 0, c.writeErr
	}
	if c.writeFIN {
		return 0, net.ErrClosed
	}
	total := 0
	for len(p) > 0 {
		n := min(len(p), authRecordMax)
		if e := c.writeRecord(0, p[:n]); e != nil {
			return total, e
		}
		total += n
		p = p[n:]
	}
	return total, nil
}

func (c *authStream) CloseWrite() error {
	c.writeMu.Lock()
	defer c.writeMu.Unlock()
	if c.writeFIN {
		return c.writeErr
	}
	if e := c.writeRecord(1, nil); e != nil {
		return e
	}
	c.writeFIN = true
	if w, ok := c.Conn.(interface{ CloseWrite() error }); ok {
		return w.CloseWrite()
	}
	return nil
}
