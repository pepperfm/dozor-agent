package integration

import(
 "context"
 "encoding/json"
 "fmt"
 "io"
 "net"
 "net/http"
 "net/http/httptest"
 "sync"
 "testing"
 "time"

 "github.com/pepperfm/dozor-agent/runtime/go/internal/ingest"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/protocol"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/shipping"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/spool"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/transform"
)

func send(t *testing.T,address,token,payload string)string{
 t.Helper()
 c,err:=net.DialTimeout("tcp",address,2*time.Second);if err!=nil{t.Fatal(err)}
 defer c.Close()
 _=c.SetDeadline(time.Now().Add(3*time.Second))
 frame:="v1:"+protocol.TokenHash(token)+":"+payload
 if _,err=fmt.Fprintf(c,"%d:%s",len(frame),frame);err!=nil{t.Fatal(err)}
 var response [4]byte
 if _,err=io.ReadFull(c,response[:]);err!=nil{t.Fatal(err)}
 return string(response[:])
}

func TestPHPWireToHTTPSDelivery(t *testing.T){
 token:="integration-test-token"
 received:=make(chan map[string]any,1)
 remote:=httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){
  if r.Header.Get("Authorization")!="Bearer "+token{w.WriteHeader(401);return}
  var payload map[string]any
  if err:=json.NewDecoder(r.Body).Decode(&payload);err!=nil{w.WriteHeader(422);return}
  if payload["v"]!=float64(1){w.WriteHeader(422);return}
  received<-payload
  w.WriteHeader(202)
 }))
 defer remote.Close()
 q,err:=spool.Open(t.TempDir(),50);if err!=nil{t.Fatal(err)};defer q.Close()
 ln,err:=net.Listen("tcp","127.0.0.1:0");if err!=nil{t.Fatal(err)}
 ctx,cancel:=context.WithCancel(context.Background());defer cancel()
 server:=&ingest.Server{Listener:ln,Queue:q,TokenHash:protocol.TokenHash(token),MaxClients:10}
 srvErr:=make(chan error,1)
 go func(){srvErr<-server.Serve(ctx)}()
 tr:=&transform.Transformer{AppName:"integration-app",AppToken:token,Environment:"testing",ServerName:"testhost"}
 worker:=&shipping.Worker{Queue:q,Transformer:tr,
  Transport:&shipping.Client{URL:remote.URL,Token:token,Attempts:2},
  PollInterval:10*time.Millisecond,
 }
 workerErr:=make(chan error,1)
 go func(){workerErr<-worker.Run(ctx)}()
 const records=`[{"type":"request","trace_id":"01J00000000000000000000900","record_id":"01J00000000000000000000901","happened_at":"2026-10-29T02:00:00+00:00","payload":{"method":"GET","route_uri":"/integration","duration_ms":7}},{"type":"query","trace_id":"01J00000000000000000000900","record_id":"01J00000000000000000000902","payload":{"time_ms":2,"sql":"select 1"}}]`
 if got:=send(t,ln.Addr().String(),token,records);got!="2:OK"{t.Fatalf("ACK: %s",got)}
 select{
 case payload:=<-received:
  traces,ok:=payload["traces"].([]any);if !ok||len(traces)!=1{t.Fatalf("expected one trace: %+v",payload)}
  trace:=traces[0].(map[string]any)
  if trace["route"]!="/integration"||trace["db_time_ms"]!=float64(2){t.Fatalf("broken trace: %+v",trace)}
  spans:=trace["spans"].([]any)
  if len(spans)!=2||spans[1].(map[string]any)["kind"]!="query"{t.Fatalf("broken spans %+v",spans)}
 case <-time.After(5*time.Second):t.Fatal("asynchronous shipping failed")
 }
 deadline:=time.After(5*time.Second)
 for{
  n,_,err:=q.Count();if err!=nil{t.Fatal(err)};if n==0{break}
  select{case <-deadline:t.Fatal("delivered batch not completed");case <-time.After(20*time.Millisecond):}
 }
 cancel()
 if err:=<-srvErr;err!=nil{t.Fatal(err)}
 if err:=<-workerErr;err!=nil{t.Fatal(err)}
}

func TestSpoolCapacityRejectsInsteadOfFalseACK(t *testing.T){
 q,err:=spool.Open(t.TempDir(),1);if err!=nil{t.Fatal(err)};defer q.Close()
 ln,err:=net.Listen("tcp","127.0.0.1:0");if err!=nil{t.Fatal(err)}
 ctx,cancel:=context.WithCancel(context.Background())
 server:=&ingest.Server{Listener:ln,Queue:q,TokenHash:protocol.TokenHash("capacity"),MaxClients:2}
 var wg sync.WaitGroup
 wg.Add(1);go func(){defer wg.Done();_ = server.Serve(ctx)}()
 if got:=send(t,ln.Addr().String(),"capacity",`[{"type":"request"}]`);got!="2:OK"{t.Fatal(got)}
 if got:=send(t,ln.Addr().String(),"capacity",`[{"type":"request"}]`);got!="5:ER"{t.Fatal(got)}
 cancel();wg.Wait()
}
