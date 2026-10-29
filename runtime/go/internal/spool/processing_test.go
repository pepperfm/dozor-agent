package spool

import (
	"encoding/json"
	"testing"
	"time"
)

func TestRecoverClaimedBatchAfterCrash(t *testing.T) {
	dir := t.TempDir()
	q, err := Open(dir, 3)
	if err != nil {
		t.Fatal(err)
	}
	_, err = q.Enqueue(json.RawMessage(`[{"type":"request"}]`))
	if err != nil {
		t.Fatal(err)
	}
	item, err := q.Claim(time.Now())
	if err != nil || item == nil {
		t.Fatalf("claim: %v %v", item, err)
	}
	q.Close()
	q, err = Open(dir, 3)
	if err != nil {
		t.Fatal(err)
	}
	defer q.Close()
	if err = q.Recover(); err != nil {
		t.Fatal(err)
	}
	files, err := q.Pending()
	if err != nil || len(files) != 1 {
		t.Fatalf("recovered: %v %v", files, err)
	}
	again, err := q.Claim(time.Now())
	if err != nil || again == nil || again.Envelope.ID != item.Envelope.ID {
		t.Fatalf("reclaim: %v %v", again, err)
	}
	if err = q.Complete(again); err != nil {
		t.Fatal(err)
	}
	pending, failed, _ := q.Count()
	if pending != 0 || failed != 0 {
		t.Fatalf("pending=%d failed=%d", pending, failed)
	}
}

func TestCountAtIncludesProcessingAndDeadLetters(t *testing.T) {
	dir := t.TempDir()
	q, err := Open(dir, 3)
	if err != nil {
		t.Fatal(err)
	}
	defer q.Close()

	_, err = q.Enqueue(json.RawMessage(`{"v":1,"traces":[]}`))
	if err != nil {
		t.Fatal(err)
	}
	item, err := q.Claim(time.Now())
	if err != nil || item == nil {
		t.Fatalf("claim: %v %v", item, err)
	}

	pending, failed, err := CountAt(dir)
	if err != nil || pending != 1 || failed != 0 {
		t.Fatalf("in-flight batch missing from status: pending=%d failed=%d err=%v", pending, failed, err)
	}
	if err := q.Abandon(item, "http-permanent"); err != nil {
		t.Fatal(err)
	}
	pending, failed, err = CountAt(dir)
	if err != nil || pending != 0 || failed != 1 {
		t.Fatalf("dead letter missing from status: pending=%d failed=%d err=%v", pending, failed, err)
	}
}
