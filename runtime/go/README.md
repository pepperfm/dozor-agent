# Dozor Go daemon — **incomplete migration / experimental**

This directory is a **migration worktree**, not a drop-in replacement for the PHP daemon. The PHP Laravel SDK, instrumentation, TCP protocol and legacy PHP agent remain unchanged.

## Implemented

- PHP SDK `v1` framing (`<length>:v1:<7-character XXH128 prefix>:<JSON>`), 1 MiB frame cap, `PING`, `2:OK` / `5:ER`.
- Bounded concurrent TCP clients and socket deadlines. **Loopback-only** listener: the 28-bit v1 token prefix is not safe authentication for a public interface.
- Durable raw spool: atomic temporary-file write, file and directory fsync, bounded queue, process ownership lock, crash recovery. ACK follows confirmed local persistence.
- `--debug` produces append-only sanitized operational logs, not the requested interactive TUI yet.
- `status --json` returns local spool counters; shipping explicitly reports disabled.
- Linux/macOS AMD64 and ARM64 cross-builds are CI artifacts **only**, not production releases.
- PHP Payload contract smoke + Go unit/race tests.

## NOT implemented (do not switch real applications)

- Exact `TraceBatchTransformer.php` parity and golden fixture corpus.
- Remote HTTPS shipping, retries, idempotency, processing/dead-letter policies and server-side E2E.
- Full PHP `dozor:status` parity, interactive TUI and production systemd/Sail deployment.
- Baseline benchmarks and verified absence of data loss after remote outage.

**The Go agent currently stores raw records that are not valid Dozor Server v1 HTTP payloads. It never uploads them.** Do not run on production monitoring traffic or assume `2:OK` means delivery to Dozor Server.

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
# in another terminal:
php runtime/go/tests/php_client.php  # from repository root, with matching variables
```

Keep the experimental spool separate from the legacy PHP `DOZOR_AGENT_SPOOL_PATH` directory. Do not start both agents on the same TCP address. The PHP daemon remains the supported fallback. No additional infrastructure is required.

## Next compatibility gate

Generate golden traces from the **PHP** transformer, implement the Go equivalent, compare canonical semantic payloads (timestamps/IDs injected for deterministic results), and execute PHP SDK → Go → Dozor Server E2E before enabling shipping or recommending any production switch.
