<?php

declare(strict_types=1);

return [
    'enabled' => env('DOZOR_ENABLED', true),
    'token' => env('DOZOR_TOKEN'),
    'app_name' => env('DOZOR_APP_NAME', env('APP_NAME', 'laravel')),
    'environment' => env('DOZOR_ENV', env('APP_ENV', 'production')),
    'deployment' => env('DOZOR_DEPLOYMENT'),
    'release' => env('DOZOR_RELEASE', env('DOZOR_DEPLOYMENT')),
    'server' => env('DOZOR_SERVER', (string) gethostname()),
    'capture_request_payload' => env('DOZOR_CAPTURE_REQUEST_PAYLOAD', true),
    'capture_exception_source_code' => env('DOZOR_CAPTURE_EXCEPTION_SOURCE_CODE', false),
    'redact_payload_fields' => explode(',', env('DOZOR_REDACT_PAYLOAD_FIELDS', '_token,password,password_confirmation')),
    'redact_headers' => explode(',', env('DOZOR_REDACT_HEADERS', 'Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN')),

    'sampling' => [
        'requests' => (float) env('DOZOR_REQUEST_SAMPLE_RATE', 1.0),
        'commands' => (float) env('DOZOR_COMMAND_SAMPLE_RATE', 1.0),
        'exceptions' => (float) env('DOZOR_EXCEPTION_SAMPLE_RATE', 1.0),
        'jobs' => (float) env('DOZOR_JOB_SAMPLE_RATE', 1.0),
        'queries' => (float) env('DOZOR_QUERY_SAMPLE_RATE', 1.0),
        'outgoing_http' => (float) env('DOZOR_OUTGOING_HTTP_SAMPLE_RATE', 1.0),
        'logs' => (float) env('DOZOR_LOG_SAMPLE_RATE', 0.1),
        'events' => (float) env('DOZOR_EVENT_SAMPLE_RATE', 0.1),
    ],

    'filtering' => [
        'ignore_queries' => env('DOZOR_IGNORE_QUERIES', false),
        'ignore_queue_jobs' => env('DOZOR_IGNORE_QUEUE_JOBS', false),
        'ignore_request_payload' => env('DOZOR_IGNORE_REQUEST_PAYLOAD', false),
        'ignore_outgoing_http' => env('DOZOR_IGNORE_OUTGOING_HTTP', false),
        'capture_query_sql' => env('DOZOR_CAPTURE_QUERY_SQL', false),
        'request' => [
            'ignore_paths' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOZOR_IGNORE_PATHS', '/up,/health,/healthz,/ping,/ready'))))),
            'ignore_path_prefixes' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOZOR_IGNORE_PATH_PREFIXES', '/_ignition,/horizon,/telescope,/pulse'))))),
            'ignore_route_names' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOZOR_IGNORE_ROUTE_NAMES', 'ignition.healthCheck'))))),
            'ignore_user_agents_contains' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOZOR_IGNORE_USER_AGENTS', 'bot,spider,crawler,uptime,kube-probe,healthcheck'))))),
        ],
    ],

    'instrumentation' => [
        'outgoing_http' => env('DOZOR_INSTRUMENT_OUTGOING_HTTP', true),
        'capture_logs' => env('DOZOR_CAPTURE_LOGS', false),
        'capture_events' => env('DOZOR_CAPTURE_EVENTS', false),
        'capture_request_headers' => env('DOZOR_CAPTURE_REQUEST_HEADERS', true),
        'capture_outgoing_http_headers' => env('DOZOR_CAPTURE_OUTGOING_HTTP_HEADERS', true),
        'event_prefixes' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOZOR_EVENT_PREFIXES', 'App\\Events\\,Illuminate\\Auth\\Events\\'))))),
        'event_ignore' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOZOR_EVENT_IGNORE', 'Illuminate\\Log\\Events\\MessageLogged'))))),
    ],

    'limits' => [
        'max_payload_bytes' => (int) env('DOZOR_MAX_PAYLOAD_BYTES', 16384),
        'truncate_preview_bytes' => (int) env('DOZOR_TRUNCATE_PREVIEW_BYTES', 2048),
        'oversize_behavior' => env('DOZOR_OVERSIZE_BEHAVIOR', 'drop'),
        'max_sql_length' => (int) env('DOZOR_MAX_SQL_LENGTH', 2000),
    ],

    'http' => [
        'attach_middleware' => env('DOZOR_ATTACH_HTTP_MIDDLEWARE', true),
        'groups' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOZOR_HTTP_GROUPS', 'web,api'))))),
    ],

    'ingest' => [
        'uri' => env('DOZOR_INGEST_URI', '127.0.0.1:4815'),
        'timeout' => (float) env('DOZOR_INGEST_TIMEOUT', 0.5),
        'connection_timeout' => (float) env('DOZOR_INGEST_CONNECTION_TIMEOUT', 0.5),
        'event_buffer' => (int) env('DOZOR_INGEST_EVENT_BUFFER', 500),
    ],

    'agent' => [
        'store_path' => env('DOZOR_AGENT_STORE_PATH', storage_path('app/dozor')),
        'spool_path' => env('DOZOR_AGENT_SPOOL_PATH', storage_path('app/dozor/spool')),
        'telemetry' => [
            'state_path' => env('DOZOR_AGENT_STATE_PATH', storage_path('app/dozor/runtime-state.json')),
        ],
        'tracing' => [
            'max_spans_per_trace' => (int) env('DOZOR_AGENT_TRACE_MAX_SPANS_PER_TRACE', 200),
            'heartbeat_interval_seconds' => (float) env('DOZOR_AGENT_HEARTBEAT_INTERVAL_SECONDS', 15.0),
        ],
        'shipper' => [
            'enabled' => env('DOZOR_AGENT_SHIPPER_ENABLED', true),
            'ingest_url' => env('DOZOR_HOSTED_INGEST_URL'),
            'ingest_token' => env('DOZOR_HOSTED_INGEST_TOKEN', env('DOZOR_TOKEN')),
            'connection_timeout' => (float) env('DOZOR_AGENT_SHIP_CONNECTION_TIMEOUT', 2.0),
            'timeout' => (float) env('DOZOR_AGENT_SHIP_TIMEOUT', 5.0),
            'retry_attempts' => (int) env('DOZOR_AGENT_SHIP_RETRY_ATTEMPTS', 3),
            'retry_backoff_ms' => (int) env('DOZOR_AGENT_SHIP_RETRY_BACKOFF_MS', 250),
            'batch_size' => (int) env('DOZOR_AGENT_SHIP_BATCH_SIZE', 100),
            'max_batches_per_flush' => (int) env('DOZOR_AGENT_SHIP_MAX_BATCHES_PER_FLUSH', 10),
            'flush_interval_seconds' => (float) env('DOZOR_AGENT_SHIP_FLUSH_INTERVAL_SECONDS', 2.0),
            'max_attempts_per_batch' => (int) env('DOZOR_AGENT_SHIP_MAX_ATTEMPTS_PER_BATCH', 8),
            'queue_backoff_base_ms' => (int) env('DOZOR_AGENT_SHIP_QUEUE_BACKOFF_BASE_MS', 500),
            'queue_backoff_cap_ms' => (int) env('DOZOR_AGENT_SHIP_QUEUE_BACKOFF_CAP_MS', 30000),
        ],
    ],
];
