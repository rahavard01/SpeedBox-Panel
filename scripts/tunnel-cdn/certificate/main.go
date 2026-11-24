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
 "errors"
 "fmt"
 "io"
 "log"
 "net"
 "os"
 "regexp"
 "path/filepath"
 "strings"
 "syscall"
 "time"
 "unicode"

 "github.com/go-acme/lego/v4/acme"
 "github.com/go-acme/lego/v4/certcrypto"
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
func acmeConfig(a *account) *lego.Config {
 config := lego.NewConfig(a)
 config.CADirURL = lego.LEDirectoryProduction
 config.Certificate.KeyType = certcrypto.EC256
 config.Certificate.Timeout = 5 * time.Minute
 return config
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
 client,err:=lego.NewClient(acmeConfig(a));if err!=nil{return nil,err}
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
 if err!=nil{return nil,fmt.Errorf("challenge setup: %w", err)}
 if a.Registration==nil {
  a.Registration,err=client.Registration.Register(registration.RegisterOptions{TermsOfServiceAgreed:true});if err!=nil{return nil,fmt.Errorf("account registration: %w", err)}
  b,_:=json.Marshal(a);if err=write(accountPath,b);err!=nil{return nil,err}
 }
 var resource *certificate.Resource
 if len(old.Certificate)>0&&valid(&old,r.Domain,0){resource,err=client.Certificate.RenewWithOptions(old,&certificate.RenewOptions{Bundle:true})
 }else{resource,err=client.Certificate.Obtain(certificate.ObtainRequest{Domains:[]string{r.Domain},Bundle:true})}
 if err!=nil{return nil,fmt.Errorf("certificate order: %w", err)};if !valid(resource,r.Domain,time.Hour){return nil,fmt.Errorf("issued certificate failed validation")}
 b,_:=json.Marshal(resource);if err=write(resourcePath,b);err!=nil{return nil,err};return saveResult(r,*resource)
}
func saveResult(r request,c certificate.Resource)(map[string]any,error){
 certPath,keyPath:=filepath.Join(r.StateDir,"fullchain.pem"),filepath.Join(r.StateDir,"privkey.pem")
 if err:=write(certPath,c.Certificate);err!=nil{return nil,err};if err:=write(keyPath,c.PrivateKey);err!=nil{return nil,err}
 certificate,err:=leaf(c.Certificate);if err!=nil{return nil,err}
 return map[string]any{"cert_file":certPath,"key_file":keyPath,"expires_at":certificate.NotAfter.Unix()},nil
}
var httpDiagnosticSecrets = regexp.MustCompile(`(?i)https?://[^[:space:]"'<>]+|[[:alnum:]._%+\-]+@[[:alnum:].\-]+|[a-z0-9_+/-]{24,}={0,2}`)
var httpDiagnosticStatus = regexp.MustCompile(`([45][0-9]{2}) ::[[:space:]]*(GET|POST|HEAD)\b`)

func httpDiagnostic(err error) string {
 message := err.Error()
 if strings.Contains(message, "-----BEGIN ") {
  return "Certificate or key material was omitted."
 }
 // Lego includes raw response bodies after JSON parse errors. Keep only the
 // HTTP status and method for these errors.
 if strings.Contains(message, "failed to unmarshal") || strings.Contains(message, "invalid character") {
  return "Unexpected ACME response format. " + httpDiagnosticStatus.FindString(message)
 }
 message = httpDiagnosticSecrets.ReplaceAllString(message, "[redacted]")
 message = strings.Join(strings.Fields(strings.Map(func(r rune) rune {
  if unicode.IsControl(r) || unicode.In(r,unicode.Cf) {return ' '}
  return r
 },message)), " ")
 runes := []rune(message)
 if len(runes)>360 {message=string(runes[:360])+"…"}
 return message
}

// Standard problem codes and a scrubbed fallback are used only for HTTP-01.

func httpFailure(err error) map[string]any {
 result := map[string]any{"ok":false,"diagnostic":httpDiagnostic(err)}
 for _, stage := range []string{"challenge setup","account registration","certificate order"} {
  if strings.HasPrefix(err.Error(),stage+": ") { result["stage"]=stage; break }
 }
 var problem *acme.ProblemDetails
 var dns *net.DNSError
 var operation *net.OpError
 switch {
 case errors.As(err,&problem):
  code := strings.TrimPrefix(problem.Type,"urn:ietf:params:acme:error:")
  if code != problem.Type && len(code)<=48 && strings.IndexFunc(code,func(r rune) bool {return (r<'a'||r>'z')&&(r<'A'||r>'Z')})<0 {
   result["code"]=code
  }
  if problem.HTTPStatus>=100 && problem.HTTPStatus<=599 { result["status"]=problem.HTTPStatus }
 case errors.Is(err,syscall.EADDRINUSE): result["code"]="addressInUse"
 case errors.Is(err,syscall.EACCES): result["code"]="permissionDenied"
 case errors.As(err,&dns): result["code"]="dns"
 case errors.As(err,&operation):
  if operation.Timeout() { result["code"]="connectionTimeout" } else { result["code"]="connection" }
 default:
  // Lego aggregates some per-domain challenge failures into a formatted error.
  // Match standard ACME codes without exporting the arbitrary error text.
  for _, code := range []string{"rateLimited","caa","dns","connection","incorrectResponse","unauthorized","malformed","accountDoesNotExist","rejectedIdentifier","serverInternal","badNonce","invalidContact"} {
   if strings.Contains(err.Error(),"urn:ietf:params:acme:error:"+code) { result["code"]=code; break }
  }
 }
 return result
}

func main(){
 legolog.Logger=log.New(io.Discard,"",0)
 var r request;decoder:=json.NewDecoder(io.LimitReader(os.Stdin,1<<20));decoder.DisallowUnknownFields()
 if err:=decoder.Decode(&r);err!=nil{fmt.Println(`{"ok":false,"error":"invalid request"}`);os.Exit(1)}
 data,err:=issue(r);if err!=nil{if r.Mode=="http"{_ = json.NewEncoder(os.Stdout).Encode(httpFailure(err));os.Exit(1)};fmt.Println(`{"ok":false,"error":"صدور یا تمدید گواهی انجام نشد؛ دسترسی DNS، مسیر چالش و ارتباط سرور با مرجع صدور را بررسی کنید."}`);os.Exit(1)}
 _=json.NewEncoder(os.Stdout).Encode(map[string]any{"ok":true,"data":data})
}
