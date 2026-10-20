package singbox

import (
	"context"
	"errors"
	"testing"

	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
	box "github.com/sagernet/sing-box"
	"github.com/sagernet/sing-box/adapter"
	"github.com/sagernet/sing-box/include"
	singLog "github.com/sagernet/sing-box/log"
	"github.com/sagernet/sing-box/option"
	"github.com/sagernet/sing/service"
)

type warpReloadOutboundManager struct {
	adapter.OutboundManager
	exists bool
	events *[]string
}

func (m *warpReloadOutboundManager) Outbound(tag string) (adapter.Outbound, bool) {
	return nil, tag == model.WarpOutboundTag && m.exists
}
func (m *warpReloadOutboundManager) Create(ctx context.Context, router adapter.Router, logger singLog.ContextLogger, tag, protocol string, options any) error {
	if tag != model.WarpOutboundTag || protocol != "socks" {
		return errors.New("wrong outbound")
	}
	m.exists = true
	*m.events = append(*m.events, "create")
	return nil
}
func (m *warpReloadOutboundManager) Remove(tag string) error {
	m.exists = false
	*m.events = append(*m.events, "remove")
	return nil
}

type warpReloadRouter struct {
	adapter.Router
	outbounds *warpReloadOutboundManager
	events    *[]string
	fail      bool
}

func (r *warpReloadRouter) UpdateRules(rules []option.Rule, sets []option.RuleSet) error {
	*r.events = append(*r.events, "rules")
	if r.fail {
		return errors.New("deliberate route failure")
	}
	if len(rules) > 2 && !r.outbounds.exists {
		return errors.New("WARP route applied before outbound creation")
	}
	return nil
}

type warpReloadInbounds struct{ adapter.InboundManager }

func TestWarpReloadCreatesOutboundBeforeRulesAndRemovesAfterRules(t *testing.T) {
	events := []string{}
	om := &warpReloadOutboundManager{events: &events}
	router := &warpReloadRouter{outbounds: om, events: &events}
	ctx := include.Context(context.Background())
	service.MustRegister[adapter.OutboundManager](ctx, om)
	service.MustRegister[adapter.Router](ctx, router)
	service.MustRegister[adapter.InboundManager](ctx, &warpReloadInbounds{})
	s := &SingBox{box: new(box.Box), ctx: ctx}
	// No inbound sockets are needed to exercise the real Reload implementation.
	if err := s.Reload(&model.NodeSpec{WarpEnabled: true}, nil, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	if len(events) != 2 || events[0] != "create" || events[1] != "rules" {
		t.Fatalf("enable order: %v", events)
	}
	events = nil
	if err := s.Reload(&model.NodeSpec{WarpEnabled: true}, nil, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	if len(events) != 1 || events[0] != "rules" {
		t.Fatalf("idempotence: %v", events)
	}
	events = nil
	if err := s.Reload(&model.NodeSpec{}, nil, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	if len(events) != 2 || events[0] != "rules" || events[1] != "remove" {
		t.Fatalf("disable order: %v", events)
	}
	router.fail = true
	events = nil
	if err := s.Reload(&model.NodeSpec{WarpEnabled: true}, nil, kernel.TLSCert{}); err == nil {
		t.Fatal("route failure hidden")
	}
	if om.exists {
		t.Fatal("new outbound not rolled back after failed route update")
	}
}
