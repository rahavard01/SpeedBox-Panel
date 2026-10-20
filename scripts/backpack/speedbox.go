// SpeedBox integration for the pinned BackPack source. Upstream license applies.
package main

import (
 "encoding/json"
 "fmt"
 "os"
 "github.com/backpack/backpack/internal/manage"
)

func init() {
 if len(os.Args)<3 || os.Args[1]!="speedbox-json" { return }
 raw,err:=os.ReadFile(os.Args[2]); if err!=nil { fmt.Fprintln(os.Stderr,"request unavailable"); os.Exit(1) }
 var req manage.SpeedboxRequest
 if json.Unmarshal(raw,&req)!=nil { fmt.Fprintln(os.Stderr,"invalid request"); os.Exit(1) }
 answer,err:=manage.SpeedboxExecute(req)
 if err!=nil { _=json.NewEncoder(os.Stdout).Encode(map[string]any{"ok":false,"error":err.Error()}); os.Exit(1) }
 _=json.NewEncoder(os.Stdout).Encode(map[string]any{"ok":true,"data":answer})
 os.Exit(0)
}
