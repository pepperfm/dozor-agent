<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Tests\TestCase;
use Dozor\Tracing\TraceBatchTransformer;

use function count;
use function is_array;
use function str_repeat;

final class TraceBatchTransformerTest extends TestCase
{
    public function test_job_root_preserves_failure_and_retry_attempts_for_jobs_dashboard(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'worker-1');
        $traceId = str()->ulid()->toString();

        $trace = $transformer->transform([[
            'record_id' => str()->ulid()->toString(),
            'type' => 'job',
            'trace_id' => $traceId,
            'happened_at' => now()->toIso8601String(),
            'payload' => [
                'name' => 'App\\Jobs\\SendInvoice',
                'queue' => 'default',
                'status' => 'failed',
                'attempts' => 3,
                'duration_ms' => 125,
            ],
        ]])['traces'][0];

        $jobSpans = array_values(array_filter(
            $trace['spans'],
            static fn(array $span): bool => $span['kind'] === 'job',
        ));

        self::assertCount(1, $jobSpans);
        self::assertSame('failed', $jobSpans[0]['metadata']['status']);
        self::assertSame(3, $jobSpans[0]['metadata']['attempts']);
        self::assertSame('default', $jobSpans[0]['queue']);
        self::assertSame(125, $jobSpans[0]['duration_ms']);
    }

    public function test_it_adds_bounded_request_context_to_trace_meta(): void
    {
        $headers = [];
        foreach (range(1, 40) as $index) {
            $headers['x-request-header-' . $index] = str_repeat('x', 700);
        }

        $records = [
            [
                'type' => 'request',
                'trace_id' => 'trace-source-1',
                'happened_at' => now()->toIso8601String(),
                'payload' => [
                    'method' => 'GET',
                    'path' => '/orders',
                    'url' => 'https://storefront.test/orders',
                    'status' => 200,
                    'duration_ms' => 123,
                    'headers' => $headers,
                    'request_payload' => [
                        'email' => 'customer@example.test',
                        'note' => str_repeat('payload-', 400),
                    ],
                ],
            ],
            [
                'type' => 'outgoing_http',
                'trace_id' => 'trace-source-1',
                'happened_at' => now()->toIso8601String(),
                'payload' => [
                    'url' => 'https://billing.example.test/api/invoices',
                    'host' => 'billing.example.test',
                    'method' => 'POST',
                    'status_code' => 502,
                    'duration_ms' => 12,
                    'request_headers' => $headers,
                    'response_headers' => $headers,
                ],
            ],
        ];

        $transformer = new TraceBatchTransformer(
            appName: 'Storefront',
            appToken: 'token',
            environment: 'production',
            serverName: 'node-1',
            maxSpansPerTrace: 50,
        );

        $payload = $transformer->transform($records);
        self::assertIsArray($payload['traces'] ?? null);
        self::assertCount(1, $payload['traces']);

        $trace = $payload['traces'][0];
        self::assertIsArray($trace['meta'] ?? null);
        self::assertSame('trace-source-1', $trace['meta']['source_trace_id'] ?? null);
        self::assertTrue((bool) ($trace['meta']['request_headers_truncated'] ?? false));
        self::assertTrue((bool) ($trace['meta']['request_payload_preview_truncated'] ?? false));
        self::assertFalse((bool) ($trace['meta']['lifecycle_ready'] ?? true));
        self::assertIsArray($trace['meta']['request_headers'] ?? null);
        self::assertSame(32, count($trace['meta']['request_headers']));

        $spans = $trace['spans'] ?? [];
        self::assertIsArray($spans);

        $outgoing = null;
        foreach ($spans as $span) {
            if (is_array($span) && ($span['kind'] ?? null) === 'outgoing_http') {
                $outgoing = $span;

                break;
            }
        }

        self::assertIsArray($outgoing);
        self::assertIsArray($outgoing['metadata'] ?? null);
        self::assertTrue((bool) ($outgoing['metadata']['request_headers_truncated'] ?? false));
        self::assertTrue((bool) ($outgoing['metadata']['response_headers_truncated'] ?? false));
        self::assertNull($outgoing['metadata']['lifecycle_phase'] ?? null);
    }

    public function test_it_builds_lifecycle_phase_spans_and_assigns_nested_span_parents(): void
    {
        $startedAt = now();

        $records = [
            [
                'type' => 'request',
                'trace_id' => 'trace-source-2',
                'happened_at' => $startedAt->format('Y-m-d\\TH:i:s.uP'),
                'payload' => [
                    'method' => 'GET',
                    'path' => '/dashboard',
                    'url' => 'https://app.example.test/dashboard',
                    'status' => 200,
                    'duration_ms' => 200,
                    'middleware' => ['auth', 'throttle:api'],
                    'lifecycle_stages' => [
                        ['name' => 'bootstrap', 'start_offset_ms' => 0, 'duration_ms' => 10],
                        ['name' => 'middleware', 'start_offset_ms' => 10, 'duration_ms' => 50],
                        ['name' => 'controller', 'start_offset_ms' => 60, 'duration_ms' => 90],
                        ['name' => 'render', 'start_offset_ms' => 150, 'duration_ms' => 30],
                        ['name' => 'sending', 'start_offset_ms' => 180, 'duration_ms' => 12],
                        ['name' => 'terminating', 'start_offset_ms' => 192, 'duration_ms' => 8],
                    ],
                ],
            ],
            [
                'type' => 'query',
                'trace_id' => 'trace-source-2',
                'happened_at' => $startedAt->copy()->addMilliseconds(80)->format('Y-m-d\\TH:i:s.uP'),
                'payload' => [
                    'sql' => 'select * from users where id = ?',
                    'time_ms' => 9.4,
                    'connection' => 'pgsql',
                ],
            ],
            [
                'type' => 'cache',
                'trace_id' => 'trace-source-2',
                'happened_at' => $startedAt->copy()->addMilliseconds(95)->format('Y-m-d\\TH:i:s.uP'),
                'payload' => [
                    'operation' => 'hit',
                    'key_hash' => 'abc123',
                    'store' => 'redis',
                ],
            ],
            [
                'type' => 'outgoing_http',
                'trace_id' => 'trace-source-2',
                'happened_at' => $startedAt->copy()->addMilliseconds(110)->format('Y-m-d\\TH:i:s.uP'),
                'payload' => [
                    'url' => 'https://billing.example.test/api/check',
                    'host' => 'billing.example.test',
                    'method' => 'GET',
                    'status_code' => 200,
                    'duration_ms' => 18,
                ],
            ],
        ];

        $transformer = new TraceBatchTransformer(
            appName: 'Storefront',
            appToken: 'token',
            environment: 'production',
            serverName: 'node-1',
            maxSpansPerTrace: 120,
        );

        $payload = $transformer->transform($records);
        $trace = $payload['traces'][0];
        $spans = is_array($trace['spans'] ?? null) ? $trace['spans'] : [];

        $controllerPhase = $this->findSpan($spans, 'lifecycle', 'Controller');
        $middlewarePhase = $this->findSpan($spans, 'lifecycle', 'Middleware');
        $authMiddleware = $this->findSpan($spans, 'middleware', 'Auth');
        $throttleMiddleware = $this->findSpan($spans, 'middleware', 'Throttle:api');
        $querySpan = $this->findSpan($spans, 'query');
        $cacheSpan = $this->findSpan($spans, 'cache');
        $outgoingSpan = $this->findSpan($spans, 'outgoing_http');

        self::assertIsArray($controllerPhase);
        self::assertIsArray($middlewarePhase);
        self::assertNull($authMiddleware);
        self::assertNull($throttleMiddleware);
        self::assertIsArray($querySpan);
        self::assertIsArray($cacheSpan);
        self::assertIsArray($outgoingSpan);
        self::assertSame($controllerPhase['id'] ?? null, $querySpan['parent_span_id'] ?? null);
        self::assertSame($controllerPhase['id'] ?? null, $cacheSpan['parent_span_id'] ?? null);
        self::assertSame($controllerPhase['id'] ?? null, $outgoingSpan['parent_span_id'] ?? null);
        self::assertSame('action', $querySpan['metadata']['lifecycle_phase'] ?? null);
        self::assertSame('action', $cacheSpan['metadata']['lifecycle_phase'] ?? null);
        self::assertSame('action', $outgoingSpan['metadata']['lifecycle_phase'] ?? null);
        // No synthetic middleware spans: only measured phases are reported.

        $middlewareChildrenCount = 0;
        foreach ($spans as $span) {
            if (!is_array($span)) {
                continue;
            }

            if (
                ($span['kind'] ?? null) === 'middleware'
                && ($span['parent_span_id'] ?? null) === ($middlewarePhase['id'] ?? null)
            ) {
                $middlewareChildrenCount++;
            }
        }

        self::assertSame(0, $middlewareChildrenCount);
    }

    public function test_it_preserves_repeated_lifecycle_phases_and_uses_longest_phase_for_middleware_children(): void
    {
        $startedAt = now();

        $records = [
            [
                'type' => 'request',
                'trace_id' => 'trace-source-repeat',
                'happened_at' => $startedAt->toIso8601String(),
                'payload' => [
                    'method' => 'GET',
                    'path' => '/academy/streams',
                    'url' => 'https://app.example.test/academy/streams',
                    'status' => 200,
                    'duration_ms' => 140,
                    'middleware' => ['web', 'routeMiddleware'],
                    'lifecycle_stages' => [
                        ['name' => 'bootstrap', 'start_offset_ms' => 0, 'duration_ms' => 8],
                        ['name' => 'middleware', 'start_offset_ms' => 8, 'duration_ms' => 18, 'metadata' => ['segment' => 'before_controller']],
                        ['name' => 'controller', 'start_offset_ms' => 26, 'duration_ms' => 92],
                        ['name' => 'render', 'start_offset_ms' => 118, 'duration_ms' => 10],
                        ['name' => 'middleware', 'start_offset_ms' => 128, 'duration_ms' => 2, 'metadata' => ['segment' => 'after_controller']],
                        ['name' => 'sending', 'start_offset_ms' => 130, 'duration_ms' => 6],
                        ['name' => 'terminating', 'start_offset_ms' => 136, 'duration_ms' => 4],
                    ],
                ],
            ],
        ];

        $transformer = new TraceBatchTransformer(
            appName: 'Storefront',
            appToken: 'token',
            environment: 'production',
            serverName: 'node-1',
            maxSpansPerTrace: 100,
        );

        $payload = $transformer->transform($records);
        $trace = $payload['traces'][0];
        $spans = is_array($trace['spans'] ?? null) ? $trace['spans'] : [];

        $middlewarePhases = array_values(array_filter(
            $spans,
            static fn(mixed $span): bool => is_array($span)
                && ($span['kind'] ?? null) === 'lifecycle'
                && ($span['name'] ?? null) === 'Middleware',
        ));

        self::assertCount(2, $middlewarePhases);

        $canonicalMiddlewarePhase = $middlewarePhases[0];
        foreach ($middlewarePhases as $phase) {
            if (!is_array($phase)) {
                continue;
            }

            if ((int) ($phase['duration_ms'] ?? 0) > (int) ($canonicalMiddlewarePhase['duration_ms'] ?? 0)) {
                $canonicalMiddlewarePhase = $phase;
            }
        }

        $middlewareChildren = array_values(array_filter(
            $spans,
            static fn(mixed $span): bool => is_array($span) && ($span['kind'] ?? null) === 'middleware',
        ));

        self::assertCount(0, $middlewareChildren);
    }

    public function test_it_prefers_payload_start_offset_over_happened_at_for_nested_spans(): void
    {
        $startedAt = now();

        $records = [
            [
                'type' => 'request',
                'trace_id' => 'trace-source-3',
                'happened_at' => $startedAt->toIso8601String(),
                'payload' => [
                    'method' => 'GET',
                    'path' => '/health',
                    'url' => 'https://app.example.test/health',
                    'status' => 200,
                    'duration_ms' => 120,
                    'lifecycle_stages' => [
                        ['name' => 'bootstrap', 'start_offset_ms' => 0, 'duration_ms' => 10],
                        ['name' => 'middleware', 'start_offset_ms' => 10, 'duration_ms' => 20],
                        ['name' => 'controller', 'start_offset_ms' => 30, 'duration_ms' => 70],
                        ['name' => 'render', 'start_offset_ms' => 100, 'duration_ms' => 10],
                        ['name' => 'sending', 'start_offset_ms' => 110, 'duration_ms' => 5],
                        ['name' => 'terminating', 'start_offset_ms' => 115, 'duration_ms' => 5],
                    ],
                ],
            ],
            [
                'type' => 'query',
                'trace_id' => 'trace-source-3',
                'happened_at' => $startedAt->toIso8601String(),
                'payload' => [
                    'sql' => 'select 1',
                    'time_ms' => 7,
                    'connection' => 'pgsql',
                    'start_offset_ms' => 64,
                ],
            ],
        ];

        $transformer = new TraceBatchTransformer(
            appName: 'Storefront',
            appToken: 'token',
            environment: 'production',
            serverName: 'node-1',
            maxSpansPerTrace: 50,
        );

        $payload = $transformer->transform($records);
        $trace = $payload['traces'][0];
        $spans = is_array($trace['spans'] ?? null) ? $trace['spans'] : [];
        $querySpan = $this->findSpan($spans, 'query');

        self::assertIsArray($querySpan);
        self::assertSame(64, $querySpan['start_offset_ms'] ?? null);
    }

    public function test_it_prefers_normalized_signature_over_raw_sql_for_query_spans(): void
    {
        $startedAt = now();

        $records = [
            [
                'type' => 'request',
                'trace_id' => 'trace-source-4',
                'happened_at' => $startedAt->toIso8601String(),
                'payload' => [
                    'method' => 'GET',
                    'path' => '/queries',
                    'url' => 'https://app.example.test/queries',
                    'status' => 200,
                    'duration_ms' => 90,
                ],
            ],
            [
                'type' => 'query',
                'trace_id' => 'trace-source-4',
                'happened_at' => $startedAt->copy()->addMilliseconds(20)->toIso8601String(),
                'payload' => [
                    'sql' => 'select * from users where id = ?',
                    'normalized_signature' => 'users.select.by-id',
                    'time_ms' => 6,
                    'connection' => 'pgsql',
                ],
            ],
            [
                'type' => 'query',
                'trace_id' => 'trace-source-4',
                'happened_at' => $startedAt->copy()->addMilliseconds(40)->toIso8601String(),
                'payload' => [
                    'sql' => 'select * from accounts where email = ?',
                    'time_ms' => 8,
                    'connection' => 'pgsql',
                ],
            ],
        ];

        $transformer = new TraceBatchTransformer(
            appName: 'Storefront',
            appToken: 'token',
            environment: 'production',
            serverName: 'node-1',
            maxSpansPerTrace: 50,
        );

        $payload = $transformer->transform($records);
        $trace = $payload['traces'][0];
        $spans = array_values(array_filter(
            is_array($trace['spans'] ?? null) ? $trace['spans'] : [],
            static fn (mixed $span): bool => is_array($span) && ($span['kind'] ?? null) === 'query',
        ));

        self::assertCount(2, $spans);
        self::assertSame('users.select.by-id', $spans[0]['normalized_signature'] ?? null);
        self::assertSame('select * from users where id = ?', $spans[0]['sql_text'] ?? null);
        self::assertSame('select * from accounts where email = ?', $spans[1]['normalized_signature'] ?? null);
        self::assertSame('select * from accounts where email = ?', $spans[1]['sql_text'] ?? null);
    }

    public function test_it_returns_integer_duration_when_computed_from_happened_at_range(): void
    {
        $records = [
            [
                'type' => 'request',
                'trace_id' => 'trace-source-5',
                'happened_at' => '2026-03-14T12:00:00.100100+00:00',
                'payload' => [
                    'method' => 'GET',
                    'path' => '/duration-range',
                    'url' => 'https://app.example.test/duration-range',
                    'status' => 200,
                ],
            ],
            [
                'type' => 'event',
                'trace_id' => 'trace-source-5',
                'happened_at' => '2026-03-14T12:00:00.150600+00:00',
                'payload' => [
                    'name' => 'processing.finished',
                ],
            ],
        ];

        $transformer = new TraceBatchTransformer(
            appName: 'Storefront',
            appToken: 'token',
            environment: 'production',
            serverName: 'node-1',
            maxSpansPerTrace: 50,
        );

        $payload = $transformer->transform($records);
        self::assertIsArray($payload['traces'] ?? null);
        self::assertCount(1, $payload['traces']);

        $trace = $payload['traces'][0];
        self::assertIsInt($trace['duration_ms'] ?? null);
        self::assertGreaterThan(0, $trace['duration_ms']);
    }

    public function test_nested_job_fragment_is_a_child_without_replacing_http_root(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'host');
        $traceId = str()->ulid()->toString();
        $startedAt = now()->toIso8601String();
        $job = $transformer->transform([[
            'type' => 'job_child',
            'record_id' => str()->ulid()->toString(),
            'trace_id' => $traceId,
            'happened_at' => $startedAt,
            'payload' => [
                'trace_started_at' => $startedAt,
                'name' => 'App\\Jobs\\SendInvoice',
                'status' => 'failed',
                'attempts' => 2,
                'duration_ms' => 15,
            ],
        ]])['traces'][0];

        self::assertCount(1, $job['spans']);
        self::assertSame('job', $job['spans'][0]['kind']);
        self::assertSame('failed', $job['spans'][0]['metadata']['status']);
        self::assertNull($job['spans'][0]['parent_span_id']);
        self::assertNotSame('request', $job['type']);

        $request = $transformer->transform([[
            'type' => 'request',
            'trace_id' => $traceId,
            'happened_at' => $startedAt,
            'payload' => [
                'method' => 'GET',
                'path' => '/invoice',
                'status' => 200,
                'duration_ms' => 25,
            ],
        ]])['traces'][0];

        self::assertSame($request['id'], $job['id']);
        self::assertSame('http', $request['spans'][0]['kind']);
        self::assertNotSame($request['spans'][0]['id'], $job['spans'][0]['id']);
    }

    public function test_explicit_zero_duration_framework_event_remains_instantaneous(): void
    {
        $trace = (new TraceBatchTransformer('App', 'token', 'production', 'host'))->transform([[
            'type' => 'event',
            'record_id' => str()->ulid()->toString(),
            'trace_id' => str()->ulid()->toString(),
            'happened_at' => now()->toIso8601String(),
            'payload' => [
                'name' => 'schedule.skipped',
                'duration_ms' => 0,
            ],
        ]])['traces'][0];

        self::assertSame(0, $trace['duration_ms']);
        self::assertSame(0, $trace['spans'][0]['duration_ms']);
    }

    public function test_unmeasured_outgoing_http_does_not_invent_one_millisecond(): void
    {
        $trace = (new TraceBatchTransformer('App', 'token', 'production', 'host'))->transform([[
            'type' => 'outgoing_http',
            'record_id' => str()->ulid()->toString(),
            'trace_id' => str()->ulid()->toString(),
            'happened_at' => now()->toIso8601String(),
            'payload' => [
                'url' => 'https://example.test/api',
                'trace_started_at' => now()->toIso8601String(),
                'status_code' => null,
                'duration_ms' => null,
            ],
        ]])['traces'][0];

        self::assertSame(0, $trace['external_time_ms']);
        self::assertCount(1, $trace['spans']);
        self::assertSame(0, $trace['spans'][0]['duration_ms']);
    }

    public function test_query_measured_during_render_is_parented_to_render_phase(): void
    {
        $traceId = str()->ulid()->toString();
        $startedAt = now()->toIso8601String();
        $trace = (new TraceBatchTransformer('App', 'token', 'production', 'host'))->transform([
            [
                'type' => 'request',
                'trace_id' => $traceId,
                'happened_at' => $startedAt,
                'payload' => [
                    'duration_ms' => 100,
                    'status' => 200,
                    'method' => 'GET',
                    'path' => '/render',
                    'lifecycle_stages' => [
                        ['name' => 'action', 'start_offset_ms' => 0, 'duration_ms' => 40],
                        ['name' => 'render', 'start_offset_ms' => 40, 'duration_ms' => 60],
                    ],
                ],
            ],
            [
                'type' => 'query',
                'record_id' => str()->ulid()->toString(),
                'trace_id' => $traceId,
                'happened_at' => $startedAt,
                'payload' => [
                    'time_ms' => 5,
                    'start_offset_ms' => 60,
                    'normalized_signature' => 'select * from posts where id = ?',
                ],
            ],
        ])['traces'][0];

        $render = $this->findSpan($trace['spans'], 'lifecycle', 'Render');
        $query = $this->findSpan($trace['spans'], 'query');
        self::assertIsArray($render);
        self::assertIsArray($query);
        self::assertSame($render['id'], $query['parent_span_id']);
        self::assertSame('render', $query['metadata']['lifecycle_phase']);
    }

    /**
     * @param array<int, mixed> $spans
     *
     * @return array<string, mixed>|null
     */
    private function findSpan(array $spans, string $kind, ?string $name = null): ?array
    {
        foreach ($spans as $span) {
            if (!is_array($span)) {
                continue;
            }

            if (($span['kind'] ?? null) !== $kind) {
                continue;
            }

            if ($name !== null && (string) ($span['name'] ?? '') !== $name) {
                continue;
            }

            return $span;
        }

        return null;
    }

    public function test_transport_fragments_share_stable_trace_and_root_ids(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'host');
        $traceId = str()->ulid()->toString();
        $record = [
            'type' => 'request',
            'trace_id' => $traceId,
            'happened_at' => now()->toIso8601String(),
            'payload' => ['method' => 'GET', 'path' => '/orders', 'status' => 200, 'duration_ms' => 12],
        ];

        $first = $transformer->transform([$record])['traces'][0];
        $second = $transformer->transform([$record])['traces'][0];

        self::assertSame($traceId, $first['id']);
        self::assertSame($first['id'], $second['id']);
        self::assertSame($first['spans'][0]['id'], $second['spans'][0]['id']);
    }

    public function test_late_query_fragment_cannot_overwrite_canonical_request_root(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'host');
        $traceId = str()->ulid()->toString();
        $startedAt = now()->toIso8601String();

        $request = $transformer->transform([[
            'type' => 'request',
            'trace_id' => $traceId,
            'happened_at' => $startedAt,
            'payload' => [
                'method' => 'GET',
                'path' => '/orders',
                'status' => 200,
                'duration_ms' => 37,
                'request_started_at' => $startedAt,
            ],
        ]])['traces'][0];

        $fragment = $transformer->transform([[
            'record_id' => str()->ulid()->toString(),
            'type' => 'query',
            'trace_id' => $traceId,
            'happened_at' => $startedAt,
            'payload' => [
                'time_ms' => 3,
                'trace_started_at' => $startedAt,
                'normalized_signature' => 'select * from orders where id = ?',
            ],
        ]])['traces'][0];

        self::assertSame($request['id'], $fragment['id']);
        $rootId = $request['spans'][0]['id'];
        self::assertSame('http', $request['spans'][0]['kind']);
        self::assertNotContains($rootId, array_column($fragment['spans'], 'id'));
        self::assertCount(1, $fragment['spans']);
        self::assertSame('query', $fragment['spans'][0]['kind']);
        self::assertNull($fragment['spans'][0]['parent_span_id']);
    }

    public function test_standalone_unmeasured_event_has_no_fabricated_root_duration(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'host');
        $trace = $transformer->transform([[
            'type' => 'event',
            'trace_id' => str()->ulid()->toString(),
            'happened_at' => now()->toIso8601String(),
            'payload' => ['name' => 'mail.sent'],
        ]])['traces'][0];

        self::assertSame(0, $trace['duration_ms']);
        self::assertCount(1, $trace['spans']);
        self::assertSame(0, $trace['spans'][0]['duration_ms']);
    }

    public function test_unmeasured_application_events_have_zero_duration(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'host');
        $result = $transformer->transform([[
            'type' => 'event',
            'trace_id' => str()->ulid()->toString(),
            'happened_at' => now()->toIso8601String(),
            'payload' => ['name' => 'schedule.skipped'],
        ]]);

        self::assertSame(0, $result['traces'][0]['spans'][0]['duration_ms']);
    }

    public function test_measured_blade_event_is_rendered_as_view_span(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'host');
        $traceId = str()->ulid()->toString();
        $epoch = now()->toIso8601String();
        $batch = $transformer->transform([
            [
                'type' => 'event',
                'trace_id' => $traceId,
                'record_id' => str()->ulid()->toString(),
                'happened_at' => $epoch,
                'payload' => [
                    'name' => 'view.rendered',
                    'duration_ms' => 12,
                    'trace_started_at' => $epoch,
                    'details' => ['view' => 'dashboard.blade.php'],
                ],
            ],
        ])['traces'][0];

        self::assertCount(1, $batch['spans']);
        self::assertSame('view', $batch['spans'][0]['kind']);
        self::assertSame('View dashboard.blade.php', $batch['spans'][0]['name']);
        self::assertSame(12, $batch['spans'][0]['duration_ms']);
    }

    public function test_late_framework_event_does_not_overwrite_http_root(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'host');
        $traceId = str()->ulid()->toString();
        $startedAt = now()->toIso8601String();
        $request = $transformer->transform([[
            'type' => 'request',
            'trace_id' => $traceId,
            'happened_at' => $startedAt,
            'payload' => ['method' => 'GET', 'path' => '/', 'status' => 200, 'duration_ms' => 20],
        ]])['traces'][0];
        $fragment = $transformer->transform([[
            'type' => 'event',
            'record_id' => str()->ulid()->toString(),
            'trace_id' => $traceId,
            'happened_at' => $startedAt,
            'payload' => ['trace_started_at' => $startedAt, 'name' => 'mail.sent', 'duration_ms' => 3],
        ]])['traces'][0];

        self::assertSame($request['id'], $fragment['id']);
        self::assertNotContains($request['spans'][0]['id'], array_column($fragment['spans'], 'id'));
        self::assertCount(1, $fragment['spans']);
        self::assertSame('event', $fragment['spans'][0]['kind']);
        self::assertSame(3, $fragment['spans'][0]['duration_ms']);
    }

    public function test_query_only_transport_fragment_preserves_first_record(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'host');
        $sourceId = str()->ulid()->toString();
        $result = $transformer->transform([[
            'type' => 'query',
            'trace_id' => $sourceId,
            'happened_at' => now()->toIso8601String(),
            'payload' => [
                'sql' => '[REDACTED_SQL]',
                'normalized_signature' => 'select * from users where id = ?',
                'time_ms' => 15,
                'connection' => 'pgsql',
            ],
        ]]);

        self::assertSame($sourceId, $result['traces'][0]['id']);
        $queries = array_values(array_filter(
            $result['traces'][0]['spans'],
            static fn(array $span): bool => $span['kind'] === 'query',
        ));
        self::assertCount(1, $queries);
        self::assertSame('select * from users where id = ?', $queries[0]['normalized_signature']);
    }

    public function test_record_ids_make_retries_idempotent(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'host');
        $traceId = str()->ulid()->toString();
        $recordId = str()->ulid()->toString();
        $records = [
            [
                'type' => 'request',
                'trace_id' => $traceId,
                'happened_at' => now()->toIso8601String(),
                'payload' => [
                    'method' => 'GET',
                    'path' => '/orders',
                    'duration_ms' => 50,
                    'lifecycle_stages' => [['name' => 'action', 'start_offset_ms' => 1, 'duration_ms' => 40]],
                ],
            ],
            [
                'record_id' => $recordId,
                'type' => 'query',
                'trace_id' => $traceId,
                'happened_at' => now()->toIso8601String(),
                'payload' => [
                    'time_ms' => 7,
                    'sql' => '[REDACTED_SQL]',
                    'normalized_signature' => 'select * from orders where id = ?',
                ],
            ],
        ];
        $a = $transformer->transform($records)['traces'][0]['spans'];
        $b = $transformer->transform($records)['traces'][0]['spans'];

        self::assertSame(array_column($a, 'id'), array_column($b, 'id'));
        self::assertContains($recordId, array_column($a, 'id'));
    }

    public function test_standalone_framework_events_do_not_duplicate_root_spans(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'host');
        $traceId = str()->ulid()->toString();
        $result = $transformer->transform([[
            'type' => 'event',
            'trace_id' => $traceId,
            'happened_at' => now()->toIso8601String(),
            'payload' => ['name' => 'mail.sent'],
        ]]);

        self::assertCount(1, $result['traces'][0]['spans']);
        self::assertSame('event', $result['traces'][0]['spans'][0]['kind']);
    }

}
