package protocol

import "testing"

func TestTokenHashLength(t *testing.T) {
	if got := TokenHash("fixture-token"); len(got) != 7 {
		t.Fatalf("invalid token hash %q", got)
	}
	if TokenHash("one") == TokenHash("two") {
		t.Fatal("unexpected token hash collision")
	}
}
