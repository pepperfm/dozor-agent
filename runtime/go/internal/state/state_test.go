package state
import("path/filepath";"testing")
func TestPersistCompatibleStatusWithoutSecrets(t *testing.T){
 path:=filepath.Join(t.TempDir(),"state.json")
 w:=&Writer{Path:path}
 if err:=w.Mark("running","127.0.0.1:4815",3,1,100,2,0,0);err!=nil{t.Fatal(err)}
 s,err:=Read(path);if err!=nil{t.Fatal(err)}
 if s.Lifecycle.Status!="running"||s.Context.Runtime!="go-ingest-only"||s.Metrics.QueueDepth!=3||s.Metrics.ReceivedRecords!=100{t.Fatalf("snapshot: %+v",s)}
 if err=w.Mark("stopped","127.0.0.1:4815",3,1,100,2);err!=nil{t.Fatal(err)}
 s,err=Read(path);if err!=nil||s.Lifecycle.StoppedAt==""{t.Fatalf("stopped snapshot: %+v %v",s,err)}
}
