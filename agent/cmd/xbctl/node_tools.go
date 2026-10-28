package main

import (
	"crypto/ecdh"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"github.com/cedar2025/xboard-node/internal/config"
)

// These helpers do not change config files or restart a kernel. Results are
// returned to the editor and only applied through the normal node save.
func runNodeTools(args []string) error {
	if len(args) == 0 {
		return errors.New("نوع ابزار نود مشخص نشده است.")
	}
	switch args[0] {
	case "sync":
		return syncNodes(args[1:])
	case "runtime-version":
		fmt.Println("1")
		return nil
	case "version":
		fmt.Println("1")
		return nil
	case "vless-encryption":
		return generateVLESSEncryption()
	case "certificate-fingerprint":
		return certificateFingerprint(args[1:])
	default:
		return errors.New("ابزار نود پشتیبانی نمی‌شود؛ ابزار سرور را به‌روزرسانی کنید.")
	}
}

func generateVLESSEncryption() error {
	privateKey := make([]byte, 32)
	if _, err := rand.Read(privateKey); err != nil {
		return errors.New("تولید کلید رمزنگاری ناموفق بود.")
	}
	privateKey[0] &= 248
	privateKey[31] &= 127
	privateKey[31] |= 64
	key, err := ecdh.X25519().NewPrivateKey(privateKey)
	if err != nil {
		return errors.New("تولید کلید رمزنگاری ناموفق بود.")
	}
	// Match the X25519 authentication pair emitted by Xray's vlessenc.
	// The ephemeral ML-KEM/X25519 exchange remains handled by the Xray core.
	encode := base64.RawURLEncoding.EncodeToString
	return json.NewEncoder(os.Stdout).Encode(map[string]string{
		"decryption": "mlkem768x25519plus.native.600s." + encode(key.Bytes()),
		"encryption": "mlkem768x25519plus.native.0rtt." + encode(key.PublicKey().Bytes()),
	})
}

func certificateFingerprint(args []string) error {
	flags := flag.NewFlagSet("certificate-fingerprint", flag.ContinueOnError)
	flags.SetOutput(io.Discard)
	panelURL := flags.String("panel-url", "", "")
	machineID := flags.Int("machine-id", 0, "")
	nodeID := flags.Int("node-id", 0, "")
	if flags.Parse(args) != nil || *panelURL == "" || *machineID < 1 || *nodeID < 1 {
		return errors.New("اطلاعات سرور یا نود برای دریافت گواهی معتبر نیست.")
	}
	// systemd loads this file for the service. An SSH CLI process needs to
	// load the same env references before config.LoadRoot validates tokens.
	if credentials, err := os.ReadFile(defaultCredentialsPath); err == nil {
		for _, line := range strings.Split(string(credentials), "\n") {
			line = strings.TrimSpace(line)
			if line == "" || strings.HasPrefix(line, "#") {
				continue
			}
			name, value, ok := strings.Cut(line, "=")
			if ok && strings.HasPrefix(name, "INSTANCE_") && os.Getenv(name) == "" {
				os.Setenv(name, strings.Trim(strings.TrimSpace(value), "\"'"))
			}
		}
	}
	root, err := config.LoadRoot(defaultConfigPath)
	if err != nil {
		return errors.New("تنظیمات سرویس مدیریت نود روی سرور قابل خواندن نیست.")
	}
	instances, err := root.NormalizeInstances()
	if err != nil {
		return errors.New("تنظیمات ماشین‌های سرور معتبر نیست.")
	}
	var certificatePath string
	for _, instance := range instances {
		if !instance.IsMachineMode() || instance.Machine.MachineID != *machineID ||
			strings.TrimRight(instance.Panel.URL, "/") != strings.TrimRight(*panelURL, "/") {
			continue
		}
		if certificatePath != "" {
			return errors.New("بیش از یک تنظیم اتصال برای این ماشین پیدا شد؛ تنظیمات سرور را بررسی کنید.")
		}
		node := instance.ExpandMachineNode(*nodeID, "hysteria")
		certificatePath = filepath.Join(node.Cert.CertDir, "cert.pem")
	}
	if certificatePath == "" {
		return errors.New("اتصال این ماشین به پنل روی سرور پیدا نشد.")
	}
	// The cert manager persists the exact PEM material supplied to the kernel
	// for self-signed, file, content and ACME modes, including renewed certs.
	data, err := os.ReadFile(certificatePath)
	if err != nil {
		return errors.New("گواهی این نود هنوز روی سرور آماده نیست؛ ابتدا نود و تنظیمات گواهی را ذخیره کنید.")
	}
	for len(data) > 0 {
		block, rest := pem.Decode(data)
		if block == nil {
			break
		}
		data = rest
		if block.Type != "CERTIFICATE" {
			continue
		}
		if _, err := x509.ParseCertificate(block.Bytes); err != nil {
			return errors.New("گواهی ذخیره‌شده روی سرور معتبر نیست.")
		}
		// Hash the leaf certificate DER, not the PEM text or entire chain.
		sum := sha256.Sum256(block.Bytes)
		return json.NewEncoder(os.Stdout).Encode(map[string]string{"fingerprint": hex.EncodeToString(sum[:])})
	}
	return errors.New("گواهی TLS در فایل این نود پیدا نشد.")
}
