package service

import (
	"context"
	"github.com/cedar2025/xboard-node/internal/model"
	"testing"
)

func TestUUIDDeltaForOnlyUserDoesNotStopKernel(t *testing.T) {
	k := &fakeKernel{running: true}
	s := newTestService(k)
	s.lastConfig = &model.NodeSpec{Protocol: "vless"}
	s.updateUserState([]model.UserSpec{{ID: 1, UUID: "old"}})
	s.applyUserDelta(context.Background(), "add", []model.UserSpec{{ID: 1, UUID: "new"}})
	if k.removeCalls != 0 || k.addCalls != 0 || k.updateCalls != 1 || k.startCalls != 0 || !k.running || len(s.lastUsers) != 1 || s.lastUsers[0].UUID != "new" {
		t.Fatalf("rotation used destructive delta: %+v users=%+v", k, s.lastUsers)
	}
}

