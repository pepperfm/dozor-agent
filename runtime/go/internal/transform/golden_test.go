package transform

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"reflect"
	"testing"
	"time"
)

func TestGoldenAgainstPHP(t *testing.T) {
	files, err := filepath.Glob("../../testdata/golden/*.json")
	if err != nil || len(files) < 5 {
		t.Fatalf("goldens unavailable: %v %v", files, err)
	}
	fixed := time.Date(2026, 10, 29, 2, 0, 0, 0, time.FixedZone("UTC", 0))
	transformer := &Transformer{AppName: "fixture-app", AppToken: "fixture-token-not-a-secret", Environment: "testing", ServerName: "fixture-host", MaxSpansPerTrace: 10, Now: func() time.Time { return fixed }}
	for _, path := range files {
		t.Run(filepath.Base(path), func(t *testing.T) {
			contents, e := os.ReadFile(path)
			if e != nil {
				t.Fatal(e)
			}
			var fixture struct {
				Input    json.RawMessage `json:"input"`
				Expected any             `json:"expected"`
			}
			if e = json.Unmarshal(contents, &fixture); e != nil {
				t.Fatal(e)
			}
			result, e := transformer.Transform(context.Background(), fixture.Input)
			if e != nil {
				t.Fatal(e)
			}
			var actual any
			if e = json.Unmarshal(result, &actual); e != nil {
				t.Fatal(e)
			}
			if !reflect.DeepEqual(actual, fixture.Expected) {
				expectedJson, _ := json.MarshalIndent(fixture.Expected, "", "  ")
				actualJson, _ := json.MarshalIndent(actual, "", "  ")
				t.Errorf("Go/PHP contract mismatch\nEXPECTED:\n%s\nACTUAL:\n%s", expectedJson, actualJson)
			}
		})
	}
}
func TestNeverUploadInvalidRecords(t *testing.T) {
	tr := &Transformer{AppName: "app", AppToken: "secret", Environment: "test", ServerName: "host"}
	for _, raw := range []string{`{}`, `[null]`, `"string"`, `null`, `[{"type":"request","trace_id":"x","payload":[] }]`} {
		_, err := tr.Transform(context.Background(), json.RawMessage(raw))
		if err == nil {
			t.Fatalf("accepted %s", raw)
		}
	}
}

func TestSpanLimitMatchesLaravelConfiguration(t *testing.T) {
	tr := &Transformer{MaxSpansPerTrace: 1}
	if got := tr.spanLimit(); got != 10 {
		t.Fatalf("Laravel clamps explicit limits to 10, got %d", got)
	}
	tr.MaxSpansPerTrace = 0
	if got := tr.spanLimit(); got != 200 {
		t.Fatalf("unspecified limit should remain 200, got %d", got)
	}
}
func TestOutgoingRootFallsBackFromNonnumericStatus(t *testing.T) {
	payload := obj{"status": "failed", "status_code": 502}
	if got := rootStatus("outgoing_http", payload); got != 502 {
		t.Fatalf("expected HTTP 502 from status_code, got %d", got)
	}
	if got := rootStatus("request", payload); got != 200 {
		t.Fatalf("unexpected request status: %d", got)
	}
}
