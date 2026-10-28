package machine

import (
	"context"
	"fmt"
	"github.com/cedar2025/xboard-node/internal/runtimecontrol"
)

type machineSync struct {
	ctx    context.Context
	nodes  []int
	result chan machineResult
}
type machineResult struct {
	states []runtimecontrol.State
	err    error
}

func (o *Orchestrator) SyncNow(ctx context.Context, ids []int) ([]runtimecontrol.State, error) {
	req := machineSync{ctx: ctx, nodes: ids, result: make(chan machineResult, 1)}
	select {
	case o.syncRequests <- req:
	case <-ctx.Done():
		return nil, ctx.Err()
	}
	select {
	case r := <-req.result:
		return r.states, r.err
	case <-ctx.Done():
		return nil, ctx.Err()
	}
}
func (o *Orchestrator) synchronize(ctx context.Context, ids []int) ([]runtimecontrol.State, error) {
	if err := o.rediscover(ctx); err != nil {
		return nil, err
	}
	states := make([]runtimecontrol.State, 0, len(ids))
	for _, id := range ids {
		if id <= 0 {
			return nil, fmt.Errorf("invalid node")
		}
		o.mu.Lock()
		h := o.nodes[id]
		o.mu.Unlock()
		if h == nil {
			states = append(states, runtimecontrol.State{NodeID: id, State: "removed"})
			continue
		}
		if h.service == nil {
			return nil, fmt.Errorf("node initializing")
		}
		state, err := h.service.SyncNow(ctx)
		if err != nil {
			return nil, fmt.Errorf("node %d: %w", id, err)
		}
		states = append(states, state)
	}
	return states, nil
}
