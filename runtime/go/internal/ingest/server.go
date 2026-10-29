package ingest
import(
 "context"
 "encoding/json"
 "errors"
 "io"
 "log"
 "net"
 "sync"
 "sync/atomic"
 "time"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/protocol"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/spool"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/tui"
)
type Server struct{
 Listener net.Listener
 Queue *spool.Queue
 TokenHash string
 MaxClients int
 BatchSize int
 Debug bool
 Events chan<- tui.Event
 Received atomic.Uint64
 Rejected atomic.Uint64
 Active atomic.Int64
}
func(s *Server)Serve(ctx context.Context)error{
 if s.MaxClients<1{s.MaxClients=32}
 limit:=make(chan struct{},s.MaxClients)
 var wg sync.WaitGroup
 go func(){<-ctx.Done();_ = s.Listener.Close()}()
 for{
  client,err:=s.Listener.Accept()
  if err!=nil{
   if ctx.Err()!=nil{break}
   var ne net.Error
   if errors.As(err,&ne)&&ne.Temporary(){time.Sleep(50*time.Millisecond);continue}
   return err
  }
  select{
  case limit<-struct{}{}:
   wg.Add(1);s.Active.Add(1)
   go func(c net.Conn){defer wg.Done();defer func(){<-limit;s.Active.Add(-1)}();s.handle(c)}(client)
  default:
   s.Rejected.Add(1);s.publish("overloaded")
   _ = client.SetWriteDeadline(time.Now().Add(100*time.Millisecond))
   _,_=io.WriteString(client,"5:ER")
   _ = client.Close()
  }
 }
 wg.Wait();return nil
}
func(s *Server)handle(c net.Conn){
 defer c.Close()
 _ = c.SetDeadline(time.Now().Add(2*time.Second))
 records,ping,err:=protocol.Decode(c,s.TokenHash)
 if err==nil&&!ping{
  // ACK follows durable persistence; raw records are not outbound traces.
  batchSize:=s.BatchSize;if batchSize<1{batchSize=100}
  chunks:=make([]json.RawMessage,0,(len(records)+batchSize-1)/batchSize)
  for i:=0;i<len(records);i+=batchSize{
   end:=i+batchSize;if end>len(records){end=len(records)}
   body,e:=json.Marshal(records[i:end]);if e!=nil{err=e;break}
   chunks=append(chunks,json.RawMessage(body))
  }
  if err==nil&&len(chunks)>0{_,err=s.Queue.EnqueueMany(chunks)}
 }
 if err!=nil{
  s.Rejected.Add(1);s.publish("rejected")
  if s.Debug{log.Printf("rejected ingest: %T",err)}
  _,_=io.WriteString(c,"5:ER");return
 }
 if !ping{s.Received.Add(uint64(len(records)));s.publish("accepted")}
 _,_=io.WriteString(c,"2:OK")
}
func(s *Server)publish(kind string){
 if s.Events!=nil{select{case s.Events<-tui.Event{Time:time.Now(),Kind:kind}:default:}}
}
