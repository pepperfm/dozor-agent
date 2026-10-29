package transform

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"testing"
	"time"
)

func TestPreservePHPHeaderInsertionOrderAtTruncationBoundary(t *testing.T) {
	// First header must survive even though it sorts after A-*.
	var headers strings.Builder
	headers.WriteString(`"Z-First":"retain"`)
	for i := 0; i < 32; i++ {
		fmt.Fprintf(&headers, `,"A-%02d":"value"`, i)
	}
	raw := json.RawMessage(fmt.Sprintf(
		`[{"type":"request","trace_id":"01J00000000000000000000100","happened_at":"2026-10-29T02:00:00+00:00","payload":{"headers":{%s},"duration_ms":4,"request_payload":{"z":1,"a":{"b":2,"a":3}}}}]`,
		headers.String(),
	))
	tr := &Transformer{AppName: "app", AppToken: "token", Environment: "test", ServerName: "server",
		Now: func() time.Time { return time.Date(2026, 10, 29, 2, 0, 0, 0, time.UTC) }}
	outbound, err := tr.Transform(context.Background(), raw)
	if err != nil {
		t.Fatal(err)
	}
	var result struct {
		Traces []struct {
			Meta struct {
				RequestHeaders   map[string]string `json:"request_headers"`
				HeadersTruncated bool              `json:"request_headers_truncated"`
				Preview          string            `json:"request_payload_preview"`
			} `json:"meta"`
		} `json:"traces"`
	}
	if err := json.Unmarshal(outbound, &result); err != nil {
		t.Fatal(err)
	}
	meta := result.Traces[0].Meta
	if !meta.HeadersTruncated || len(meta.RequestHeaders) != 32 {
		t.Fatalf("invalid header bounds: %+v", meta)
	}
	if meta.RequestHeaders["Z-First"] != "retain" {
		t.Fatalf("first PHP header lost: %+v", meta.RequestHeaders)
	}
	if _, exists := meta.RequestHeaders["A-31"]; exists {
		t.Fatalf("last header was incorrectly selected")
	}
	if meta.Preview != `{"z":1,"a":{"b":2,"a":3}}` {
		t.Fatalf("PHP request payload order lost: %q", meta.Preview)
	}
}

func TestOrderedJSONPreservesNestedArraysAndSlashes(t *testing.T) {
	v, err := decodeOrdered(json.RawMessage(`{"z":"a/b","a":[{"y":1,"x":2}]}`))
	if err != nil {
		t.Fatal(err)
	}
	got, truncated := preview(v)
	if truncated || got != `{"z":"a\/b","a":[{"y":1,"x":2}]}` {
		t.Fatalf("preview mismatch: %v", got)
	}
}

func TestPHPAssociativeHeaderValues(t *testing.T) {
	value, err := decodeOrdered(json.RawMessage(`{"second":false,"first":"abc","third":3}`))
	if err != nil {
		t.Fatal(err)
	}
	if got := headerValue(value); got != "false, abc, 3" {
		t.Fatalf("associative PHP header values lost: %q", got)
	}
}
