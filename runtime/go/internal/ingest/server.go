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
)

type Server struct{
 Listener net.Listener
 Queue *spool.Queue
 TokenHash string
 MaxClients int
 Debug bool
 Received atomic.Uint64
 Rejected atomic.Uint64
 Active atomic.Int64
}
func(s *Server) Serve(ctx context.Context)error{
 if s.MaxClients<1{s.MaxClients=32}
 limit:=make(chan struct{},s.MaxClients)
 var wg sync.WaitGroup
 go func(){<-ctx.Done();_ = s.Listener.Close()}()
 for {
  client,err:=s.Listener.Accept()
  if err!=nil{
   if ctx.Err()!=nil{break}
   var ne net.Error
   if errors.As(err,&ne)&&ne.Temporary(){time.Sleep(50*time.Millisecond);continue}
   return err
  }
  select {
  case limit<-struct{}{}:
   wg.Add(1);s.Active.Add(1)
   go func(c net.Conn){defer wg.Done();defer func(){<-limit;s.Active.Add(-1)}();s.handle(c)}(client)
  default:
   s.Rejected.Add(1)
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
  // Durable acceptance occurs before ACK; raw records are NOT outbound traces.
  var body []byte
  body,err=json.Marshal(records)
  if err==nil{_,err=s.Queue.Enqueue(json.RawMessage(body))}
 }
 if err!=nil{
  s.Rejected.Add(1)
  if s.Debug{log.Printf("rejected ingest: %T",err)}
  _,_=io.WriteString(c,"5:ER")
  return
 }
 if !ping{s.Received.Add(uint64(len(records)))}
 _,_=io.WriteString(c,"2:OK")
}
