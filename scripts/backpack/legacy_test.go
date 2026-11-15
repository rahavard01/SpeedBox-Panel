package l3

import "testing"

func TestSpeedboxCompatibilityIsRestrictedToOwnedInterfaces(t *testing.T) {
	for _, carrier := range []string{CarrierUDP, CarrierQuic, CarrierPck} {
		for _, iface := range []string{"sbp0123456789a", "bp0", "sbp-not-owned", ""} {
			c := Config{Carrier: carrier, Iface: iface}
			if got, want := speedboxLegacyConfig(c), iface == "sbp0123456789a"; got != want {
				t.Fatalf("carrier=%s iface=%s allowed=%v", carrier, iface, got)
			}
		}
	}
	// Public upstream names and menus must keep reflecting the new engine.
	if KnownCarrier("udp") || KnownCarrier("quic") {
		t.Fatal("legacy carrier leaked into upstream menu")
	}
}
