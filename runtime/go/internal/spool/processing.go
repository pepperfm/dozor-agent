package spool
import(
 "encoding/json"
 "errors"
 "fmt"
 "os"
 "path/filepath"
 "sort"
 "time"
)
type Claimed struct{Path string;Envelope Envelope}
func(q *Queue)processingDir()string{return filepath.Join(q.root,"processing")}
func(q *Queue)validateProcessing(path string)error{
 if filepath.Clean(filepath.Dir(path))!=filepath.Clean(q.processingDir()){return errors.New("invalid processing path")}
 return nil
}
// Recover returns unacknowledged in-flight files to queue after a crash.
func(q *Queue)Recover()error{
 q.mu.Lock();defer q.mu.Unlock()
 if err:=os.MkdirAll(q.processingDir(),0700);err!=nil{return err}
 files,err:=filepath.Glob(filepath.Join(q.processingDir(),"*.json"));if err!=nil{return err}
 for _,path:=range files{
  dest:=filepath.Join(q.root,"queue",filepath.Base(path))
  if _,err:=os.Stat(dest);err==nil{return fmt.Errorf("duplicate queue file during recovery %s",filepath.Base(path))}
  if err:=os.Rename(path,dest);err!=nil{return err}
 }
 if err:=syncDir(q.processingDir());err!=nil{return err}
 return syncDir(filepath.Join(q.root,"queue"))
}
// Claim atomically moves a pending batch to processing, excluding other workers.
func(q *Queue)Claim(now time.Time)(*Claimed,error){
 q.mu.Lock();defer q.mu.Unlock()
 if err:=os.MkdirAll(q.processingDir(),0700);err!=nil{return nil,err}
 files,err:=filepath.Glob(filepath.Join(q.root,"queue","*.json"));if err!=nil{return nil,err}
 sort.Strings(files)
 for _,path:=range files{
  body,err:=os.ReadFile(path)
  var env Envelope
  if err==nil{err=json.Unmarshal(body,&env)}
  if err==nil&&env.NextAttemptAt>float64(now.UnixNano())/1e9{continue}
  dest:=filepath.Join(q.processingDir(),filepath.Base(path))
  if err:=os.Rename(path,dest);err!=nil{return nil,err}
  if err:=syncDir(filepath.Join(q.root,"queue"));err!=nil{return nil,err}
  if err:=syncDir(q.processingDir());err!=nil{return nil,err}
  return &Claimed{Path:dest,Envelope:env},nil
 }
 return nil,nil
}
func(q *Queue)Save(item *Claimed)error{
 if err:=q.validateProcessing(item.Path);err!=nil{return err}
 q.mu.Lock();defer q.mu.Unlock()
 body,err:=json.Marshal(item.Envelope);if err!=nil{return err}
 return writeAtomic(item.Path,append(body,'\n'))
}
func(q *Queue)Complete(item *Claimed)error{
 if err:=q.validateProcessing(item.Path);err!=nil{return err}
 q.mu.Lock();defer q.mu.Unlock()
 if err:=os.Remove(item.Path);err!=nil{return err}
 return syncDir(q.processingDir())
}
func(q *Queue)Release(item *Claimed)error{
 if err:=q.validateProcessing(item.Path);err!=nil{return err}
 q.mu.Lock();defer q.mu.Unlock()
 dest:=filepath.Join(q.root,"queue",filepath.Base(item.Path))
 if err:=os.Rename(item.Path,dest);err!=nil{return err}
 if err:=syncDir(q.processingDir());err!=nil{return err}
 return syncDir(filepath.Join(q.root,"queue"))
}
func(q *Queue)Abandon(item *Claimed,reason string)error{
 if err:=q.validateProcessing(item.Path);err!=nil{return err}
 q.mu.Lock();defer q.mu.Unlock()
 dest:=filepath.Join(q.root,"failed",fmt.Sprintf("%d-%s-%s",time.Now().UnixNano(),reason,filepath.Base(item.Path)))
 if err:=os.Rename(item.Path,dest);err!=nil{return err}
 if err:=syncDir(q.processingDir());err!=nil{return err}
 return syncDir(filepath.Join(q.root,"failed"))
}
