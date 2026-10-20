package xray

import (
	"bytes"
	"encoding/json"
	"reflect"
	"testing"

	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/xtls/xray-core/infra/conf/serial"
)

func TestWarpEgressIsPerNodeReversibleAndKeepsExistingRules(t *testing.T) {
	for _, protocol := range []string{"socks", "trojan", "vless", "vmess", "shadowsocks"} {
		t.Run(protocol, func(t *testing.T) {
			nc := &model.NodeSpec{Protocol: protocol, ServerPort: 12345, Cipher: "aes-128-gcm", WarpEnabled: true,
				CustomRoutes: []map[string]any{{"type": "field", "domain": []string{"example.test"}, "outboundTag": "direct"}}}
			before, _ := json.Marshal(nc.CustomRoutes)
			cfg := buildConfig(config.KernelConfig{Type: "xray"}, nc, testUsers, kernel.TLSCert{})
			outbounds := cfg["outbounds"].([]M)
			warp := outbounds[len(outbounds)-1]
			if warp["tag"] != model.WarpOutboundTag || warp["protocol"] != "socks" {
				t.Fatalf("invalid WARP outbound: %v", warp)
			}
			server := warp["settings"].(M)["servers"].([]M)[0]
			if server["address"] != "127.0.0.1" || server["port"] != 40000 {
				t.Fatalf("unexpected WARP endpoint: %v", server)
			}
			rules := cfg["routing"].(M)["rules"].([]M)
			if !reflect.DeepEqual(rules[0]["domain"], []string{"example.test"}) {
				t.Fatal("custom rule priority changed")
			}
			if rules[1]["outboundTag"] != "block" {
				t.Fatal("private-IP protection lost")
			}
			for i, network := range []string{"tcp", "udp"} {
				rule := rules[len(rules)-2+i]
				if rule["network"] != network || !reflect.DeepEqual(rule["inboundTag"], []string{protocol + "-in"}) {
					t.Fatalf("wrong node scope: %v", rule)
				}
				want := model.WarpOutboundTag
				if network == "udp" {
					want = "direct"
				}
				if rule["outboundTag"] != want {
					t.Fatalf("wrong route: %v", rule)
				}
			}
			data, _ := json.Marshal(cfg)
			if _, err := serial.LoadJSONConfig(bytes.NewReader(data)); err != nil {
				t.Fatalf("Xray rejected WARP config: %v", err)
			}
			nc.WarpEnabled = false
			disabled, _ := json.Marshal(buildConfig(config.KernelConfig{Type: "xray"}, nc, testUsers, kernel.TLSCert{}))
			if bytes.Contains(disabled, []byte(model.WarpOutboundTag)) {
				t.Fatal("WARP remains after disabling")
			}
			after, _ := json.Marshal(nc.CustomRoutes)
			if !bytes.Equal(before, after) {
				t.Fatal("manual routes mutated")
			}
		})
	}
}
