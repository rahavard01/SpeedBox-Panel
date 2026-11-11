package main

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/binary"
	"errors"
	"io"
	"net"
	"strings"
	"testing"
	"time"
)

type authMemoryConn struct {
	net.Conn
	input  *bytes.Reader
	output bytes.Buffer
}

func (c *authMemoryConn) Read(p []byte) (int, error)  { return c.input.Read(p) }
func (c *authMemoryConn) Write(p []byte) (int, error) { return c.output.Write(p) }
func (c *authMemoryConn) Close() error                { return nil }

func memoryStream(wire []byte, key string) (*authStream, *authMemoryConn) {
	m := &authMemoryConn{input: bytes.NewReader(wire)}
	return &authStream{Conn: m, readMAC: hmac.New(sha256.New, []byte(key)), writeMAC: hmac.New(sha256.New, []byte(key))}, m
}

func TestAuthStreamRecordsRejectTamperingReplayReflectionAndTruncation(t *testing.T) {
	w, raw := memoryStream(nil, "client-record-key")
	if _, e := w.Write([]byte("private-user-data")); e != nil {
		t.Fatal(e)
	}
	first := bytes.Clone(raw.output.Bytes())
	if e := w.CloseWrite(); e != nil {
		t.Fatal(e)
	}
	full := bytes.Clone(raw.output.Bytes())
	if !bytes.Contains(full, []byte("private-user-data")) {
		t.Fatal("experimental transport is meant to be plaintext")
	}
	for _, kind := range []string{"payload", "tag", "header", "fin", "duplicate", "reorder", "reflect", "other-session", "truncated", "oversize", "empty-data"} {
		t.Run(kind, func(t *testing.T) {
			wire := bytes.Clone(full)
			key := "client-record-key"
			expected := ""
			switch kind {
			case "payload":
				wire[3] ^= 1
			case "tag":
				wire[len(first)-1] ^= 1
			case "header":
				wire[0] = 1
			case "fin":
				wire[len(wire)-1] ^= 1
				expected = "private-user-data"
			case "duplicate":
				wire = append(bytes.Clone(first), full...)
				expected = "private-user-data"
			case "reorder":
				wire = append(bytes.Clone(full[len(first):]), first...)
			case "reflect":
				key = "server-record-key"
			case "other-session":
				key = "another-session-key"
			case "truncated":
				wire = first
				expected = "private-user-data"
			case "oversize":
				binary.BigEndian.PutUint16(wire[1:3], authRecordMax+1)
			case "empty-data":
				wire[1], wire[2] = 0, 0
			}
			r, _ := memoryStream(wire, key)
			b, e := io.ReadAll(r)
			if e == nil || string(b) != expected {
				t.Fatalf("released %q, error %v", b, e)
			}
			if n, e := r.Read(make([]byte, 1)); n != 0 || e == nil {
				t.Fatal("poisoned reader recovered")
			}
		})
	}
	r, _ := memoryStream(full, "client-record-key")
	b, e := io.ReadAll(r)
	if e != nil || string(b) != "private-user-data" {
		t.Fatal(string(b), e)
	}
	if _, e = w.Write([]byte("after-fin")); !errors.Is(e, net.ErrClosed) {
		t.Fatal(e)
	}
}

func authPeer(t *testing.T, credential string) *server {
	t.Helper()
	s, e := newServer(Config{TCPSecurity: "hmac-stream-v1", Role: "egress", Secret: credential, Listen: "127.0.0.1:0", Targets: []int{1}, TCP: true})
	if e != nil {
		t.Fatal(e)
	}
	return s
}

func authPipe(t *testing.T) (net.Conn, net.Conn) {
	t.Helper()
	a, b := net.Pipe()
	t.Cleanup(func() { a.Close(); b.Close() })
	a.SetDeadline(time.Now().Add(3 * time.Second))
	b.SetDeadline(time.Now().Add(3 * time.Second))
	return a, b
}

func TestAuthStreamHandshakeDuplexAndAuthenticatedHalfClose(t *testing.T) {
	a, b := authPipe(t)
	s := authPeer(t, secret)
	result := make(chan error, 1)
	payload := bytes.Repeat([]byte("large-bidirectional-payload"), 4096)
	go func() {
		c, e := s.wrapTCP(context.Background(), b, true)
		if e != nil {
			result <- e
			return
		}
		defer c.Close()
		data, e := io.ReadAll(c)
		if e == nil && !bytes.Equal(data, payload) {
			e = errors.New("payload mismatch")
		}
		if e == nil {
			_, e = c.Write(data)
		}
		if e == nil {
			e = c.(interface{ CloseWrite() error }).CloseWrite()
		}
		result <- e
	}()
	c, e := s.wrapTCP(context.Background(), a, false)
	if e != nil {
		t.Fatal(e)
	}
	c.SetDeadline(time.Now().Add(3 * time.Second))
	if _, e = c.Write(payload); e != nil {
		t.Fatal(e)
	}
	if e = c.(interface{ CloseWrite() error }).CloseWrite(); e != nil {
		t.Fatal(e)
	}
	data, e := io.ReadAll(c)
	if e != nil || !bytes.Equal(data, payload) {
		t.Fatal("duplex/FIN failed", e)
	}
	if e = <-result; e != nil {
		t.Fatal(e)
	}
}

func TestAuthStreamWrongSecretAndModeMismatchFailClosed(t *testing.T) {
	for _, mode := range []string{"wrong-secret", "tls-client", "tls-server"} {
		t.Run(mode, func(t *testing.T) {
			a, b := authPipe(t)
			client, server := authPeer(t, secret), authPeer(t, secret)
			if mode == "wrong-secret" {
				client = authPeer(t, strings.Repeat("2", 64))
			}
			if mode == "tls-client" {
				client.cfg.TCPSecurity = "tls"
				client.clientTLS = client.tlsConfig(false)
			}
			if mode == "tls-server" {
				server.cfg.TCPSecurity = "tls"
				server.serverTLS = server.tlsConfig(true)
			}
			ctx, cancel := context.WithTimeout(context.Background(), 250*time.Millisecond)
			defer cancel()
			done := make(chan error, 1)
			go func() { _, e := server.wrapTCP(ctx, b, true); done <- e }()
			if _, e := client.wrapTCP(ctx, a, false); e == nil {
				t.Fatal("client accepted wrong peer")
			}
			if e := <-done; e == nil {
				t.Fatal("server accepted wrong peer")
			}
		})
	}
}

func TestAuthStreamCapturedClientProofCannotReplayOnFreshConnection(t *testing.T) {
	s := authPeer(t, secret)
	// A valid proof for a fixed, previously observed server nonce.
	var clientNonce, oldServerNonce [32]byte
	clientNonce[0], oldServerNonce[0] = 1, 2
	oldProof := s.mac([]byte("speedbox-origin-hmac-stream-v1\x00client-proof\x00"), clientNonce[:], oldServerNonce[:])
	a, b := authPipe(t)
	done := make(chan error, 1)
	go func() { _, e := s.wrapTCP(context.Background(), b, true); done <- e }()
	if e := writeFull(a, append([]byte(authMagic), clientNonce[:]...)); e != nil {
		t.Fatal(e)
	}
	var reply [64]byte
	if _, e := io.ReadFull(a, reply[:]); e != nil {
		t.Fatal(e)
	}
	if e := writeFull(a, oldProof); e != nil {
		t.Fatal(e)
	}
	if e := <-done; e == nil {
		t.Fatal("old connection proof accepted")
	}
}

func TestAuthStreamConfigurationIsExplicit(t *testing.T) {
	c := Config{Role: "egress", Secret: secret, Listen: "127.0.0.1:0", Targets: []int{1}, TCP: true}
	s, e := newServer(c)
	if e != nil || s.clientTLS == nil || s.serverTLS == nil {
		t.Fatal("default is no longer TLS", e)
	}
	c.TCPSecurity = "plain"
	if _, e = newServer(c); e == nil {
		t.Fatal("unknown/insecure mode accepted")
	}
}
