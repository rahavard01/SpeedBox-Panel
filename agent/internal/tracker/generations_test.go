package tracker

import "testing"

func TestReportRetryKeepsOldGenerationAfterPlanSwitch(t *testing.T) {
	tr := New()
	tr.SetGenerations(map[int]uint64{1: 3, 2: 3})
	tr.Process(map[int][2]int64{1: {100, 200}, 2: {10, 20}}, nil, 0)
	old, batches := tr.FlushVersionedTraffic()
	if len(batches) != 2 || batches[0].ID != batches[1].ID {
		t.Fatal("one immutable packet ID required")
	}
	id := batches[0].ID
	tr.SetGenerations(map[int]uint64{1: 4, 2: 3})
	tr.Process(map[int][2]int64{1: {120, 250}, 2: {30, 40}}, nil, 0)
	tr.RestoreVersionedTraffic(old, batches)
	traffic, retry := tr.FlushVersionedTraffic()
	if traffic[1] != [2]int64{120, 250} {
		t.Fatalf("legacy totals lost: %v", traffic)
	}
	oldFound, newFound := false, false
	for _, b := range retry {
		if b.UserID == 1 && b.Generation == 3 {
			oldFound = b.ID == id && b.Upload == 100 && b.Download == 200
		}
		if b.UserID == 1 && b.Generation == 4 {
			newFound = b.ID != id && b.Upload == 20 && b.Download == 50
		}
	}
	if !oldFound || !newFound {
		t.Fatalf("generation or identity mixed: %+v", retry)
	}
}
