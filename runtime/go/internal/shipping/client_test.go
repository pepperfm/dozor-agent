package shipping
import(
 "context"
 "encoding/json"
 "errors"
 "io"
 "net/http"
 "net/http/httptest"
 "reflect"
 "sync/atomic"
 "testing"
 "time"
)
func TestUploadRetriesSamePayloadAndHonorsRetryAfter(t *testing.T){
 var calls atomic.Int64;var payloads []string;var delays []time.Duration
 server:=httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){
  if r.Method!="POST"||r.Header.Get("Authorization")!="Bearer secret"{t.Errorf("unexpected headers/method")}
  b,_:=io.ReadAll(r.Body);payloads=append(payloads,string(b))
  if calls.Add(1)==1{w.Header().Set("Retry-After","1");w.WriteHeader(429);return}
  w.WriteHeader(202)
 }));defer server.Close()
 body:=json.RawMessage(`{"v":1,"sent_at":"unchanged","traces":[]}`)
 client:=Client{URL:server.URL,Token:"secret",Attempts:2,Wait:func(_ context.Context,d time.Duration)error{delays=append(delays,d);return nil}}
 if err:=client.Upload(context.Background(),body);err!=nil{t.Fatal(err)}
 if calls.Load()!=2||!reflect.DeepEqual(payloads,[]string{string(body),string(body)}){t.Fatalf("payload changed: %v",payloads)}
 if len(delays)!=1||delays[0]<time.Second{t.Fatalf("Retry-After ignored: %v",delays)}
}
func TestHTTPErrorDoesNotRetry(t *testing.T){
 var calls atomic.Int64
 server:=httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter,_ *http.Request){calls.Add(1);w.WriteHeader(422)}));defer server.Close()
 client:=Client{URL:server.URL,Token:"secret",Attempts:5}
 err:=client.Upload(context.Background(),json.RawMessage(`{"v":1}`))
 var permanent *HTTPError
 if !errors.As(err,&permanent)||permanent.Status!=422||permanent.Retryable||calls.Load()!=1{t.Fatalf("unexpected %v %d",err,calls.Load())}
}
func TestCancellation(t *testing.T){
 ctx,cancel:=context.WithCancel(context.Background());cancel()
 client:=Client{URL:"http://127.0.0.1:1",Token:"secret",Attempts:3}
 if err:=client.Upload(ctx,json.RawMessage(`{}`));!errors.Is(err,context.Canceled){t.Fatalf("unexpected: %v",err)}
}
func TestRetryAfterParser(t *testing.T){
 now:=time.Now()
 if got:=retryAfter("5",now);got!=5*time.Second{t.Fatal(got)}
 if got:=retryAfter("nonsense",now);got!=0{t.Fatal(got)}
}
