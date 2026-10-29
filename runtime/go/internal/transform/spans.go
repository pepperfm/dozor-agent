package transform

import (
 "fmt"
 "strings"
 "time"
)

func rootSpan(record obj,rootType,startedAt,app,environment string)obj{
 payload:=asObj(get(record,"payload"))
 rootID:=stable(app,environment,s(record,"trace_id"),"root")
 kind:=map[string]string{
  "request":"http","job":"job","command":"command","heartbeat":"heartbeat",
  "outgoing_http":"outgoing_http","event":"event","log":"log",
 }[rootType]
 if kind==""{kind="internal"}
 name:=rootType
 if rootType=="request"{name=firstString(payload,"route_uri","path")}
 if name==""{name=firstString(payload,"name")}
 if name==""{name=rootType}
 duration:=max(0,rounded(number(payload,0,"duration_ms","time_ms")))
 metadata:=obj{"source_type":rootType,"started_at":startedAt}
 if rootType=="job"{
  if get(payload,"status")!=nil{metadata["status"]=get(payload,"status")}
  if get(payload,"attempts")!=nil{metadata["attempts"]=get(payload,"attempts")}
 }
 if rootType=="command"&&get(payload,"details.exit_code")!=nil{metadata["exit_code"]=get(payload,"details.exit_code")}
 span:=emptyFields(kind,name,rootID,nil,0,duration,metadata)
 span["queue"]=nonemptyString(get(payload,"queue"))
 if rootType=="request"{
  method:=firstString(payload,"method");if method==""{method="GET"}
  span["method"]=method
  span["status_code"]=statusCode(get(payload,"status"))
 }
 return span
}

type phaseRange struct{start,end,duration int}
func lifecycleSpans(rootPayload obj,duration int,rootSpanID any,app,environment string)([]obj,map[string]string,map[string]phaseRange){
 spans:=[]obj{};ids:=map[string]string{};ranges:=map[string]phaseRange{}
 // PHP builds no synthetic lifecycle phases without explicit measurements.
 supplied:=list(get(rootPayload,"lifecycle_stages"))
 maxDuration:=max(1,duration)
 for i,raw:=range supplied{
  stage:=asObj(raw);name,ok:=get(stage,"name").(string);if !ok{continue}
  metadata:=asObj(get(stage,"metadata"))
  switch name{
  case "middleware":
   if s(metadata,"segment")=="after_controller"{name="after_middleware"}else{name="before_middleware"}
  case "controller":name="action"
  case "bootstrap","before_middleware","action","after_middleware","render","sending","terminating":
  default:continue
  }
  offset:=max(0,integer(stage,"start_offset_ms"))
  if offset>=maxDuration{offset=max(0,maxDuration-1)}
  spanDuration:=max(1,integer(stage,"duration_ms"))
  if offset+spanDuration>maxDuration{spanDuration=max(1,maxDuration-offset)}
  rootText:=stringVal(rootSpanID)
  id:=stable(app,environment,rootText,fmt.Sprintf("phase:%d",i))
  label:=displayName(name);if name=="action"{label="Controller"}
  if name=="before_middleware"||name=="after_middleware"{label="Middleware"}
  var legacy any
  if name=="action"{legacy="controller"}
  if name=="before_middleware"||name=="after_middleware"{legacy="middleware"}
  phase:=emptyFields("lifecycle",label,id,rootSpanID,offset,spanDuration,obj{
   "phase":name,"legacy_phase":legacy,"phase_meta":metadata,
  })
  spans=append(spans,phase)
  old,exists:=ranges[name]
  if !exists||spanDuration>old.duration{
   ids[name]=id
   ranges[name]=phaseRange{start:offset,end:offset+spanDuration-1,duration:spanDuration}
  }
 }
 return spans,ids,ranges
}

func recordDuration(kind string,payload obj)int{
 switch kind{
 case "query":return max(1,rounded(number(payload,1,"time_ms")))
 case "outgoing_http","job","job_child","cache":return max(0,rounded(number(payload,0,"duration_ms")))
 default:return max(1,rounded(number(payload,1,"duration_ms","time_ms")))
 }
}
func parentForRecord(kind string,offset,duration int,ids map[string]string,ranges map[string]phaseRange,rootID any)(any,any){
 duration=max(1,duration)
 var phase string
 for _,name:=range []string{"bootstrap","before_middleware","action","render","after_middleware","sending","terminating"}{
  r,ok:=ranges[name]
  if ok&&offset>=r.start&&offset+duration-1<=r.end{phase=name;break}
 }
 if phase==""{
  switch kind{
  case "query","outgoing_http","cache","job","job_child","exception","event","log":
   if ids["action"]!=""{phase="action"}
  }
 }
 if phase==""&&ids["action"]!=""{phase="action"}
 if phase!=""{return ids[phase],phase}
 return rootID,nil
}
func offsetMs(record obj,startedAt string)int{
 p:=asObj(get(record,"payload"))
 if f,ok:=numeric(get(p,"start_offset_us"));ok{return max(0,rounded(f/1000))}
 if f,ok:=numeric(get(p,"start_offset_ms"));ok{return max(0,rounded(f))}
 if f,ok:=numeric(get(p,"end_offset_us"));ok{
  duration,ok:=numeric(get(p,"duration_us"));if ok&&duration>=0{return max(0,rounded(float64(max(0,rounded(f)-rounded(duration)))/1000))}
 }
 if f,ok:=numeric(get(p,"end_offset_ms"));ok{
  duration:=max(1,rounded(number(p,1,"duration_ms","time_ms")))
  return max(0,rounded(f)-duration)
 }
 start,ok:=parseTime(startedAt);if !ok{return 0}
 if t,ok:=parseTime(s(record,"happened_at"));ok{
  return max(0,rounded(float64(t.UnixMilli()-start.UnixMilli())))
 }
 if f,ok:=numeric(get(record,"timestamp"));ok{
  return max(0,rounded(f*1000)-start.UnixMilliInt())
 }
 return 0
}

// The PHP code stores event records with their original (already redacted) payload.
func childSpan(record obj,offset int,parent any)obj{
 kind:=s(record,"type");if kind==""{kind="custom"}
 p,valid:=get(record,"payload").(map[string]any);if !valid{return nil}
 id:=spanID(record)
 switch kind{
 case "query":
  name:=firstString(p,"sql");if name==""{name="query"}
  span:=emptyFields("query",shortRune(name,80),id,parent,offset,max(0,rounded(number(p,0,"time_ms"))),obj{
   "bindings_count":integer(p,"bindings_count"),"source":get(p,"source"),
  })
  span["connection"]=nonemptyString(get(p,"connection"))
  span["normalized_signature"]=signature(p)
  span["sql_text"]=nonemptyString(get(p,"sql"))
  if f,ok:=numeric(get(p,"rows_count"));ok{span["rows_count"]=int(f)}
  return span
 case "outgoing_http":
  name:=firstString(p,"url");if name==""{name="outgoing_http"}
  req,reqTruncated:=headers(get(p,"request_headers"))
  resp,respTruncated:=headers(get(p,"response_headers"))
  span:=emptyFields("outgoing_http",name,id,parent,offset,max(0,rounded(number(p,0,"duration_ms"))),obj{
   "url":get(p,"url"),"failed":boolValue(get(p,"failed")),
   "error_class":get(p,"error_class"),"error_message":get(p,"error_message"),
   "request_headers":req,"request_headers_truncated":reqTruncated,
   "response_headers":resp,"response_headers_truncated":respTruncated,
  })
  span["service"]=nonemptyString(get(p,"host"))
  span["method"]=nonemptyString(get(p,"method"))
  if f,ok:=numeric(get(p,"status_code"));ok{span["status_code"]=int(f)}
  return span
 case "job","job_child":
  name:=firstString(p,"name");if name==""{name="job"}
  span:=emptyFields("job",name,id,parent,offset,max(1,rounded(number(p,1,"duration_ms"))),obj{
   "status":get(p,"status"),"attempts":get(p,"attempts"),
  })
  span["connection"]=nonemptyString(get(p,"connection"))
  span["queue"]=nonemptyString(get(p,"queue"))
  return span
 case "cache":
  span:=emptyFields("cache","Cache "+displayName(s(p,"operation")),id,parent,offset,0,obj{
   "operation":get(p,"operation"),"key_hash":get(p,"key_hash"),"store":get(p,"store"),
  })
  if s(p,"operation")==""{span["name"]="Cache Unknown"}
  span["service"]=nonemptyString(get(p,"store"))
  return span
 case "exception":
  name:=firstString(p,"class");if name==""{name="exception"}
  stack:=list(get(p,"stacktrace"));if len(stack)>15{stack=stack[:15]}
  return emptyFields("exception",name,id,parent,offset,1,obj{
   "message":get(p,"message"),"file":get(p,"file"),"line":get(p,"line"),"stacktrace":stack,
  })
 case "event":
  name:=firstString(p,"name","event_name");if name==""{name="event"}
  k:="event"
  if name=="view.rendered"{k="view";v:=s(p,"details.view");if v==""{v="template"};name="View "+v}
  return emptyFields(k,name,id,parent,offset,max(0,rounded(number(p,0,"duration_ms"))),p)
 case "heartbeat":
  return emptyFields("heartbeat","agent.heartbeat",id,parent,offset,1,p)
 default:
  return emptyFields("log",kind,id,parent,offset,max(1,rounded(number(p,1,"duration_ms"))),p)
 }
}
func extractException(record obj)any{
 p:=asObj(get(record,"payload"))
 if s(record,"type")=="exception"{
  class:=s(p,"class");if class==""{class="RuntimeException"}
  msg:=s(p,"message");if msg==""{msg="Exception"}
  var line any;if f,ok:=numeric(get(p,"line"));ok{line=int(f)}
  return obj{
   "fingerprint":nil,"title":class,"class":class,"message":msg,
   "file":nonemptyString(get(p,"file")),"line":line,"owner":"platform","regression":false,
  }
 }
 class:=firstString(p,"exception_class");msg:=firstString(p,"exception_message")
 if class!=""&&msg!=""{
  return obj{"fingerprint":nil,"title":class,"class":class,"message":msg,
   "file":nil,"line":nil,"owner":"platform","regression":false}
 }
 return nil
}
func startedAt(root obj,records []obj,now time.Time)string{
 p:=asObj(get(root,"payload"))
 if started:=firstString(p,"request_started_at","trace_started_at");started!=""{return started}
 if text,ok:=timeFromTimestamp(get(root,"timestamp"));ok{return text}
 if text:=s(root,"happened_at");text!=""{return text}
 for _,rec:=range records{
  if text,ok:=timeFromTimestamp(get(rec,"timestamp"));ok{return text}
  if text:=s(rec,"happened_at");text!=""{return text}
 }
 return iso(now)
}
func durationMs(root obj,records []obj,db,external,job int)int{
 payload:=asObj(get(root,"payload"))
 if measured:=number(payload,-1,"duration_ms","time_ms");measured>=0{return max(0,rounded(measured))}
 var first,last time.Time
 for _,rec:=range records{
  t,ok:=parseTime(s(rec,"happened_at"));if !ok{continue}
  if first.IsZero()||t.Before(first){first=t}
  if last.IsZero()||t.After(last){last=t}
 }
 if !first.IsZero()&&!last.IsZero(){return max(0,rounded(float64(last.Sub(first))/float64(time.Millisecond)))}
 return max(0,db+external+job)
}
func recordType(rec obj)string{t:=s(rec,"type");if t==""{return "custom"};return t}
func sourceTrace(rec obj,index int)string{
 id:=firstString(rec,"trace_id");if id==""{return fmt.Sprintf("missing-trace-%d",index)}
 return id
}
func groupRecords(records []obj)([]string,map[string][]obj){
 keys:=[]string{};groups:=map[string][]obj{}
 for i,record:=range records{
  key:=sourceTrace(record,i)
  if _,ok:=groups[key];!ok{keys=append(keys,key)}
  groups[key]=append(groups[key],record)
 }
 return keys,groups
}
func rootRecord(records []obj)obj{
 for _,kind:=range []string{"request","command","job"}{
  for _,record:=range records{if s(record,"type")==kind{return record}}
 }
 return records[0]
}
func isFragment(rootType string,payload obj)bool{
 switch rootType{case "request","job","command","heartbeat":return false}
 _,ok:=get(payload,"trace_started_at").(string);return ok
}
func rootMethod(kind string,payload obj)string{
 if kind=="request"{m:=s(payload,"method");if m!=""{return m};return "GET"}
 if kind=="heartbeat"{return "HEARTBEAT"}
 if kind=="outgoing_http"{m:=s(payload,"method");if m!=""{return m};return "HTTP"}
 if kind=="job"{return "QUEUE"}
 return "CLI"
}
func rootRoute(kind string,payload obj)string{
 if route:=firstString(payload,"route_uri","path","name");route!=""{return route}
 if kind=="heartbeat"{return "agent.heartbeat"}
 return "/"
}
func rootURI(kind string,payload obj)string{
 if uri:=firstString(payload,"url","path");uri!=""{return uri}
 if name:=firstString(payload,"name");name!=""{return "queue://"+name}
 if kind=="heartbeat"{return "agent://heartbeat"}
 return "internal://agent"
}
func parentString(parent any)string{if parent==nil{return ""};return fmt.Sprint(parent)}
func applyPhase(span obj,phase any){
 if phase==nil{return}
 metadata:=asObj(get(span,"metadata"));metadata["lifecycle_phase"]=phase
 span["metadata"]=metadata
}
func rootStatus(kind string,payload obj)int{
 if get(payload,"status")!=nil{return statusCode(get(payload,"status"))}
 if kind=="outgoing_http"{return statusCode(get(payload,"status_code"))}
 return 200
}
func spanOffsetFallback(value string)time.Time{t,_:=parseTime(value);return t}
func stringifyKind(kind string)string{return strings.ToLower(kind)}
