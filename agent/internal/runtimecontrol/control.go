// Package runtimecontrol exposes root-only local synchronization, never a public API.
package runtimecontrol

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

const SocketPath = "/run/speedbox-origin/control.sock"

type State struct {
	NodeID     int    `json:"node_id"`
	State      string `json:"state"`
	ConfigHash string `json:"config_hash,omitempty"`
}
type Request struct {
	Panel     string `json:"panel"`
	MachineID int    `json:"machine_id"`
	Nodes     []int  `json:"nodes"`
}
type Handler func(context.Context, []int) ([]State, error)

var mu sync.RWMutex
var handlers = map[string]Handler{}

func key(panel string, machine int) string {
	return fmt.Sprintf("%s/%d", strings.TrimRight(panel, "/"), machine)
}
func Register(panel string, machine int, h Handler) func() {
	k := key(panel, machine)
	mu.Lock()
	handlers[k] = h
	mu.Unlock()
	return func() { mu.Lock(); delete(handlers, k); mu.Unlock() }
}
func serve(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		http.Error(w, "POST required", 405)
		return
	}
	var req Request
	if json.NewDecoder(http.MaxBytesReader(w, r.Body, 65536)).Decode(&req) != nil || req.MachineID <= 0 || len(req.Nodes) == 0 || len(req.Nodes) > 256 {
		http.Error(w, "invalid request", 400)
		return
	}
	mu.RLock()
	h := handlers[key(req.Panel, req.MachineID)]
	mu.RUnlock()
	if h == nil {
		http.Error(w, "machine instance is not running", 409)
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 60*time.Second)
	defer cancel()
	states, err := h(ctx, req.Nodes)
	if err != nil {
		http.Error(w, "runtime synchronization failed: "+err.Error(), 409)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{"states": states})
}
func Listen() (*http.Server, error) {
	if err := os.MkdirAll(filepath.Dir(SocketPath), 0700); err != nil {
		return nil, err
	}
	if st, err := os.Lstat(SocketPath); err == nil {
		if st.Mode()&os.ModeSocket == 0 {
			return nil, fmt.Errorf("unsafe control path")
		}
		c, e := net.DialTimeout("unix", SocketPath, time.Second)
		if e == nil {
			c.Close()
			return nil, fmt.Errorf("control socket already active")
		}
		if err = os.Remove(SocketPath); err != nil {
			return nil, err
		}
	}
	st, err := os.Stat(filepath.Dir(SocketPath))
	if err != nil || st.Mode().Perm()&0077 != 0 {
		return nil, fmt.Errorf("control directory must be private")
	}
	ln, err := net.Listen("unix", SocketPath)
	if err != nil {
		return nil, err
	}
	if err = os.Chmod(SocketPath, 0600); err != nil {
		ln.Close()
		return nil, err
	}
	srv := &http.Server{Handler: http.HandlerFunc(serve), ReadHeaderTimeout: 5 * time.Second}
	go srv.Serve(ln)
	return srv, nil
}
