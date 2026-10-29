package tui

import (
	"github.com/pepperfm/dozor-agent/runtime/go/internal/state"
	"strings"
	"testing"
)

func TestRenderNoSecrets(t *testing.T) {
	s := state.Snapshot{}
	s.Context.ListenOn = "127.0.0.1:4815"
	s.Metrics.ReceivedRecords = 12
	content := render(s, []Event{{Kind: "accepted"}})
	for _, needle := range []string{"DOZOR AGENT", "127.0.0.1:4815", "RECEIVED", "12", "accepted"} {
		if !strings.Contains(content, needle) {
			t.Fatalf("missing %s", needle)
		}
	}
}
