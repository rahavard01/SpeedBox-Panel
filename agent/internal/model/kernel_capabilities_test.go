package model

import (
	"testing"

	"github.com/cedar2025/xboard-node/internal/config"
)

func TestKernelCapabilitiesRejectUnsupportedRuntimeCombinations(t *testing.T) {
	if err := ValidateNodeSpec(&NodeSpec{Protocol: "tuic", Version: 4}, config.KernelConfig{Type: "singbox"}); err == nil {
		t.Fatal("TUIC 4 must not be silently built as TUIC 5")
	}
	for _, protocol := range []string{"tuic", "anytls", "naive", "mieru", "socks", "http"} {
		t.Run(protocol, func(t *testing.T) {
			n := &NodeSpec{Protocol: protocol}
			if err := ValidateNodeSpec(n, config.KernelConfig{Type: "xray"}); err == nil {
				t.Fatal("Xray must be rejected before runtime startup")
			}
			if err := ValidateNodeSpec(n, config.KernelConfig{Type: "singbox"}); err != nil {
				t.Fatal(err)
			}
		})
	}
	for _, n := range []*NodeSpec{
		{Protocol: "vless", Network: "xhttp"},
		{Protocol: "vless", Network: "kcp"},
		{Protocol: "vless", Decryption: "test-decryption"},
		{Protocol: "vmess", Network: "tcp", NetworkSettings: map[string]any{"header": map[string]any{"type": "http"}}},
	} {
		if err := ValidateNodeSpec(n, config.KernelConfig{Type: "singbox"}); err == nil {
			t.Fatalf("Sing-box accepted incompatible spec: %#v", n)
		}
		if err := ValidateNodeSpec(n, config.KernelConfig{Type: "xray"}); err != nil {
			t.Fatal(err)
		}
	}
	if err := ValidateNodeSpec(&NodeSpec{Protocol: "hysteria", Version: 1}, config.KernelConfig{Type: "xray"}); err == nil {
		t.Fatal("Hysteria 1 must reject Xray")
	}
	for _, kernel := range []string{"singbox", "xray"} {
		if err := ValidateNodeSpec(&NodeSpec{Protocol: "hysteria", Version: 2}, config.KernelConfig{Type: kernel}); err != nil {
			t.Fatal(err)
		}
	}
	if err := ValidateNodeSpec(&NodeSpec{Protocol: "shadowsocks", Cipher: "2022-blake3-chacha20-poly1305"}, config.KernelConfig{Type: "xray"}); err == nil {
		t.Fatal("multiuser SS2022 chacha must reject Xray")
	}
}
