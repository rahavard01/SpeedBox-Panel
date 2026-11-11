package main

import (
	"bytes"
	"io"
	"testing"
)

// Same payload and framing before/after: measures copy allocations, not RTT.
func BenchmarkOriginRelayMemory(b *testing.B) {
	payload := bytes.Repeat([]byte("x"), 4096)
	w, raw := memoryStream(nil, "key")
	w.Write(payload)
	w.CloseWrite()
	wire := bytes.Clone(raw.output.Bytes())
	b.Run("encode", func(b *testing.B) {
		b.ReportAllocs()
		for i := 0; i < b.N; i++ {
			dst, _ := memoryStream(nil, "key")
			src := &authMemoryConn{input: bytes.NewReader(payload)}
			if n, e := copyOriginStream(dst, src); e != nil || n != int64(len(payload)) {
				b.Fatal(n, e)
			}
		}
	})
	b.Run("decode", func(b *testing.B) {
		b.ReportAllocs()
		for i := 0; i < b.N; i++ {
			src, _ := memoryStream(wire, "key")
			dst := &authMemoryConn{input: bytes.NewReader(nil)}
			if n, e := copyOriginStream(dst, src); e != nil || n != int64(len(payload)) {
				b.Fatal(n, e)
			}
		}
	})
}

func TestOriginRelayVerifiedBytesOnly(t *testing.T) {
	payload := bytes.Repeat([]byte("authenticated"), 10000)
	w, raw := memoryStream(nil, "key")
	w.Write(payload)
	w.CloseWrite()
	wire := bytes.Clone(raw.output.Bytes())
	for _, kind := range []string{"valid", "tampered", "truncated"} {
		t.Run(kind, func(t *testing.T) {
			input := bytes.Clone(wire)
			if kind == "tampered" {
				input[3] ^= 1
			}
			if kind == "truncated" {
				input = input[:len(input)-35]
			}
			src, _ := memoryStream(input, "key")
			dst := &authMemoryConn{input: bytes.NewReader(nil)}
			n, e := copyOriginStream(dst, src)
			if kind == "valid" {
				if e != nil || n != int64(len(payload)) || !bytes.Equal(dst.output.Bytes(), payload) {
					t.Fatal(n, e)
				}
			} else if e == nil {
				t.Fatal("accepted invalid stream")
			}
			if kind == "tampered" && dst.output.Len() != 0 {
				t.Fatal("released unauthenticated bytes")
			}
		})
	}
	// Existing Read may have partially consumed a verified record before relay.
	src, _ := memoryStream(wire, "key")
	prefix := make([]byte, 7)
	io.ReadFull(src, prefix)
	dst := &authMemoryConn{input: bytes.NewReader(nil)}
	if _, e := copyOriginStream(dst, src); e != nil || !bytes.Equal(append(prefix, dst.output.Bytes()...), payload) {
		t.Fatal("lost pending bytes", e)
	}
}
