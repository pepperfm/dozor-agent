package envfile

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestReadLaravelEnvWithoutShellExecution(t *testing.T) {
	for _, key := range []string{"DOZOR_TOKEN", "DOZOR_HOSTED_INGEST_URL", "APP_NAME"} {
		t.Setenv(key, "")
		os.Unsetenv(key)
	}
	path := filepath.Join(t.TempDir(), ".env")
	contents := strings.Join([]string{
		"# comment",
		"APP_NAME=\"My Laravel App\"",
		"DOZOR_TOKEN='tok#en with spaces'",
		"DOZOR_HOSTED_INGEST_URL=https://example.test/api/v1/ingest # comment",
		"OTHER_SECRET=should-not-be-loaded",
		"DOZOR_SERVER=\$(touch /tmp/this-is-data-not-shell)",
	}, "\n")
	if err := os.WriteFile(path, []byte(contents), 0600); err != nil {
		t.Fatal(err)
	}
	if err := LoadMissing(path); err != nil {
		t.Fatal(err)
	}
	if got := os.Getenv("DOZOR_TOKEN"); got != "tok#en with spaces" {
		t.Fatalf("token not decoded: %q", got)
	}
	if got := os.Getenv("APP_NAME"); got != "My Laravel App" {
		t.Fatalf("app name: %q", got)
	}
	if got := os.Getenv("DOZOR_HOSTED_INGEST_URL"); got != "https://example.test/api/v1/ingest" {
		t.Fatalf("url: %q", got)
	}
	if _, exists := os.LookupEnv("OTHER_SECRET"); exists {
		t.Fatal("unrelated secret imported")
	}
	if got := os.Getenv("DOZOR_SERVER"); got != "\$(touch /tmp/this-is-data-not-shell)" {
		t.Fatalf("shell text mutated: %q", got)
	}
}

func TestProcessEnvOverridesFile(t *testing.T) {
	t.Setenv("DOZOR_TOKEN", "process-token")
	path := filepath.Join(t.TempDir(), ".env")
	if err := os.WriteFile(path, []byte("DOZOR_TOKEN=file-token\n"), 0600); err != nil {
		t.Fatal(err)
	}
	if err := LoadMissing(path); err != nil {
		t.Fatal(err)
	}
	if os.Getenv("DOZOR_TOKEN") != "process-token" {
		t.Fatal("process environment should win")
	}
}

func TestParseEnvFileCLI(t *testing.T) {
	for _, args := range [][]string{{"--env-file=/srv/app/.env"}, {"--debug", "--env-file", "/srv/app/.env"}} {
		path, ok, err := FromArgs(args)
		if err != nil || !ok || path != "/srv/app/.env" {
			t.Fatalf("args=%v path=%q ok=%t err=%v", args, path, ok, err)
		}
	}
	if _, _, err := FromArgs([]string{"--env-file"}); err == nil {
		t.Fatal("missing env-file value accepted")
	}
}
