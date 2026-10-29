package main
import(
 "context"
 "encoding/json"
 "errors"
 "flag"
 "fmt"
 "log"
 "net"
 "os"
 "os/signal"
 "strconv"
 "sync"
 "syscall"
 "time"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/ingest"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/spool"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/state"
)
const version="0.1.0-migration"
func env(key,fallback string)string{if v:=os.Getenv(key);v!=""{return v};return fallback}
func main(){if err:=run();err!=nil{fmt.Fprintln(os.Stderr,"dozor-agent:",err);os.Exit(1)}}
func run()error{
 if len(os.Args)>1&&os.Args[1]=="status"{
  fs:=flag.NewFlagSet("status",flag.ContinueOnError)
  jsonFlag:=fs.Bool("json",false,"machine-readable status")
  if err:=fs.Parse(os.Args[2:]);err!=nil{return err}
  if !*jsonFlag{return errors.New("only status --json is supported during migration")}
  return status(env("DOZOR_GO_SPOOL_PATH","./dozor-go-spool"))
 }
 fs:=flag.NewFlagSet("dozor-agent",flag.ContinueOnError)
 listen:=fs.String("listen",env("DOZOR_INGEST_URI","127.0.0.1:4815"),"loopback listen address")
 path:=fs.String("spool",env("DOZOR_GO_SPOOL_PATH","./dozor-go-spool"),"durable raw records directory")
 hash:=fs.String("token-hash",env("DOZOR_AGENT_TOKEN_HASH",""),"PHP xxh128 token hash prefix")
 statePath:=fs.String("state-path",env("DOZOR_AGENT_STATE_PATH","./runtime-state.json"),"PHP-compatible runtime status path")
 max:=fs.Int("max-batches",envInt("DOZOR_AGENT_SHIP_MAX_SPOOL_BATCHES",500),"spool capacity")
 debug:=fs.Bool("debug",false,"append-only safe debug log")
 ver:=fs.Bool("version",false,"show version")
 ingestOnly:=fs.Bool("ingest-only",false,"explicitly enable pre-release ingest-only mode")
 if err:=fs.Parse(os.Args[1:]);err!=nil{return err}
 if *ver{fmt.Println(version);return nil}
 if !*ingestOnly{return errors.New("Go transformer and shipping are not validated: --ingest-only is required; do not use for production")}
 if *hash==""||len(*hash)!=7{return errors.New("DOZOR_AGENT_TOKEN_HASH must contain the 7-character PHP xxh128 prefix")}
 host,_,err:=net.SplitHostPort(*listen);if err!=nil{return err}
 if host!="127.0.0.1"&&host!="::1"&&host!="localhost"{return errors.New("v1 short token-hash protocol must bind loopback only")}
 q,err:=spool.Open(*path,*max);if err!=nil{return err};defer q.Close()
 ln,err:=net.Listen("tcp",*listen);if err!=nil{return err}
 ctx,stop:=signal.NotifyContext(context.Background(),syscall.SIGTERM,os.Interrupt);defer stop()
 writer:=&state.Writer{Path:*statePath}
 srv:=&ingest.Server{Listener:ln,Queue:q,TokenHash:*hash,MaxClients:32,Debug:*debug}
 stateCtx,stateCancel:=context.WithCancel(context.Background())
 var stateWG sync.WaitGroup
 persist:=func(status string){
  queued,failed,_:=q.Count()
  if err:=writer.Mark(status,*listen,queued,failed,srv.Received.Load(),srv.Rejected.Load());err!=nil{log.Printf("state persistence failed: %T",err)}
 }
 persist("running")
 stateWG.Add(1)
 go func(){
  defer stateWG.Done()
  ticker:=time.NewTicker(time.Second);defer ticker.Stop()
  for{select{case <-stateCtx.Done():return;case <-ticker.C:persist("running")}}
 }()
 defer func(){stateCancel();stateWG.Wait();persist("stopped")}()
 if *debug{
  log.Printf("ingest-only listener=%s spool=%s",ln.Addr(),*path)
  done:=make(chan struct{})
  go func(){
   defer close(done)
   ticker:=time.NewTicker(2*time.Second);defer ticker.Stop()
   for{select{
   case <-ctx.Done():return
   case <-ticker.C:
    pending,failed,_:=q.Count()
    log.Printf("received=%d rejected=%d active=%d pending=%d failed=%d",srv.Received.Load(),srv.Rejected.Load(),srv.Active.Load(),pending,failed)
   }}
  }()
  defer func(){<-done}()
 }
 return srv.Serve(ctx)
}
func envInt(key string,def int)int{if v,e:=strconv.Atoi(os.Getenv(key));e==nil&&v>0{return v};return def}
func status(path string)error{
 pending,failed,err:=spool.CountAt(path);if err!=nil{return err}
 return json.NewEncoder(os.Stdout).Encode(map[string]any{"status":"ingest-only","shipping_enabled":false,"queue_depth":pending,"failed_queue_depth":failed})
}
