package manage

import (
 "encoding/json"
 "strings"
 "testing"
 "github.com/BurntSushi/toml"
 "github.com/backpack/backpack/config"
)

func TestSpeedboxDirectKeepsIranIngressInBothOrientations(t *testing.T){
 for _,swapped:=range []bool{false,true}{
  for _,side:=range []string{"iran","kharej"}{
   req:=SpeedboxRequest{Name:"sb-0123456789abcdef0123456789abcdef",Role:side,Swapped:swapped,Transport:"udp",Host:"192.0.2.1",Port:8000,Token:strings.Repeat("a",64),Ports:"443=5790",LocalIP:"10.203.250.1/30",PeerIP:"10.203.250.2"}
   if side=="kharej"{req.LocalIP="10.203.250.2/30";req.PeerIP="10.203.250.1"}
   s,err:=sbDirectSpec(req);if err!=nil{t.Fatal(err)}
   if !strings.HasPrefix(s.Iface,"sbp")||len(s.Iface)>15{t.Fatal("interface not isolated")}
   var c config.Config;if _,err=toml.Decode(s.Render(),&c);err!=nil{t.Fatal(err)}
   wantMode:="dial";if (side=="kharej")!=swapped{wantMode="listen"}
   if c.L3.Mode!=wantMode{t.Fatalf("%s swapped=%v mode=%s",side,swapped,c.L3.Mode)}
   if side=="iran"&&len(c.L3.Ports)!=1{t.Fatal("Iran ingress lost")}
   if side=="kharej"&&len(c.L3.Ports)!=0{t.Fatal("public ingress moved abroad")}
  }
 }
}
func TestSpeedboxRejectsUnownedNamesAndOperations(t *testing.T){
 for _,r:=range []SpeedboxRequest{{Action:"delete",Name:"manual-tunnel"},{Action:"delete",Name:"../other"},{Action:"snapshot",Name:"sb-0123456789abcdef0123456789abcdef",Revision:"../../etc"},{Action:"shell",Name:"sb-0123456789abcdef0123456789abcdef"}}{
  if _,err:=SpeedboxExecute(r);err==nil{t.Fatalf("accepted %+v",r)}
 }
}
func TestSpeedboxReversePartialTuneKeepsPreset(t *testing.T){
 f:=&FineTune{};if err:=json.Unmarshal([]byte(`{"acceptUDP":true}`),f);err!=nil{t.Fatal(err)}
 s,err:=specFromNew(NewTunnel{Role:"server",Transport:"tcp",Name:"sb-0123456789abcdef0123456789abcdef",Token:strings.Repeat("t",64),TunnelPort:"61020",Ports:"61021=61022",Preset:PresetTurbo,Tune:f});if err!=nil{t.Fatal(err)}
 if !s.AcceptUDP||s.KeepAlive==0{t.Fatal("partial UDP setting erased preset")}
}
