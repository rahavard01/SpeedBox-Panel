package manage

import (
 "context"
 "encoding/json"
 "fmt"
 "io"
 "net"
 "os"
 "os/exec"
 "os/signal"
 "path/filepath"
 "regexp"
 "strconv"
 "strings"
 "syscall"
 "sync"
 "time"

 "github.com/backpack/backpack/internal/app"
 "github.com/backpack/backpack/internal/metrics"
 "github.com/backpack/backpack/internal/tunnel/l3"
 "github.com/sirupsen/logrus"
)

const speedboxProtocol=1
var sbName=regexp.MustCompile(`^sb-[a-f0-9]{32}$`)
var sbID=regexp.MustCompile(`^[a-f0-9]{32}$`)
type SpeedboxRequest struct {
 Action string `json:"action"`
 ID string `json:"id"`
 Name string `json:"name"`
 Host string `json:"host"`
 Link string `json:"link"`
 Role string `json:"role"`
 Kind string `json:"kind"`
 Transport string `json:"transport"`
 Token string `json:"token"`
 Port int `json:"port"`
 Ports string `json:"ports"`
 UDP bool `json:"udp"`
 LocalIP string `json:"local_ip"`
 PeerIP string `json:"peer_ip"`
 TargetPorts []int `json:"target_ports"`
 Revision string `json:"revision"`
 Swapped bool `json:"swapped"`
}
type sbTestState struct {
 State string `json:"state"`
 Link string `json:"link,omitempty"`
 Rows []ConnTestResult `json:"rows,omitempty"`
 Error string `json:"error,omitempty"`
 Updated int64 `json:"updated"`
}
func sbTestPath(id string)string{return filepath.Join(app.ConfigDir,"speedbox-tests",id+".json")}
func sbWriteTest(id string,s sbTestState)error{
 s.Updated=time.Now().Unix(); raw,err:=json.Marshal(s); if err!=nil{return err}
 if err=os.MkdirAll(filepath.Dir(sbTestPath(id)),0700);err!=nil{return err}
 return app.WriteFileAtomic(sbTestPath(id),raw,0600)
}
func SpeedboxExecute(r SpeedboxRequest)(any,error){
 logrus.SetOutput(os.Stderr)
 switch r.Action {
 case "version": return map[string]any{"protocol":speedboxProtocol,"upstream":app.Version,"source":"132467bfdfb2698d74ada2992413e75bfccbfbd3"},nil
 case "spawn-host","spawn-peer":
  if !sbID.MatchString(r.ID){return nil,fmt.Errorf("invalid test id")}
  dir:=filepath.Dir(sbTestPath(r.ID));if err:=os.MkdirAll(dir,0700);err!=nil{return nil,err}
  role:=strings.TrimPrefix(r.Action,"spawn-");r.Action="test-"+role
  raw,_:=json.Marshal(r);path:=filepath.Join(dir,r.ID+"-"+role+".request")
  if err:=app.WriteFileAtomic(path,raw,0600);err!=nil{return nil,err}
  unit:="speedbox-bp-test-"+r.ID+"-"+role
  out,err:=exec.Command("systemd-run","--quiet","--collect","--unit="+unit,"--property=RuntimeMaxSec=1000","--property=TimeoutStopSec=20",app.BinPath,"speedbox-json",path).CombinedOutput()
  if err!=nil{return nil,fmt.Errorf("test could not start: %s",out)}
  return map[string]any{"started":true},nil
 case "test-state":
  if !sbID.MatchString(r.ID){return nil,fmt.Errorf("invalid test id")}
  raw,err:=os.ReadFile(sbTestPath(r.ID));if os.IsNotExist(err){return sbTestState{State:"starting"},nil};if err!=nil{return nil,err}
  var s sbTestState;err=json.Unmarshal(raw,&s);return s,err
 case "test-stop":
  if !sbID.MatchString(r.ID){return nil,fmt.Errorf("invalid test id")}
  for _,role:=range []string{"host","peer"}{_ =exec.Command("systemctl","stop","speedbox-bp-test-"+r.ID+"-"+role+".service").Run();_ =os.Remove(filepath.Join(filepath.Dir(sbTestPath(r.ID)),r.ID+"-"+role+".request"))}
  _=os.Remove(sbTestPath(r.ID));return map[string]bool{"stopped":true},nil
 case "test-host":return sbRunHost(r)
 case "test-peer":
  ctx,stop:=signal.NotifyContext(context.Background(),os.Interrupt,syscall.SIGTERM);defer stop()
  ctx,cancel:=context.WithTimeout(ctx,950*time.Second);defer cancel()
  defer os.Remove(filepath.Join(filepath.Dir(sbTestPath(r.ID)),r.ID+"-peer.request"))
  rows,_,err:=RunConnTestKharej(ctx,r.Link,io.Discard,nil);return rows,err
 }
 if !sbName.MatchString(r.Name){return nil,fmt.Errorf("invalid owned tunnel name")}
 switch r.Action {
 case "apply":return sbApply(r)
 case "snapshot","rollback","commit":return sbRevision(r)
 case "start","stop","restart":
  if _,ok:=Find(r.Name);!ok{return nil,fmt.Errorf("tunnel missing")}
  var err error
  switch r.Action{case "start":err=StartService(app.ServiceName(r.Name));case "stop":err=StopService(app.ServiceName(r.Name));default:err=RestartService(app.ServiceName(r.Name))}
  return map[string]any{"active":IsActive(app.ServiceName(r.Name))},err
 case "delete":
  if _,ok:=Find(r.Name);ok{if err:=Delete(r.Name);err!=nil{return nil,err}}
  return map[string]bool{"deleted":true},nil
 case "status":return sbStatus(r)
 case "logs":return map[string]string{"text":Logs(r.Name,150)},nil
 }
 return nil,fmt.Errorf("unsupported operation")
}
func sbRunHost(r SpeedboxRequest)(any,error){
 if !sbID.MatchString(r.ID){return nil,fmt.Errorf("invalid test id")}
 ctx,stop:=signal.NotifyContext(context.Background(),os.Interrupt,syscall.SIGTERM);defer stop()
 ctx,cancel:=context.WithTimeout(ctx,950*time.Second);defer cancel()
 defer os.Remove(filepath.Join(filepath.Dir(sbTestPath(r.ID)),r.ID+"-host.request"))
 _=sbWriteTest(r.ID,sbTestState{State:"starting"})
 s,link,err:=StartConnTestIran(ConnTestOptions{Context:ctx,Host:r.Host,Direct:true,Preset:PresetTurbo})
 if err!=nil{_=sbWriteTest(r.ID,sbTestState{State:"failed",Error:err.Error()});return nil,err};defer s.Close()
 _=sbWriteTest(r.ID,sbTestState{State:"waiting",Link:link})
 select{case <-s.Joined():case <-ctx.Done():_=sbWriteTest(r.ID,sbTestState{State:"failed",Error:"peer did not join"});return nil,ctx.Err()}
 state:=sbTestState{State:"running",Link:link}
 var progressLock sync.Mutex
 state.Rows=s.Run(ctx,func(i int,row ConnTestResult){progressLock.Lock();defer progressLock.Unlock();for len(state.Rows)<=i{state.Rows=append(state.Rows,ConnTestResult{})};state.Rows[i]=row;_=sbWriteTest(r.ID,state)})
 if ctx.Err()!=nil{state.State="failed";state.Error="test interrupted"}else{state.State="completed"}
 _=sbWriteTest(r.ID,state)
 select{case <-s.Fetched():case <-ctx.Done():case <-time.After(15*time.Second):}
 return map[string]any{"state":state.State},nil
}
func sbApply(r SpeedboxRequest)(any,error){
 if r.Port<1||r.Port>65535||len(r.Token)<32{return nil,fmt.Errorf("invalid port or token")}
 if r.Kind=="direct" {
  // The /30 is reserved by the panel for this pair and checked on both hosts.
  spec,err:=sbDirectSpec(r);if err!=nil{return nil,err}
  name,body,err:=directBodyFromSpec(spec);if err!=nil{return nil,err}
  if err=writeDirectConfig(name,body);err!=nil{return nil,err}
  if err=RestartService(app.ServiceName(name));err!=nil{return nil,err}
 }else if r.Kind=="reverse" {
  form:=NewTunnel{Role:r.Role,Transport:r.Transport,Name:r.Name,Token:r.Token,ServerAddr:r.Host,TunnelPort:strconv.Itoa(r.Port),Ports:r.Ports,Preset:PresetTurbo}
  if r.Role=="server" { form.Tune=&FineTune{};raw:=[]byte(fmt.Sprintf(`{"acceptUDP":%t}`,r.UDP));_=json.Unmarshal(raw,form.Tune) }
  if _,_,_,err:=ApplyTunnel(form);err!=nil{return nil,err}
 }else{return nil,fmt.Errorf("unsupported tunnel kind")}
 return sbStatus(r)
}
func sbStatus(r SpeedboxRequest)(any,error){
 exists:=false;if _,ok:=Find(r.Name);ok{exists=true}
 out:=map[string]any{"exists":exists,"active":exists&&IsActive(app.ServiceName(r.Name)),"checked_at":time.Now().Unix()}
 if snap,err:=metrics.Read(app.ConfigDir,r.Name);err==nil&&time.Since(snap.Taken)<90*time.Second{out["metrics"]=snap}
 targets:=map[string]any{};for _,p:=range r.TargetPorts{if p<1||p>65535{continue};if r.UDP{targets[strconv.Itoa(p)]=nil;continue};c,err:=net.DialTimeout("tcp",net.JoinHostPort("127.0.0.1",strconv.Itoa(p)),time.Second);targets[strconv.Itoa(p)]=err==nil;if err==nil{_=c.Close()}}
 out["targets"]=targets;return out,nil
}
func sbRevision(r SpeedboxRequest)(any,error){
 if !sbID.MatchString(r.Revision){return nil,fmt.Errorf("invalid revision")}
 dir:=filepath.Join(app.ConfigDir,"speedbox-revisions");if err:=os.MkdirAll(dir,0700);err!=nil{return nil,err}
 backup:=filepath.Join(dir,r.Name+"-"+r.Revision);path:=app.ConfigPath(r.Name)
 switch r.Action{
 case "snapshot":
  if _,err:=os.Stat(backup);err==nil{return map[string]bool{"saved":true},nil}
  raw,err:=os.ReadFile(path);if os.IsNotExist(err){raw=[]byte{};err=nil};if err!=nil{return nil,err}
  return map[string]bool{"saved":true},app.WriteFileAtomic(backup,raw,0600)
 case "commit":return map[string]bool{"committed":true},os.Remove(backup)
 case "rollback":
  raw,err:=os.ReadFile(backup);if os.IsNotExist(err){return map[string]bool{"restored":true},nil};if err!=nil{return nil,err}
  if len(raw)==0 {if _,ok:=Find(r.Name);ok{err=Delete(r.Name)}}else{err=app.WriteFileAtomic(path,raw,0600);if err==nil{err=RestartService(app.ServiceName(r.Name))}}
  if err==nil{_=os.Remove(backup)};return map[string]bool{"restored":err==nil},err
 }
 return nil,fmt.Errorf("invalid revision operation")
}

// Dial/listen placement is independent from the physical Iran ingress.
func sbDirectSpec(r SpeedboxRequest)(l3Spec,error){
 if !sbName.MatchString(r.Name){return l3Spec{},fmt.Errorf("invalid owned name")}
 if r.Role!="iran"&&r.Role!="kharej"{return l3Spec{},fmt.Errorf("invalid physical side")}
 if !offeredCarrier(r.Transport)||r.Transport=="spoof"{return l3Spec{},fmt.Errorf("unsupported carrier")}
 if err:=l3.CheckTunnelEnds(r.LocalIP,r.PeerIP);err!=nil{return l3Spec{},err}
 if owner:=l3BlockOwner(r.LocalIP);owner!=""&&owner!=r.Name{return l3Spec{},fmt.Errorf("subnet already used")}
 side:=sideIran
 if (r.Role=="kharej")!=r.Swapped {side=sideKharej}
 addr:=net.JoinHostPort("0.0.0.0",strconv.Itoa(r.Port))
 if side==sideIran{if strings.TrimSpace(r.Host)==""{return l3Spec{},fmt.Errorf("missing peer")};addr=net.JoinHostPort(r.Host,strconv.Itoa(r.Port))}
 spec:=l3Spec{Name:r.Name,Side:side,Carrier:r.Transport,Encap:"gre",Addr:addr,Token:r.Token,Iface:"sbp"+r.Name[3:14],LocalIP:r.LocalIP,PeerIP:r.PeerIP,MTU:defaultL3MTU}
 findL3Preset(PresetTurbo).apply(&spec)
 old,oldErr:=DirectSettingsOf(r.Name)
 if oldErr==nil&&strings.HasPrefix(old.Iface,"sbp"){spec.Iface=old.Iface}
 if _,err:=net.InterfaceByName(spec.Iface);err==nil&&(oldErr!=nil||old.Iface!=spec.Iface){return l3Spec{},fmt.Errorf("interface already used")}
 if r.Role=="iran"{spec.Ports=parsePorts(r.Ports);if len(spec.Ports)==0{return l3Spec{},fmt.Errorf("ports required")};if err:=validatePortSpecs(spec.Ports);err!=nil{return l3Spec{},err};spec.AcceptUDP=r.UDP}
 return spec,nil
}

// Also account for tunnels outside the isolated config directory.
func speedboxKernelBlockOwner(addr string)string{
 ip:=net.ParseIP(strings.SplitN(addr,"/",2)[0]);if ip==nil{return ""};_,candidate,_:=net.ParseCIDR(ip.String()+"/30")
 interfaces,_:=net.Interfaces()
 for _,iface:=range interfaces{addrs,_:=iface.Addrs();for _,a:=range addrs{address,network,err:=net.ParseCIDR(a.String());if err==nil&&(network.Contains(ip)||candidate.Contains(address)){return "interface:"+iface.Name}}}
 return ""
}
func speedboxTestIface(id string,index int)string{
 if len(id)>8{id=id[:8]}
 for attempt:=0;attempt<256;attempt++{name:=fmt.Sprintf("sbt%s%02x%02x",id,index,attempt);if len(name)>15{name=name[:15]};if _,err:=net.InterfaceByName(name);err!=nil{return name}}
 return "sbt"+randomToken(8)
}
