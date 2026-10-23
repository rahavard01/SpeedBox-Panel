package origin

import (
	"bufio"
	"encoding/json"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func testBroker(t *testing.T) (*Registry, string) {
	t.Helper()
	dir := t.TempDir()
	if err := os.Chmod(dir, 0700); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(dir, "origin.sock")
	r, err := Listen(path)
	if err != nil {
		if os.Getenv("SPEEDBOX_TEST_NO_UNIX") == "1" {
			t.Skip("local runtime prohibits Unix sockets; exercised in Linux CI")
		}
		t.Fatal(err)
	}
	t.Cleanup(func() { r.Close() })
	return r, path
}
func testRegister(t *testing.T, path string, source, target uint16, ip string) net.Conn {
	t.Helper()
	c, err := net.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { c.Close() })
	c.SetDeadline(time.Now().Add(time.Second))
	json.NewEncoder(c).Encode(Registration{Version: 1, SourcePort: source, TargetPort: target, OriginalIP: ip})
	line, err := bufio.NewReader(c).ReadString('\n')
	if err != nil || line != "OK\n" {
		t.Fatalf("registration: %q %v", line, err)
	}
	c.SetDeadline(time.Time{})
	return c
}
func TestPrivateOriginIdentityAndLifecycle(t *testing.T) {
	r, path := testBroker(t)
	c := testRegister(t, path, 43001, 2087, "::ffff:198.51.100.7")
	p := Policy{Required: true, Port: 2087, Registry: r}
	for _, ip := range []string{"127.0.0.1", "::ffff:127.0.0.1"} {
		got, err := p.Resolve(netip.MustParseAddr(ip), 43001)
		if err != nil || got != "198.51.100.7" {
			t.Fatal(got, err)
		}
	}
	if _, err := p.Resolve(netip.MustParseAddr("127.0.0.1"), 43002); err == nil {
		t.Fatal("unregistered loopback accepted")
	}
	if _, err := (Policy{Required: true, Port: 2088, Registry: r}).Resolve(netip.MustParseAddr("127.0.0.1"), 43001); err == nil {
		t.Fatal("registration leaked into a different node")
	}
	if got, err := p.Resolve(netip.MustParseAddr("203.0.113.9"), 43001); err != nil || got != "203.0.113.9" {
		t.Fatal("direct client identity changed", got, err)
	}
	c.Close()
	until := time.Now().Add(time.Second)
	for {
		_, ok := r.Lookup(43001, 2087)
		if !ok {
			break
		}
		if time.Now().After(until) {
			t.Fatal("closed origin retained")
		}
		time.Sleep(time.Millisecond)
	}
	if _, err := p.Resolve(netip.MustParseAddr("127.0.0.1"), 43001); err == nil {
		t.Fatal("closed registration accepted")
	}
}
func TestOriginRejectsCollisionsInvalidAndUnsafeSocket(t *testing.T) {
	r, path := testBroker(t)
	_ = testRegister(t, path, 45001, 443, "2001:db8::1")
	for _, req := range []Registration{{Version: 1, SourcePort: 45002, TargetPort: 443, OriginalIP: "127.0.0.1"}, {Version: 1, SourcePort: 45002, TargetPort: 443, OriginalIP: "0.0.0.0"}, {Version: 2, SourcePort: 45002, TargetPort: 443, OriginalIP: "203.0.113.2"}, {Version: 1, TargetPort: 443, OriginalIP: "203.0.113.2"}} {
		c, err := net.Dial("unix", path)
		if err != nil {
			t.Fatal(err)
		}
		c.SetDeadline(time.Now().Add(time.Second))
		json.NewEncoder(c).Encode(req)
		var b [1]byte
		n, _ := c.Read(b[:])
		c.Close()
		if n != 0 {
			t.Fatal("invalid origin acknowledged", req)
		}
	}
	collision, err := net.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	collision.SetDeadline(time.Now().Add(time.Second))
	json.NewEncoder(collision).Encode(Registration{Version: 1, SourcePort: 45001, TargetPort: 443, OriginalIP: "203.0.113.2"})
	reply, e := bufio.NewReader(collision).ReadString('\n')
	collision.Close()
	if e != nil || reply != "BUSY\n" {
		t.Fatal("collision was not rejected", reply, e)
	}
	if ip, ok := r.Lookup(45001, 443); !ok || ip.String() != "2001:db8::1" {
		t.Fatal("collision replaced the original", ip, ok)
	}
	if _, err := Listen(path); err == nil {
		t.Fatal("live socket taken over")
	}
	st, _ := os.Stat(path)
	if st.Mode().Perm() != 0600 {
		t.Fatal("socket not private")
	}
	unsafe := filepath.Join(t.TempDir(), "public")
	os.Mkdir(unsafe, 0755)
	if _, err := Listen(filepath.Join(unsafe, "origin.sock")); err == nil {
		t.Fatal("public registration directory accepted")
	}
}
func TestBrokerShutdownRevokesAllRegistrations(t *testing.T) {
	r, path := testBroker(t)
	c := testRegister(t, path, 45001, 443, "198.51.100.1")
	r.Close()
	c.SetReadDeadline(time.Now().Add(time.Second))
	var b [1]byte
	if n, err := c.Read(b[:]); n != 0 || err == nil {
		t.Fatal("broker shutdown did not revoke forwarder")
	}
}
