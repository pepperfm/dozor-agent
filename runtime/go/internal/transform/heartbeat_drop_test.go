package transform

import (
	"context"
	"encoding/json"
	"testing"
	"time"
)

func TestHeartbeatDroppedBatchesReachMeta(t *testing.T) {
	raw := json.RawMessage(`[{"type":"heartbeat","trace_id":"01J00000000000000000000999","happened_at":"2026-10-29T02:00:00+00:00","payload":{"dropped_batches":4}}]`)
	out, err := (&Transformer{
		AppName: "app",
		AppToken: "token",
		Environment: "production",
		ServerName: "web",
		Now: func() time.Time { return time.Date(2026, 10, 29, 2, 0, 0, 0, time.UTC) },
	}).Transform(context.Background(), raw)
	if err != nil {
		t.Fatal(err)
	}
	var body struct {
		Traces []struct {
			Meta map[string]any `json:"meta"`
		} `json:"traces"`
	}
	if err = json.Unmarshal(out, &body); err != nil {
		t.Fatal(err)
	}
	if len(body.Traces) != 1 {
		t.Fatalf("traces=%d", len(body.Traces))
	}
	if got, _ := body.Traces[0].Meta["dropped_batches"].(float64); got != 4 {
		t.Fatalf("dropped_batches=%v", body.Traces[0].Meta["dropped_batches"])
	}
}
