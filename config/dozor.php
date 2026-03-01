<?php

declare(strict_types=1);

return [
    'enabled' => env('DOZOR_ENABLED', true),
    'token' => env('DOZOR_TOKEN'),
    'app_name' => env('DOZOR_APP_NAME', env('APP_NAME', 'laravel')),
    'environment' => env('DOZOR_ENV', env('APP_ENV', 'production')),
    'deployment' => env('DOZOR_DEPLOYMENT'),
    'server' => env('DOZOR_SERVER', (string) gethostname()),
    'capture_request_payload' => env('DOZOR_CAPTURE_REQUEST_PAYLOAD', false),
    'capture_exception_source_code' => env('DOZOR_CAPTURE_EXCEPTION_SOURCE_CODE', false),
    'redact_payload_fields' => explode(',', env('DOZOR_REDACT_PAYLOAD_FIELDS', '_token,password,password_confirmation')),
    'redact_headers' => explode(',', env('DOZOR_REDACT_HEADERS', 'Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN')),

    'sampling' => [
        'requests' => (float) env('DOZOR_REQUEST_SAMPLE_RATE', 1.0),
        'commands' => (float) env('DOZOR_COMMAND_SAMPLE_RATE', 1.0),
        'exceptions' => (float) env('DOZOR_EXCEPTION_SAMPLE_RATE', 1.0),
        'jobs' => (float) env('DOZOR_JOB_SAMPLE_RATE', 1.0),
    ],

    'filtering' => [
        'ignore_queries' => env('DOZOR_IGNORE_QUERIES', false),
        'ignore_queue_jobs' => env('DOZOR_IGNORE_QUEUE_JOBS', false),
        'ignore_request_payload' => env('DOZOR_IGNORE_REQUEST_PAYLOAD', false),
    ],

    'http' => [
        'attach_middleware' => env('DOZOR_ATTACH_HTTP_MIDDLEWARE', true),
        'groups' => explode(',', env('DOZOR_HTTP_GROUPS', 'web,api')),
    ],

    'ingest' => [
        'uri' => env('DOZOR_INGEST_URI', '127.0.0.1:4815'),
        'timeout' => (float) env('DOZOR_INGEST_TIMEOUT', 0.5),
        'connection_timeout' => (float) env('DOZOR_INGEST_CONNECTION_TIMEOUT', 0.5),
        'event_buffer' => (int) env('DOZOR_INGEST_EVENT_BUFFER', 500),
    ],

    'agent' => [
        'store_path' => env('DOZOR_AGENT_STORE_PATH', storage_path('app/dozor')),
    ],
];
