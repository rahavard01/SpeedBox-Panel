"""Exact, pinned upstream edits; all protocol logic stays in reviewed overlays."""
from pathlib import Path

def apply(root):
    def patch(path,old,new):
        p=root/path;s=p.read_text();assert s.count(old)==1,(path,old);p.write_text(s.replace(old,new))
    main=Path('cmd/wattun/main.go');udp=Path('cmd/wattun/udp.go')
    patch(main,'\tdebugOn = *debug','\tif err := speedboxConfigureOrigin(); err != nil { log.Fatal(err) }\n\tdebugOn = *debug')
    patch(main,'\tdbg("client conn %s -> stream opened", c.RemoteAddr())','\tif speedboxOriginPorts[target] {\n\t\tif err := speedboxWriteOrigin(st,c.RemoteAddr()); err != nil { c.Close(); st.Reset(); return }\n\t}\n\tdbg("client conn %s -> stream opened", c.RemoteAddr())')
    patch(main,'\t\t\tc, err := net.DialTimeout("tcp", net.JoinHostPort("127.0.0.1", strconv.Itoa(port)), 5*time.Second)', '''\t\t\tvar c net.Conn
            var err error
            if speedboxOriginPorts[port] {
                var src netip.AddrPort
                src,err=speedboxReadOrigin(st)
                if err==nil {c,err=speedboxDialOriginTCP(port,src)}
            } else { c,err=net.DialTimeout("tcp",net.JoinHostPort("127.0.0.1",strconv.Itoa(port)),5*time.Second) }''')
    patch(main,'"net"','"net"\n"net/netip"')
    patch(udp,'l.c.tun.forFlow(f.id).SendDatagram(f.id, l.target, buf[:n])','''payload:=buf[:n]
        if speedboxOriginPorts[l.target] {
            header,err:=speedboxEncodeOrigin(src);if err!=nil {continue}
            payload=append(header,payload...)
        }
        l.c.tun.forFlow(f.id).SendDatagram(f.id, l.target, payload)''')
    patch(udp,'type sflow struct {','type sflow struct {\n original netip.AddrPort')
    patch(udp,'\t\tcase d := <-sess.Datagrams():','''\t\tcase d := <-sess.Datagrams():
            var original netip.AddrPort
            if speedboxOriginPorts[d.Port] {
                var err error
                original,err=speedboxDecodeOrigin(d.Data)
                if err!=nil {udpRejected.Add(1);continue}
                d.Data=d.Data[speedboxOriginSize:]
            }''')
    patch(udp,'if f != nil && f.port != d.Port {','if f != nil && (f.port != d.Port || f.original != original) {')
    patch(udp,'\t\t\t\tconn, err := net.DialUDP("udp", nil, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: d.Port})','''                var conn *net.UDPConn
                var err error
                if speedboxOriginPorts[d.Port] {conn,err=speedboxDialOriginUDP(d.Port,original)} else {
                    conn,err=net.DialUDP("udp",nil,&net.UDPAddr{IP:net.IPv4(127,0,0,1),Port:d.Port})
                }''')
    patch(udp,'f = &sflow{conn: conn, port: d.Port,','f = &sflow{original: original, conn: conn, port: d.Port,')
    p=root/udp;s=p.read_text();assert s.count('f.conn.Close()')==2;s=s.replace('f.conn.Close()','speedboxCloseUDP(f.conn)');p.write_text(s)
    patch(udp,'\t\t\t\tf.stat.errs.Add(1)\n\t\t\t}\n\t\t}\n\t}\n}','''                f.stat.errs.Add(1)
                // Broker loss closes this UDP socket. Remove its flow even
                // when the client keeps sending, so the next packet can
                // register a fresh socket instead of extending a dead flow.
                mu.Lock()
                if flows[d.Flow]==f {delete(flows,d.Flow);udpFlowsActive.Add(-1);f.stat.udpFlows.Add(-1)}
                mu.Unlock()
                speedboxCloseUDP(f.conn)
            }
        }
    }
}''')

