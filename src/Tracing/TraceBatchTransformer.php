<?php

declare(strict_types=1);

namespace Dozor\Tracing;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

use function array_values;
use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function mb_substr;
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

        $traceException = $this->extractException($rootRecord);

        foreach ($records as $record) {
            if ($record === $rootRecord) {
                continue;
            }

            $span = $this->buildSpanFromRecord($record, $startedAt, $rootSpanId);
            if ($span === null) {
                continue;
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

        $dbTimeMs = $this->sumQueryTime($records);
        $externalTimeMs = $this->sumOutgoingHttpTime($records);
        $jobTimeMs = $this->sumJobTime($records);
        $durationMs = $this->resolveDurationMs($rootRecord, $records, $dbTimeMs, $externalTimeMs, $jobTimeMs);
        $requestId = Arr::get($rootPayload, 'request_id');
        $release = Arr::get($rootRecord, 'release', Arr::get($rootRecord, 'deployment'));
        $controller = $this->resolveController($rootPayload);
        $middleware = $this->resolveMiddleware($rootPayload);

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
        $rootHappenedAt = Arr::get($rootRecord, 'happened_at');
        if (is_string($rootHappenedAt) && $rootHappenedAt !== '') {
            return $rootHappenedAt;
        }

        foreach ($records as $record) {
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
    private function buildSpanFromRecord(array $record, string $traceStartedAt, ?string $parentSpanId): ?array
    {
        $type = (string) Arr::get($record, 'type', 'custom');
        $payload = Arr::get($record, 'payload', []);
        if (!is_array($payload)) {
            return null;
        }

        $happenedAt = Arr::get($record, 'happened_at');
        $offsetMs = $this->resolveOffsetMs(
            is_string($happenedAt) ? $happenedAt : null,
            $traceStartedAt,
        );

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
                'metadata' => [
                    'failed' => (bool) Arr::get($payload, 'failed', false),
                    'error_class' => Arr::get($payload, 'error_class'),
                    'error_message' => Arr::get($payload, 'error_message'),
                ],
            ],
            'query' => [
                'id' => str()->ulid()->toString(),
                'parent_span_id' => $parentSpanId,
                'kind' => 'query',
                'name' => $this->querySpanName($payload),
                'start_offset_ms' => $offsetMs,
                'duration_ms' => max(1, (int) round($this->resolveNumeric($payload, ['time_ms'], 1))),
                'connection' => is_string(Arr::get($payload, 'connection')) ? Arr::get($payload, 'connection') : null,
                'normalized_signature' => is_string(Arr::get($payload, 'sql')) ? mb_substr((string) Arr::get($payload, 'sql'), 0, 255) : null,
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

    private function resolveOffsetMs(?string $eventHappenedAt, string $traceStartedAt): int
    {
        if ($eventHappenedAt === null || $eventHappenedAt === '') {
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
}
