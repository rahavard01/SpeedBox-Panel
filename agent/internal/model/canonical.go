package model

import "encoding/json"

// CanonicalNodeSpec removes disabled editor defaults, not effective settings.
func CanonicalNodeSpec(n *NodeSpec) *NodeSpec {
	if n == nil {
		return nil
	}
	data, _ := json.Marshal(n)
	var c NodeSpec
	_ = json.Unmarshal(data, &c)
	if c.Multiplex != nil {
		if !c.Multiplex.Enabled {
			c.Multiplex = nil
		} else if c.Multiplex.Brutal != nil && !c.Multiplex.Brutal.Enabled {
			c.Multiplex.Brutal = nil
		}
	}
	if c.CertConfig != nil && (c.CertConfig.CertMode == "none" || (c.CertConfig.CertMode == "" && !c.CertConfig.AutoTLS && c.CertConfig.CertFile == "" && c.CertConfig.CertContent == "")) {
		c.CertConfig = nil
	}
	c.NetworkSettings = emptyMap(c.NetworkSettings)
	c.TLSSettings = emptyMap(c.TLSSettings)
	if c.TLSSettings != nil {
		if c.TLSSettings["server_name"] == "" {
			delete(c.TLSSettings, "server_name")
		}
		if c.TLSSettings["allow_insecure"] == false {
			delete(c.TLSSettings, "allow_insecure")
		}
		if ech, ok := c.TLSSettings["ech"].(map[string]any); ok && ech["enabled"] == false {
			delete(c.TLSSettings, "ech")
		}
		c.TLSSettings = emptyMap(c.TLSSettings)
	}
	return &c
}
func emptyMap(m map[string]any) map[string]any {
	if len(m) == 0 {
		return nil
	}
	return m
}

// Cert policy is applied by the service; the kernel receives the resulting PEM.
func KernelNodeSpec(n *NodeSpec) *NodeSpec {
	c := CanonicalNodeSpec(n)
	if c != nil {
		c.CertConfig = nil
	}
	return c
}
