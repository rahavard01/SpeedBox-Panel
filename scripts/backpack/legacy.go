package l3

import (
	"context"
	"fmt"
	"net"
	"regexp"
)

// A compatibility exception for configurations owned by the SpeedBox bridge.
// Upstream's removed-carrier refusal remains in place for every other config.
var speedboxIface = regexp.MustCompile(`^sbp[a-f0-9]{11}$`)

func speedboxLegacyConfig(c Config) bool {
	return speedboxIface.MatchString(c.Iface) &&
		(c.Carrier == CarrierUDP || c.Carrier == CarrierQuic || c.Carrier == CarrierPck)
}

func speedboxOpenLegacy(ctx context.Context, c Config) (DatagramCarrier, net.Addr, error) {
	if err := c.Multipath.Validate(); err != nil {
		return nil, nil, err
	}
	if c.Multipath.Enabled() && c.Carrier != CarrierUDP {
		return nil, nil, fmt.Errorf("l3: multiple legacy paths require UDP")
	}
	switch c.Carrier {
	case CarrierUDP:
		return openUDPPathsContext(ctx, c)
	case CarrierQuic:
		return openQuicContext(ctx, c)
	case CarrierPck:
		return openRawPck(c)
	}
	return nil, nil, fmt.Errorf("l3: unsupported SpeedBox compatibility carrier")
}
