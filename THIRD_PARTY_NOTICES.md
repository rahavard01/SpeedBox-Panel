# Source attribution

## Tunnel certificate helper

The standalone tunnel certificate helper uses go-acme/lego v4.33.0, including its
ArvanCloud and Cloudflare DNS providers, under the MIT License. Upstream:
https://github.com/go-acme/lego/tree/v4.33.0
The license is retained at `scripts/tunnel-cdn/LEGO-LICENSE`; the pinned module
versions, checksums, integration source and reproducible build are distributed in
`scripts/tunnel-cdn/`. Binaries for Linux amd64 and arm64 and their source/binary
checksums are in `tunnel-cdn-dist/`. Provider credentials are supplied over private
standard input rather than command-line arguments.

Panel SpeedBox combines the existing rahavard01/Panel application with subscription exporters from rahavard01/Xboard (ecf1129aa9b4f987421d902eb0a263daa0f49907) and the embedded cedar2025/Xboard-Node agent (0a29338e1f102a462363ce3527417029f89bab28).

Upstream URLs:
- https://github.com/rahavard01/Panel
- https://github.com/rahavard01/Xboard
- https://github.com/cedar2025/Xboard-Node

Original license files and notices are retained where provided. The agent's internal module and service names remain compatible with upstream; users manage it from the Panel SpeedBox interface.


## BackPack integration

Tunnel management uses a modified, isolated build of AminMGMT/BackPack, pinned to
official release v1.8.5, commit `ff86354bcafbd054e7888e48343145323c9b2207`.
Upstream: https://github.com/AminMGMT/BackPack
License, notices, trademark terms and the complete pinned upstream source archive
are distributed in `scripts/backpack/`. The integration changes and reproducible
build script are in that same directory. The modified build uses separate
configuration paths, service names and a JSON integration interface; it does not
replace a manually installed BackPack binary. Global kernel tuning is disabled
in this integration build.

## GOST and Linux GRE integration

The independently written integration uses official GOST 2.11.5 and 3.0.0
Linux amd64/arm64 binaries, with their MIT licenses in `gost-dist/`.
Original distribution URLs and SHA-256 hashes are recorded in the artifact
manifest. Upstreams: https://github.com/ginuerzh/gost and
https://github.com/go-gost/gost. Linux GRE uses the operating system's `ip`
and `nft` tools. No code, web panel or binary from DrSaeedHub/Tunnel-Panel
or masoudgb/Gost-ip6 is redistributed or executed.

## WatTunnel

WatTunnel (MIT), JohnWatsoon/WatTunnel, pinned commit `7c30c59a65f962ceb6763daf23952ecbc5e7de91` (2.10.9). The panel bundles only Linux engine binaries built with Go 1.26.10 and the documented UDP reply and trusted source-identity overlays, integration version `2.10.9-sb3`. Upstream managers/installers are not run. License: `wattunnel-dist/LICENSE`. Reproduction: `python3 scripts/wattunnel/build.py`; verification: `python3 scripts/verify-wattunnel.py`. Dependency licenses remain those of the pinned upstream Go modules.


