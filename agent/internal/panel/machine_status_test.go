package panel

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"

	"github.com/cedar2025/xboard-node/internal/config"
)

func TestReportMachineStatusAdvertisesWarpAndPreservesMetrics(t *testing.T) {
	for _, withNetwork := range []bool{false, true} {
		name := "without_network_sample"
		if withNetwork {
			name = "with_network_sample"
		}
		t.Run(name, func(t *testing.T) {
			var calls atomic.Int32
			ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				calls.Add(1)
				if r.Method != http.MethodPost || r.URL.Path != "/api/v2/server/machine/status" {
					t.Errorf("unexpected status request: %s %s", r.Method, r.URL.Path)
				}
				var body struct {
					MachineID    int                `json:"machine_id"`
					Token        string             `json:"token"`
					Capabilities []string           `json:"capabilities"`
					CPU          float64            `json:"cpu"`
					Mem          map[string]uint64  `json:"mem"`
					Net          map[string]float64 `json:"net"`
				}
				if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
					t.Errorf("decode actual HTTP report: %v", err)
					w.WriteHeader(http.StatusBadRequest)
					return
				}
				if body.MachineID != 17 || body.Token != "machine-fixture-token" {
					t.Error("machine authentication was not preserved")
				}
				if len(body.Capabilities) != 1 || body.Capabilities[0] != "warp-egress-v1" {
					t.Errorf("WARP capability missing from machine report: %v", body.Capabilities)
				}
				if r.Header.Get("X-Speedbox-Capabilities") != "warp-egress-v1" {
					t.Error("existing capability header was not preserved")
				}
				if body.CPU != 12.5 || body.Mem["total"] != 8192 || body.Mem["used"] != 2048 {
					t.Error("status metrics changed")
				}
				if withNetwork {
					if body.Net["in_speed"] != 100 || body.Net["out_speed"] != 200 {
						t.Error("network metrics changed")
					}
				} else if body.Net != nil {
					t.Error("unavailable network sample should remain omitted")
				}
				w.Header().Set("Content-Type", "application/json")
				_, _ = w.Write([]byte(`{"data":true}`))
			}))
			defer ts.Close()
			client := NewClient(config.PanelConfig{URL: ts.URL, Token: "machine-fixture-token", MachineID: 17})
			in, out := float64(-1), float64(-1)
			if withNetwork {
				in, out = 100, 200
			}
			if err := client.ReportMachineStatus(12.5, [2]uint64{8192, 2048}, [2]uint64{}, [2]uint64{}, in, out); err != nil {
				t.Fatalf("ReportMachineStatus: %v", err)
			}
			if calls.Load() != 1 {
				t.Errorf("expected one machine report, got %d", calls.Load())
			}
		})
	}
}
