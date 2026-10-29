# PHP → Go migration checklist

Baseline: `dozor-agent` master at `b0f17f47` (2026-10-29). PHP SDK and daemon remain the production path until complete parity.

| Stage | Status | Evidence / remaining gate |
|---|---|---|
| A — compatibility contracts | PARTIAL | PHP v1 framing tests and real Payload smoke. Golden transformer fixtures and server validation pending |
| B — concurrent TCP ingest | IMPLEMENTED (experimental) | bounded goroutines, deadlines, errors and concurrent-client test |
| C — durable spool | PARTIAL | tempfile + file/directory fsync, lock, capacity, recovery test. Crash-injection / disk-full tests pending |
| D — Go transformer | NOT STARTED | PHP source is 1500+ lines; requires strict trace/span and lifecycle parity |
| E — asynchronous shipping | PARTIAL | independent HTTP client handles 202/429/5xx, Retry-After and retries; spool claim/recover/retry and background worker covered by tests, activation blocked on validated Go transformer |
| F — metrics and TUI | PARTIAL | atomic runtime state file and interactive terminal dashboard, headless debug logging; full shipping views pending |
| G — Laravel config/status | PARTIAL | v1 wire contract and optional DOZOR_AGENT_STATE_PATH. Manual token hash required |
| H — builds/deployment | PARTIAL | four CI cross-builds. Production systemd/Sail deliberately deferred |
| I — test/benchmark rollout | PARTIAL | Go unit/race tests, PHP Payload smoke, HTTP mock tests. Server E2E and measured baseline pending |

Safety: keep `--ingest-only` mandatory until outbound payload parity, use dedicated `DOZOR_GO_SPOOL_PATH`, loopback-only v1, and never claim exactly-once network delivery. ACK means durable local write only. PHP instrumentation and server contracts are untouched.
