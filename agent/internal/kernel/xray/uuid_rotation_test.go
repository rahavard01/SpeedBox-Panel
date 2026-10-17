package xray

import (
	"context"
	"errors"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/xtls/xray-core/common/protocol"
	"github.com/xtls/xray-core/common/uuid"
	"github.com/xtls/xray-core/proxy/vless"
	"testing"
)

func TestRunningKernelRotatesUUIDWithoutRestart(t *testing.T) {
	for _, proto := range []string{"vmess", "vless"} {
		t.Run(proto, func(t *testing.T) {
			old := model.UserSpec{ID: 1, UUID: "11111111-1111-4111-8111-111111111111"}
			other := model.UserSpec{ID: 2, UUID: "33333333-3333-4333-8333-333333333333"}
			x := New(config.KernelConfig{Type: "xray"})
			nc := &model.NodeSpec{Protocol: proto, ListenIP: "127.0.0.1", ServerPort: 0, Network: "tcp"}
			if err := x.Start(nc, []model.UserSpec{old, other}, kernel.TLSCert{}); err != nil {
				t.Fatal(err)
			}
			defer x.Stop()
			instance := x.instance
			target := old
			target.UUID = "22222222-2222-4222-8222-222222222222"
			added, err := x.AddUsers([]model.UserSpec{target})
			if err != nil || added != 1 || x.instance != instance || !x.IsRunning() {
				t.Fatalf("rotation restarted/failed: added=%d err=%v", added, err)
			}
			x.mu.Lock()
			manager, err := x.getUserManager()
			x.mu.Unlock()
			if err != nil {
				t.Fatal(err)
			}
			current := manager.GetUser(context.Background(), userEmail(old.ID))
			expected, err := toMemoryUser(proto, nc, target)
			if err != nil {
				t.Fatal(err)
			}
			if current == nil || !current.Account.Equals(expected.Account) || manager.GetUser(context.Background(), userEmail(other.ID)) == nil {
				t.Fatal("running inbound does not contain expected identities")
			}
			replacement := target
			replacement.UUID = "44444444-4444-4444-8444-444444444444"
			a, r, e := x.UpdateUsers([]model.UserSpec{replacement, other})
			if e != nil || a != 1 || r != 1 || x.instance != instance {
				t.Fatalf("full sync rotation: +%d -%d err=%v", a, r, e)
			}
		})
	}
}

// Exercise the real Xray credential validator; faults only wrap its operations.
type rotationManager struct {
	validator  vless.MemoryValidator
	failAdd    bool
	failRemove bool
}

func (m *rotationManager) AddUser(_ context.Context, u *protocol.MemoryUser) error {
	if m.failAdd {
		m.failAdd = false
		return errors.New("injected add failure")
	}
	return m.validator.Add(u)
}
func (m *rotationManager) RemoveUser(_ context.Context, email string) error {
	if m.failRemove {
		return errors.New("injected remove failure")
	}
	return m.validator.Del(email)
}
func (m *rotationManager) GetUser(_ context.Context, email string) *protocol.MemoryUser {
	return m.validator.GetByEmail(email)
}
func (m *rotationManager) GetUsers(context.Context) []*protocol.MemoryUser {
	return m.validator.GetAll()
}
func (m *rotationManager) GetUsersCount(context.Context) int64 { return m.validator.GetCount() }

func TestUUIDRotationUsesRealXrayValidator(t *testing.T) {
	const oldUUID = "11111111-1111-4111-8111-111111111111"
	const newUUID = "22222222-2222-4222-8222-222222222222"
	const otherUUID = "33333333-3333-4333-8333-333333333333"
	for _, mode := range []string{"single", "other-user", "add-failure", "remove-failure", "invalid-new"} {
		t.Run(mode, func(t *testing.T) {
			m := &rotationManager{}
			before := []model.UserSpec{{ID: 1, UUID: oldUUID}}
			if mode == "other-user" {
				before = append(before, model.UserSpec{ID: 2, UUID: otherUUID})
			}
			for _, u := range before {
				memory, err := toMemoryUser("vless", &model.NodeSpec{}, u)
				if err != nil {
					t.Fatal(err)
				}
				if err = m.AddUser(context.Background(), memory); err != nil {
					t.Fatal(err)
				}
			}
			target := append([]model.UserSpec(nil), before...)
			target[0].UUID = newUUID
			if mode == "add-failure" {
				m.failAdd = true
			}
			if mode == "remove-failure" {
				m.failRemove = true
			}
			if mode == "invalid-new" {
				target[0].UUID = "zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz-extra"
			}
			applied, added, removed, err := applyUserChanges(m, "vless", &model.NodeSpec{}, before, target)
			oldID, _ := uuid.ParseString(oldUUID)
			newID, _ := uuid.ParseString(newUUID)
			if mode == "single" || mode == "other-user" {
				if err != nil || added != 1 || removed != 1 || m.validator.Get(oldID) != nil || m.validator.Get(newID) == nil {
					t.Fatalf("rotation failed: +%d -%d err=%v", added, removed, err)
				}
				if mode == "other-user" {
					otherID, _ := uuid.ParseString(otherUUID)
					if m.validator.Get(otherID) == nil {
						t.Fatal("unrelated user lost")
					}
				}
				_, a, r, e := applyUserChanges(m, "vless", &model.NodeSpec{}, applied, target)
				if e != nil || a != 0 || r != 0 {
					t.Fatal("repeat sync is not idempotent")
				}
			} else {
				if err == nil || m.validator.Get(oldID) == nil || m.validator.Get(newID) != nil || len(applied) != 1 || applied[0].UUID != oldUUID {
					t.Fatalf("failure falsely committed: %+v err=%v", applied, err)
				}
				m.failRemove = false
				target[0].UUID = newUUID
				_, a, r, e := applyUserChanges(m, "vless", &model.NodeSpec{}, applied, target)
				if e != nil || a != 1 || r != 1 || m.validator.Get(newID) == nil || m.validator.Get(oldID) != nil {
					t.Fatalf("retry failed: %v", e)
				}
			}
		})
	}
}
