package transform

import (
	"context"
	"encoding/json"
	"errors"
	"net/url"
	"strings"
	"time"
)

// Transformer reproduces the existing PHP TraceBatchTransformer's v1 contract.
// It never mutates the received records or upstream redaction rules.
type Transformer struct {
	AppName          string
	AppToken         string
	Environment      string
	ServerName       string
	MaxSpansPerTrace int
	Now              func() time.Time
}

func (t *Transformer) now() time.Time {
	if t.Now != nil {
		return t.Now()
	}
	return time.Now().UTC()
}
func (t *Transformer) spanLimit() int {
	if t.MaxSpansPerTrace <= 0 {
		return 200
	}
	// Mirror Laravel's max(10, DOZOR_AGENT_TRACE_MAX_SPANS_PER_TRACE).
	return max(10, t.MaxSpansPerTrace)
}
func (t *Transformer) Transform(ctx context.Context, raw json.RawMessage) (json.RawMessage, error) {
	if t.AppName == "" || t.AppToken == "" || t.Environment == "" || t.ServerName == "" {
		return nil, errors.New("transformer requires app name, token, environment, server")
	}
	var records []obj
	if err := json.Unmarshal(raw, &records); err != nil {
		return nil, err
	}
	if records == nil {
		return nil, errors.New("records must be an array")
	}
	// Preserve PHP associative array insertion order for headers and previews:
	// JSON objects in Go's map iteration have no defined ordering.
	if err := restoreOrderedFields(raw, records); err != nil {
		return nil, err
	}
	for _, record := range records {
		if record == nil {
			return nil, errors.New("record must be an object")
		}
		if _, exists := record["payload"]; exists {
			if _, ok := record["payload"].(map[string]any); !ok {
				return nil, errors.New("record payload must be an object")
			}
		}
	}
	keys, groups := groupRecords(records)
	traces := []any{}
	for _, source := range keys {
		if err := ctx.Err(); err != nil {
			return nil, err
		}
		trace := t.transformTrace(source, groups[source])
		if trace != nil {
			traces = append(traces, trace)
		}
	}
	outbound := obj{
		"v":           1,
		"app":         obj{"name": t.AppName, "token": t.AppToken},
		"environment": t.Environment,
		"server":      t.ServerName,
		"sent_at":     iso(t.now()),
		"traces":      traces,
	}
	return json.Marshal(outbound)
}
func (t *Transformer) transformTrace(source string, records []obj) obj {
	if len(records) == 0 {
		return nil
	}
	root := rootRecord(records)
	p := asObj(get(root, "payload"))
	kind := recordType(root)
	start := startedAt(root, records, t.now())
	db, external, job := 0, 0, 0
	for _, r := range records {
		payload := asObj(get(r, "payload"))
		switch s(r, "type") {
		case "query":
			db += rounded(number(payload, 0, "time_ms"))
		case "outgoing_http":
			external += rounded(number(payload, 0, "duration_ms"))
		case "job":
			job += rounded(number(payload, 0, "duration_ms"))
		}
	}
	db = max(0, db)
	external = max(0, external)
	job = max(0, job)
	duration := durationMs(root, records, db, external, job)
	traceID := source
	if !ulid(source) {
		traceID = stable(t.AppName, t.Environment, source, "")
	}
	limit := t.spanLimit()
	spans := []any{}
	dropped := 0
	var rootID any
	if !isFragment(kind, p) {
		if _, ok := get(root, "payload").(map[string]any); ok {
			rootSpan := rootSpan(root, kind, start, t.AppName, t.Environment)
			rootID = rootSpan["id"]
			spans = append(spans, rootSpan)
		}
	}
	if isFragment(kind, p) || kind == "query" || kind == "cache" || kind == "outgoing_http" || kind == "exception" {
		if child := childSpan(root, offsetMs(root, start), rootID); child != nil && len(spans) < limit {
			spans = append(spans, child)
		}
	}
	lifecycle := []obj{}
	phaseIDs := map[string]string{}
	ranges := map[string]phaseRange{}
	if kind == "request" {
		lifecycle, phaseIDs, ranges = lifecycleSpans(p, duration, rootID, t.AppName, t.Environment)
	}
	for i, span := range lifecycle {
		if len(spans) >= limit {
			dropped += len(lifecycle) - i
			break
		}
		spans = append(spans, span)
	}
	exception := extractException(root)
	for _, r := range records {
		// PHP uses loose array-value equality to omit the chosen root record.
		if jsonInline(r) == jsonInline(root) {
			continue
		}
		payload := asObj(get(r, "payload"))
		recKind := recordType(r)
		offset := offsetMs(r, start)
		parent, phase := parentForRecord(recKind, offset, recordDuration(recKind, payload), phaseIDs, ranges, rootID)
		child := childSpan(r, offset, parent)
		if child == nil {
			continue
		}
		applyPhase(child, phase)
		if len(spans) >= limit {
			dropped++
			continue
		}
		spans = append(spans, child)
		if exception == nil {
			exception = extractException(r)
		}
	}
	queryGroups := map[string]int{}
	for _, raw := range spans {
		span := asObj(raw)
		if s(span, "kind") != "query" {
			continue
		}
		sig := firstString(span, "normalized_signature", "name")
		if sig == "" || sig == "[REDACTED_SQL]" {
			continue
		}
		connection := s(span, "connection")
		if connection == "" {
			connection = "default"
		}
		queryGroups[connection+"|"+sig]++
	}
	repeated, nPlusOne := 0, 0
	for _, n := range queryGroups {
		if n >= 2 {
			repeated++
		}
		if n >= 5 {
			nPlusOne++
		}
	}
	h, hTruncated := headers(get(p, "headers"))
	prev, prevTruncated := preview(get(p, "request_payload"))
	controller := firstString(p, "controller", "route_name")
	var controllerValue any
	if controller != "" {
		controllerValue = controller
	}
	var requestID any
	requestID = nonemptyString(get(p, "request_id"))
	release := firstString(root, "release", "deployment")
	var releaseValue any
	if release != "" {
		releaseValue = release
	}
	var userLabel any
	if v := get(p, "user_id"); v != nil {
		if text, ok := v.(string); ok && text != "" {
			userLabel = text
		} else if _, ok := numeric(v); ok {
			userLabel = stringVal(v)
		}
	}
	hostname := hostFromURL(s(p, "url"))
	if hostname == "" {
		hostname = firstString(root, "server")
	}
	if hostname == "" {
		hostname = t.ServerName
	}
	code := rootStatus(kind, p)
	memory := max(0, rounded(number(p, 0, "memory_peak_bytes")/1024/1024))
	method := rootMethod(kind, p)
	route := rootRoute(kind, p)
	uri := rootURI(kind, p)
	middleware := arrayAnyString(get(p, "middleware"))
	sampling := ensureArray(get(p, "sampling_dropped"))
	if _, ok := get(p, "sampling_dropped").(map[string]any); ok {
		sampling = get(p, "sampling_dropped")
	}
	meta := obj{
		"source_trace_id": source, "repeated_query_groups": repeated, "suspected_n_plus_one_groups": nPlusOne,
		"records_count": len(records), "dropped_spans": dropped,
		"transport_dropped_records": integer(p, "transport_dropped_records"),
		"span_limit":                limit, "sampling_dropped": sampling,
		"cache_capture_enabled": boolValue(get(p, "cache_capture_enabled")),
		"cache_sample_rate":     number(p, 1, "cache_sample_rate"),
		"middleware":            middleware, "route_name": get(p, "route_name"),
		"request_headers": h, "request_headers_truncated": hTruncated,
		"request_payload_preview": prev, "request_payload_preview_truncated": prevTruncated,
		"lifecycle_ready": len(phaseIDs) > 0, "lifecycle_stages": ensureArray(get(p, "lifecycle_stages")),
	}
	if _, ok := p["dropped_batches"]; ok {
		meta["dropped_batches"] = integer(p, "dropped_batches")
	}
	if _, ok := p["inertia"]; ok {
		meta["inertia"] = boolValue(get(p, "inertia"))
		meta["ssr_duration_measured"] = false
	}
	return obj{
		"id": traceID, "type": kind, "started_at": start,
		"method": method, "route": route, "uri": uri, "controller": controllerValue,
		"host": hostname, "status_code": code, "duration_ms": duration, "db_time_ms": db,
		"external_time_ms": external, "job_time_ms": job,
		"cpu_time_ms": integer(p, "cpu_time_ms"), "memory_mb": memory,
		"request_id": requestID, "release": releaseValue, "user_label": userLabel,
		"tenant_label": nil, "deployment_id": nil,
		"spans": spans, "tags": []any{}, "meta": meta, "exception": exception,
	}
}
func validateURL(raw string) bool {
	if strings.TrimSpace(raw) == "" {
		return false
	}
	parsed, err := url.Parse(raw)
	return err == nil && parsed.Host != ""
}
