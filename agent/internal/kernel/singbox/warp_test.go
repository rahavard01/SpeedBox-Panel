package singbox

import (
	"bytes"
	"context"
	"encoding/json"
	"reflect"
	"testing"

	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/sagernet/sing-box/include"
	"github.com/sagernet/sing-box/option"
	singJSON "github.com/sagernet/sing/common/json"
)

func TestWarpEgressIsPerNodeReversibleAndKeepsExistingRules(t *testing.T) {
	for _, protocol := range []string{"socks", "trojan", "vless", "vmess", "shadowsocks", "hysteria", "tuic", "anytls", "naive", "http", "mieru"} {
		t.Run(protocol, func(t *testing.T) {
			nc := &model.NodeSpec{Protocol: protocol, ServerPort: 12345, Cipher: "aes-128-gcm", WarpEnabled: true,
				CustomRoutes: []map[string]any{{"domain": []string{"example.test"}, "outbound": "direct"}}}
			before, _ := json.Marshal(nc.CustomRoutes)
			cfg := buildConfig(config.KernelConfig{Type: "singbox"}, nc, testUsers, kernel.TLSCert{})
			outbounds := cfg["outbounds"].([]M)
			warp := outbounds[len(outbounds)-1]
			if warp["tag"] != model.WarpOutboundTag || warp["type"] != "socks" || warp["server"] != "127.0.0.1" || warp["server_port"] != 40000 {
				t.Fatalf("invalid WARP outbound: %v", warp)
			}
			rules := cfg["route"].(M)["rules"].([]M)
			if !reflect.DeepEqual(rules[0]["domain"], []string{"example.test"}) {
				t.Fatal("custom rule priority changed")
			}
			if rules[1]["outbound"] != "block" {
				t.Fatal("private-IP protection lost")
			}
			for i, network := range []string{"tcp", "udp"} {
				rule := rules[len(rules)-2+i]
				if rule["network"] != network || !reflect.DeepEqual(rule["inbound"], []string{protocol + "-in"}) {
					t.Fatalf("wrong node scope: %v", rule)
				}
				want := model.WarpOutboundTag
				if network == "udp" {
					want = "direct"
				}
				if rule["outbound"] != want {
					t.Fatalf("wrong route: %v", rule)
				}
			}
			data, _ := json.Marshal(cfg)
			if _, err := singJSON.UnmarshalExtendedContext[option.Options](include.Context(context.Background()), data); err != nil {
				t.Fatalf("Sing-box rejected WARP config: %v", err)
			}
			nc.WarpEnabled = false
			disabled, _ := json.Marshal(buildConfig(config.KernelConfig{Type: "singbox"}, nc, testUsers, kernel.TLSCert{}))
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
