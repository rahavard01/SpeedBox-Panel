package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"testing"

	"github.com/go-acme/lego/v4/certcrypto"
)

func TestACMEConfigGeneratesFreshCertificateKey(t *testing.T) {
	config := acmeConfig(&account{})
	key, err := certcrypto.GeneratePrivateKey(config.Certificate.KeyType)
	if err != nil {
		t.Fatalf("generate certificate key: %v", err)
	}
	ec, ok := key.(*ecdsa.PrivateKey)
	if !ok || ec.Curve != elliptic.P256() {
		t.Fatalf("expected an ECDSA P-256 certificate key, got %T", key)
	}
}
