package admission

import (
	"context"
	"errors"
	"time"
)

const MaxRenewalBatch = 64

// ErrBatchUnsupported permits one-way fallback after an explicit unsupported
// response. Network errors must never amplify a batch into a request storm.
var ErrBatchUnsupported = errors.New("lease renewal batches unsupported")

type Renewal struct {
	UserID   int    `json:"user_id"`
	SourceIP string `json:"source_ip"`
	LeaseID  string `json:"lease_id"`
}

type RenewalResult struct {
	Renewal
	Allowed bool `json:"allowed"`
	TTL     int  `json:"ttl"`
}

type BatchCaller interface {
	LeaseBatchSize() int
	RenewConnectionLeases(context.Context, []Renewal) ([]RenewalResult, error)
}

// ValidateRenewalResults requires a complete bijection. An omitted, duplicated
// or foreign result is a protocol error, not an instruction to revoke users.
func ValidateRenewalResults(input []Renewal, results []RenewalResult) error {
	if len(input) == 0 || len(input) > MaxRenewalBatch || len(input) != len(results) {
		return errors.New("invalid renewal result count")
	}
	expected := make(map[Renewal]bool, len(input))
	for _, item := range input {
		if _, exists := expected[item]; exists {
			return errors.New("duplicate renewal request")
		}
		expected[item] = false
	}
	for _, item := range results {
		seen, exists := expected[item.Renewal]
		if !exists || seen {
			return errors.New("foreign or duplicate renewal result")
		}
		if item.Allowed && (item.TTL <= 5 || item.TTL > 60) {
			return errors.New("invalid renewal lifetime")
		}
		expected[item.Renewal] = true
	}
	return nil
}

func (m *Manager) renewBatch(client BatchCaller, entries []*entry) {
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	select {
	case m.batchSlots <- struct{}{}:
		defer func() { <-m.batchSlots }()
	case <-ctx.Done():
		for _, e := range entries {
			m.finishRenew(e, time.Now(), false, 0, ctx.Err())
		}
		return
	}
	requests := make([]Renewal, len(entries))
	for i, e := range entries {
		requests[i] = Renewal{e.uid, e.ip, e.id}
	}
	// The guard is measured from request start, never response arrival.
	start := time.Now()
	results, err := client.RenewConnectionLeases(ctx, requests)
	m.pressure.observe(err)
	if errors.Is(err, ErrBatchUnsupported) {
		for _, e := range entries {
			go m.renew(e)
		}
		return
	}
	if err == nil {
		err = ValidateRenewalResults(requests, results)
	}
	if err != nil {
		for _, e := range entries {
			m.finishRenew(e, start, false, 0, err)
		}
		return
	}
	byIdentity := make(map[Renewal]RenewalResult, len(results))
	for _, result := range results {
		byIdentity[result.Renewal] = result
	}
	for _, e := range entries {
		result := byIdentity[Renewal{e.uid, e.ip, e.id}]
		m.finishRenew(e, start, result.Allowed, result.TTL, nil)
	}
}

func (m *Manager) renewDue(now time.Time) {
	m.mu.Lock()
	type closing struct {
		e         *entry
		callbacks []func()
	}
	var expired []closing
	var renew []*entry
	for _, e := range m.entries {
		if e.pending {
			continue
		}
		if !now.Before(e.deadline) {
			expired = append(expired, closing{e, m.removeLocked(e)})
			continue
		}
		if !e.renewing && now.Sub(e.renewed) >= 10*time.Second {
			e.renewing = true
			e.renewed = now
			renew = append(renew, e)
		}
	}
	m.mu.Unlock()
	for _, c := range expired {
		m.closeEntry(c.e, c.callbacks)
	}
	if client, ok := m.client.(BatchCaller); ok {
		size := client.LeaseBatchSize()
		if size > MaxRenewalBatch {
			size = MaxRenewalBatch
		}
		if size > 1 {
			for start := 0; start < len(renew); start += size {
				end := start + size
				if end > len(renew) {
					end = len(renew)
				}
				go m.renewBatch(client, renew[start:end])
			}
			return
		}
	}
	for _, e := range renew {
		go m.renew(e)
	}
}
