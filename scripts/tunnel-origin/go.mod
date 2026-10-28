module github.com/cedar2025/xboard-node/tunnel-origin

go 1.26

godebug multipathtcp=0

require github.com/cedar2025/xboard-node v0.0.0

replace github.com/cedar2025/xboard-node => ../../agent
