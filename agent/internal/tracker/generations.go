package tracker

import (
	"crypto/rand"
	"encoding/hex"
)

type TrafficBatch struct {
	ID         string `json:"id"`
	UserID     int    `json:"user_id"`
	Generation uint64 `json:"generation"`
	Upload     int64  `json:"u"`
	Download   int64  `json:"d"`
}
type trafficKey struct {
	userID     int
	generation uint64
}

// SetGenerations follows the last capture of old-generation counters.
func (t *Tracker) SetGenerations(g map[int]uint64) {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.generations = g
}
func (t *Tracker) accumulateBatch(uid int, up, down int64) {
	key := trafficKey{uid, t.generations[uid]}
	batch := t.pendingBatches[key]
	if batch == nil {
		batch = &TrafficBatch{UserID: uid, Generation: key.generation}
		t.pendingBatches[key] = batch
	}
	batch.Upload += up
	batch.Download += down
}
func (t *Tracker) FlushVersionedTraffic() (map[int][2]int64, []TrafficBatch) {
	t.mu.Lock()
	defer t.mu.Unlock()
	traffic := t.pendingTraffic
	t.pendingTraffic = make(map[int][2]int64)
	batches := t.restoredBatches
	t.restoredBatches = nil
	if len(t.pendingBatches) > 0 {
		var id [16]byte
		if _, err := rand.Read(id[:]); err != nil {
			panic(err)
		}
		reportID := hex.EncodeToString(id[:])
		for _, batch := range t.pendingBatches {
			batch.ID = reportID
			batches = append(batches, *batch)
		}
	}

	t.pendingBatches = make(map[trafficKey]*TrafficBatch)
	return traffic, batches
}

// Failed reports retain IDs and generations across a plan switch.
func (t *Tracker) RestoreVersionedTraffic(traffic map[int][2]int64, batches []TrafficBatch) {
	t.mu.Lock()
	defer t.mu.Unlock()
	for uid, d := range traffic {
		cur := t.pendingTraffic[uid]
		cur[0] += d[0]
		cur[1] += d[1]
		t.pendingTraffic[uid] = cur
	}
	t.restoredBatches = append(t.restoredBatches, batches...)
}
