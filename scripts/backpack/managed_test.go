package manage

import (
	"crypto/ecdh"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/pem"
	"github.com/BurntSushi/toml"
	"github.com/backpack/backpack/config"
	"math/big"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestSpeedboxNativePCKDoesNotChangeLegacyPCK(t *testing.T) {
	r := SpeedboxRequest{Name: "sb-0123456789abcdef0123456789abcdef", Role: "iran", Host: "192.0.2.1", Port: 60000, Token: strings.Repeat("a", 64), Ports: "60001=60002", LocalIP: "10.203.250.1/30", PeerIP: "10.203.250.2"}
	r.Transport = "pck"
	legacy, err := sbDirectSpec(r)
	if err != nil {
		t.Fatal(err)
	}
	var cfg config.Config
	if _, err := toml.Decode(legacy.Render(), &cfg); err != nil {
		t.Fatal(err)
	}
	if cfg.L3.Iface != legacy.Iface || cfg.L3.Iface == "" {
		t.Fatal("legacy PCK lost its TUN wire")
	}
	r.Transport = "paqet"
	native, err := sbDirectSpec(r)
	if err != nil {
		t.Fatal(err)
	}
	cfg = config.Config{}
	if _, err := toml.Decode(native.Render(), &cfg); err != nil {
		t.Fatal(err)
	}
	if cfg.L3.Iface != "" || cfg.L3.Carrier != "pck" {
		t.Fatal("native PCK did not use the new engine")
	}
	r.Swapped = true
	if _, err = sbDirectSpec(r); err == nil {
		t.Fatal("native PCK moved public ingress abroad")
	}
}

func TestSpeedboxManagedHTTPSUsesPrivateReverseTarget(t *testing.T) {
	binary := filepath.Join(t.TempDir(), "helper")
	if err := os.WriteFile(binary, []byte("#!/bin/sh\nexit 0\n"), 0700); err != nil {
		t.Fatal(err)
	}
	old := sbManagedHelper
	sbManagedHelper = func(string) string { return binary }
	t.Cleanup(func() { sbManagedHelper = old })
	priv, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	template := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "tunnel.example.com"}, DNSNames: []string{"tunnel.example.com"}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour), KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}
	der, err := x509.CreateCertificate(rand.Reader, template, template, &priv.PublicKey, priv)
	if err != nil {
		t.Fatal(err)
	}
	key, err := x509.MarshalECPrivateKey(priv)
	if err != nil {
		t.Fatal(err)
	}
	reality, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	for _, mode := range []string{"naive", "xhttp", "reality"} {
		for _, role := range []string{"server", "client"} {
			m := &SpeedboxManaged{Mode: mode, ServerName: "tunnel.example.com", UUID: "6bf7a33e-7833-4e72-9219-506585657345", Username: "sb-user", Password: "secret", Connections: 4}
			if mode == "reality" {
				m.Target = "tunnel.example.com:443"
				m.ShortID = "0123456789abcdef"
				m.PrivateKey = base64.RawURLEncoding.EncodeToString(reality.Bytes())
				m.PublicKey = base64.RawURLEncoding.EncodeToString(reality.PublicKey().Bytes())
			} else {
				m.Certificate = string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
				m.Key = string(pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: key}))
				if mode == "xhttp" {
					m.Path = "/sb-test"
				}
			}
			r := SpeedboxRequest{Name: "sb-0123456789abcdef0123456789abcdef", Kind: "reverse", Role: role, Transport: mode, Host: "192.0.2.1", Port: 18443, BridgePort: 44000, Managed: m}
			s := TunnelSpec{Role: role, Transport: "tcp", Name: r.Name, Token: strings.Repeat("a", 64), Ports: []string{"60001=60002"}}
			ApplyPreset(&s, PresetTurbo)
			if err := sbConfigureManaged(&s, r); err != nil {
				t.Fatalf("%s %s: %v", mode, role, err)
			}
			if s.AcceptUDP || (role == "server" && s.BindAddr != "127.0.0.1:44000") || (role == "client" && s.RemoteAddr != "127.0.0.1:44000") {
				t.Fatal("managed reverse target exposed")
			}
			r.Swapped = true
			if err := sbConfigureManaged(&s, r); err == nil {
				t.Fatal("unsupported swapped managed helper accepted")
			}
		}
	}
}
