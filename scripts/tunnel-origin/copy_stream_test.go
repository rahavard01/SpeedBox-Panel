package main

import (
	"bytes"
	"io"
	"net"
	"testing"
	"time"
)

func TestOriginLargeHalfClosedResponse(t *testing.T) {
	f := makeFixture(t)
	c, reader, line := openTCP(t, f.public)
	defer c.Close()
	if line != "OK 198.51.100.2\n" {
		t.Fatal(line)
	}
	c.SetDeadline(time.Now().Add(15 * time.Second))
	payload := bytes.Repeat([]byte("0123456789abcdef"), 65536)
	sent := make(chan error, 1)
	go func() {
		_, err := io.Copy(c, bytes.NewReader(payload))
		if err == nil {
			err = c.(*net.TCPConn).CloseWrite()
		}
		sent <- err
	}()
	answer, err := io.ReadAll(reader)
	if err != nil {
		t.Fatal(err)
	}
	if err = <-sent; err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(answer, payload) {
		t.Fatalf("half-close corrupted response: got=%d want=%d", len(answer), len(payload))
	}
	eventually(t, func() bool {
		ips, refs := f.gate.counts()
		return ips == 0 && refs == 0
	})
}
