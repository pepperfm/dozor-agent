# Dozor Go daemon — **incomplete migration / experimental**

This is migration work, not a drop-in replacement for the PHP daemon. The PHP Laravel SDK, instrumentation, TCP protocol and legacy PHP agent remain unchanged.

## Implemented

- PHP SDK `v1` framing (`<length>:v1:<7-character XXH128 prefix>:<JSON>`), 1 MiB frame cap, `PING`, `2:OK` / `5:ER`.
- Bounded concurrent TCP clients and deadlines. **Loopback-only** listener: 28-bit v1 token-prefix authentication is not safe over a public network.
- Durable raw spool with temp files, file/directory fsync, bounded size, singleton lock and crash recovery; ACK follows local persistence.
- Live ANSI TUI with `--debug` on a terminal (alternate screen restored after shutdown); append-only safe logging without TTY.
- Atomic JSON runtime state file; `status --json` reports local queue counts, shipping disabled.
- Independent HTTP shipping client module with tests; not attached until transformer contract parity.
- Experimental Linux/macOS AMD64 and ARM64 CI artifacts, not production releases.
- PHP SDK Payload wire smoke and Go unit/race tests.

## NOT implemented (do not switch real applications)

- Full `TraceBatchTransformer.php` golden fixture parity and server-side HTTP E2E.
- Active shipping worker, durable retries/dead-letter and integration with transformed payloads.
- Full PHP `dozor:status` parity, production systemd/Sail deployment, baseline benchmarks.

**Go currently stores raw records, not valid Dozor Server v1 HTTP payloads. It never uploads them.** Do not run on production monitoring traffic.

## Development-only smoke

```bash
cd runtime/go
go test -race ./...
go build -o ./dozor-agent ./cmd/dozor-agent

export DOZOR_TEST_TOKEN='dev-only-secret'
export DOZOR_AGENT_TOKEN_HASH="$(php -r 'echo substr(hash("xxh128", getenv("DOZOR_TEST_TOKEN")), 0, 7);')"
export DOZOR_GO_SPOOL_PATH="$(mktemp -d)"
export DOZOR_INGEST_URI='127.0.0.1:4816'
./dozor-agent --ingest-only --debug
# Other terminal, from repository root:
php runtime/go/tests/php_client.php
```

Keep experimental spool separate from `DOZOR_AGENT_SPOOL_PATH`. Do not start PHP/Go agents on the same TCP port. PHP daemon remains the production fallback.

Next: generate PHP transformer goldens; implement Go parity and server E2E; only then enable shipping and production rollout.
