//go:build linux

package main

import (
    "net"
    "sync"
    "golang.org/x/sys/unix"
)

// Idle flows wait on Go's poller with a one-byte peek, without retaining a
// 64 KiB buffer. Only readable sockets borrow a full datagram buffer.
var speedboxUDPBuffers = sync.Pool{New: func() any { b:=make([]byte,65535);return &b }}

func speedboxReadUDP(c *net.UDPConn) ([]byte,error) {
    raw,err:=c.SyscallConn();if err!=nil{return nil,err}
    var buf []byte
    var readErr error
    err=raw.Read(func(fd uintptr) bool {
        var peek [1]byte
        _,_,readErr=unix.Recvfrom(int(fd),peek[:],unix.MSG_PEEK|unix.MSG_DONTWAIT)
        if readErr==unix.EAGAIN||readErr==unix.EWOULDBLOCK{return false}
        if readErr!=nil{return true}
        bp:=speedboxUDPBuffers.Get().(*[]byte)
        var n int
        n,readErr=unix.Read(int(fd),*bp)
        if readErr!=nil {
            speedboxUDPBuffers.Put(bp)
            return readErr!=unix.EAGAIN && readErr!=unix.EWOULDBLOCK
        }
        buf=(*bp)[:n]
        return true
    })
    if err!=nil{return nil,err}
    return buf,readErr
}

func speedboxReleaseUDP(buf []byte) {
    buf=buf[:cap(buf)]
    speedboxUDPBuffers.Put(&buf)
}
