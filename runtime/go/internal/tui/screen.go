package tui
import(
 "context"
 "fmt"
 "io"
 "os"
 "strconv"
 "strings"
 "time"
 "github.com/pepperfm/dozor-agent/runtime/go/internal/state"
)
type Event struct{Time time.Time;Kind string}
const enter="\x1b[?1049h\x1b[?25l"
const exit="\x1b[?25h\x1b[?1049l\x1b[0m"
func IsTerminal(f *os.File)bool{info,err:=f.Stat();return err==nil&&info.Mode()&os.ModeCharDevice!=0}
func Run(ctx context.Context,path string,events <-chan Event,out io.Writer){
 fmt.Fprint(out,enter);defer fmt.Fprint(out,exit)
 ticker:=time.NewTicker(250*time.Millisecond);defer ticker.Stop()
 recent:=make([]Event,0,12)
 draw:=func(){
  snapshot,err:=state.Read(path);if err!=nil{snapshot=state.Snapshot{}}
  fmt.Fprint(out,"\x1b[H\x1b[2J");fmt.Fprint(out,render(snapshot,recent))
 }
 draw()
 for{select{
 case <-ctx.Done():return
 case e:=<-events:
  if e.Time.IsZero(){e.Time=time.Now()}
  recent=append(recent,e)
  if len(recent)>12{recent=recent[len(recent)-12:]}
 case <-ticker.C:draw()
 }}
}
func render(s state.Snapshot,events []Event)string{
 width:=80
 if n,e:=strconv.Atoi(os.Getenv("COLUMNS"));e==nil&&n>=60&&n<=240{width=n}
 border:=strings.Repeat("─",width-2)
 var b strings.Builder
 b.WriteString("\x1b[1;36m╭"+border+"╮\x1b[0m\n")
 b.WriteString(line(width,"  ◉  DOZOR AGENT    /    live telemetry monitor"))
 b.WriteString("\x1b[36m├"+border+"┤\x1b[0m\n")
 b.WriteString(line(width,fmt.Sprintf("  ●  %-18s   UPTIME %-8ds   RUNTIME Go",s.Lifecycle.Status,s.Metrics.UptimeSeconds)))
 b.WriteString(line(width,"  LISTENER   "+s.Context.ListenOn))
 b.WriteString("\x1b[36m├"+border+"┤\x1b[0m\n")
 b.WriteString(line(width,fmt.Sprintf("  RECEIVED  %-10d REJECTED  %-10d QUEUED  %-6d",s.Metrics.ReceivedRecords,s.Metrics.RejectedClients,s.Metrics.QueueDepth)))
 b.WriteString(line(width,fmt.Sprintf("  DEAD LETTER %-5d  DELIVERED %-7d  UPLOAD ERRORS %-5d",s.Metrics.FailedQueueDepth,s.Metrics.DeliveredBatches,s.Metrics.FailedUploads)))
 if s.Context.ShipperEnabled{b.WriteString(line(width,"  SHIPPING   active (HTTP v1)"))}else{b.WriteString(line(width,"  SHIPPING   disabled (ingest-only)"))}
 b.WriteString("\x1b[36m├"+border+"┤\x1b[0m\n")
 b.WriteString(line(width,"  RECENT EVENTS"))
 for _,event:=range events{b.WriteString(line(width,fmt.Sprintf("  %s   %-14s",event.Time.Format("15:04:05"),event.Kind)))}
 if len(events)==0{b.WriteString(line(width,"  No events yet. Waiting for PHP SDK..."))}
 b.WriteString("\x1b[36m├"+border+"┤\x1b[0m\n")
 b.WriteString(line(width,"  [Ctrl+C] Stop & restore terminal   •   ingest-only experimental"))
 b.WriteString("\x1b[36m╰"+border+"╯\x1b[0m\n")
 return b.String()
}
func line(width int,text string)string{
 runes:=[]rune(text)
 if len(runes)>width-4{runes=runes[:width-4]}
 return "\x1b[36m│\x1b[0m"+string(runes)+strings.Repeat(" ",width-2-len(runes))+"\x1b[36m│\x1b[0m\n"
}
