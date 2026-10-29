package shipping
import(
 "context"
 "encoding/json"
 "errors"
 "io"
 "net/http"
 "net/http/httptest"
 "sync/atomic"
 "testing"
 "time"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/spool"
)
type fixedTransformer struct{calls atomic.Int32}
func(t *fixedTransformer)Transform(_ context.Context,_ json.RawMessage)(json.RawMessage,error){
 t.calls.Add(1);return json.RawMessage(`{"v":1,"sent_at":"immutable","traces":[]}`),nil
}
func TestWorkerRetryAndNoRetransform(t *testing.T){
 q,err:=spool.Open(t.TempDir(),20);if err!=nil{t.Fatal(err)};defer q.Close()
 if _,err=q.Enqueue(json.RawMessage(`[{"type":"request"}]`));err!=nil{t.Fatal(err)}
 var calls atomic.Int32;var bodies []string
 ctx,cancel:=context.WithCancel(context.Background())
 server:=httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){
  b,_:=io.ReadAll(r.Body);bodies=append(bodies,string(b))
  if calls.Add(1)==1{w.WriteHeader(503)}else{w.WriteHeader(202);cancel()}
 }));defer server.Close()
 transformer:=&fixedTransformer{}
 worker:=Worker{Queue:q,Transport:&Client{URL:server.URL,Token:"secret",Attempts:1},Transformer:transformer,RetryBase:time.Millisecond,RetryCap:time.Millisecond,PollInterval:time.Millisecond}
 if err=worker.Run(ctx);err!=nil{t.Fatal(err)}
 pending,failed,err:=q.Count();if err!=nil{t.Fatal(err)}
 if failed!=0||pending>1||transformer.calls.Load()!=1||calls.Load()!=2||len(bodies)!=2||bodies[0]!=bodies[1]{
  t.Fatalf("pending=%d failed=%d transformer=%d calls=%d bodies=%v",pending,failed,transformer.calls.Load(),calls.Load(),bodies)
 }
}
func TestRequireTransformer(t *testing.T){w:=&Worker{};if err:=w.Run(context.Background());err==nil{t.Fatal("unsafe shipping started")}}
func TestPermanentStatusToDeadLetter(t *testing.T){
 q,err:=spool.Open(t.TempDir(),5);if err!=nil{t.Fatal(err)};defer q.Close()
 q.Enqueue(json.RawMessage(`[{"type":"request"}]`))
 server:=httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter,_ *http.Request){w.WriteHeader(422)}));defer server.Close()
 ctx,cancel:=context.WithTimeout(context.Background(),100*time.Millisecond);defer cancel()
 w:=&Worker{Queue:q,Transport:&Client{URL:server.URL,Token:"secret",Attempts:1},Transformer:&fixedTransformer{},PollInterval:5*time.Millisecond}
 err=w.Run(ctx);if err!=nil&&!errors.Is(err,context.Canceled){t.Fatal(err)}
 _,failed,_:=q.Count();if failed!=1{t.Fatalf("expected deadletter, got %d",failed)}
}
