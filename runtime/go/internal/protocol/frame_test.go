package protocol

import (
	"fmt"
	"strings"
	"testing"
)

func frame(s string) string { return fmt.Sprintf("%d:%s", len(s), s) }

func TestDecode(t *testing.T) {
	tests := []struct {
		name, data, hash    string
		wantPing, wantError bool
		wantCount           int
	}{
		{"ping", frame("v1:abcdef0:PING"), "abcdef0", true, false, 0},
		{"json", frame(`v1:abcdef0:[{"type":"request"},{"type":"query"}]`), "abcdef0", false, false, 2},
		{"unauthorized", frame("v1:wrongxx:PING"), "abcdef0", false, true, 0},
		{"no hash", frame("v1:abcdef0:PING"), "", false, true, 0},
		{"bad version", frame("v2:abcdef0:PING"), "abcdef0", false, true, 0},
		{"bad json", frame("v1:abcdef0:[1]"), "abcdef0", false, true, 0},
		{"empty", "0:", "abcdef0", false, true, 0},
		{"empty-record-list", frame("v1:abcdef0:[]"), "abcdef0", false, true, 0},
		{"oversize", "1048577:", "abcdef0", false, true, 0},
		{"slow eof", "20:v1:abcdef0:[]", "abcdef0", false, true, 0},
		{"long length", strings.Repeat("9", 9) + ":", "abcdef0", false, true, 0},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			rec, ping, err := Decode(strings.NewReader(tc.data), tc.hash)
			if (err != nil) != tc.wantError || ping != tc.wantPing || len(rec) != tc.wantCount {
				t.Fatalf("records=%d ping=%v err=%v", len(rec), ping, err)
			}
		})
	}
}
