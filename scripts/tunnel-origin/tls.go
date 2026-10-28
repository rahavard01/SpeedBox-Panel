package main

import (
	"crypto/ed25519"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"math/big"
	"time"
)

// Mutual TLS binds all TCP bytes to the authenticated origin. A signed header
// alone would permit a plaintext-carrier observer to replace its later payload.
// The private key is derived only on the two managed endpoints; it is
// domain-separated from UDP authentication and native tunnel credentials.
func (s *server) tlsConfig(serverSide bool) *tls.Config {
	seed := sha256.Sum256(append([]byte("speedbox-origin-tls-v2\x00"), s.key...))
	key := ed25519.NewKeyFromSeed(seed[:])
	template := &x509.Certificate{SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: "SpeedBox private origin"}, DNSNames: []string{"origin.internal"}, NotBefore: time.Date(2000, 1, 1, 0, 0, 0, 0, time.UTC), NotAfter: time.Date(2100, 1, 1, 0, 0, 0, 0, time.UTC), KeyUsage: x509.KeyUsageDigitalSignature | x509.KeyUsageCertSign, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth, x509.ExtKeyUsageClientAuth}, BasicConstraintsValid: true, IsCA: true}
	der, e := x509.CreateCertificate(nil, template, template, key.Public(), key)
	if e != nil {
		panic(e)
	}
	cert, e := x509.ParseCertificate(der)
	if e != nil {
		panic(e)
	}
	roots := x509.NewCertPool()
	roots.AddCert(cert)
	cfg := &tls.Config{MinVersion: tls.VersionTLS13, Certificates: []tls.Certificate{{Certificate: [][]byte{der}, PrivateKey: key, Leaf: cert}}, RootCAs: roots, ServerName: "origin.internal"}
	if serverSide {
		cfg.ClientAuth = tls.RequireAndVerifyClientCert
		cfg.ClientCAs = roots
	}
	return cfg
}
