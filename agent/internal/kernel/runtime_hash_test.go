package kernel

import (
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/model"
	"testing"
)

func TestRuntimeHashUsesEffectiveSettings(t *testing.T) {
	base := &model.NodeSpec{Protocol: "vless", ServerPort: 443}
	equivalent := *base
	equivalent.Multiplex = &model.MultiplexConfig{Enabled: false, Protocol: "smux", MaxConnections: 4}
	equivalent.NetworkSettings = map[string]any{}
	equivalent.TLSSettings = map[string]any{"server_name": "", "allow_insecure": false, "ech": map[string]any{"enabled": false}}
	equivalent.CertConfig = &config.CertConfig{CertMode: "none"}
	if ComputeHash(base, nil) != ComputeHash(&equivalent, nil) {
		t.Fatal("disabled editor defaults restarted a listener")
	}
	for _, mutate := range []func(*model.NodeSpec){func(n *model.NodeSpec) { n.WarpEnabled = true }, func(n *model.NodeSpec) { n.Routes = []model.RouteRule{{ID: 1, Action: "block"}} }} {
		n := *base
		mutate(&n)
		if ComputeHash(base, nil) == ComputeHash(&n, nil) {
			t.Fatal("runtime change lost")
		}
		if InboundHash(base, nil) != InboundHash(&n, nil) {
			t.Fatal("routing-only change reconstructed Sing-box listener")
		}
	}
	n := *base
	n.ServerPort = 8443
	if InboundHash(base, nil) == InboundHash(&n, nil) {
		t.Fatal("port change ignored")
	}
	n = *base
	n.Multiplex = &model.MultiplexConfig{Enabled: true}
	if ComputeHash(base, nil) == ComputeHash(&n, nil) {
		t.Fatal("enabled multiplex ignored")
	}
	n = *base
	n.CertConfig = &config.CertConfig{CertMode: "dns", Email: "a@example.test"}
	if ComputeHash(base, nil) != ComputeHash(&n, nil) {
		t.Fatal("certificate management policy changed listener hash")
	}
}
