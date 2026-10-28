package service

import (
	"context"
	"errors"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/controlplane"
	"github.com/cedar2025/xboard-node/internal/model"
	"testing"
)

type syncSource struct {
	controlplane.Source
	snapshot controlplane.Snapshot
	err      error
}

func (f syncSource) Poll(context.Context) (controlplane.Snapshot, error) { return f.snapshot, f.err }

type noReports struct{ controlplane.Sink }

func (noReports) SupportsReporting() bool { return false }
func TestTargetedSyncNoopFailureAndStalePoll(t *testing.T) {
	k := &fakeKernel{running: true}
	s := newTestService(k)
	s.cfg = &config.Config{Panel: config.PanelConfig{NodeID: 8}, Kernel: config.KernelConfig{Type: "xray"}}
	s.sink = noReports{}
	n := &model.NodeSpec{Protocol: "vless", ServerPort: 443}
	users := []model.UserSpec{{ID: 1, UUID: "one"}}
	s.lastConfig = n
	s.lastConfigHash = computeConfigHash(n)
	s.updateUserState(users)
	s.appliedState.Config = n
	s.appliedState.Users = users
	s.source = syncSource{snapshot: controlplane.Snapshot{Config: n, Users: users}}
	state, err := s.synchronize(context.Background())
	if err != nil || state.State != "running" || state.NodeID != 8 || k.startCalls != 0 {
		t.Fatalf("no-op restarted: %+v %v calls=%d", state, err, k.startCalls)
	}
	s.source = syncSource{err: errors.New("panel unavailable")}
	if _, err = s.synchronize(context.Background()); err == nil {
		t.Fatal("failed pull reported applied")
	}
	s.appliedSequence = 10
	old := *n
	old.ServerPort = 444
	s.applyPullResult(context.Background(), pullResult{sequence: 1, config: &old, configHash: computeConfigHash(&old)})
	if s.lastConfig.ServerPort != 443 {
		t.Fatal("stale poll overwrote forced sync")
	}
}
