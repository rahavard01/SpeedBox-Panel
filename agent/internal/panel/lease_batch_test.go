package panel

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"github.com/cedar2025/xboard-node/internal/admission"
	"net/http"
	"sync/atomic"
	"testing"
)

func TestLeaseBatchNegotiationAndEnvelope(t *testing.T) {
	var advertised atomic.Bool
	var requests atomic.Int64
	ts, c := newTestServer(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v2/server/handshake" {
			if advertised.Load() {
				fmt.Fprint(w, `{"connection_limits":{"enabled":true,"renew_batch":{"version":1,"max_items":999}},"settings":[]}`)
			} else {
				fmt.Fprint(w, `{"connection_limits":{"enabled":true},"settings":[]}`)
			}
			return
		}
		requests.Add(1)
		var p struct {
			Token  string              `json:"token"`
			Node   int                 `json:"node_id"`
			Leases []admission.Renewal `json:"leases"`
		}
		json.NewDecoder(r.Body).Decode(&p)
		if p.Token != "test-token" || p.Node != 1 || len(p.Leases) != 2 || r.URL.Path != "/api/v2/server/connections/lease/renew-batch" {
			t.Errorf("wrong scoped payload %+v", p)
		}
		json.NewEncoder(w).Encode(map[string]interface{}{"results": []admission.RenewalResult{{Renewal: p.Leases[1], Allowed: false}, {Renewal: p.Leases[0], Allowed: true, TTL: 60}}})
	})
	defer ts.Close()
	in := []admission.Renewal{{1, "192.0.2.1", "one"}, {2, "192.0.2.2", "two"}}
	if _, err := c.Handshake(); err != nil {
		t.Fatal(err)
	}
	if _, err := c.RenewConnectionLeases(context.Background(), in); !errors.Is(err, admission.ErrBatchUnsupported) || requests.Load() != 0 {
		t.Fatal("unadvertised batching attempted")
	}
	advertised.Store(true)
	if _, err := c.Handshake(); err != nil {
		t.Fatal(err)
	}
	if c.LeaseBatchSize() != 64 {
		t.Fatal("size not bounded")
	}
	out, err := c.RenewConnectionLeases(context.Background(), in)
	if err != nil || len(out) != 2 || out[0].Allowed {
		t.Fatalf("out=%+v err=%v", out, err)
	}
}
func TestLeaseBatchFailureClassification(t *testing.T) {
	for _, status := range []int{401, 404, 405, 422, 429, 500, 501, 503} {
		t.Run(fmt.Sprint(status), func(t *testing.T) {
			ts, c := newTestServer(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(status) })
			defer ts.Close()
			c.leaseBatchMax.Store(64)
			_, err := c.RenewConnectionLeases(context.Background(), []admission.Renewal{{1, "192.0.2.1", "one"}})
			unsupported := status == 404 || status == 405 || status == 501
			if err == nil || errors.Is(err, admission.ErrBatchUnsupported) != unsupported || (c.LeaseBatchSize() == 0) != unsupported {
				t.Fatalf("wrong fallback status=%d err=%v", status, err)
			}
		})
	}
}
func TestLeaseBatchRejectsIncompleteResults(t *testing.T) {
	for _, body := range []string{`{}`, `{"results":[]}`, `{"results":[{"user_id":1,"source_ip":"192.0.2.1","lease_id":"one","ttl":60}]}`, `{"results":[{"user_id":1,"source_ip":"192.0.2.1","lease_id":"one","allowed":true}]}`, `{"results":[{"user_id":1,"source_ip":"192.0.2.1","lease_id":"one","allowed":true,"ttl":600}]}`} {
		ts, c := newTestServer(func(w http.ResponseWriter, r *http.Request) { fmt.Fprint(w, body) })
		c.leaseBatchMax.Store(64)
		_, err := c.RenewConnectionLeases(context.Background(), []admission.Renewal{{1, "192.0.2.1", "one"}})
		ts.Close()
		if err == nil {
			t.Fatalf("accepted %s", body)
		}
	}
}
