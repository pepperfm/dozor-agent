package transform

import (
 "bytes"
 "crypto/rand"
 "crypto/sha256"
 "encoding/hex"
 "encoding/json"
 "fmt"
 "math"
 "net/url"
 "regexp"
 "strconv"
 "strings"
 "time"
 "unicode"
 "unicode/utf8"
)

type obj = map[string]any
func asObj(value any) obj { if x,ok:=value.(map[string]any);ok{return x};return obj{} }
func list(value any) []any { if x,ok:=value.([]any);ok{return x};return []any{} }
func get(m obj, path string) any {
 var v any=m
 for _,part:=range strings.Split(path,".") {
  x,ok:=v.(map[string]any);if !ok{return nil}
  v=x[part]
 }
 return v
}
func stringVal(v any)string {
 switch t:=v.(type){
 case string:return t
 case json.Number:return string(t)
 case float64:return strconv.FormatFloat(t,'f',-1,64)
 case int:return strconv.Itoa(t)
 case int64:return strconv.FormatInt(t,10)
 case bool:if t{return "1"};return ""
 case nil:return ""
 default:return ""
 }
}
func s(m obj,key string)string{return stringVal(get(m,key))}
func firstString(m obj,keys ...string)string{for _,k:=range keys{if v:=get(m,k);v!=nil{if t,ok:=v.(string);ok&&t!=""{return t}}};return ""}
func nullable(v any)any{if s,ok:=v.(string);ok&&s!=""{return s};return nil}
func numeric(v any)(float64,bool){
 switch t:=v.(type){
 case float64:return t,true
 case float32:return float64(t),true
 case int:return float64(t),true
 case int64:return float64(t),true
 case json.Number:f,e:=t.Float64();return f,e==nil
 case string:
  if strings.TrimSpace(t)==""{return 0,false}
  f,e:=strconv.ParseFloat(strings.TrimSpace(t),64);return f,e==nil
 }
 return 0,false
}
func number(m obj,defaultValue float64,keys ...string)float64{
 for _,k:=range keys{if f,ok:=numeric(get(m,k));ok{return f}}
 return defaultValue
}
func rounded(n float64)int{return int(math.Round(n))}
func integer(m obj,key string)int{return rounded(number(m,0,key))}
func max(a,b int)int{if a>b{return a};return b}
func min(a,b int)int{if a<b{return a};return b}
func iso(t time.Time)string{return t.Format("2006-01-02T15:04:05-07:00")}
func parseTime(s string)(time.Time,bool){
 for _,layout:=range []string{time.RFC3339Nano,"2006-01-02 15:04:05.999999","2006-01-02 15:04:05","2006-01-02"}{
  if t,e:=time.Parse(layout,s);e==nil{return t,true}
 }
 return time.Time{},false
}
func timeFromTimestamp(v any)(string,bool){
 f,ok:=numeric(v);if !ok{return "",false}
 if math.IsNaN(f)||math.IsInf(f,0){return "",false}
 t:=time.Unix(0,int64(f*1e9)).UTC()
 return t.Format("2006-01-02T15:04:05.000000-07:00"),true
}
func ulid(v string)bool{
 if len(v)!=26{return false}
 for i,r:=range v{
  if i==0&&r>'7'{return false}
  if !strings.ContainsRune("0123456789ABCDEFGHJKMNPQRSTVWXYZ",unicode.ToUpper(r)){return false}
 }
 return true
}
func stable(app,environment,source,component string)string{
 basis:=app+"|"+environment+"|"+source
 if component!=""{basis+="|"+component}
 sum:=sha256.Sum256([]byte(basis))
 return "0"+strings.ToUpper(hex.EncodeToString(sum[:])[:25])
}
const crockford="0123456789ABCDEFGHJKMNPQRSTVWXYZ"
func randomULID()string{
 b:=make([]byte,16);_,_=rand.Read(b)
 now:=uint64(time.Now().UnixMilli())
 for i:=5;i>=0;i--{b[i]=byte(now);now>>=8}
 // Encode 128-bit binary value as base32 without padding.
 bits:=0;value:=uint64(0);out:=make([]byte,0,26)
 for _,part:=range b{
  value=(value<<8)|uint64(part);bits+=8
  for bits>=5{bits-=5;out=append(out,crockford[(value>>bits)&31]);value&=(1<<bits)-1}
 }
 if bits>0{out=append(out,crockford[(value<<(5-bits))&31])}
 return string(out)
}
func spanID(record obj)string{
 if id:=s(record,"record_id");ulid(id){return id}
 return randomULID()
}
func emptyFields(kind,name,id string,parent any,offset,duration int,metadata any)obj{
 return obj{
  "id":id,"parent_span_id":parent,"kind":kind,"name":name,
  "start_offset_ms":offset,"duration_ms":duration,
  "connection":nil,"normalized_signature":nil,"sql_text":nil,"rows_count":nil,
  "service":nil,"queue":nil,"method":nil,"status_code":nil,"metadata":metadata,
 }
}
func arrayAnyString(raw any)[]any{
 out:=[]any{}
 for _,v:=range list(raw){if text,ok:=v.(string);ok&&text!=""{out=append(out,text)}}
 return out
}
func displayName(value string)string{
 if value==""{return ""}
 value=strings.ReplaceAll(value,"/","\\")
 parts:=strings.Split(value,"\\");name:=value
 for _,part:=range parts{if part!=""{name=part}}
 cleaned:=strings.NewReplacer("_"," ","-"," ").Replace(name)
 if name==strings.ToLower(name)||name==strings.ToUpper(name){
  words:=strings.Fields(strings.ToLower(cleaned))
  for i,word:=range words{r:=[]rune(word);if len(r)>0{r[0]=unicode.ToUpper(r[0])};words[i]=string(r)}
  return strings.Join(words," ")
 }
 return name
}
func shortRune(s string,n int)string{
 if utf8.RuneCountInString(s)<=n{return s}
 return string([]rune(s)[:n])
}
func jsonInline(v any)string{
 var b bytes.Buffer
 enc:=json.NewEncoder(&b);enc.SetEscapeHTML(false)
 if err:=enc.Encode(v);err!=nil{return ""}
 return strings.TrimSuffix(b.String(),"\n")
}
func preview(v any)(any,bool){
 if v==nil{return nil,false}
 var value string
 switch t:=v.(type){
 case string:value=t
 case bool:if t{value="true"}else{value="false"}
 case json.Number,float64,int,int64:value=stringVal(t)
 case []any,map[string]any:value=jsonInline(t)
 default:return nil,false
 }
 if value==""{return nil,false}
 if utf8.RuneCountInString(value)>2048{return shortRune(value,2048),true}
 return value,false
}
func headerValue(v any)string{
 switch t:=v.(type){
 case []any:
  out:=[]string{}
  for _,item:=range t{switch item.(type){case string,bool,int,int64,float64,json.Number:out=append(out,stringVal(item))}}
  return strings.Join(out,", ")
 case string,bool,int,int64,float64,json.Number:return stringVal(t)
 default:return ""
 }
}
func headers(v any)(obj,bool){
 out:=obj{};truncated:=false
 input:=asObj(v)
 // map order is not significant when under the 32 header limit.
 count:=0
 for k,item:=range input{
  if count>=32{truncated=true;break}
  if k==""{continue}
  value:=headerValue(item);if value==""{continue}
  if utf8.RuneCountInString(k)>64{truncated=true}
  k=shortRune(k,64)
  if utf8.RuneCountInString(value)>512{value=shortRune(value,512);truncated=true}
  out[k]=value;count++
 }
 return out,truncated
}
func hostFromURL(value string)string{
 parsed,err:=url.Parse(value);if err==nil&&parsed.Hostname()!=""{return parsed.Hostname()}
 return ""
}
func statusCode(value any)int{
 if f,ok:=numeric(value);ok{n:=int(f);if n>=100&&n<=599{return n}}
 return 200
}
var sqlSpace=regexp.MustCompile(`\s+`)
func signature(payload obj)any{
 v:=firstString(payload,"normalized_signature","sql")
 if v==""{return nil}
 return shortRune(v,255)
}
func nonemptyString(v any)any {x,ok:=v.(string);if ok&&x!=""{return x};return nil}
func ensureArray(v any)any{if a,ok:=v.([]any);ok{return a};return []any{}}
func ensureObj(v any)any{if o,ok:=v.(map[string]any);ok{return o};return obj{}}
func boolValue(v any)bool{
 switch t:=v.(type){case bool:return t;case nil:return false;case string:return t!=""&&t!="0";case float64:return t!=0}
 return false
}
func toFloat(v any,defaultValue float64)float64{if f,ok:=numeric(v);ok{return f};return defaultValue}
func fmtType(v any)string{return fmt.Sprintf("%T",v)}
