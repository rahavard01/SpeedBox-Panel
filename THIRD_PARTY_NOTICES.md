# Source attribution

Panel SpeedBox combines the existing rahavard01/Panel application with subscription exporters from rahavard01/Xboard (ecf1129aa9b4f987421d902eb0a263daa0f49907) and the embedded cedar2025/Xboard-Node agent (0a29338e1f102a462363ce3527417029f89bab28).

Upstream URLs:
- https://github.com/rahavard01/Panel
- https://github.com/rahavard01/Xboard
- https://github.com/cedar2025/Xboard-Node

Original license files and notices are retained where provided. The agent's internal module and service names remain compatible with upstream; users manage it from the Panel SpeedBox interface.


## BackPack integration

Tunnel management uses a modified, isolated build of AminMGMT/BackPack, pinned to
commit `132467bfdfb2698d74ada2992413e75bfccbfbd3` (reports v1.9.0).
Upstream: https://github.com/AminMGMT/BackPack
License, notices, trademark terms and the complete pinned upstream source archive
are distributed in `scripts/backpack/`. The integration changes and reproducible
build script are in that same directory. The modified build uses separate
configuration paths, service names and a JSON integration interface; it does not
replace a manually installed BackPack binary. Global kernel tuning is disabled
in this integration build.
