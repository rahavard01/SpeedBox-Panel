package manage

import (
	"encoding/json"
	"fmt"
	"github.com/BurntSushi/toml"
	"github.com/backpack/backpack/config"
	"strings"
	"testing"
	"time"
)

func TestSpeedboxDesiredBootAndRollbackStates(t *testing.T) {
	oldSys, oldRestart, oldStop, oldDisable := Systemctl, RestartService, StopService, DisableService
	defer func() {
		Systemctl = oldSys
		RestartService = oldRestart
		StopService = oldStop
		DisableService = oldDisable
	}()
	calls := []string{}
	Systemctl = func(args ...string) (string, error) { calls = append(calls, strings.Join(args, " ")); return "", nil }
	RestartService = func(unit string) error { calls = append(calls, "restart "+unit); return nil }
	StopService = func(unit string) error { calls = append(calls, "stop "+unit); return nil }
	DisableService = func(unit string) error { calls = append(calls, "disable --now "+unit); return nil }
	name := "sb-0123456789abcdef0123456789abcdef"
	unit := "speedbox-backpack-" + name + ".service"
	on, off := true, false
	if err := sbConfiguredService(name, &on); err != nil {
		t.Fatal(err)
	}
	if strings.Join(calls, "|") != "enable "+unit+"|restart "+unit {
		t.Fatal(calls)
	}
	calls = nil
	if err := sbConfiguredService(name, &off); err != nil {
		t.Fatal(err)
	}
	if len(calls) != 1 || calls[0] != "disable --now "+unit {
		t.Fatal(calls)
	}
	for _, active := range []bool{false, true} {
		for _, enabled := range []bool{false, true} {
			calls = nil
			state := sbServiceState{active, enabled}
			b, _ := json.Marshal(state)
			var decoded sbServiceState
			if err := json.Unmarshal(b, &decoded); err != nil {
				t.Fatal(err)
			}
			if err := sbRestoreService(name, decoded); err != nil {
				t.Fatal(err)
			}
			action := "disable"
			if enabled {
				action = "enable"
			}
			run := "stop"
			if active {
				run = "restart"
			}
			if strings.Join(calls, "|") != fmt.Sprintf("%s %s|%s %s", action, unit, run, unit) {
				t.Fatal(calls)
			}
		}
	}
}

func TestSpeedboxBoundedSetupKeepsFullStabilitySample(t *testing.T) {
	if connTestConnectWait != 30*time.Second || connTestSoak != 60 {
		t.Fatal("setup budget or stability sample changed")
	}
	if connectWaitFor(&connTestCase{kind: "direct"}) != 45*time.Second {
		t.Fatal("direct setup budget is not bounded")
	}
}

func TestSpeedboxDirectKeepsIranIngressInBothOrientations(t *testing.T) {
	for _, swapped := range []bool{false, true} {
		for _, side := range []string{"iran", "kharej"} {
			req := SpeedboxRequest{Name: "sb-0123456789abcdef0123456789abcdef", Role: side, Swapped: swapped, Transport: "udp", Host: "192.0.2.1", Port: 8000, Token: strings.Repeat("a", 64), Ports: "443=5790", LocalIP: "10.203.250.1/30", PeerIP: "10.203.250.2"}
			if side == "kharej" {
				req.LocalIP = "10.203.250.2/30"
				req.PeerIP = "10.203.250.1"
			}
			s, err := sbDirectSpec(req)
			if err != nil {
				t.Fatal(err)
			}
			if !strings.HasPrefix(s.Iface, "sbp") || len(s.Iface) > 15 {
				t.Fatal("interface not isolated")
			}
			var c config.Config
			if _, err = toml.Decode(s.Render(), &c); err != nil {
				t.Fatal(err)
			}
			wantMode := "dial"
			if (side == "kharej") != swapped {
				wantMode = "listen"
			}
			if c.L3.Mode != wantMode {
				t.Fatalf("%s swapped=%v mode=%s", side, swapped, c.L3.Mode)
			}
			if side == "iran" && len(c.L3.Ports) != 1 {
				t.Fatal("Iran ingress lost")
			}
			if side == "kharej" && len(c.L3.Ports) != 0 {
				t.Fatal("public ingress moved abroad")
			}
		}
	}
}
func TestSpeedboxRejectsUnownedNamesAndOperations(t *testing.T) {
	for _, r := range []SpeedboxRequest{{Action: "delete", Name: "manual-tunnel"}, {Action: "delete", Name: "../other"}, {Action: "snapshot", Name: "sb-0123456789abcdef0123456789abcdef", Revision: "../../etc"}, {Action: "shell", Name: "sb-0123456789abcdef0123456789abcdef"}} {
		if _, err := SpeedboxExecute(r); err == nil {
			t.Fatalf("accepted %+v", r)
		}
	}
}
func TestSpeedboxReversePartialTuneKeepsPreset(t *testing.T) {
	f := &FineTune{}
	if err := json.Unmarshal([]byte(`{"acceptUDP":true}`), f); err != nil {
		t.Fatal(err)
	}
	s, err := specFromNew(NewTunnel{Role: "server", Transport: "tcp", Name: "sb-0123456789abcdef0123456789abcdef", Token: strings.Repeat("t", 64), TunnelPort: "61020", Ports: "61021=61022", Preset: PresetTurbo, Tune: f})
	if err != nil {
		t.Fatal(err)
	}
	if !s.AcceptUDP || s.KeepAlive == 0 {
		t.Fatal("partial UDP setting erased preset")
	}
}
func TestSpeedboxSwappedCarrierKeepsEveryIngressOnIran(t *testing.T) {
	for _, transport := range []string{"tcp", "tcpmux", "stealth", "pck", "ws", "wss", "wsmux", "wssmux", "kcp", "quic", "udp"} {
		for _, role := range []string{"server", "client"} {
			r := SpeedboxRequest{Name: "sb-0123456789abcdef0123456789abcdef", Kind: "reverse", Swapped: true, Role: role, Transport: transport, Host: "192.0.2.2", Port: 58443, BridgePort: 55211, Token: strings.Repeat("a", 64), Ports: "60443=61443", LocalIP: "10.203.250.1/30", PeerIP: "10.203.250.2", UDP: true}
			if role == "client" {
				r.LocalIP = "10.203.250.2/30"
				r.PeerIP = "10.203.250.1"
			}
			carrier, main, err := sbSwappedSpecs(r)
			if err != nil {
				t.Fatalf("%s %s: %v", transport, role, err)
			}
			var c config.Config
			if _, err = toml.Decode(main.Render(), &c); err != nil {
				t.Fatal(err)
			}
			if role == "server" {
				if carrier.Role != "client" || c.L3.Mode != "listen" || len(c.L3.Ports) != 1 {
					t.Fatalf("Iran ingress moved: %s", transport)
				}
			} else {
				if carrier.Role != "server" || c.L3.Mode != "dial" || len(c.L3.Ports) != 0 {
					t.Fatalf("foreign ingress exposed: %s", transport)
				}
				for _, port := range carrier.Ports {
					if !strings.HasPrefix(port, "127.0.0.1:") {
						t.Fatal("carrier forward exposed publicly")
					}
				}
			}
		}
	}
}

func TestSpeedboxCDNUsesEdgeDomainAndIndependentOriginPort(t *testing.T) {
	for _, transport := range []string{"ws", "wss", "wsmux", "wssmux"} {
		for _, role := range []string{"server", "client"} {
			s := TunnelSpec{Role: role, Transport: transport, BindAddr: "0.0.0.0:18443", RemoteAddr: "192.0.2.1:18443"}
			cdn := &SpeedboxCDN{Domain: "tunnel.example.com", Port: 8443, EdgeIP: "185.143.234.132", CertFile: "/etc/speedbox-tunnel-cdn/test/fullchain.pem", KeyFile: "/etc/speedbox-tunnel-cdn/test/privkey.pem"}
			if err := sbConfigureCDN(&s, cdn); err != nil {
				t.Fatal(err)
			}
			if s.BindAddr != "0.0.0.0:18443" {
				t.Fatal("CDN edge port replaced origin listener")
			}
			if role == "client" && (s.RemoteAddr != "tunnel.example.com:8443" || s.EdgeIP != cdn.EdgeIP) {
				t.Fatal("edge route or domain lost")
			}
			if needsTLS(transport) && !s.SimpleAuth {
				t.Fatal("TLS termination requires simple authentication on both peers")
			}
			if role == "server" && needsTLS(transport) && (s.TLSCert != cdn.CertFile || s.TLSKey != cdn.KeyFile || s.ACMEDomain != "") {
				t.Fatal("prepared certificate not used")
			}
		}
	}
	for _, transport := range []string{"tcp", "tcpmux", "quic", "udp"} {
		s := TunnelSpec{Role: "client", Transport: transport}
		if sbConfigureCDN(&s, &SpeedboxCDN{Domain: "tunnel.example.com", Port: 443}) == nil {
			t.Fatal("unsupported CDN carrier accepted")
		}
	}
	s := TunnelSpec{Role: "client", Transport: "wss"}
	if sbConfigureCDN(&s, &SpeedboxCDN{Domain: "192.0.2.1", Port: 443}) == nil {
		t.Fatal("raw origin accepted as CDN")
	}
	if err := sbConfigureCDN(&s, nil); err != nil {
		t.Fatal("direct path changed")
	}
}
