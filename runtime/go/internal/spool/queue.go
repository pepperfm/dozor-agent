package spool

import (
 "crypto/rand"
 "encoding/hex"
 "encoding/json"
 "errors"
 "fmt"
 "os"
 "path/filepath"
 "sort"
 "strings"
 "sync"
 "syscall"
 "time"
)

var ErrCapacity = errors.New("spool capacity reached")
var ErrLocked = errors.New("spool already owned by another agent")
type Envelope struct {
 ID string `json:"id"`
 Attempt int `json:"attempt"`
 NextAttemptAt float64 `json:"next_attempt_at"`
 CreatedAt float64 `json:"created_at"`
 Payload json.RawMessage `json:"payload"`
 LastFailedAt float64 `json:"last_failed_at,omitempty"`
}
type Queue struct { root string; max int; lock *os.File; mu sync.Mutex }
func Open(root string, max int) (*Queue,error) {
 if max<1 {return nil,errors.New("max spool batches must be positive")}
 for _,sub:=range []string{"queue","failed"} {
  if err:=os.MkdirAll(filepath.Join(root,sub),0700);err!=nil{return nil,err}
 }
 f,err:=os.OpenFile(filepath.Join(root,".go-agent.lock"),os.O_CREATE|os.O_RDWR,0600)
 if err!=nil{return nil,err}
 if err=syscall.Flock(int(f.Fd()),syscall.LOCK_EX|syscall.LOCK_NB);err!=nil {
  f.Close();return nil,fmt.Errorf("%w: %v",ErrLocked,err)
 }
 return &Queue{root:root,max:max,lock:f},nil
}
func(q *Queue) Close()error{
 q.mu.Lock();defer q.mu.Unlock()
 if q.lock==nil{return nil}
 err:=syscall.Flock(int(q.lock.Fd()),syscall.LOCK_UN)
 e:=q.lock.Close();q.lock=nil;if err!=nil{return err};return e
}
func(q *Queue) Count()(int,int,error){q.mu.Lock();defer q.mu.Unlock();return q.count()}
func(q *Queue) count()(int,int,error){
 a,e:=filepath.Glob(filepath.Join(q.root,"queue","*.json"));if e!=nil{return 0,0,e}
 b,e:=filepath.Glob(filepath.Join(q.root,"failed","*.json"));return len(a),len(b),e
}
func id()string{
 var b [8]byte
 if _,err:=rand.Read(b[:]);err!=nil{panic(err)}
 return hex.EncodeToString(b[:])
}
func syncDir(dir string)error{f,err:=os.Open(dir);if err!=nil{return err};defer f.Close();return f.Sync()}
func writeAtomic(target string,contents []byte)error{
 dir:=filepath.Dir(target)
 f,err:=os.CreateTemp(dir,".partial-*");if err!=nil{return err}
 tmp:=f.Name();defer os.Remove(tmp)
 if err=f.Chmod(0600);err!=nil{f.Close();return err}
 n,err:=f.Write(contents)
 if err==nil&&n!=len(contents){err=errors.New("short spool write")}
 if err==nil{err=f.Sync()}
 if e:=f.Close();err==nil{err=e}
 if err!=nil{return err}
 if err=os.Rename(tmp,target);err!=nil{return err}
 return syncDir(dir)
}
// Enqueue persists an immutable outbound/record payload before allowing TCP ACK.
func(q *Queue) Enqueue(payload json.RawMessage)(string,error){
 q.mu.Lock();defer q.mu.Unlock()
 pending,failed,err:=q.count();if err!=nil{return "",err}
 if pending+failed>=q.max{return "",ErrCapacity}
 e:=Envelope{ID:id(),CreatedAt:float64(time.Now().UnixNano())/1e9,Payload:payload}
 body,err:=json.Marshal(e);if err!=nil{return "",err}
 path:=filepath.Join(q.root,"queue",fmt.Sprintf("%d-%s.json",time.Now().Unix(),e.ID))
 return e.ID,writeAtomic(path,append(body,'\n'))
}
// Pending lists raw envelopes in lexical order. No upload worker exists in this stage.
func(q *Queue) Pending()([]string,error){
 q.mu.Lock();defer q.mu.Unlock()
 files,err:=filepath.Glob(filepath.Join(q.root,"queue","*.json"));sort.Strings(files);return files,err
}
func(q *Queue) Read(path string)(Envelope,error){
 if filepath.Dir(path)!=filepath.Join(q.root,"queue"){return Envelope{},errors.New("invalid queue path")}
 data,err:=os.ReadFile(path);if err!=nil{return Envelope{},err}
 var e Envelope
 if err=json.Unmarshal(data,&e);err!=nil{return e,err}
 if e.ID==""||len(e.Payload)==0||!json.Valid(e.Payload){return e,errors.New("corrupt envelope")}
 return e,nil
}
func(q *Queue) Fail(path,reason string)error{
 q.mu.Lock();defer q.mu.Unlock()
 if filepath.Dir(path)!=filepath.Join(q.root,"queue"){return errors.New("invalid queue path")}
 reason=strings.Map(func(r rune)rune{if r>='a'&&r<='z'||r=='-'{return r};return '-'},reason)
 target:=filepath.Join(q.root,"failed",fmt.Sprintf("%d-%s-%s",time.Now().UnixNano(),reason,filepath.Base(path)))
 if err:=os.Rename(path,target);err!=nil{return err}
 if err:=syncDir(filepath.Dir(target));err!=nil{return err}
 return syncDir(filepath.Dir(path))
}
// CountAt inspects spool without claiming its lock so status works while daemon is active.
func CountAt(root string)(int,int,error){
 a,err:=filepath.Glob(filepath.Join(root,"queue","*.json"));if err!=nil{return 0,0,err}
 b,err:=filepath.Glob(filepath.Join(root,"failed","*.json"));return len(a),len(b),err
}
