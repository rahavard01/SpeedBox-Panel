package runtimecontrol

import (
	"context"
	"encoding/json"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestLocalSyncRoutesExactMachineAndReportsErrors(t *testing.T) {
	calls := 0
	unregister := Register("https://panel.test/", 7, func(_ context.Context, ids []int) ([]State, error) {
		calls++
		return []State{{NodeID: ids[0], State: "running"}}, nil
	})
	defer unregister()
	for _, tc := range []struct {
		body string
		code int
	}{{`{"panel":"https://panel.test","machine_id":7,"nodes":[8]}`, 200}, {`{"panel":"https://other.test","machine_id":7,"nodes":[8]}`, 409}, {`{"panel":"https://panel.test","machine_id":9,"nodes":[8]}`, 409}, {`{"panel":"https://panel.test","machine_id":7,"nodes":[]}`, 400}} {
		w := httptest.NewRecorder()
		r := httptest.NewRequest("POST", "/sync", strings.NewReader(tc.body))
		serve(w, r)
		if w.Code != tc.code {
			t.Fatalf("%d: %s", w.Code, w.Body)
		}
		if w.Code == 200 {
			var result struct{ States []State }
			if json.Unmarshal(w.Body.Bytes(), &result) != nil || result.States[0].NodeID != 8 {
				t.Fatal("bad acknowledgement")
			}
		}
	}
	if calls != 1 {
		t.Fatal("wrong instance was synchronized")
	}
}
