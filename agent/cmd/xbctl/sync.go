package main

import (
	"bytes"
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"github.com/cedar2025/xboard-node/internal/runtimecontrol"
	"io"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"
)

func syncNodes(args []string) error {
	f := flag.NewFlagSet("sync", flag.ContinueOnError)
	panel := f.String("panel", "", "panel URL")
	machine := f.Int("machine-id", 0, "machine ID")
	nodes := f.String("nodes", "", "comma separated node IDs")
	if err := f.Parse(args); err != nil {
		return err
	}
	req := runtimecontrol.Request{Panel: *panel, MachineID: *machine}
	for _, s := range strings.Split(*nodes, ",") {
		id, e := strconv.Atoi(s)
		if e != nil || id <= 0 {
			return fmt.Errorf("invalid node IDs")
		}
		req.Nodes = append(req.Nodes, id)
	}
	data, _ := json.Marshal(req)
	ctx, cancel := context.WithTimeout(context.Background(), 65*time.Second)
	defer cancel()
	tr := &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
		return (&net.Dialer{}).DialContext(ctx, "unix", runtimecontrol.SocketPath)
	}}
	defer tr.CloseIdleConnections()
	r, _ := http.NewRequestWithContext(ctx, "POST", "http://local/sync", bytes.NewReader(data))
	res, err := (&http.Client{Transport: tr}).Do(r)
	if err != nil {
		return fmt.Errorf("node agent requires the current runtime-sync release: %w", err)
	}
	defer res.Body.Close()
	body, err := io.ReadAll(io.LimitReader(res.Body, 65536))
	if err != nil {
		return err
	}
	if res.StatusCode != 200 {
		return fmt.Errorf("runtime sync rejected: %s", body)
	}
	_, err = os.Stdout.Write(body)
	return err
}
