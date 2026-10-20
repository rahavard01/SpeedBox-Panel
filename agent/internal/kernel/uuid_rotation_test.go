package kernel

import (
	"github.com/cedar2025/xboard-node/internal/model"
	"testing"
)

func TestUUIDRotationRemovesOldIdentityOnSameID(t *testing.T) {
	before := []model.UserSpec{{ID: 1, UUID: "old"}, {ID: 2, UUID: "unchanged"}}
	target := []model.UserSpec{{ID: 1, UUID: "new"}, {ID: 2, UUID: "unchanged", SpeedLimit: 10}}
	add, remove := UserDiff(before, target)
	if len(add) != 1 || add[0].UUID != "new" || len(remove) != 1 || remove[0].UUID != "old" {
		t.Fatalf("rotation diff: add=%+v remove=%+v", add, remove)
	}
	add, remove = UserDiff(target, target)
	if len(add) != 0 || len(remove) != 0 {
		t.Fatal("unchanged users must not be replaced")
	}
	add, remove = UserDiff(target, []model.UserSpec{{ID: 2, UUID: "unchanged"}, {ID: 3, UUID: "third"}})
	if len(add) != 1 || add[0].ID != 3 || len(remove) != 1 || remove[0].ID != 1 {
		t.Fatal("ordinary additions/removals changed")
	}
}

