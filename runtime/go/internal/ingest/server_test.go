package ingest
import(
 "context"
 "fmt"
 "io"
 "net"
 "sync"
 "testing"
 "time"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/spool"
)
func TestConcurrentClientsAndOutage(t *testing.T){
 q,err:=spool.Open(t.TempDir(),200);if err!=nil{t.Fatal(err)};defer q.Close()
 listener,err:=net.Listen("tcp","127.0.0.1:0");if err!=nil{t.Fatal(err)}
 ctx,cancel:=context.WithCancel(context.Background())
 srv:=&Server{Listener:listener,Queue:q,TokenHash:"abcdef0",MaxClients:8}
 done:=make(chan error,1);go func(){done<-srv.Serve(ctx)}()
 var wg sync.WaitGroup
 for i:=0;i<50;i++{wg.Add(1);go func(){
  defer wg.Done()
  c,e:=net.DialTimeout("tcp",listener.Addr().String(),time.Second);if e!=nil{return};defer c.Close()
  b:=`v1:abcdef0:[{"type":"request"}]`
  fmt.Fprintf(c,"%d:%s",len(b),b)
  var resp [4]byte;_ = c.SetReadDeadline(time.Now().Add(2*time.Second));_,_=io.ReadFull(c,resp[:])
 }()}
 wg.Wait();cancel()
 select{case err=<-done:if err!=nil{t.Fatal(err)};case <-time.After(3*time.Second):t.Fatal("shutdown blocked")}
 count,_,err:=q.Count();if err!=nil||count==0{t.Fatalf("spool count %d err %v",count,err)}
}
