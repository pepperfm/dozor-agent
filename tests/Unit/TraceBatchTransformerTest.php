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
                'happened_at' => $startedAt->toIso8601String(),
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
                'happened_at' => $startedAt->copy()->addMilliseconds(80)->toIso8601String(),
                'payload' => [
                    'sql' => 'select * from users where id = ?',
                    'time_ms' => 9.4,
                    'connection' => 'pgsql',
                ],
            ],
            [
                'type' => 'cache',
                'trace_id' => 'trace-source-2',
                'happened_at' => $startedAt->copy()->addMilliseconds(95)->toIso8601String(),
                'payload' => [
                    'operation' => 'hit',
                    'key_hash' => 'abc123',
                    'store' => 'redis',
                ],
            ],
            [
                'type' => 'outgoing_http',
                'trace_id' => 'trace-source-2',
                'happened_at' => $startedAt->copy()->addMilliseconds(110)->toIso8601String(),
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
        self::assertIsArray($authMiddleware);
        self::assertIsArray($throttleMiddleware);
        self::assertIsArray($querySpan);
        self::assertIsArray($cacheSpan);
        self::assertIsArray($outgoingSpan);
        self::assertSame($controllerPhase['id'] ?? null, $querySpan['parent_span_id'] ?? null);
        self::assertSame($controllerPhase['id'] ?? null, $cacheSpan['parent_span_id'] ?? null);
        self::assertSame($controllerPhase['id'] ?? null, $outgoingSpan['parent_span_id'] ?? null);
        self::assertSame('action', $querySpan['metadata']['lifecycle_phase'] ?? null);
        self::assertSame('action', $cacheSpan['metadata']['lifecycle_phase'] ?? null);
        self::assertSame('action', $outgoingSpan['metadata']['lifecycle_phase'] ?? null);
        self::assertSame('Auth', $authMiddleware['name'] ?? null);
        self::assertSame('Throttle:api', $throttleMiddleware['name'] ?? null);

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

        self::assertSame(2, $middlewareChildrenCount);
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

        self::assertCount(2, $middlewareChildren);
        self::assertSame(
            $canonicalMiddlewarePhase['id'] ?? null,
            $middlewareChildren[0]['parent_span_id'] ?? null,
        );
        self::assertSame(
            $canonicalMiddlewarePhase['id'] ?? null,
            $middlewareChildren[1]['parent_span_id'] ?? null,
        );
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
}
