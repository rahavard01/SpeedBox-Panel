package l3

import (
	"net"
	"time"
)

// Only the private UDP bridge used by swapped reverse carriers needs this.
// Preserve its 60-second mapping with authenticated request/reply traffic;
// ordinary Internet carriers and temporary user flows retain their policies.
var speedboxInternalKeepalive = 15 * time.Second

func (t *Tunnel) speedboxInternalCarrier() bool {
	if t.cfg.Carrier != CarrierUDP {
		return false
	}
	host, _, err := net.SplitHostPort(t.cfg.Addr)
	ip := net.ParseIP(host)
	return err == nil && ip != nil && ip.IsLoopback()
}
