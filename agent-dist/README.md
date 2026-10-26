# Bundled Linux agent releases

Each archive is stored as ordered `linux-ARCH.tar.gz.part-NNN` files of at most
8 MiB. The manifest records every part's size and SHA-256, the combined archive's
size and SHA-256, and the extracted Linux binaries' architecture and checksums.

Install and update commands stay unchanged. `scripts/build-agent.sh` verifies
the parts, assembles archives in its temporary staging directory, extracts and
verifies the binaries, then removes staging files. No Go or network fallback is
used on deployment servers. The verifier also accepts older complete archives.

For a new release, run `bash scripts/build-agent-from-source.sh` on a Linux build
machine and commit the changed agent source, packaging scripts, manifest and all
part files together. Do not construct a new manifest around old agent binaries.
