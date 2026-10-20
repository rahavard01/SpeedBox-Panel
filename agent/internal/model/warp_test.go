package model

import (
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/panel"
	"testing"
)

func TestWarpPanelMappingAndReservedOutbound(t *testing.T) {
	n := NodeSpecFromPanel(&panel.NodeConfig{Protocol: "socks", WarpEnabled: true})
	if !n.WarpEnabled {
		t.Fatal("WARP setting lost in panel adapter")
	}
	for _, enabled := range []bool{false, true} {
		n.WarpEnabled = enabled
		n.CustomOutbounds = []OutboundConfig{{Tag: " Speedbox-Warp ", Protocol: "socks", Settings: map[string]any{"server": "127.0.0.1"}}}
		if err := ValidateNodeSpec(n, config.KernelConfig{Type: "xray"}); err == nil {
			t.Fatal("reserved panel tag accepted")
		}
		n.CustomOutbounds = nil
		if err := ValidateNodeSpec(n, config.KernelConfig{Type: "xray", CustomOutbound: []map[string]any{{"tag": "speedbox-warp"}}}); err == nil {
			t.Fatal("reserved local tag accepted")
		}
	}
}
