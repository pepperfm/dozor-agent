# Example record shape

```json
{
  "type": "request",
  "trace_id": "0195f4a2-3c0c-7b0d-b4ff-0f5f3f5f9f42",
  "happened_at": "2026-02-28T13:45:12+00:00",
  "app": "billing",
  "environment": "production",
  "server": "app-1",
  "deployment": "2026-02-28.1",
  "payload": {
    "method": "GET",
    "url": "https://billing.example.com/invoices",
    "route_name": "invoices.index",
    "route_uri": "invoices",
    "status": 200,
    "duration_ms": 87.12,
    "memory_peak_bytes": 16777216
  }
}
```

The local agent stores one JSON object per line in `ingest-YYYY-MM-DD.ndjson`.
