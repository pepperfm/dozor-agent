<?php

declare(strict_types=1);

namespace Dozor\Tracing;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

use function array_values;
use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function json_encode;
use function max;
use function mb_substr;
use function mb_strtoupper;
use function mb_strlen;
use function round;

final class TraceBatchTransformer
{
    public function __construct(
        private readonly string $appName,
        private readonly string $appToken,
        private readonly string $environment,
        private readonly string $serverName,
        private readonly int $maxSpansPerTrace = 200,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $records
     *
     * @return array<string, mixed>
     */
    public function transform(array $records): array
    {
        $grouped = $this->groupBySourceTrace($records);
        $traces = [];

        foreach ($grouped as $sourceTraceId => $group) {
            $trace = $this->transformGroup((string) $sourceTraceId, $group);
            if ($trace === null) {
                continue;
            }

            $traces[] = $trace;
        }

        $payload = [
            'v' => 1,
            'app' => [
                'name' => $this->appName,
                'token' => $this->appToken,
            ],
            'environment' => $this->environment,
            'server' => $this->serverName,
            'sent_at' => now()->toIso8601String(),
            'traces' => $traces,
        ];

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupBySourceTrace(array $records): array
    {
        $grouped = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                continue;
            }

            $sourceTraceId = Arr::get($record, 'trace_id');
            $groupKey = is_string($sourceTraceId) && $sourceTraceId !== ''
                ? $sourceTraceId
                : 'missing-trace-' . $index;

            $grouped[$groupKey] ??= [];
            $grouped[$groupKey][] = $record;
        }

        return $grouped;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     *
     * @return array<string, mixed>|null
     */
    private function transformGroup(string $sourceTraceId, array $records): ?array
    {
        if ($records === []) {
            return null;
        }

        $rootRecord = $this->resolveRootRecord($records);
        $rootPayload = Arr::get($rootRecord, 'payload', []);
        $rootType = (string) Arr::get($rootRecord, 'type', 'custom');
        $startedAt = $this->resolveStartedAt($rootRecord, $records);
        $dbTimeMs = $this->sumQueryTime($records);
        $externalTimeMs = $this->sumOutgoingHttpTime($records);
        $jobTimeMs = $this->sumJobTime($records);
        $durationMs = $this->resolveDurationMs($rootRecord, $records, $dbTimeMs, $externalTimeMs, $jobTimeMs);
        $traceId = str()->ulid()->toString();
        $spans = [];
        $droppedSpans = 0;

        $rootSpan = $this->buildRootSpan($rootRecord, $rootType, $startedAt);
        if ($rootSpan !== null) {
            $spans[] = $rootSpan;
        }

        $rootSpanId = is_array($rootSpan) && is_string(Arr::get($rootSpan, 'id'))
            ? (string) Arr::get($rootSpan, 'id')
            : null;
        [
            'spans' => $lifecycleSpans,
            'phase_span_ids' => $phaseSpanIds,
            'phase_ranges' => $phaseRanges,
        ] = $this->buildLifecyclePhaseSpans($rootPayload, $durationMs, $rootSpanId, $rootType);

        foreach ($lifecycleSpans as $lifecycleSpan) {
            if (count($spans) >= $this->maxSpansPerTrace) {
                $droppedSpans++;

                break;
            }

            $spans[] = $lifecycleSpan;
        }

        $traceException = $this->extractException($rootRecord);

        foreach ($records as $record) {
            if ($record === $rootRecord) {
                continue;
            }

            $type = (string) Arr::get($record, 'type', 'custom');
            $recordPayload = Arr::get($record, 'payload', []);
            $recordDurationMs = is_array($recordPayload)
                ? $this->resolveRecordDurationMs($type, $recordPayload)
                : 1;
            $happenedAt = Arr::get($record, 'happened_at');
            $timestamp = Arr::get($record, 'timestamp');
            $offsetMs = $this->resolveOffsetMs(
                is_string($happenedAt) ? $happenedAt : null,
                $startedAt,
                is_array($recordPayload) ? $recordPayload : [],
                is_numeric($timestamp) ? (float) $timestamp : null,
            );
            [
                'span_id' => $parentSpanId,
                'phase' => $phaseName,
            ] = $this->resolveParentSpanForRecord(
                $type,
                $offsetMs,
                $recordDurationMs,
                $phaseSpanIds,
                $phaseRanges,
                $rootSpanId,
            );

            $span = $this->buildSpanFromRecord($record, $offsetMs, $parentSpanId);
            if ($span === null) {
                continue;
            }

            if ($phaseName !== null) {
                $metadata = Arr::get($span, 'metadata', []);

                if (is_array($metadata)) {
                    $metadata['lifecycle_phase'] = $phaseName;
                    $span['metadata'] = $metadata;
                }
            }

            if (count($spans) >= $this->maxSpansPerTrace) {
                $droppedSpans++;

                continue;
            }

            $spans[] = $span;

            if ($traceException === null) {
                $traceException = $this->extractException($record);
            }
        }
        $requestId = Arr::get($rootPayload, 'request_id');
        $release = Arr::get($rootRecord, 'release', Arr::get($rootRecord, 'deployment'));
        $controller = $this->resolveController($rootPayload);
        $middleware = $this->resolveMiddleware($rootPayload);
        $hasLifecycle = count($phaseSpanIds) > 0;
        ['headers' => $requestHeaders, 'truncated' => $requestHeadersTruncated] = $this->boundedHeaders(
            Arr::get($rootPayload, 'headers', []),
        );
        ['preview' => $requestPayloadPreview, 'truncated' => $requestPayloadPreviewTruncated] = $this->boundedPayloadPreview(
            Arr::get($rootPayload, 'request_payload'),
        );

        return [
            'id' => $traceId,
            'type' => $rootType,
            'started_at' => $startedAt,
            'method' => $this->resolveMethod($rootRecord),
            'route' => $this->resolveRoute($rootRecord),
            'uri' => $this->resolveUri($rootRecord),
            'controller' => $controller,
            'host' => $this->resolveHost($rootRecord),
            'status_code' => $this->resolveStatusCode($rootRecord),
            'duration_ms' => $durationMs,
            'db_time_ms' => $dbTimeMs,
            'external_time_ms' => $externalTimeMs,
            'job_time_ms' => $jobTimeMs,
            'cpu_time_ms' => (int) Arr::get($rootPayload, 'cpu_time_ms', 0),
            'memory_mb' => $this->resolveMemoryMb($rootPayload),
            'request_id' => is_string($requestId) ? $requestId : null,
            'release' => is_string($release) ? $release : null,
            'user_label' => $this->resolveUserLabel($rootPayload),
            'tenant_label' => null,
            'deployment_id' => null,
            'spans' => $spans,
            'tags' => [],
            'meta' => [
                'source_trace_id' => $sourceTraceId,
                'records_count' => count($records),
                'middleware' => $middleware,
                'route_name' => Arr::get($rootPayload, 'route_name'),
                'request_headers' => $requestHeaders,
                'request_headers_truncated' => $requestHeadersTruncated,
                'request_payload_preview' => $requestPayloadPreview,
                'request_payload_preview_truncated' => $requestPayloadPreviewTruncated,
                'lifecycle_ready' => $hasLifecycle,
                'lifecycle_stages' => Arr::get($rootPayload, 'lifecycle_stages', []),
            ],
            'exception' => $traceException,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $records
     *
     * @return array<string, mixed>
     */
    private function resolveRootRecord(array $records): array
    {
        foreach (['request', 'command', 'job'] as $rootType) {
            foreach ($records as $record) {
                if ((string) Arr::get($record, 'type', '') === $rootType) {
                    return $record;
                }
            }
        }

        return $records[0];
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function resolveStartedAt(array $rootRecord, array $records): string
    {
        $rootTimestamp = Arr::get($rootRecord, 'timestamp');
        if (is_numeric($rootTimestamp)) {
            try {
                return Carbon::createFromTimestamp((float) $rootTimestamp)->toIso8601String();
            } catch (Throwable) {
                // fall through to happened_at path
            }
        }

        $rootHappenedAt = Arr::get($rootRecord, 'happened_at');
        if (is_string($rootHappenedAt) && $rootHappenedAt !== '') {
            return $rootHappenedAt;
        }

        foreach ($records as $record) {
            $timestamp = Arr::get($record, 'timestamp');
            if (is_numeric($timestamp)) {
                try {
                    return Carbon::createFromTimestamp((float) $timestamp)->toIso8601String();
                } catch (Throwable) {
                    // fall through to happened_at path
                }
            }

            $happenedAt = Arr::get($record, 'happened_at');
            if (is_string($happenedAt) && $happenedAt !== '') {
                return $happenedAt;
            }
        }

        return now()->toIso8601String();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildRootSpan(array $rootRecord, string $rootType, string $startedAt): ?array
    {
        $payload = Arr::get($rootRecord, 'payload', []);
        if (!is_array($payload)) {
            return null;
        }

        return [
            'id' => str()->ulid()->toString(),
            'parent_span_id' => null,
            'kind' => match ($rootType) {
                'request' => 'http',
                'job' => 'job',
                'command' => 'command',
                'heartbeat' => 'heartbeat',
                'outgoing_http' => 'outgoing_http',
                'event' => 'event',
                'log' => 'log',
                default => 'internal',
            },
            'name' => $this->resolveRootSpanName($rootType, $payload),
            'start_offset_ms' => 0,
            'duration_ms' => max(1, (int) round($this->resolveNumeric($payload, ['duration_ms', 'time_ms'], 1))),
            'connection' => null,
            'normalized_signature' => null,
            'sql_text' => null,
            'rows_count' => null,
            'service' => null,
            'queue' => is_string(Arr::get($payload, 'queue')) ? Arr::get($payload, 'queue') : null,
            'method' => $this->resolveRootSpanMethod($rootType, $payload),
            'status_code' => $this->resolveRootSpanStatusCode($rootType, $payload),
            'metadata' => [
                'source_type' => $rootType,
                'started_at' => $startedAt,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildSpanFromRecord(array $record, int $offsetMs, ?string $parentSpanId): ?array
    {
        $type = (string) Arr::get($record, 'type', 'custom');
        $payload = Arr::get($record, 'payload', []);
        if (!is_array($payload)) {
            return null;
        }

        return match ($type) {
            'outgoing_http' => [
                'id' => str()->ulid()->toString(),
                'parent_span_id' => $parentSpanId,
                'kind' => 'outgoing_http',
                'name' => (string) Arr::get($payload, 'url', 'outgoing_http'),
                'start_offset_ms' => $offsetMs,
                'duration_ms' => max(1, (int) round($this->resolveNumeric($payload, ['duration_ms'], 1))),
                'connection' => null,
                'normalized_signature' => null,
                'sql_text' => null,
                'rows_count' => null,
                'service' => is_string(Arr::get($payload, 'host')) ? Arr::get($payload, 'host') : null,
                'queue' => null,
                'method' => is_string(Arr::get($payload, 'method')) ? Arr::get($payload, 'method') : null,
                'status_code' => is_numeric(Arr::get($payload, 'status_code')) ? (int) Arr::get($payload, 'status_code') : null,
                'metadata' => (function () use ($payload): array {
                    ['headers' => $requestHeaders, 'truncated' => $requestHeadersTruncated] = $this->boundedHeaders(
                        Arr::get($payload, 'request_headers', []),
                    );
                    ['headers' => $responseHeaders, 'truncated' => $responseHeadersTruncated] = $this->boundedHeaders(
                        Arr::get($payload, 'response_headers', []),
                    );

                    return [
                        'url' => Arr::get($payload, 'url'),
                        'failed' => (bool) Arr::get($payload, 'failed', false),
                        'error_class' => Arr::get($payload, 'error_class'),
                        'error_message' => Arr::get($payload, 'error_message'),
                        'request_headers' => $requestHeaders,
                        'request_headers_truncated' => $requestHeadersTruncated,
                        'response_headers' => $responseHeaders,
                        'response_headers_truncated' => $responseHeadersTruncated,
                    ];
                })(),
            ],
            'query' => [
                'id' => str()->ulid()->toString(),
                'parent_span_id' => $parentSpanId,
                'kind' => 'query',
                'name' => $this->querySpanName($payload),
                'start_offset_ms' => $offsetMs,
                'duration_ms' => max(1, (int) round($this->resolveNumeric($payload, ['time_ms'], 1))),
                'connection' => is_string(Arr::get($payload, 'connection')) ? Arr::get($payload, 'connection') : null,
                'normalized_signature' => $this->resolveQueryNormalizedSignature($payload),
                'sql_text' => is_string(Arr::get($payload, 'sql')) ? Arr::get($payload, 'sql') : null,
                'rows_count' => is_numeric(Arr::get($payload, 'rows_count')) ? (int) Arr::get($payload, 'rows_count') : null,
                'service' => null,
                'queue' => null,
                'method' => null,
                'status_code' => null,
                'metadata' => [
                    'bindings_count' => (int) Arr::get($payload, 'bindings_count', 0),
                ],
            ],
            'job' => [
                'id' => str()->ulid()->toString(),
                'parent_span_id' => $parentSpanId,
                'kind' => 'job',
                'name' => (string) Arr::get($payload, 'name', 'job'),
                'start_offset_ms' => $offsetMs,
                'duration_ms' => max(1, (int) round($this->resolveNumeric($payload, ['duration_ms'], 1))),
                'connection' => is_string(Arr::get($payload, 'connection')) ? Arr::get($payload, 'connection') : null,
                'normalized_signature' => null,
                'sql_text' => null,
                'rows_count' => null,
                'service' => null,
                'queue' => is_string(Arr::get($payload, 'queue')) ? Arr::get($payload, 'queue') : null,
                'method' => null,
                'status_code' => null,
                'metadata' => [
                    'status' => Arr::get($payload, 'status'),
                ],
            ],
            'cache' => [
                'id' => str()->ulid()->toString(),
                'parent_span_id' => $parentSpanId,
                'kind' => 'cache',
                'name' => 'Cache ' . $this->formatDisplayLabel((string) Arr::get($payload, 'operation', 'unknown')),
                'start_offset_ms' => $offsetMs,
                'duration_ms' => max(1, (int) round($this->resolveNumeric($payload, ['duration_ms'], 1))),
                'connection' => null,
                'normalized_signature' => null,
                'sql_text' => null,
                'rows_count' => null,
                'service' => is_string(Arr::get($payload, 'store')) ? Arr::get($payload, 'store') : null,
                'queue' => null,
                'method' => null,
                'status_code' => null,
                'metadata' => [
                    'operation' => Arr::get($payload, 'operation'),
                    'key_hash' => Arr::get($payload, 'key_hash'),
                    'store' => Arr::get($payload, 'store'),
                ],
            ],
            'exception' => [
                'id' => str()->ulid()->toString(),
                'parent_span_id' => $parentSpanId,
                'kind' => 'exception',
                'name' => (string) Arr::get($payload, 'class', 'exception'),
                'start_offset_ms' => $offsetMs,
                'duration_ms' => 1,
                'connection' => null,
                'normalized_signature' => null,
                'sql_text' => null,
                'rows_count' => null,
                'service' => null,
                'queue' => null,
                'method' => null,
                'status_code' => null,
                'metadata' => [
                    'message' => Arr::get($payload, 'message'),
                ],
            ],
            'event' => [
                'id' => str()->ulid()->toString(),
                'parent_span_id' => $parentSpanId,
                'kind' => 'event',
                'name' => (string) Arr::get($payload, 'name', Arr::get($payload, 'event_name', 'event')),
                'start_offset_ms' => $offsetMs,
                'duration_ms' => 1,
                'connection' => null,
                'normalized_signature' => null,
                'sql_text' => null,
                'rows_count' => null,
                'service' => null,
                'queue' => null,
                'method' => null,
                'status_code' => null,
                'metadata' => $payload,
            ],
            'heartbeat' => [
                'id' => str()->ulid()->toString(),
                'parent_span_id' => $parentSpanId,
                'kind' => 'heartbeat',
                'name' => 'agent.heartbeat',
                'start_offset_ms' => $offsetMs,
                'duration_ms' => 1,
                'connection' => null,
                'normalized_signature' => null,
                'sql_text' => null,
                'rows_count' => null,
                'service' => null,
                'queue' => null,
                'method' => null,
                'status_code' => null,
                'metadata' => $payload,
            ],
            default => [
                'id' => str()->ulid()->toString(),
                'parent_span_id' => $parentSpanId,
                'kind' => 'log',
                'name' => (string) Arr::get($record, 'type', 'custom'),
                'start_offset_ms' => $offsetMs,
                'duration_ms' => max(1, (int) round($this->resolveNumeric($payload, ['duration_ms'], 1))),
                'connection' => null,
                'normalized_signature' => null,
                'sql_text' => null,
                'rows_count' => null,
                'service' => null,
                'queue' => null,
                'method' => null,
                'status_code' => null,
                'metadata' => $payload,
            ],
        };
    }

    /**
     * @param array<string, mixed> $rootPayload
     *
     * @param array<string, mixed> $rootPayload
     *
     * @return array{
     *     spans: array<int, array<string, mixed>>,
     *     phase_span_ids: array<string, string>,
     *     phase_ranges: array<string, array{start: int, end: int}>
     * }
     */
    private function buildLifecyclePhaseSpans(
        array $rootPayload,
        int $durationMs,
        ?string $rootSpanId,
        string $rootType,
    ): array
    {
        $stages = $this->resolveLifecycleStages($rootPayload, $durationMs, $rootType);
        $spans = [];
        $phaseSpanIds = [];
        $phaseRanges = [];

        foreach ($stages as $stage) {
            $stageName = Arr::get($stage, 'name');
            if (!is_string($stageName) || $stageName === '') {
                continue;
            }

            $startOffsetMs = max(0, (int) Arr::get($stage, 'start_offset_ms', 0));
            $stageDurationMs = max(1, (int) Arr::get($stage, 'duration_ms', 1));
            $metadata = Arr::get($stage, 'metadata', []);

            $phaseSpanId = str()->ulid()->toString();

            $spans[] = [
                'id' => $phaseSpanId,
                'parent_span_id' => $rootSpanId,
                'kind' => 'lifecycle',
                'name' => $this->lifecycleSpanDisplayName($stageName),
                'start_offset_ms' => $startOffsetMs,
                'duration_ms' => $stageDurationMs,
                'connection' => null,
                'normalized_signature' => null,
                'sql_text' => null,
                'rows_count' => null,
                'service' => null,
                'queue' => null,
                'method' => null,
                'status_code' => null,
                'metadata' => [
                    'phase' => $stageName,
                    'legacy_phase' => $this->legacyPhaseAlias($stageName),
                    'phase_meta' => is_array($metadata) ? $metadata : [],
                ],
            ];

            $existingRange = $phaseRanges[$stageName] ?? null;
            $existingDurationMs = is_array($existingRange)
                ? max(1, ((int) Arr::get($existingRange, 'end', 0)) - ((int) Arr::get($existingRange, 'start', 0)) + 1)
                : 0;

            // Keep a canonical phase span per phase for parent mapping. When repeated phases exist
            // (for example middleware before/after controller), pick the longest one.
            if (!isset($phaseSpanIds[$stageName]) || $stageDurationMs > $existingDurationMs) {
                $phaseSpanIds[$stageName] = $phaseSpanId;
                $phaseRanges[$stageName] = [
                    'start' => $startOffsetMs,
                    'end' => $startOffsetMs + $stageDurationMs - 1,
                ];
            }
        }

        $middleware = $this->resolveMiddleware($rootPayload);
        $middlewarePhaseId = $phaseSpanIds[RequestLifecycleStage::BeforeMiddleware->value]
            ?? $phaseSpanIds[RequestLifecycleStage::Middleware->value]
            ?? $phaseSpanIds[RequestLifecycleStage::AfterMiddleware->value]
            ?? null;
        $middlewarePhaseRange = $phaseRanges[RequestLifecycleStage::BeforeMiddleware->value]
            ?? $phaseRanges[RequestLifecycleStage::Middleware->value]
            ?? $phaseRanges[RequestLifecycleStage::AfterMiddleware->value]
            ?? null;

        if (
            is_string($middlewarePhaseId)
            && is_array($middlewarePhaseRange)
            && $middleware !== []
        ) {
            $phaseStart = max(0, (int) Arr::get($middlewarePhaseRange, 'start', 0));
            $phaseEnd = max($phaseStart, (int) Arr::get($middlewarePhaseRange, 'end', $phaseStart));
            $phaseDuration = max(1, $phaseEnd - $phaseStart + 1);
            $middlewareCount = count($middleware);
            $slotDuration = max(1, (int) floor($phaseDuration / max(1, $middlewareCount)));

            foreach ($middleware as $index => $item) {
                $middlewareStart = $phaseStart + ($slotDuration * $index);
                $middlewareDuration = $index === $middlewareCount - 1
                    ? max(1, ($phaseStart + $phaseDuration) - $middlewareStart)
                    : $slotDuration;

                $spans[] = [
                    'id' => str()->ulid()->toString(),
                    'parent_span_id' => $middlewarePhaseId,
                    'kind' => 'middleware',
                    'name' => $this->formatDisplayLabel($item),
                    'start_offset_ms' => $middlewareStart,
                    'duration_ms' => $middlewareDuration,
                    'connection' => null,
                    'normalized_signature' => null,
                    'sql_text' => null,
                    'rows_count' => null,
                    'service' => null,
                    'queue' => null,
                    'method' => null,
                    'status_code' => null,
                    'metadata' => [
                        'phase' => RequestLifecycleStage::Middleware->value,
                        'middleware' => $item,
                    ],
                ];
            }
        }

        return [
            'spans' => $spans,
            'phase_span_ids' => $phaseSpanIds,
            'phase_ranges' => $phaseRanges,
        ];
    }

    /**
     * @return array<int, array{name: string, start_offset_ms: int, duration_ms: int, metadata: array<string, mixed>}>
     */
    private function resolveLifecycleStages(array $rootPayload, int $durationMs, string $rootType): array
    {
        if ($rootType !== 'request') {
            return [];
        }

        $maxDuration = max(1, $durationMs);
        $providedStages = Arr::get($rootPayload, 'lifecycle_stages', []);
        $resolvedStages = [];

        if (is_array($providedStages)) {
            foreach ($providedStages as $stage) {
                if (!is_array($stage)) {
                    continue;
                }

                $stageName = Arr::get($stage, 'name');
                if (!is_string($stageName)) {
                    continue;
                }

                $metadata = Arr::get($stage, 'metadata', []);
                $normalizedStageName = $this->normalizeLifecycleStageName(
                    $stageName,
                    is_array($metadata) ? $metadata : [],
                );

                if ($normalizedStageName === null) {
                    continue;
                }

                $startOffsetMs = max(0, (int) Arr::get($stage, 'start_offset_ms', 0));
                if ($startOffsetMs >= $maxDuration) {
                    $startOffsetMs = max(0, $maxDuration - 1);
                }

                $stageDurationMs = max(1, (int) Arr::get($stage, 'duration_ms', 1));
                if ($startOffsetMs + $stageDurationMs > $maxDuration) {
                    $stageDurationMs = max(1, $maxDuration - $startOffsetMs);
                }

                $resolvedStages[] = [
                    'name' => $normalizedStageName,
                    'start_offset_ms' => $startOffsetMs,
                    'duration_ms' => $stageDurationMs,
                    'metadata' => is_array($metadata) ? $metadata : [],
                ];
            }
        }

        if ($resolvedStages === []) {
            return [];
        }

        return $resolvedStages;
    }

    /**
     * @param array<string, string> $phaseSpanIds
     * @param array<string, array{start: int, end: int}> $phaseRanges
     *
     * @return array{span_id: ?string, phase: ?string}
     */
    private function resolveParentSpanForRecord(
        string $type,
        int $offsetMs,
        int $durationMs,
        array $phaseSpanIds,
        array $phaseRanges,
        ?string $rootSpanId,
    ): array {
        $durationMs = max(1, $durationMs);
        $preferredPhase = $this->preferredPhaseForRecord($type);
        $phaseFromOffset = null;
        $recordEndOffsetMs = max($offsetMs, $offsetMs + $durationMs - 1);

        foreach ($this->requestPhaseOrder() as $phaseName) {
            $range = $phaseRanges[$phaseName] ?? null;
            if (!is_array($range)) {
                continue;
            }

            $rangeStart = max(0, (int) Arr::get($range, 'start', 0));
            $rangeEnd = max($rangeStart, (int) Arr::get($range, 'end', $rangeStart));

            if ($offsetMs >= $rangeStart && $recordEndOffsetMs <= $rangeEnd) {
                $phaseFromOffset = $phaseName;

                break;
            }
        }

        $phase = null;
        if ($preferredPhase !== null && isset($phaseSpanIds[$preferredPhase])) {
            $phase = $preferredPhase;
        } elseif ($phaseFromOffset !== null && isset($phaseSpanIds[$phaseFromOffset])) {
            $phase = $phaseFromOffset;
        } elseif (isset($phaseSpanIds[RequestLifecycleStage::Action->value])) {
            $phase = RequestLifecycleStage::Action->value;
        }

        return [
            'span_id' => $phase !== null ? ($phaseSpanIds[$phase] ?? $rootSpanId) : $rootSpanId,
            'phase' => $phase,
        ];
    }

    private function preferredPhaseForRecord(string $type): ?string
    {
        return match ($type) {
            'query',
            'outgoing_http',
            'cache',
            'job',
            'exception',
            'event',
            'log' => RequestLifecycleStage::Action->value,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveRecordDurationMs(string $type, array $payload): int
    {
        return match ($type) {
            'query' => max(1, (int) round($this->resolveNumeric($payload, ['time_ms'], 1))),
            'outgoing_http',
            'job',
            'cache' => max(1, (int) round($this->resolveNumeric($payload, ['duration_ms'], 1))),
            default => max(1, (int) round($this->resolveNumeric($payload, ['duration_ms', 'time_ms'], 1))),
        };
    }

    /**
     * @return array<int, string>
     */
    private function requestPhaseOrder(): array
    {
        return [
            RequestLifecycleStage::Bootstrap->value,
            RequestLifecycleStage::BeforeMiddleware->value,
            RequestLifecycleStage::Action->value,
            RequestLifecycleStage::Render->value,
            RequestLifecycleStage::AfterMiddleware->value,
            RequestLifecycleStage::Sending->value,
            RequestLifecycleStage::Terminating->value,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractException(array $record): ?array
    {
        $payload = Arr::get($record, 'payload', []);
        if (!is_array($payload)) {
            return null;
        }

        $type = (string) Arr::get($record, 'type', '');
        if ($type === 'exception') {
            $class = (string) Arr::get($payload, 'class', 'RuntimeException');
            $message = (string) Arr::get($payload, 'message', 'Exception');

            return [
                'fingerprint' => null,
                'title' => $class,
                'class' => $class,
                'message' => $message,
                'file' => is_string(Arr::get($payload, 'file')) ? Arr::get($payload, 'file') : null,
                'line' => is_numeric(Arr::get($payload, 'line')) ? (int) Arr::get($payload, 'line') : null,
                'owner' => 'platform',
                'regression' => false,
            ];
        }

        $exceptionClass = Arr::get($payload, 'exception_class');
        $exceptionMessage = Arr::get($payload, 'exception_message');

        if (is_string($exceptionClass) && $exceptionClass !== '' && is_string($exceptionMessage) && $exceptionMessage !== '') {
            return [
                'fingerprint' => null,
                'title' => $exceptionClass,
                'class' => $exceptionClass,
                'message' => $exceptionMessage,
                'file' => null,
                'line' => null,
                'owner' => 'platform',
                'regression' => false,
            ];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function sumQueryTime(array $records): int
    {
        $sum = 0;

        foreach ($records as $record) {
            if ((string) Arr::get($record, 'type', '') !== 'query') {
                continue;
            }

            $payload = Arr::get($record, 'payload', []);
            if (!is_array($payload)) {
                continue;
            }

            $sum += (int) round($this->resolveNumeric($payload, ['time_ms'], 0));
        }

        return max(0, $sum);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function sumJobTime(array $records): int
    {
        $sum = 0;

        foreach ($records as $record) {
            if ((string) Arr::get($record, 'type', '') !== 'job') {
                continue;
            }

            $payload = Arr::get($record, 'payload', []);
            if (!is_array($payload)) {
                continue;
            }

            $sum += (int) round($this->resolveNumeric($payload, ['duration_ms'], 0));
        }

        return max(0, $sum);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function sumOutgoingHttpTime(array $records): int
    {
        $sum = 0;

        foreach ($records as $record) {
            if ((string) Arr::get($record, 'type', '') !== 'outgoing_http') {
                continue;
            }

            $payload = Arr::get($record, 'payload', []);
            if (!is_array($payload)) {
                continue;
            }

            $sum += (int) round($this->resolveNumeric($payload, ['duration_ms'], 0));
        }

        return max(0, $sum);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function resolveDurationMs(
        array $rootRecord,
        array $records,
        int $dbTimeMs,
        int $externalTimeMs,
        int $jobTimeMs,
    ): int
    {
        $rootPayload = Arr::get($rootRecord, 'payload', []);
        if (is_array($rootPayload)) {
            $duration = $this->resolveNumeric($rootPayload, ['duration_ms', 'time_ms'], -1);
            if ($duration >= 0) {
                return max(1, (int) round($duration));
            }
        }

        $firstAt = null;
        $lastAt = null;

        foreach ($records as $record) {
            $happenedAt = Arr::get($record, 'happened_at');
            if (!is_string($happenedAt) || $happenedAt === '') {
                continue;
            }

            try {
                $ts = Carbon::parse($happenedAt)->valueOf();
            } catch (Throwable) {
                continue;
            }

            $firstAt = $firstAt === null ? $ts : min($firstAt, $ts);
            $lastAt = $lastAt === null ? $ts : max($lastAt, $ts);
        }

        if ($firstAt !== null && $lastAt !== null) {
            return max(1, $lastAt - $firstAt);
        }

        return max(1, $dbTimeMs + $externalTimeMs + $jobTimeMs);
    }

    private function resolveMethod(array $rootRecord): string
    {
        $type = (string) Arr::get($rootRecord, 'type', 'custom');
        $payload = Arr::get($rootRecord, 'payload', []);
        if (!is_array($payload)) {
            return $type === 'job' ? 'QUEUE' : 'CLI';
        }

        if ($type === 'request') {
            $method = Arr::get($payload, 'method');

            return is_string($method) && $method !== '' ? $method : 'GET';
        }

        if ($type === 'heartbeat') {
            return 'HEARTBEAT';
        }

        if ($type === 'outgoing_http') {
            $method = Arr::get($payload, 'method');

            return is_string($method) && $method !== '' ? $method : 'HTTP';
        }

        return $type === 'job' ? 'QUEUE' : 'CLI';
    }

    private function resolveRoute(array $rootRecord): string
    {
        $payload = Arr::get($rootRecord, 'payload', []);
        if (!is_array($payload)) {
            return '/';
        }

        $routeUri = Arr::get($payload, 'route_uri');
        if (is_string($routeUri) && $routeUri !== '') {
            return $routeUri;
        }

        $path = Arr::get($payload, 'path');
        if (is_string($path) && $path !== '') {
            return $path;
        }

        $jobName = Arr::get($payload, 'name');
        if (is_string($jobName) && $jobName !== '') {
            return $jobName;
        }

        if ((string) Arr::get($rootRecord, 'type', '') === 'heartbeat') {
            return 'agent.heartbeat';
        }

        return '/';
    }

    private function resolveUri(array $rootRecord): string
    {
        $payload = Arr::get($rootRecord, 'payload', []);
        if (!is_array($payload)) {
            return 'internal://agent';
        }

        $url = Arr::get($payload, 'url');
        if (is_string($url) && $url !== '') {
            return $url;
        }

        $path = Arr::get($payload, 'path');
        if (is_string($path) && $path !== '') {
            return $path;
        }

        $name = Arr::get($payload, 'name');
        if (is_string($name) && $name !== '') {
            return 'queue://' . $name;
        }

        if ((string) Arr::get($rootRecord, 'type', '') === 'heartbeat') {
            return 'agent://heartbeat';
        }

        return 'internal://agent';
    }

    private function resolveHost(array $rootRecord): string
    {
        $payload = Arr::get($rootRecord, 'payload', []);
        if (is_array($payload)) {
            $url = Arr::get($payload, 'url');
            if (is_string($url) && $url !== '') {
                $host = parse_url($url, PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    return $host;
                }
            }
        }

        $server = Arr::get($rootRecord, 'server');

        return is_string($server) && $server !== '' ? $server : $this->serverName;
    }

    private function resolveStatusCode(array $rootRecord): int
    {
        $type = (string) Arr::get($rootRecord, 'type', 'custom');
        $payload = Arr::get($rootRecord, 'payload', []);
        if (is_array($payload)) {
            $status = Arr::get($payload, 'status');
            if (is_numeric($status)) {
                $statusCode = (int) $status;

                return $statusCode >= 100 && $statusCode <= 599 ? $statusCode : 200;
            }

            if ($type === 'outgoing_http') {
                $outgoingStatus = Arr::get($payload, 'status_code');
                if (is_numeric($outgoingStatus)) {
                    $statusCode = (int) $outgoingStatus;

                    return $statusCode >= 100 && $statusCode <= 599 ? $statusCode : 200;
                }
            }
        }

        return 200;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveMemoryMb(array $payload): int
    {
        $bytes = Arr::get($payload, 'memory_peak_bytes');
        if (!is_numeric($bytes)) {
            return 0;
        }

        return max(0, (int) round(((float) $bytes) / 1024 / 1024));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveUserLabel(array $payload): ?string
    {
        $userId = Arr::get($payload, 'user_id');
        if (is_string($userId) && $userId !== '') {
            return $userId;
        }

        if (is_numeric($userId)) {
            return (string) $userId;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveRootSpanName(string $rootType, array $payload): string
    {
        if ($rootType === 'request') {
            $route = Arr::get($payload, 'route_uri');
            if (is_string($route) && $route !== '') {
                return $route;
            }

            $path = Arr::get($payload, 'path');
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        $name = Arr::get($payload, 'name');
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $rootType;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveRootSpanMethod(string $rootType, array $payload): ?string
    {
        if ($rootType !== 'request') {
            return null;
        }

        $method = Arr::get($payload, 'method');

        return is_string($method) && $method !== '' ? $method : 'GET';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveRootSpanStatusCode(string $rootType, array $payload): ?int
    {
        if ($rootType !== 'request') {
            return null;
        }

        $status = Arr::get($payload, 'status');
        if (!is_numeric($status)) {
            return 200;
        }

        $statusCode = (int) $status;

        return $statusCode >= 100 && $statusCode <= 599 ? $statusCode : 200;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<int, string>
     */
    private function resolveMiddleware(array $payload): array
    {
        $middleware = Arr::get($payload, 'middleware', []);
        if (!is_array($middleware)) {
            return [];
        }

        $result = [];

        foreach ($middleware as $item) {
            if (is_string($item) && $item !== '') {
                $result[] = $item;
            }
        }

        return array_values($result);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveController(array $payload): ?string
    {
        $controller = Arr::get($payload, 'controller');
        if (is_string($controller) && $controller !== '') {
            return $controller;
        }

        $routeName = Arr::get($payload, 'route_name');

        return is_string($routeName) && $routeName !== '' ? $routeName : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function querySpanName(array $payload): string
    {
        $sql = Arr::get($payload, 'sql');
        if (!is_string($sql) || $sql === '') {
            return 'query';
        }

        return mb_substr($sql, 0, 80);
    }

    private function formatDisplayLabel(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $normalizedValue = str_replace('/', '\\', $value);
        $segments = array_values(array_filter(explode('\\', $normalizedValue)));
        $label = $segments === [] ? $value : (string) end($segments);

        if ($label === '') {
            return $value;
        }

        $collapsedLabel = str_replace(['_', '-'], ' ', $label);
        if (mb_strtolower($collapsedLabel) === $collapsedLabel || mb_strtoupper($collapsedLabel) === $collapsedLabel) {
            return mb_convert_case($collapsedLabel, MB_CASE_TITLE, 'UTF-8');
        }

        return $label;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function normalizeLifecycleStageName(string $stageName, array $metadata): ?string
    {
        $resolvedStage = RequestLifecycleStage::fromName($stageName);
        if ($resolvedStage === null || !$resolvedStage->isRequestPhase()) {
            return null;
        }

        return match ($resolvedStage) {
            RequestLifecycleStage::Middleware => Arr::get($metadata, 'segment') === 'after_controller'
                ? RequestLifecycleStage::AfterMiddleware->value
                : RequestLifecycleStage::BeforeMiddleware->value,
            RequestLifecycleStage::Controller => RequestLifecycleStage::Action->value,
            default => $resolvedStage->value,
        };
    }

    private function lifecycleSpanDisplayName(string $phaseName): string
    {
        return match ($phaseName) {
            RequestLifecycleStage::BeforeMiddleware->value,
            RequestLifecycleStage::AfterMiddleware->value => 'Middleware',
            RequestLifecycleStage::Action->value => 'Controller',
            default => $this->formatDisplayLabel($phaseName),
        };
    }

    private function legacyPhaseAlias(string $phaseName): ?string
    {
        return match ($phaseName) {
            RequestLifecycleStage::BeforeMiddleware->value,
            RequestLifecycleStage::AfterMiddleware->value => RequestLifecycleStage::Middleware->value,
            RequestLifecycleStage::Action->value => RequestLifecycleStage::Controller->value,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveQueryNormalizedSignature(array $payload): ?string
    {
        $normalizedSignature = Arr::get($payload, 'normalized_signature');
        if (is_string($normalizedSignature) && $normalizedSignature !== '') {
            return mb_substr($normalizedSignature, 0, 255);
        }

        $sql = Arr::get($payload, 'sql');
        if (is_string($sql) && $sql !== '') {
            return mb_substr($sql, 0, 255);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveOffsetMs(?string $eventHappenedAt, string $traceStartedAt, array $payload = [], ?float $eventTimestamp = null): int
    {
        $payloadStartOffsetUs = Arr::get($payload, 'start_offset_us');
        if (is_numeric($payloadStartOffsetUs)) {
            return max(0, (int) round(((float) $payloadStartOffsetUs) / 1000));
        }

        $payloadStartOffsetMs = Arr::get($payload, 'start_offset_ms');
        if (is_numeric($payloadStartOffsetMs)) {
            return max(0, (int) round((float) $payloadStartOffsetMs));
        }

        $payloadEndOffsetUs = Arr::get($payload, 'end_offset_us');
        if (is_numeric($payloadEndOffsetUs)) {
            $durationUs = $this->resolveNumeric($payload, ['duration_us'], -1);
            if ($durationUs >= 0) {
                $endOffsetUs = max(0, (int) round((float) $payloadEndOffsetUs));
                $startOffsetUs = max(0, $endOffsetUs - (int) round($durationUs));

                return max(0, (int) round($startOffsetUs / 1000));
            }
        }

        $payloadEndOffsetMs = Arr::get($payload, 'end_offset_ms');
        if (is_numeric($payloadEndOffsetMs)) {
            $durationMs = max(1, (int) round($this->resolveNumeric($payload, ['duration_ms', 'time_ms'], 1)));
            $endOffsetMs = max(0, (int) round((float) $payloadEndOffsetMs));

            return max(0, $endOffsetMs - $durationMs);
        }

        if ($eventHappenedAt === null || $eventHappenedAt === '') {
            if (is_numeric($eventTimestamp)) {
                try {
                    $eventMs = (int) round($eventTimestamp * 1000);
                    $startMs = Carbon::parse($traceStartedAt)->valueOf();

                    return max(0, $eventMs - $startMs);
                } catch (Throwable) {
                    return 0;
                }
            }

            return 0;
        }

        try {
            $eventMs = Carbon::parse($eventHappenedAt)->valueOf();
            $startMs = Carbon::parse($traceStartedAt)->valueOf();

            return max(0, $eventMs - $startMs);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function resolveNumeric(array $payload, array $keys, float $default): float
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return $default;
    }

    /**
     * @return array{headers: array<string, string>, truncated: bool}
     */
    private function boundedHeaders(mixed $headers): array
    {
        if (!is_array($headers)) {
            return ['headers' => [], 'truncated' => false];
        }

        $maxHeadersCount = 32;
        $maxHeaderKeyLength = 64;
        $maxHeaderValueLength = 512;
        $normalized = [];
        $truncated = false;

        foreach ($headers as $key => $value) {
            if (count($normalized) >= $maxHeadersCount) {
                $truncated = true;

                break;
            }

            if (!is_string($key) || $key === '') {
                continue;
            }

            $normalizedKey = mb_substr($key, 0, $maxHeaderKeyLength);
            $normalizedValue = $this->headerValueToString($value);

            if ($normalizedValue === '') {
                continue;
            }

            if (mb_strlen($key) > $maxHeaderKeyLength) {
                $truncated = true;
            }

            if (mb_strlen($normalizedValue) > $maxHeaderValueLength) {
                $normalizedValue = mb_substr($normalizedValue, 0, $maxHeaderValueLength);
                $truncated = true;
            }

            $normalized[$normalizedKey] = $normalizedValue;
        }

        return ['headers' => $normalized, 'truncated' => $truncated];
    }

    /**
     * @return array{preview: ?string, truncated: bool}
     */
    private function boundedPayloadPreview(mixed $payload): array
    {
        if ($payload === null) {
            return ['preview' => null, 'truncated' => false];
        }

        $maxPreviewLength = 2048;
        $preview = null;
        $truncated = false;

        if (is_string($payload)) {
            $preview = $payload;
        } elseif (is_array($payload)) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $preview = is_string($encoded) ? $encoded : null;
        } elseif (is_bool($payload)) {
            $preview = $payload ? 'true' : 'false';
        } elseif (is_int($payload) || is_float($payload)) {
            $preview = (string) $payload;
        }

        if ($preview === null || $preview === '') {
            return ['preview' => null, 'truncated' => false];
        }

        if (mb_strlen($preview) > $maxPreviewLength) {
            $preview = mb_substr($preview, 0, $maxPreviewLength);
            $truncated = true;
        }

        return ['preview' => $preview, 'truncated' => $truncated];
    }

    private function headerValueToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $fragments = [];

            foreach ($value as $item) {
                if (is_string($item)) {
                    $fragments[] = $item;

                    continue;
                }

                if (is_bool($item)) {
                    $fragments[] = $item ? 'true' : 'false';

                    continue;
                }

                if (is_int($item) || is_float($item)) {
                    $fragments[] = (string) $item;
                }
            }

            return implode(', ', $fragments);
        }

        return '';
    }
}
