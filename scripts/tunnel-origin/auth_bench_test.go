package main

import (
	"context"
	"net"
	"testing"
)

// In-process handshake cost only: these figures are NOT network ping or a
// prediction of carrier latency. Both sides use the same net.Pipe harness.
func BenchmarkOriginHandshake(b *testing.B) {
	for _, mode := range []string{"tls", "hmac-stream-v1"} {
		b.Run(mode, func(b *testing.B) {
			s, e := newServer(Config{TCPSecurity: mode, Role: "egress", Secret: secret, Listen: "127.0.0.1:0", Targets: []int{1}, TCP: true})
			if e != nil {
				b.Fatal(e)
			}
			b.ReportAllocs()
			b.ResetTimer()
			for i := 0; i < b.N; i++ {
				a, peer := net.Pipe()
				done := make(chan error, 1)
				go func() { _, e := s.wrapTCP(context.Background(), peer, true); peer.Close(); done <- e }()
				_, e := s.wrapTCP(context.Background(), a, false)
				a.Close()
				serverErr := <-done
				if e != nil || serverErr != nil {
					b.Fatal(e, serverErr)
				}
			}
		})
	}
}
