# PHP → Go migration checklist

Baseline: `dozor-agent` master at `b0f17f47` (2026-10-29). PHP SDK and daemon are the production path until parity is verified.

| Stage | Status | Evidence / remaining gate |
|---|---|---|
| A — compatibility contracts | PARTIAL | PHP v1 framing tests and real Payload smoke. Golden transformer fixtures and server validation pending |
| B — concurrent TCP ingest | IMPLEMENTED (experimental) | bounded goroutines, deadlines, errors and concurrent-client test. Further slowloris benchmarks pending |
| C — durable spool | PARTIAL | tempfile + file/directory fsync, lock, capacity, recovery test. Crash-injection / disk-full tests pending |
| D — Go transformer | NOT STARTED | PHP source is 1500+ lines; requires strict trace/span and lifecycle parity |
| E — asynchronous shipping | PARTIAL | independent HTTP client with 202 handling, retryable 429/5xx and Retry-After tests; no worker or spool integration until D |
| F — metrics and TUI | PARTIAL | debug logs and JSON spool counts; interactive screen pending |
| G — Laravel config/status | PARTIAL | v1 wire contract; manually supplied hash required; legacy PHP status still supported |
| H — builds/deployment | PARTIAL | four CI cross-builds. Production systemd/Sail deliberately deferred |
| I — test/benchmark rollout | PARTIAL | Go unit/race tests, PHP Payload smoke, HTTP mock tests. Server E2E and measured baseline pending |

Safety requirements: keep `--ingest-only` mandatory until outbound payload parity, use a dedicated `DOZOR_GO_SPOOL_PATH`, bind the v1 protocol only to loopback, and never claim exactly-once network delivery. For crash recovery, an ACK is only a durable-local-write acknowledgment.

No PHP instrumentation, Laravel public APIs or Dozor Server contracts were changed.
