package main

import (
    "bytes"
    "net"
    "testing"
    "time"
)

func TestSpeedboxUDPReply(t *testing.T) {
    peer,err:=net.ListenUDP("udp4",&net.UDPAddr{IP:net.IPv4(127,0,0,1)})
    if err!=nil {t.Fatal(err)};defer peer.Close()
    c,err:=net.DialUDP("udp4",nil,peer.LocalAddr().(*net.UDPAddr))
    if err!=nil {t.Fatal(err)};defer c.Close()
    c.Write([]byte{1});var hello [1]byte;_,addr,_:=peer.ReadFromUDP(hello[:])
    for _,size:=range []int{0,64,4096,8192,60000,65507} {
        want:=bytes.Repeat([]byte{byte(size%251)},size)
        if _,err=peer.WriteToUDP(want,addr);err!=nil {t.Fatal(err)}
        c.SetReadDeadline(time.Now().Add(time.Second))
        got,err:=speedboxReadUDP(c)
        if err!=nil {t.Fatal(err)}
        if !bytes.Equal(got,want) {t.Fatalf("reply truncated: want %d got %d",len(want),len(got))}
        speedboxReleaseUDP(got)
    }
    done:=make(chan error,1);c.SetReadDeadline(time.Time{})
    go func(){_,err:=speedboxReadUDP(c);done<-err}()
    c.Close()
    select {case err:=<-done:if err==nil {t.Fatal("closed socket accepted")};case <-time.After(time.Second):t.Fatal("idle close blocked")}
}
