# SS2022 connection lifetime

The pinned Xray SS2022 TCP handlers used `singbridge.CopyConn`, whose pipe
wrapper does not close the dispatched link. A closed client can therefore
leave admission tickets alive until the bridge's 300-second read timeout.

The reviewed patch uses `DispatchLink` with `singbridge.NewConn`, the same
reader/writer adapter used by Xray's existing synchronous connection handler.
Both single-user and multi-user SS2022 TCP handlers are covered. Authentication,
cipher/key handling, UDP processing and general connection timeouts are unchanged.

Run source tests and builds on Linux with:

```sh
SPEEDBOX_GO=/path/to/go python3 go_patched.py test -p 1 -count=1 ./internal/kernel/xray
```

`go_patched.py` verifies the pinned module's reviewed source files, patches a
private copy and checks the entire copy before reuse. Its generated directory
is `../.speedbox-build/`. All module operations use temporary module/sum files;
the original module cache and project module files are preserved. The Makefile,
Docker build, panel source-release builder and Linux release test runner use it.
Bare `go test`/`go build` still select the original upstream dependency, so use
the supplied runner or existing build entry points for this patched release.

The real-socket regression compares legacy SS and SS2022 AES-128/AES-256. It
checks two sockets sharing one lease, quiet healthy sockets, closing one socket
without disturbing the other, releasing the final socket, and completing an
SS2022 response after the client closes its write half.

After tests pass, rebuild/package the agent using the normal source release
script. Commit the generated `agent-dist` release and its manifest together with
the source changes before updating the panel and replacing remote node agents.
The source-release version ends in `ss2022-dispatchlink-v1`.
