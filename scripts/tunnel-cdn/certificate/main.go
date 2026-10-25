// Certificate issuance is independent from proxy nodes and tunnel engines.
package main

import (
 "crypto"
 "crypto/ecdsa"
 "crypto/elliptic"
 "crypto/rand"
 "crypto/x509"
 "encoding/json"
 "encoding/pem"
 "fmt"
 "io"
 "log"
 "os"
 "path/filepath"
 "strings"
 "time"

 "github.com/go-acme/lego/v4/certificate"
 "github.com/go-acme/lego/v4/challenge/http01"
 "github.com/go-acme/lego/v4/lego"
 legolog "github.com/go-acme/lego/v4/log"
 "github.com/go-acme/lego/v4/providers/dns/arvancloud"
 "github.com/go-acme/lego/v4/providers/dns/cloudflare"
 "github.com/go-acme/lego/v4/registration"
)

type request struct {
 StateDir string `json:"state_dir"`
 Domain string `json:"domain"`
 Mode string `json:"cert_mode"`
 Email string `json:"email"`
 HTTPPort int `json:"http_port"`
 Provider string `json:"dns_provider"`
 Env map[string]string `json:"dns_env"`
}
type account struct { Email string `json:"email"`; Registration *registration.Resource `json:"registration"`; Key crypto.PrivateKey `json:"-"` }
func(a *account)GetEmail()string{return a.Email}
func(a *account)GetRegistration()*registration.Resource{return a.Registration}
func(a *account)GetPrivateKey()crypto.PrivateKey{return a.Key}

func write(path string, b []byte)error {
 if err:=os.MkdirAll(filepath.Dir(path),0700);err!=nil{return err}
 f,err:=os.CreateTemp(filepath.Dir(path),".new-");if err!=nil{return err}
 name:=f.Name();defer os.Remove(name)
 if err=f.Chmod(0600);err==nil{_,err=f.Write(b)}
 if err==nil{err=f.Sync()};closeErr:=f.Close();if err==nil{err=closeErr};if err!=nil{return err}
 return os.Rename(name,path)
}
func leaf(b []byte)(*x509.Certificate,error){p,_:=pem.Decode(b);if p==nil{return nil,fmt.Errorf("certificate is not PEM")};return x509.ParseCertificate(p.Bytes)}
func valid(resource *certificate.Resource,domain string,remaining time.Duration)bool {
 c,err:=leaf(resource.Certificate);return err==nil&&c.VerifyHostname(domain)==nil&&time.Now().After(c.NotBefore)&&time.Until(c.NotAfter)>remaining
}
func issue(r request)(map[string]any,error){
 if r.Domain==""||strings.ContainsAny(r.Domain,"/:\\\n\r ")||r.Mode!="http"&&r.Mode!="dns"{return nil,fmt.Errorf("invalid certificate request")}
 if err:=os.MkdirAll(r.StateDir,0700);err!=nil{return nil,err}
 resourcePath:=filepath.Join(r.StateDir,"certificate.json")
 var old certificate.Resource
 if b,err:=os.ReadFile(resourcePath);err==nil&&json.Unmarshal(b,&old)==nil{
  // Lego deliberately omits PEM fields from Resource JSON. Load the paired
  // files before checking renewal age, otherwise every daily run reissues.
  old.Certificate,_=os.ReadFile(filepath.Join(r.StateDir,"fullchain.pem"))
  old.PrivateKey,_=os.ReadFile(filepath.Join(r.StateDir,"privkey.pem"))
  if len(old.PrivateKey)>0&&valid(&old,r.Domain,30*24*time.Hour){return saveResult(r,old)}
 }
 a:=&account{Email:r.Email};accountPath:=filepath.Join(r.StateDir,"account.json");keyPath:=filepath.Join(r.StateDir,"account.key")
 if b,err:=os.ReadFile(keyPath);err==nil{p,_:=pem.Decode(b);if p==nil{return nil,fmt.Errorf("invalid account key")};a.Key,err=x509.ParsePKCS8PrivateKey(p.Bytes);if err!=nil{return nil,err}}
 if a.Key==nil {
  key,err:=ecdsa.GenerateKey(elliptic.P256(),rand.Reader);if err!=nil{return nil,err};a.Key=key
  der,err:=x509.MarshalPKCS8PrivateKey(key);if err!=nil{return nil,err};if err=write(keyPath,pem.EncodeToMemory(&pem.Block{Type:"PRIVATE KEY",Bytes:der}));err!=nil{return nil,err}
 }
 if b,err:=os.ReadFile(accountPath);err==nil{if err=json.Unmarshal(b,a);err!=nil{return nil,err}}
 config:=lego.NewConfig(a);config.CADirURL=lego.LEDirectoryProduction;config.Certificate.KeyType="EC256";config.Certificate.Timeout=5*time.Minute
 client,err:=lego.NewClient(config);if err!=nil{return nil,err}
 if r.Mode=="http" {
  if r.HTTPPort==0{r.HTTPPort=80};err=client.Challenge.SetHTTP01Provider(http01.NewProviderServer("",fmt.Sprint(r.HTTPPort)))
 }else{switch strings.ToLower(r.Provider){
  case "arvancloud","arvan","آروان":
   cfg:=arvancloud.NewDefaultConfig();cfg.APIKey=r.Env["ARVANCLOUD_API_KEY"]
   provider,e:=arvancloud.NewDNSProviderConfig(cfg);if e!=nil{return nil,e};err=client.Challenge.SetDNS01Provider(provider)
  case "cloudflare","cf":
   cfg:=cloudflare.NewDefaultConfig();cfg.AuthToken=r.Env["CLOUDFLARE_DNS_API_TOKEN"];if cfg.AuthToken==""{cfg.AuthToken=r.Env["CF_DNS_API_TOKEN"]};cfg.ZoneToken=r.Env["CLOUDFLARE_ZONE_API_TOKEN"]
   provider,e:=cloudflare.NewDNSProviderConfig(cfg);if e!=nil{return nil,e};err=client.Challenge.SetDNS01Provider(provider)
  default:return nil,fmt.Errorf("unsupported DNS provider")
 }}
 if err!=nil{return nil,err}
 if a.Registration==nil {
  a.Registration,err=client.Registration.Register(registration.RegisterOptions{TermsOfServiceAgreed:true});if err!=nil{return nil,err}
  b,_:=json.Marshal(a);if err=write(accountPath,b);err!=nil{return nil,err}
 }
 var resource *certificate.Resource
 if len(old.Certificate)>0&&valid(&old,r.Domain,0){resource,err=client.Certificate.RenewWithOptions(old,&certificate.RenewOptions{Bundle:true})
 }else{resource,err=client.Certificate.Obtain(certificate.ObtainRequest{Domains:[]string{r.Domain},Bundle:true})}
 if err!=nil{return nil,err};if !valid(resource,r.Domain,time.Hour){return nil,fmt.Errorf("issued certificate failed validation")}
 b,_:=json.Marshal(resource);if err=write(resourcePath,b);err!=nil{return nil,err};return saveResult(r,*resource)
}
func saveResult(r request,c certificate.Resource)(map[string]any,error){
 certPath,keyPath:=filepath.Join(r.StateDir,"fullchain.pem"),filepath.Join(r.StateDir,"privkey.pem")
 if err:=write(certPath,c.Certificate);err!=nil{return nil,err};if err:=write(keyPath,c.PrivateKey);err!=nil{return nil,err}
 certificate,err:=leaf(c.Certificate);if err!=nil{return nil,err}
 return map[string]any{"cert_file":certPath,"key_file":keyPath,"expires_at":certificate.NotAfter.Unix()},nil
}
func main(){
 legolog.Logger=log.New(io.Discard,"",0)
 var r request;decoder:=json.NewDecoder(io.LimitReader(os.Stdin,1<<20));decoder.DisallowUnknownFields()
 if err:=decoder.Decode(&r);err!=nil{fmt.Println(`{"ok":false,"error":"invalid request"}`);os.Exit(1)}
 data,err:=issue(r);if err!=nil{fmt.Println(`{"ok":false,"error":"صدور یا تمدید گواهی انجام نشد؛ دسترسی DNS، مسیر چالش و ارتباط سرور با مرجع صدور را بررسی کنید."}`);os.Exit(1)}
 _=json.NewEncoder(os.Stdout).Encode(map[string]any{"ok":true,"data":data})
}
