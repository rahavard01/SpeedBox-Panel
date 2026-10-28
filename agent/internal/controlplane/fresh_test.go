package controlplane

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/panel"
)

func TestFreshMachinePollDoesNotAcknowledgePendingETagState(t *testing.T) {
	conditional := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("If-None-Match") != "" {
			conditional++
			w.WriteHeader(http.StatusNotModified)
			return
		}
		w.Header().Set("ETag", `"current"`)
		w.Header().Set("Content-Type", "application/json")
		if strings.HasSuffix(r.URL.Path, "/user") {
			w.Write([]byte(`{"users":[{"id":1,"uuid":"one"}]}`))
		} else {
			w.Write([]byte(`{"protocol":"vless","server_port":443,"kernel_type":"xray"}`))
		}
	}))
	defer server.Close()
	p := NewMachinePanelControlPlane(panel.NewClient(config.PanelConfig{URL: server.URL, NodeID: 8, MachineID: 10}), config.KernelConfig{Type: "xray"}, nil, nil)
	// This fetched result could still be queued in the service loop.
	if _, err := p.Poll(context.Background()); err != nil {
		t.Fatal(err)
	}
	if _, err := p.Poll(context.Background()); err != nil {
		t.Fatal(err)
	}
	if conditional != 2 {
		t.Fatalf("expected normal conditional requests, got %d", conditional)
	}
	snapshot, err := p.PollFresh(context.Background())
	if err != nil || snapshot.Config == nil || len(snapshot.Users) != 1 || conditional != 2 {
		t.Fatalf("fresh apply reused pending ETags: %+v %v conditional=%d", snapshot, err, conditional)
	}
}
