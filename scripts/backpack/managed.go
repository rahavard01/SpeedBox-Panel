package manage

import (
	"crypto/sha256"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"runtime"
	"strconv"

	"github.com/BurntSushi/toml"
	"github.com/backpack/backpack/config"
	"github.com/backpack/backpack/internal/app"
	"github.com/backpack/backpack/internal/tunnel/naive"
)

type SpeedboxManaged struct {
	Mode        string `json:"mode"`
	ServerName  string `json:"server_name"`
	Target      string `json:"target"`
	Path        string `json:"path"`
	Connections int    `json:"connections"`
	Username    string `json:"username"`
	Password    string `json:"password"`
	UUID        string `json:"uuid"`
	ShortID     string `json:"short_id"`
	PublicKey   string `json:"public_key"`
	PrivateKey  string `json:"private_key"`
	Certificate string `json:"certificate"`
	Key         string `json:"key"`
}

func sbManagedTransport(transport string) bool {
	return transport == "naive" || transport == "xhttp" || transport == "reality"
}

func sbManagedDir(name string) string { return filepath.Join(app.ConfigDir, "managed", name) }

var sbManagedHelper = func(tool string) string {
	version := speedboxHelperVersions[tool]
	return filepath.Join("/opt/speedbox-backpack/helpers", tool, version+"-"+runtime.GOARCH, tool)
}

func sbConfigureManaged(s *TunnelSpec, r SpeedboxRequest) error {
	m := r.Managed
	if m == nil || m.Mode != r.Transport || !sbManagedTransport(r.Transport) || r.Kind != "reverse" || r.Swapped || r.UDP || r.CDN != nil {
		return fmt.Errorf("invalid managed HTTPS method or orientation")
	}
	if r.BridgePort < 1024 || r.BridgePort > 65535 || r.BridgePort == r.Port {
		return fmt.Errorf("invalid managed loopback port")
	}
	target := net.JoinHostPort("127.0.0.1", strconv.Itoa(r.BridgePort))
	s.Transport = "tcp"
	s.BindAddr, s.RemoteAddr = "", ""
	if s.Role == "server" {
		s.BindAddr = target
	} else {
		s.RemoteAddr = target
	}
	s.AcceptUDP = false
	bind := "0.0.0.0"
	if ip := net.ParseIP(r.Host); ip != nil && ip.To4() == nil {
		bind = "::"
	}
	listen := net.JoinHostPort(bind, strconv.Itoa(r.Port))
	remote := net.JoinHostPort(r.Host, strconv.Itoa(r.Port))
	var cert, key string
	if m.Mode != "reality" {
		if len(m.Certificate) > 32768 || len(m.Key) > 32768 || len(m.Certificate) == 0 {
			return fmt.Errorf("invalid managed TLS material")
		}
		folder := sbManagedDir(r.Name)
		if err := os.MkdirAll(folder, 0700); err != nil {
			return err
		}
		// Content-addressed TLS files let rollback keep the previous key pair.
		digest := fmt.Sprintf("%x", sha256.Sum256([]byte(m.Certificate)))
		cert = filepath.Join(folder, digest+".pem")
		if err := app.WriteFileAtomic(cert, []byte(m.Certificate), 0600); err != nil {
			return err
		}
		if s.Role == "server" {
			if m.Key == "" {
				return fmt.Errorf("managed TLS private key missing")
			}
			key = filepath.Join(folder, digest+".key")
			if err := app.WriteFileAtomic(key, []byte(m.Key), 0600); err != nil {
				return err
			}
		}
	}
	if m.Mode == "naive" {
		if s.Role == "server" {
			s.NaiveServer = config.NaiveServerConfig{Binary: sbManagedHelper("sing-box"), Listen: listen, Username: m.Username, Password: m.Password, Certificate: cert, Key: key}
		} else {
			// Naive's HTTPS URL is also its certificate verification name.
			s.NaiveClient = config.NaiveClientConfig{Binary: sbManagedHelper("naive"), Server: net.JoinHostPort(m.ServerName, strconv.Itoa(r.Port)), Username: m.Username, Password: m.Password, CAFile: cert, Connections: m.Connections}
		}
	} else if s.Role == "server" {
		s.XrayServer = config.XrayServerConfig{Binary: sbManagedHelper("xray"), Listen: listen, Mode: m.Mode, UUID: m.UUID, ServerName: m.ServerName, Path: m.Path, Certificate: cert, Key: key, PrivateKey: m.PrivateKey, Target: m.Target, ShortID: m.ShortID}
		if m.Mode == "xhttp" {
			s.XrayServer.ShortID = ""
		}
	} else {
		s.XrayClient = config.XrayClientConfig{Binary: sbManagedHelper("xray"), Server: remote, Mode: m.Mode, UUID: m.UUID, ServerName: m.ServerName, Path: m.Path, CAFile: cert, PublicKey: m.PublicKey, ShortID: m.ShortID}
		if m.Mode == "xhttp" {
			s.XrayClient.ShortID = ""
		}
	}
	var cfg config.Config
	if _, err := toml.Decode(s.Render(), &cfg); err != nil {
		return err
	}
	if err := naive.Validate(&cfg); err != nil {
		return err
	}
	return naive.ValidateXray(&cfg)
}
