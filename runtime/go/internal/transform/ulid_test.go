package transform

import (
	"testing"
)

func TestRandomULIDIsValid(t *testing.T) {
	previous := make(map[string]bool)
	for i := 0; i < 10000; i++ {
		id := NewTraceID()
		if !ulid(id) {
			t.Fatalf("generated an invalid ULID: %q", id)
		}
		if previous[id] {
			t.Fatalf("duplicate ULID: %q", id)
		}
		previous[id] = true
	}
}

func TestMissingRecordIDGetsValidSpanULID(t *testing.T) {
	id := spanID(obj{})
	if !ulid(id) {
		t.Fatalf("invalid span ID: %s", id)
	}
}
