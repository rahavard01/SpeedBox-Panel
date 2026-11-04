package panel

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/admission"
)

func TestLeasePressureTransientClassificationAndDenial(t *testing.T) {
	for _, status := range []int{200, 400, 401, 403, 404, 408, 429, 500, 503} {
		t.Run(http.StatusText(status), func(t *testing.T) {
			s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(status)
				w.Write([]byte(`{"allowed":false}`))
			}))
			defer s.Close()
			c := &Client{baseURL: s.URL, httpClient: s.Client()}
			allowed, _, err := c.ConnectionLease(context.Background(), "acquire", 1, "198.18.0.1", "id")
			if allowed {
				t.Fatal("denial became a grant")
			}
			want := status == 408 || status == 429 || status >= 500
			if errors.Is(err, admission.ErrTransientLease) != want {
				t.Fatalf("status=%d err=%v transient=%v", status, err, want)
			}
			if status == 200 && err != nil {
				t.Fatal("business denial must not open the network circuit")
			}
		})
	}
}
func TestLeasePressureTimeoutRetainsContextCause(t *testing.T) {
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		io.Copy(io.Discard, r.Body)
		select {
		case <-r.Context().Done():
		case <-time.After(time.Second):
		}
	}))
	defer s.Close()
	c := &Client{baseURL: s.URL, httpClient: s.Client()}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Millisecond)
	defer cancel()
	_, _, err := c.ConnectionLease(ctx, "acquire", 1, "198.18.0.1", "id")
	if !errors.Is(err, admission.ErrTransientLease) || !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("lost error classification: %v", err)
	}
}
func TestLeasePressureForNodeSharesPanelKeyOnly(t *testing.T) {
	c := &Client{baseURL: "https://panel.example", httpClient: &http.Client{}}
	if c.AdmissionPressureKey() != c.ForNode(2).AdmissionPressureKey() {
		t.Fatal("node managers do not share panel backpressure")
	}
	other := &Client{baseURL: "https://other.example"}
	if c.AdmissionPressureKey() == other.AdmissionPressureKey() {
		t.Fatal("unrelated panels share a pressure key")
	}
}
func TestLeaseBatchPressureKeepsUnsupportedSeparate(t *testing.T) {
	for _, status := range []int{400, 404, 408, 429, 500, 501, 503} {
		t.Run(http.StatusText(status), func(t *testing.T) {
			s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(status) }))
			defer s.Close()
			c := &Client{baseURL: s.URL, httpClient: s.Client()}
			c.leaseBatchMax.Store(64)
			_, err := c.RenewConnectionLeases(context.Background(), []admission.Renewal{{UserID: 1, SourceIP: "198.18.0.1", LeaseID: "id"}})
			unsupported := status == 404 || status == 501
			if errors.Is(err, admission.ErrBatchUnsupported) != unsupported {
				t.Fatalf("fallback classification %d: %v", status, err)
			}
			transient := !unsupported && (status == 408 || status == 429 || status >= 500)
			if errors.Is(err, admission.ErrTransientLease) != transient {
				t.Fatalf("transient classification %d: %v", status, err)
			}
		})
	}
}
