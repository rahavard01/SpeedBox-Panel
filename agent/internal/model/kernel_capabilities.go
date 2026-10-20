package model

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"strings"
)

//go:embed kernel-capabilities.json
var kernelCapabilitiesJSON []byte

type kernelCapabilityRule struct {
	Kernels []string `json:"kernels"`
}

var kernelCapabilities = func() struct {
	Protocols map[string][]string             `json:"protocols"`
	Features  map[string]kernelCapabilityRule `json:"features"`
} {
	var definition struct {
		Protocols map[string][]string             `json:"protocols"`
		Features  map[string]kernelCapabilityRule `json:"features"`
	}
	if err := json.Unmarshal(kernelCapabilitiesJSON, &definition); err != nil {
		panic(fmt.Sprintf("invalid embedded kernel capabilities: %v", err))
	}
	return definition
}()

func validateKernelCapabilities(n *NodeSpec, kernelType string) error {
	allowed := kernelCapabilities.Protocols[n.Protocol]
	transport := n.Protocol == "vmess" || n.Protocol == "vless" || n.Protocol == "trojan"
	network := strings.ToLower(strings.TrimSpace(n.Network))
	features := []struct {
		name    string
		enabled bool
	}{
		{"hysteria1", n.Protocol == "hysteria" && n.Version == 1},
		{"tuic_legacy", n.Protocol == "tuic" && n.Version != 0 && n.Version != 5},
		{"xray_transport", transport && singboxUnsupportedTransports[network]},
		{"vless_encryption", n.Protocol == "vless" && n.Decryption != "" && n.Decryption != "none"},
		{"tcp_http", transport && IsTCPHTTPTransport(n.Network, n.NetworkSettings)},
		{"ss2022_chacha", n.Protocol == "shadowsocks" && n.Cipher == "2022-blake3-chacha20-poly1305"},
	}
	for _, feature := range features {
		if !feature.enabled {
			continue
		}
		var filtered []string
		for _, candidate := range allowed {
			for _, required := range kernelCapabilities.Features[feature.name].Kernels {
				if candidate == required {
					filtered = append(filtered, candidate)
					break
				}
			}
		}
		allowed = filtered
	}
	for _, candidate := range allowed {
		if candidate == kernelType {
			return nil
		}
	}
	return fmt.Errorf("protocol %q and its settings are not supported by kernel %q", n.Protocol, kernelType)
}
