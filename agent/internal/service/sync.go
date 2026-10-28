package service

import (
	"context"
	"fmt"
	"github.com/cedar2025/xboard-node/internal/controlplane"
	"github.com/cedar2025/xboard-node/internal/runtimecontrol"
	"time"
)

type syncRequest struct {
	ctx    context.Context
	result chan syncResponse
}
type syncResponse struct {
	state runtimecontrol.State
	err   error
}

// SyncNow runs on the service loop, serializing changes with normal kernel work.
func (s *Service) SyncNow(ctx context.Context) (runtimecontrol.State, error) {
	req := syncRequest{ctx: ctx, result: make(chan syncResponse, 1)}
	select {
	case s.syncRequests <- req:
	case <-ctx.Done():
		return runtimecontrol.State{}, ctx.Err()
	}
	select {
	case r := <-req.result:
		return r.state, r.err
	case <-ctx.Done():
		return runtimecontrol.State{}, ctx.Err()
	}
}
func (s *Service) synchronize(ctx context.Context) (runtimecontrol.State, error) {
	s.pollMu.Lock()
	seq := s.pullSequence.Add(1)
	var snapshot controlplane.Snapshot
	var err error
	if fresh, ok := s.source.(interface {
		PollFresh(context.Context) (controlplane.Snapshot, error)
	}); ok {
		snapshot, err = fresh.PollFresh(ctx)
	} else {
		snapshot, err = s.source.Poll(ctx)
	}
	s.pollMu.Unlock()
	state := runtimecontrol.State{NodeID: s.cfg.Panel.NodeID}
	if err != nil {
		return state, err
	}
	r := pullResult{sequence: seq, users: snapshot.Users, userHash: computeUserHash(snapshot.Users), certChanged: s.cert.CertRenewed()}
	if snapshot.Config != nil {
		if e := validateNodeRuntime(s.cfg, s.kernel.Protocols(), snapshot.Config, s.cert.TLSCert()); e != nil {
			return state, e
		}
		r.configHash = computeConfigHash(snapshot.Config)
		if r.configHash != s.lastConfigHash {
			r.config = snapshot.Config
		}
	}
	wantedUsers := s.lastUserHash
	if snapshot.Users != nil {
		wantedUsers = r.userHash
	}
	s.applyPullResult(ctx, r)
	state.ConfigHash = s.lastConfigHash
	if s.lastUserHash != wantedUsers || (len(s.lastUsers) > 0 && computeUserHash(s.appliedState.Users) != wantedUsers) {
		return state, fmt.Errorf("user policy was not applied")
	}
	if s.lastConfig == nil {
		return state, fmt.Errorf("node config unavailable")
	}
	if len(s.lastUsers) == 0 {
		state.State = "waiting_for_users"
	} else {
		s.retryKernelAt = time.Time{}
		if !s.ensureRunning() || computeConfigHash(s.appliedState.Config) != s.lastConfigHash {
			return state, fmt.Errorf("kernel did not apply current config")
		}
		state.State = "running"
	}
	s.pushReportAsync()
	return state, nil
}
