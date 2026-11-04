package panel

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"

	"github.com/cedar2025/xboard-node/internal/admission"
)

func (c *Client) LeaseBatchSize() int { return int(c.leaseBatchMax.Load()) }

// RenewConnectionLeases is optional and negotiated by handshake. An old panel
// gets the existing single-item protocol; a network outage never fans out retries.
func (c *Client) RenewConnectionLeases(ctx context.Context, leases []admission.Renewal) ([]admission.RenewalResult, error) {
	max := c.LeaseBatchSize()
	if max < 2 {
		return nil, admission.ErrBatchUnsupported
	}
	if len(leases) == 0 || len(leases) > max {
		return nil, errors.New("invalid renewal batch size")
	}
	payload := map[string]interface{}{"leases": leases}
	c.injectAuth(payload)
	body, err := json.Marshal(payload)
	if err != nil {
		return nil, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/api/v2/server/connections/lease/renew-batch", bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, errors.Join(admission.ErrTransientLease, err)
	}
	defer drainAndClose(resp.Body)
	switch resp.StatusCode {
	case http.StatusNotFound, http.StatusMethodNotAllowed, http.StatusNotImplemented:
		c.leaseBatchMax.Store(0)
		return nil, admission.ErrBatchUnsupported
	}
	if resp.StatusCode == http.StatusRequestTimeout || resp.StatusCode == http.StatusTooManyRequests || resp.StatusCode >= 500 {
		return nil, fmt.Errorf("%w (HTTP %d)", admission.ErrTransientLease, resp.StatusCode)
	}
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("renewal batch status %d", resp.StatusCode)
	}
	var envelope struct {
		Results []struct {
			admission.Renewal
			Allowed *bool `json:"allowed"`
			TTL     *int  `json:"ttl"`
		} `json:"results"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 65536)).Decode(&envelope); err != nil {
		return nil, err
	}
	results := make([]admission.RenewalResult, len(envelope.Results))
	for i, item := range envelope.Results {
		if item.Allowed == nil || item.TTL == nil {
			return nil, errors.New("incomplete renewal result")
		}
		results[i] = admission.RenewalResult{Renewal: item.Renewal, Allowed: *item.Allowed, TTL: *item.TTL}
	}
	if err := admission.ValidateRenewalResults(leases, results); err != nil {
		return nil, err
	}
	return results, nil
}
