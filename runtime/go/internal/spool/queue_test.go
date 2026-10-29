package spool

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func TestDurableEnqueueAndCapacity(t *testing.T) {
	dir := t.TempDir()
	q, err := Open(dir, 1)
	if err != nil {
		t.Fatal(err)
	}
	if _, err = Open(dir, 1); !errors.Is(err, ErrLocked) {
		t.Fatalf("lock: %v", err)
	}
	id, err := q.Enqueue(json.RawMessage(`[{"type":"request"}]`))
	if err != nil {
		t.Fatal(err)
	}
	if len(id) != 16 {
		t.Fatal(id)
	}
	if _, err = q.Enqueue(json.RawMessage(`[]`)); !errors.Is(err, ErrCapacity) {
		t.Fatalf("capacity: %v", err)
	}
	files, err := q.Pending()
	if err != nil || len(files) != 1 {
		t.Fatalf("pending: %v %v", files, err)
	}
	envelope, err := q.Read(files[0])
	if err != nil || envelope.ID != id {
		t.Fatalf("read: %v %v", envelope, err)
	}
	q.Close()
	q, err = Open(dir, 1)
	if err != nil {
		t.Fatal(err)
	}
	defer q.Close()
	files, _ = q.Pending()
	if len(files) != 1 {
		t.Fatal("lost confirmed batch")
	}
	if err = q.Fail(files[0], "corrupted"); err != nil {
		t.Fatal(err)
	}
	pending, failed, err := q.Count()
	if err != nil || pending != 0 || failed != 1 {
		t.Fatalf("counts %d %d %v", pending, failed, err)
	}
}
func TestLegacyEnvelope(t *testing.T) {
	dir := t.TempDir()
	q, err := Open(dir, 4)
	if err != nil {
		t.Fatal(err)
	}
	defer q.Close()
	data := []byte(`{"id":"legacy","attempt":1,"next_attempt_at":0,"created_at":1,"payload":{"v":1,"traces":[]}}`)
	if err = os.WriteFile(filepath.Join(dir, "queue", "legacy.json"), data, 0600); err != nil {
		t.Fatal(err)
	}
	e, err := q.Read(filepath.Join(dir, "queue", "legacy.json"))
	if err != nil || e.Attempt != 1 {
		t.Fatalf("legacy: %v %v", e, err)
	}
}

func TestEnqueueManyCapacityAllOrNothing(t *testing.T) {
	q, err := Open(t.TempDir(), 2)
	if err != nil {
		t.Fatal(err)
	}
	defer q.Close()
	ids, err := q.EnqueueMany([]json.RawMessage{json.RawMessage(`[]`), json.RawMessage(`[]`)})
	if err != nil || len(ids) != 2 {
		t.Fatalf("batch %v %v", ids, err)
	}
	_, err = q.EnqueueMany([]json.RawMessage{json.RawMessage(`[]`), json.RawMessage(`[]`)})
	if !errors.Is(err, ErrCapacity) {
		t.Fatalf("expected capacity, got %v", err)
	}
	count, _, err := q.Count()
	if err != nil || count != 2 {
		t.Fatalf("spool corrupted: %d %v", count, err)
	}
}
