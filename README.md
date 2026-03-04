# Dozor Agent Starter

A self-hostable Laravel instrumentation and local agent starter, adapted from the public
`laravel/nightwatch` package architecture and transport ideas, but renamed and reshaped for **Dozor**.

## What this bundle gives you

- Laravel package with `dozor:agent` and `dozor:status` commands
- local TCP ingest client/server using a framed payload protocol
- request tracing middleware
- query, queue, and exception watchers
- newline-delimited JSON storage for ingested records
- configuration already renamed from `NIGHTWATCH_*` to `DOZOR_*`

## What this is not

- not a drop-in clone of the hosted Nightwatch backend
- not a full production-ready APM yet
- not a fork that keeps Laravel/Nightwatch branding

It is a **good self-hosted starter**: clean enough to keep, small enough to reshape.

## Install

```bash
composer require pepperfm/dozor-agent
php artisan vendor:publish --tag=dozor-config
```

### Local package smoke install (path repository)

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../dozor-agent",
      "options": {
        "symlink": true
      }
    }
  ]
}
```

```bash
composer require pepperfm/dozor-agent:*
php artisan list | grep dozor:
php artisan vendor:publish --tag=dozor-config
```

Install is safe even if the local worker is not running yet. The package does not break
`composer install`, deploy hooks, or `package:discover` when `127.0.0.1:4815` is unavailable.
You can install first and start the worker later.

## Typical env

```env
DOZOR_ENABLED=true
DOZOR_TOKEN=change-me
DOZOR_APP_NAME="My App"
DOZOR_ENV=production
DOZOR_SERVER=app-1
DOZOR_INGEST_URI=127.0.0.1:4815
DOZOR_INGEST_EVENT_BUFFER=500
DOZOR_REQUEST_SAMPLE_RATE=1.0
DOZOR_IGNORE_QUERIES=false
DOZOR_AGENT_STORE_PATH=/var/www/app/storage/app/dozor
DOZOR_AGENT_SHIP_FLUSH_INTERVAL_SECONDS=10
```

## Run local agent

```bash
php artisan dozor:agent
```

Useful options:

```bash
php artisan dozor:agent --listen-on=127.0.0.1:4815 --store-path=/var/www/app/storage/app/dozor
php artisan dozor:agent --ship-flush-interval-seconds=10
php artisan dozor:status
php artisan dozor:status --json
php artisan dozor:status --strict
```

`dozor:status --strict` is opt-in diagnostics and may fail when the local worker is down.
Regular runtime instrumentation stays non-fatal and keeps buffered retry behavior.

## Runtime telemetry and status

`dozor:status` now reports:

- local agent reachability (`ping`)
- queue depth and failed queue depth
- dropped batches and failed upload counters
- last flush / heartbeat / upload timestamps

Shipping strategy is strict batching by default and cannot be toggled via config flags.

Telemetry state is persisted to:

```env
DOZOR_AGENT_STATE_PATH=/var/www/app/storage/app/dozor/runtime-state.json
```

## Production packaging examples

Example service files are available in:

- `examples/supervisor/dozor-agent.conf.example`
- `examples/systemd/dozor-agent.service.example`

## Current record model

The package emits records like:

- `request`
- `query`
- `job`
- `exception`
- `custom`

Each record includes a `trace_id`, timestamps, app/environment/server metadata, and a `payload` block.

## Files worth editing first

- `src/DozorServiceProvider.php`
- `src/Core.php`
- `src/Http/Middleware/TraceRequest.php`
- `src/Agent/Server.php`
- `config/dozor.php`

## Suggested next steps

1. swap NDJSON file storage for ClickHouse / Postgres / Kafka / Redis Streams
2. add outgoing HTTP instrumentation
3. add trace/span hierarchy instead of flat child records
4. add metric rollups (minute buckets, P95/P99)
5. correlate deployments/releases
