package singbox

import (
	"context"
	"errors"

	"github.com/sagernet/sing-box/adapter"
	singM "github.com/sagernet/sing/common/metadata"
	"google.golang.org/grpc/peer"
)

// gRPC supports a public X-Forwarded-For header. In trusted-origin mode its
// metadata is not an authority: recover the actual transport peer from the
// gRPC stream, including protocol wrappers, or fail closed.
func (t *ConnTracker) sourceIdentity(conn any, metadata adapter.InboundContext) (string, error) {
	source := metadata.Source
	if t.originPolicy.Required && t.originTransport == "grpc" {
		source = singM.Socksaddr{}
		queue := []any{conn}
		for steps := 0; len(queue) > 0 && steps < 64; steps++ {
			c := queue[0]
			queue = queue[1:]
			if stream, ok := c.(interface{ Context() context.Context }); ok {
				if p, ok := peer.FromContext(stream.Context()); ok {
					source = singM.SocksaddrFromNet(p.Addr)
					break
				}
			}
			if u, ok := c.(interface{ Upstream() any }); ok {
				queue = append(queue, u.Upstream())
			}
			if u, ok := c.(interface{ UpstreamReader() any }); ok {
				queue = append(queue, u.UpstreamReader())
			}
			if u, ok := c.(interface{ UpstreamWriter() any }); ok {
				queue = append(queue, u.UpstreamWriter())
			}
		}
		if !source.IsValid() {
			return "", errors.New("trusted origin requires the gRPC transport peer")
		}
	}
	return t.originPolicy.Resolve(source.Addr, source.Port)
}
