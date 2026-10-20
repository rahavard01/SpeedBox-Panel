package service

import (
	"context"
	"errors"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/controlplane"
	"github.com/cedar2025/xboard-node/internal/model"
)

type firstAccountSource struct {
	controlplane.Source
	bootstrap controlplane.Bootstrap
}

func (f firstAccountSource) Initial(context.Context, func() map[string]interface{}, chan<- controlplane.Event, chan<- controlplane.StatusChange) (controlplane.Bootstrap, error) {
	return f.bootstrap, nil
}

func TestEmptyBootstrapPreparesPanelCertificateBeforeFirstAccount(t *testing.T) {
	k := &fakeKernel{}
	s := newTestService(k)
	s.cfg = &config.Config{Kernel: config.KernelConfig{Type: "xray"}, Cert: config.CertConfig{CertDir: t.TempDir()}}
	s.source = firstAccountSource{bootstrap: controlplane.Bootstrap{Config: &model.NodeSpec{
		Protocol: "vless", TLS: 1,
		CertConfig: &config.CertConfig{CertMode: "self", Domain: "node.example.test"},
	}}}
	defer s.cert.Stop()
	if err := s.initialSetup(context.Background()); err != nil {
		t.Fatal(err)
	}
	if k.running || !s.cert.HasCert() {
		t.Fatal("empty bootstrap must prepare panel TLS while waiting for an account")
	}
	s.applyUserDelta(context.Background(), "add", []model.UserSpec{{ID: 1, UUID: "first-with-tls"}})
	if !k.running || k.startCalls != 1 {
		t.Fatal("first account did not start the TLS-prepared kernel")
	}
}

func TestFirstUserStartsStoppedKernel(t *testing.T) {
	for _, delivery := range []string{"full", "delta", "rest", "websocket"} {
		t.Run(delivery, func(t *testing.T) {
			k := &fakeKernel{}
			s := newTestService(k)
			s.lastConfig = &model.NodeSpec{Protocol: "vless"}
			s.updateUserState(nil)
			users := []model.UserSpec{{ID: 7, UUID: "first-account", SpeedLimit: 5}}
			switch delivery {
			case "full":
				s.applyUserUpdate(context.Background(), users, computeUserHash(users))
			case "delta":
				s.applyUserDelta(context.Background(), "add", users)
			case "rest":
				s.applyPullResult(context.Background(), pullResult{users: users, userHash: computeUserHash(users)})
			case "websocket":
				s.handleWSEvent(context.Background(), controlplane.Event{Type: controlplane.EventSyncUsers, Users: users})
			}
			if !k.running || k.startCalls != 1 || len(s.lastUsers) != 1 || len(s.appliedState.Users) != 1 {
				t.Fatalf("first account was not applied: running=%v starts=%d users=%v applied=%v", k.running, k.startCalls, s.lastUsers, s.appliedState.Users)
			}
			if s.speedTracker.GetLimiter("first-account") == nil {
				t.Fatal("first account limiter missing")
			}
			if k.addCalls != 0 || k.updateCalls != 0 {
				t.Fatal("startup must not add the first account twice")
			}
		})
	}
}

func TestFirstUserStartupFailureKeepsDesiredUsersForBoundedRetry(t *testing.T) {
	k := &fakeKernel{startErr: errors.New("temporary bind failure")}
	s := newTestService(k)
	s.lastConfig = &model.NodeSpec{Protocol: "vless"}
	s.applyUserDelta(context.Background(), "add", []model.UserSpec{{ID: 8, UUID: "retry-account"}})
	if k.running || len(s.lastUsers) != 1 || s.lastKernelError == "" {
		t.Fatal("failed startup lost desired account or error state")
	}
	if s.ensureRunning() || k.startCalls != 1 {
		t.Fatal("retry must be bounded after failure")
	}
	k.startErr = nil
	s.retryKernelAt = time.Now().Add(-time.Second)
	if !s.ensureRunning() || k.startCalls != 2 || s.lastKernelError != "" {
		t.Fatal("retry did not recover the stopped kernel")
	}
}

func TestRemovingLastAccountStopsAndNextAccountRestartsKernel(t *testing.T) {
	k := &fakeKernel{running: true}
	s := newTestService(k)
	s.lastConfig = &model.NodeSpec{Protocol: "vless"}
	s.updateUserState([]model.UserSpec{{ID: 1, UUID: "old"}})
	s.applyUserDelta(context.Background(), "remove", []model.UserSpec{{ID: 1}})
	if k.running || len(s.lastUsers) != 0 {
		t.Fatal("last account was not removed")
	}
	s.applyUserDelta(context.Background(), "add", []model.UserSpec{{ID: 2, UUID: "new"}})
	if !k.running || len(s.appliedState.Users) != 1 || s.appliedState.Users[0].ID != 2 {
		t.Fatal("next account did not restart kernel cleanly")
	}
}

func TestUserChangesWhileStoppedNeverResurrectRemovedAccounts(t *testing.T) {
	k := &fakeKernel{}
	s := newTestService(k)
	s.updateUserState([]model.UserSpec{{ID: 1, UUID: "removed"}})
	s.applyUserDelta(context.Background(), "remove", []model.UserSpec{{ID: 1}})
	if len(s.lastUsers) != 0 || k.startCalls != 0 {
		t.Fatal("stopped service retained a removed account")
	}
	s.applyUserUpdate(context.Background(), []model.UserSpec{{ID: 2, UUID: "waiting-for-config"}}, "")
	if k.startCalls != 0 || len(s.lastUsers) != 1 {
		t.Fatal("account should wait for a valid config")
	}
}
