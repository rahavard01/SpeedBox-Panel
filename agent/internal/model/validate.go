package model

import (
	"fmt"
	"strings"

	"github.com/cedar2025/xboard-node/internal/config"
)

func ValidateNodeSpec(n *NodeSpec, kcfg config.KernelConfig) error {
	if n == nil {
		return nil
	}

	effectiveKernelType := strings.TrimSpace(kcfg.Type)
	if effectiveKernelType == "" {
		effectiveKernelType = strings.TrimSpace(n.KernelType)
	}
	kernelType, err := normalizeKernelType(effectiveKernelType)
	if err != nil {
		return fmt.Errorf("normalize kernel type: %w", err)
	}

	additionalOutboundSources, err := collectAdditionalOutboundTagSources(kcfg.CustomConfig, kcfg.CustomOutbound)
	if err != nil {
		return fmt.Errorf("collect additional outbound tags: %w", err)
	}
	if err := validateOutboundTagCollisions(n.CustomOutbounds, additionalOutboundSources); err != nil {
		return fmt.Errorf("validate outbound tags: %w", err)
	}
	for _, source := range additionalOutboundSources {
		if strings.EqualFold(strings.TrimSpace(source.Tag), WarpOutboundTag) {
			return fmt.Errorf("outbound tag %q is reserved for automatic WARP egress", WarpOutboundTag)
		}
	}
	for _, outbound := range n.CustomOutbounds {
		if strings.EqualFold(strings.TrimSpace(outbound.Tag), WarpOutboundTag) {
			return fmt.Errorf("outbound tag %q is reserved for automatic WARP egress", WarpOutboundTag)
		}
	}
	additionalTags := additionalTagNames(additionalOutboundSources)
	availableTags := buildAvailableOutboundTags(n.CustomOutbounds, additionalTags)
	if err := ValidateCustomOutboundsForKernel(n.CustomOutbounds, kernelType, additionalTags); err != nil {
		return fmt.Errorf("validate custom outbounds: %w", err)
	}
	if err := ValidateCustomRouteRules(n.CustomRouteRules, kernelType, availableTags); err != nil {
		return fmt.Errorf("validate custom route rules: %w", err)
	}
	if err := validateTransportKernel(n.Network, kernelType); err != nil {
		return err
	}
	if err := validateKernelCapabilities(n, kernelType); err != nil {
		return err
	}
	if IsTCPHTTPTransport(n.Network, n.NetworkSettings) {
		if kernelType != "xray" {
			return fmt.Errorf("TCP HTTP obfuscation requires the xray kernel")
		}
		if n.Protocol == "vless" && n.TLS == 0 && n.Flow != "" {
			return fmt.Errorf("VLESS TCP HTTP without TLS requires an empty flow")
		}
	}
	return nil
}

// IsTCPHTTPTransport recognizes the HTTP-obfs template without changing plain
// TCP, TLS or Reality. Historical TCP names share the same header settings.
func IsTCPHTTPTransport(network string, settings map[string]any) bool {
	net := strings.ToLower(strings.TrimSpace(network))
	if net != "" && net != "tcp" && net != "raw" {
		return false
	}
	header, ok := settings["header"].(map[string]any)
	if !ok {
		return false
	}
	kind, _ := header["type"].(string)
	return strings.EqualFold(strings.TrimSpace(kind), "http")
}

// singboxUnsupportedTransports lists transport types that sing-box does not support.
var singboxUnsupportedTransports = map[string]bool{
	"xhttp":     true,
	"splithttp": true,
	"kcp":       true,
}

func validateTransportKernel(network, kernelType string) error {
	net := strings.ToLower(strings.TrimSpace(network))
	if kernelType == "singbox" && singboxUnsupportedTransports[net] {
		return fmt.Errorf("transport %q is not supported by sing-box kernel; use xray kernel instead", net)
	}
	return nil
}

// ResolveKernelForTransport returns the kernel type required by the given
// transport. If the configured kernel cannot handle the transport, it returns
// the kernel that can. Otherwise it returns configuredKernel unchanged.
// This is used in machine mode to auto-switch kernel per node.
func ResolveKernelForTransport(network, configuredKernel string) string {
	net := strings.ToLower(strings.TrimSpace(network))
	if configuredKernel == "singbox" && singboxUnsupportedTransports[net] {
		return "xray"
	}
	return configuredKernel
}

func normalizeKernelType(value string) (string, error) {
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "singbox", "sing-box":
		return "singbox", nil
	case "xray":
		return "xray", nil
	default:
		return "", fmt.Errorf("unsupported kernel type %q", value)
	}
}

func buildAvailableOutboundTags(structured []OutboundConfig, rawTags []string) map[string]struct{} {
	available := map[string]struct{}{
		"direct": {},
		"block":  {},
	}
	for _, outbound := range structured {
		tag := strings.ToLower(strings.TrimSpace(outbound.Tag))
		if tag != "" {
			available[tag] = struct{}{}
		}
	}
	for _, tag := range rawTags {
		tag = strings.ToLower(strings.TrimSpace(tag))
		if tag != "" {
			available[tag] = struct{}{}
		}
	}
	return available
}
