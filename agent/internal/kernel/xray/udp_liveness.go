package xray

import (
	"context"
	"time"

	"github.com/cedar2025/xboard-node/internal/liveness"
	"github.com/xtls/xray-core/common/buf"
	"github.com/xtls/xray-core/transport"
	"github.com/xtls/xray-core/transport/pipe"
)

func watchDatagram(link *transport.Link, w *closeTrackingWriter, cancel context.CancelFunc, timeout time.Duration) {
	if cancel != nil {
		w.stopFlow.Store(&cancel)
		if w.closed.Load() {
			cancel()
		}
	}
	idle := liveness.NewIdle(timeout, func() {
		if cancel != nil {
			cancel()
		}
		w.Interrupt()
	})
	if old := w.idle.Swap(idle); old != nil {
		old.Stop()
	}
	if w.closed.Load() {
		idle.Stop()
	}
	if reader, ok := link.Reader.(*pipe.Reader); ok {
		// Embed the real pipe: XUDP's ReturnAnError/Recover and timeout-read
		// contract remain available when it detaches and reconnects its session.
		link.Reader = &udpPipeReader{Reader: reader, idle: idle}
	} else {
		link.Reader = &udpActivityReader{Reader: link.Reader, idle: idle}
	}
}

type udpPipeReader struct {
	*pipe.Reader
	idle *liveness.Idle
}

func (r *udpPipeReader) ReadMultiBuffer() (buf.MultiBuffer, error) {
	b, e := r.Reader.ReadMultiBuffer()
	if len(b) > 0 {
		r.idle.Touch()
	}
	return b, e
}
func (r *udpPipeReader) ReadMultiBufferTimeout(d time.Duration) (buf.MultiBuffer, error) {
	b, e := r.Reader.ReadMultiBufferTimeout(d)
	if len(b) > 0 {
		r.idle.Touch()
	}
	return b, e
}

type udpActivityReader struct {
	buf.Reader
	idle *liveness.Idle
}

func (r *udpActivityReader) ReadMultiBuffer() (buf.MultiBuffer, error) {
	b, e := r.Reader.ReadMultiBuffer()
	if len(b) > 0 {
		r.idle.Touch()
	}
	return b, e
}
func (r *udpActivityReader) ReadMultiBufferTimeout(d time.Duration) (buf.MultiBuffer, error) {
	reader, ok := r.Reader.(buf.TimeoutReader)
	if !ok {
		return nil, buf.ErrNotTimeoutReader
	}
	b, e := reader.ReadMultiBufferTimeout(d)
	if len(b) > 0 {
		r.idle.Touch()
	}
	return b, e
}
