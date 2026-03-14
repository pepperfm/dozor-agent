<?php

declare(strict_types=1);

namespace Dozor;

use Dozor\Contracts\DozorContract;
use Dozor\Contracts\IngestContract;
use Dozor\Context\TraceContext;
use Dozor\Filters\RequestFilter;
use Dozor\Redaction\PayloadRedactor;
use Dozor\Sampling\DeterministicSampler;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Arr;
use Throwable;

use function hash;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function mb_substr;
use function round;
use function substr;
use function str_starts_with;

class Dozor implements DozorContract
{
    private const DEFAULT_RUNTIME_ERROR_COOLDOWN_SECONDS = 60.0;

    public ?TraceContext $currentTrace = null;

    /**
     * @var array<string, float>
     */
    private array $jobStartedAt = [];

    private bool $capturingLogs = false;

    private bool $currentRequestSuppressed = false;

    private string $currentRequestSuppressedReason = '';

    private DeterministicSampler $sampler;

    private PayloadRedactor $redactor;

    private RequestFilter $requestFilter;

    /**
     * @var array<string, float>
     */
    private array $runtimeErrorReportedAt = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly IngestContract $ingest,
        private readonly array $config,
    ) {
        $this->sampler = new DeterministicSampler((array) Arr::get($this->config, 'sampling', []));
        $this->redactor = new PayloadRedactor(
            payloadFields: (array) Arr::get($this->config, 'redact_payload_fields', []),
            headerFields: (array) Arr::get($this->config, 'redact_headers', []),
            maxPayloadBytes: (int) Arr::get($this->config, 'limits.max_payload_bytes', 16384),
            truncatePreviewBytes: (int) Arr::get($this->config, 'limits.truncate_preview_bytes', 2048),
            oversizeBehavior: (string) Arr::get($this->config, 'limits.oversize_behavior', 'drop'),
        );
        $this->requestFilter = new RequestFilter((array) Arr::get($this->config, 'filtering.request', []));

    }

    public function enabled(): bool
    {
        return (bool) $this->config('enabled', true);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    public function beginRequest(Request $request, ?float $startedAt = null): TraceContext
    {
        $startedAt ??= microtime(true);

        try {
            $traceId = $this->makeTraceId();
            $route = $request->route();
            $this->currentRequestSuppressed = false;
            $this->currentRequestSuppressedReason = '';

            if ($this->requestFilter->shouldIgnoreRequest($request)) {
                $this->currentRequestSuppressed = true;
                $this->currentRequestSuppressedReason = 'filter';
            } else {
                $samplingKey = $request->method() . '|' . $request->path() . '|' . ($request->ip() ?? 'unknown') . '|' . $traceId;
                if (!$this->sampler->shouldSample('requests', $samplingKey)) {
                    $this->currentRequestSuppressed = true;
                    $this->currentRequestSuppressedReason = 'sampling';
                }
            }

            $requestMeta = [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'path' => '/' . ltrim($request->path(), '/'),
                'route_name' => $route?->getName(),
                'route_uri' => $route?->uri(),
                'controller' => $route?->getActionName(),
                'middleware' => $route?->gatherMiddleware() ?? [],
                'ip' => $request->ip(),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'user_agent' => $request->userAgent(),
                'sampled' => !$this->currentRequestSuppressed,
            ];

            if ((bool) $this->config('instrumentation.capture_request_headers', false)) {
                $requestMeta['headers'] = $this->redactor->redactHeaders($request->headers->all());
            }

            $trace = new TraceContext(
                traceId: $traceId,
                startedAt: $startedAt,
                requestMeta: $requestMeta,
            );

            if (!$this->currentRequestSuppressed) {
                $trace->beginLifecycleStage(
                    RequestLifecycleStage::Bootstrap->value,
                    startedAt: $startedAt,
                );
            }

            return $this->currentTrace = $trace;
        } catch (Throwable $e) {
            $this->reportRuntimeException('begin_request', $e);
            $this->currentRequestSuppressed = true;
            $this->currentRequestSuppressedReason = 'dozor_runtime_error';

            return $this->currentTrace = new TraceContext(
                traceId: $this->makeTraceId(),
                startedAt: $startedAt,
                requestMeta: ['sampled' => false],
            );
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function beginLifecycleStage(string $stage, array $metadata = []): void
    {
        if ($this->currentRequestSuppressed) {
            return;
        }

        $resolvedStage = RequestLifecycleStage::fromName($stage);
        if ($resolvedStage === null) {
            return;
        }

        if (!$resolvedStage->isLifecyclePhase()) {
            return;
        }

        $this->currentTrace?->beginLifecycleStage($resolvedStage->value, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function endLifecycleStage(string $stage, array $metadata = []): void
    {
        if ($this->currentRequestSuppressed) {
            return;
        }

        $resolvedStage = RequestLifecycleStage::fromName($stage);
        if ($resolvedStage === null) {
            return;
        }

        if (!$resolvedStage->isLifecyclePhase()) {
            return;
        }

        $this->currentTrace?->endLifecycleStage($resolvedStage->value, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transitionLifecycleStage(string $stage, array $metadata = []): void
    {
        if ($this->currentRequestSuppressed) {
            return;
        }

        $resolvedStage = RequestLifecycleStage::fromName($stage);
        if ($resolvedStage === null || !$resolvedStage->isLifecyclePhase()) {
            return;
        }

        $trace = $this->currentTrace;
        if ($trace === null) {
            return;
        }

        $nextStage = $resolvedStage->value;
        $currentStage = $trace->currentLifecycleStage();
        if ($currentStage === $nextStage) {
            return;
        }

        $now = microtime(true);

        if (is_string($currentStage) && $currentStage !== '') {
            $trace->endLifecycleStage($currentStage, endedAt: $now);
        }

        $trace->beginLifecycleStage($nextStage, $metadata, startedAt: $now);
    }

    public function lifecycleStageIs(string $stage): bool
    {
        $resolvedStage = RequestLifecycleStage::fromName($stage);
        if ($resolvedStage === null || !$resolvedStage->isLifecyclePhase()) {
            return false;
        }

        return $this->currentTrace?->currentLifecycleStage() === $resolvedStage->value;
    }

    public function hasActiveRequestTrace(): bool
    {
        return $this->currentTrace !== null;
    }

    public function finishRequest(
        Request $request,
        mixed $response,
        float $startedAt,
        ?Throwable $e = null
    ): void {
        try {
            if (!$this->enabled()) {
                return;
            }

            if ($this->currentRequestSuppressed) {
                return;
            }

            if (!$this->lifecycleStageIs(RequestLifecycleStage::Sending->value)
                && !$this->lifecycleStageIs(RequestLifecycleStage::Terminating->value)
                && !$this->lifecycleStageIs(RequestLifecycleStage::End->value)
            ) {
                $this->transitionLifecycleStage(RequestLifecycleStage::Sending->value);
            }

            if (
                !$this->lifecycleStageIs(RequestLifecycleStage::Terminating->value)
                && !$this->lifecycleStageIs(RequestLifecycleStage::End->value)
            ) {
                $this->transitionLifecycleStage(RequestLifecycleStage::Terminating->value);
            }

            if (!$this->lifecycleStageIs(RequestLifecycleStage::End->value)) {
                $this->transitionLifecycleStage(RequestLifecycleStage::End->value);
            }

            $this->endLifecycleStage(RequestLifecycleStage::End->value);
            $this->currentTrace?->closeOpenLifecycleStages();

            $traceId = $this->currentTrace?->traceId ?? $this->makeTraceId();
            $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : ($e ? 500 : 200);

            $payload = array_merge($this->currentTrace?->requestMeta ?? [], [
                'status' => $status,
                'duration_ms' => $durationMs,
                'request_id' => $request->headers->get('X-Request-Id'),
                'memory_peak_bytes' => memory_get_peak_usage(true),
                'lifecycle_stages' => $this->currentTrace?->lifecycleStages() ?? [],
            ]);

            if ((bool) $this->config('capture_request_payload', false) && !$this->config('filtering.ignore_request_payload', false)) {
                $payload['request_payload'] = $this->sanitizePayload($request->all());
            }

            if ($e) {
                $payload['exception_class'] = $e::class;
                $payload['exception_message'] = $e->getMessage();
            }

            $this->report([
                'type' => 'request',
                'trace_id' => $traceId,
                'happened_at' => $this->isoTime(),
                'app' => $this->config('app_name'),
                'environment' => $this->config('environment'),
                'server' => $this->config('server'),
                'payload' => $payload,
            ]);
        } catch (Throwable $exception) {
            $this->reportRuntimeException('finish_request', $exception);
        } finally {
            $this->currentTrace = null;
            $this->currentRequestSuppressed = false;
            $this->currentRequestSuppressedReason = '';
        }
    }

    public function recordQuery(QueryExecuted $event): void
    {
        if (
            !$this->enabled()
            || $this->config('filtering.ignore_queries', false)
            || $this->currentRequestSuppressed
        ) {
            return;
        }

        $traceId = $this->currentTrace?->traceId ?? $this->makeTraceId();
        $samplingKey = $traceId . '|' . $event->connectionName . '|' . $event->sql;

        if (!$this->sampler->shouldSample('queries', $samplingKey)) {
            return;
        }

        $captureSql = (bool) $this->config('filtering.capture_query_sql', false);
        $sqlMaxLength = max(64, (int) $this->config('limits.max_sql_length', 2000));

        $this->report([
            'type' => 'query',
            'trace_id' => $traceId,
            'happened_at' => $this->isoTime(),
            'app' => $this->config('app_name'),
            'environment' => $this->config('environment'),
            'server' => $this->config('server'),
            'payload' => $this->attachSpanOffsets([
                'sql' => $captureSql ? mb_substr($event->sql, 0, $sqlMaxLength) : '[REDACTED_SQL]',
                'time_ms' => (float) $event->time,
                'connection' => $event->connectionName,
                'bindings_count' => count($event->bindings),
            ], durationUs: (int) round(((float) $event->time) * 1000)),
        ]);
    }

    public function recordJobStarted(JobProcessing $event): void
    {
        if (!$this->enabled() || $this->config('filtering.ignore_queue_jobs', false)) {
            return;
        }

        $this->jobStartedAt[$this->jobKey($event)] = microtime(true);
    }

    public function recordJobFinished(JobProcessed|JobFailed $event): void
    {
        if (!$this->enabled() || $this->config('filtering.ignore_queue_jobs', false)) {
            return;
        }

        $traceId = $this->currentTrace?->traceId ?? $this->makeTraceId();
        $samplingKey = $traceId . '|' . $event->connectionName . '|' . $event->job->resolveName();

        if (!$this->sampler->shouldSample('jobs', $samplingKey)) {
            return;
        }

        $key = $this->jobKey($event);
        $startedAt = $this->jobStartedAt[$key] ?? microtime(true);
        unset($this->jobStartedAt[$key]);

        $this->report([
            'type' => 'job',
            'trace_id' => $traceId,
            'happened_at' => $this->isoTime(),
            'app' => $this->config('app_name'),
            'environment' => $this->config('environment'),
            'server' => $this->config('server'),
            'payload' => $this->attachSpanOffsets([
                'name' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'status' => $event instanceof JobFailed ? 'failed' : 'processed',
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'exception' => $event instanceof JobFailed && $event->exception ? [
                    'class' => $event->exception::class,
                    'message' => $event->exception->getMessage(),
                ] : null,
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordException(Throwable $e, array $context = []): void
    {
        if (!$this->enabled()) {
            return;
        }

        $trace = $this->currentTrace?->traceId ?? $this->makeTraceId();
        $samplingKey = $trace . '|' . $e::class . '|' . $e->getFile() . ':' . $e->getLine();

        if (!$this->sampler->shouldSample('exceptions', $samplingKey)) {
            return;
        }

        $payload = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'context' => $this->sanitizePayload($context),
        ];

        $payload = $this->attachSpanOffsets($payload);

        if ((bool) $this->config('capture_exception_source_code', false)) {
            $payload['snippet'] = $this->sourceSnippet($e->getFile(), $e->getLine());
        }

        $this->report([
            'type' => 'exception',
            'trace_id' => $trace,
            'happened_at' => $this->isoTime(),
            'app' => $this->config('app_name'),
            'environment' => $this->config('environment'),
            'server' => $this->config('server'),
            'payload' => $payload,
        ], immediate: true);
    }

    public function recordOutgoingHttp(array $payload): void
    {
        if (
            !$this->enabled()
            || $this->config('filtering.ignore_outgoing_http', false)
            || $this->currentRequestSuppressed
        ) {
            return;
        }

        $traceId = $this->currentTrace?->traceId ?? $this->makeTraceId();
        $samplingKey = $traceId . '|' . Arr::get($payload, 'method') . '|' . Arr::get($payload, 'url');

        if (!$this->sampler->shouldSample('outgoing_http', $samplingKey)) {
            return;
        }

        if (is_array(Arr::get($payload, 'request_headers'))) {
            $payload['request_headers'] = $this->redactor->redactHeaders((array) Arr::get($payload, 'request_headers', []));
        }

        if (is_array(Arr::get($payload, 'response_headers'))) {
            $payload['response_headers'] = $this->redactor->redactHeaders((array) Arr::get($payload, 'response_headers', []));
        }

        $this->report([
            'type' => 'outgoing_http',
            'trace_id' => $traceId,
            'happened_at' => $this->isoTime(),
            'app' => $this->config('app_name'),
            'environment' => $this->config('environment'),
            'server' => $this->config('server'),
            'payload' => $this->attachSpanOffsets($payload),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordCacheEvent(array $payload): void
    {
        if (!$this->enabled() || $this->currentRequestSuppressed) {
            return;
        }

        $traceId = $this->currentTrace?->traceId ?? $this->makeTraceId();
        $operation = Arr::get($payload, 'operation');
        $operation = is_string($operation) && $operation !== '' ? $operation : 'unknown';
        $cacheKey = Arr::get($payload, 'key');
        $cacheKey = is_string($cacheKey) ? $cacheKey : '';
        $samplingKey = $traceId . '|' . $operation . '|' . $cacheKey;

        if (!$this->sampler->shouldSample('events', $samplingKey)) {
            return;
        }

        $this->report([
            'type' => 'cache',
            'trace_id' => $traceId,
            'happened_at' => $this->isoTime(),
            'app' => $this->config('app_name'),
            'environment' => $this->config('environment'),
            'server' => $this->config('server'),
            'payload' => $this->attachSpanOffsets([
                'operation' => $operation,
                'key_hash' => $cacheKey !== '' ? substr(hash('xxh128', $cacheKey), 0, 24) : null,
                'store' => Arr::get($payload, 'store'),
            ]),
        ]);
    }

    public function recordLogMessage(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled() || !$this->config('instrumentation.capture_logs', false)) {
            return;
        }

        if ($this->capturingLogs || str_starts_with($message, 'dozor.')) {
            return;
        }

        $traceId = $this->currentTrace?->traceId ?? $this->makeTraceId();
        $samplingKey = $traceId . '|' . $level . '|' . $message;

        if (!$this->sampler->shouldSample('logs', $samplingKey)) {
            return;
        }

        $this->capturingLogs = true;

        try {
            $this->report([
                'type' => 'log',
                'trace_id' => $traceId,
                'happened_at' => $this->isoTime(),
                'app' => $this->config('app_name'),
                'environment' => $this->config('environment'),
                'server' => $this->config('server'),
                'payload' => $this->attachSpanOffsets([
                    'level' => $level,
                    'message' => mb_substr($message, 0, 3000),
                    'context' => $this->sanitizePayload($context),
                ]),
            ]);
        } finally {
            $this->capturingLogs = false;
        }
    }

    public function recordApplicationEvent(string $eventName, array $payload = []): void
    {
        if (!$this->enabled() || !$this->config('instrumentation.capture_events', false)) {
            return;
        }

        $traceId = $this->currentTrace?->traceId ?? $this->makeTraceId();
        $samplingKey = $traceId . '|' . $eventName;

        if (!$this->sampler->shouldSample('events', $samplingKey)) {
            return;
        }

        $this->report([
            'type' => 'event',
            'trace_id' => $traceId,
            'happened_at' => $this->isoTime(),
            'app' => $this->config('app_name'),
            'environment' => $this->config('environment'),
            'server' => $this->config('server'),
            'payload' => $this->attachSpanOffsets([
                'name' => $eventName,
                'data' => $this->sanitizePayload($payload),
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $record
     */
    public function report(array $record, bool $immediate = false): void
    {
        try {
            if (!$this->enabled()) {
                return;
            }

            $record['deployment'] ??= $this->config('deployment');
            $record['release'] ??= $this->config('release', $this->config('deployment'));
            $record['timestamp'] ??= microtime(true);

            $payload = Arr::get($record, 'payload');
            if (is_array($payload)) {
                $payload = $this->redactor->redactPayload($payload);
                $limitedPayload = $this->redactor->enforcePayloadLimit($payload, (string) Arr::get($record, 'type', 'unknown'));

                if ($limitedPayload === null) {
                    return;
                }

                $record['payload'] = $limitedPayload;
            }

            if ($immediate) {
                $this->ingest->writeNow($record);

                return;
            }

            $this->ingest->write($record);
        } catch (Throwable $exception) {
            $this->reportRuntimeException('report', $exception);
        }
    }

    public function digest(): void
    {
        try {
            $this->ingest->digest();
        } catch (Throwable $exception) {
            $this->reportRuntimeException('digest', $exception);
        }
    }

    public function ping(): void
    {
        $this->ingest->ping();
    }

    public function makeTraceId(): string
    {
        return str()->ulid()->toString();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        return $this->redactor->redactPayload($payload);
    }

    private function sourceSnippet(string $file, int $line): ?array
    {
        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            return null;
        }

        $start = max($line - 3, 1);
        $end = min($line + 2, count($lines));
        $snippet = [];

        for ($i = $start; $i <= $end; $i++) {
            $snippet[$i] = $lines[$i - 1];
        }

        return $snippet;
    }

    private function isoTime(): string
    {
        return now()->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function attachSpanOffsets(array $payload, ?int $durationMs = null, ?int $durationUs = null): array
    {
        $trace = $this->currentTrace;
        if ($trace === null) {
            return $payload;
        }

        $endOffsetUs = $trace->offsetUsAt();
        $resolvedDurationUs = $durationUs;
        if ($resolvedDurationUs === null && $durationMs !== null) {
            $resolvedDurationUs = max(1, $durationMs * 1000);
        }

        if ($resolvedDurationUs === null && is_numeric(Arr::get($payload, 'duration_us'))) {
            $resolvedDurationUs = max(1, (int) round((float) Arr::get($payload, 'duration_us', 1)));
        }

        if ($resolvedDurationUs === null) {
            $durationValue = $this->resolveNumeric(
                $payload,
                ['duration_ms', 'time_ms'],
                default: -1.0,
            );

            if ($durationValue >= 0) {
                $resolvedDurationUs = max(1, (int) round($durationValue * 1000));
            }
        }

        if ($resolvedDurationUs !== null) {
            $resolvedDurationUs = max(1, $resolvedDurationUs);
            $payload['duration_us'] = $resolvedDurationUs;
            $payload['duration_ms'] = max(1, (int) round($resolvedDurationUs / 1000));
            $payload['start_offset_us'] = max(0, $endOffsetUs - $resolvedDurationUs);
            $payload['end_offset_us'] = $endOffsetUs;
            $payload['start_offset_ms'] = max(0, (int) round(((int) $payload['start_offset_us']) / 1000));
            $payload['end_offset_ms'] = max(0, (int) round($endOffsetUs / 1000));

            return $payload;
        }

        $payload['start_offset_us'] = $endOffsetUs;
        $payload['start_offset_ms'] = max(0, (int) round($endOffsetUs / 1000));

        return $payload;
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

    private function jobKey(JobProcessing|JobProcessed|JobFailed $event): string
    {
        $jobId = method_exists($event->job, 'uuid') ?
            ($event->job->uuid() ?: spl_object_id($event->job)) :
            spl_object_id($event->job);

        return $event->connectionName . '|' . $event->job->resolveName() . '|' . $jobId;
    }

    private function reportRuntimeException(string $operation, Throwable $exception): void
    {
        $message = $exception->getMessage();
        $message = $message === '' ? '[empty-message]' : $message;
        $signature = hash('xxh128', $operation . '|' . $exception::class . '|' . $message);
        $now = microtime(true);
        $cooldown = max(
            1.0,
            (float) $this->config('instrumentation.runtime_error_cooldown_seconds', self::DEFAULT_RUNTIME_ERROR_COOLDOWN_SECONDS),
        );

        $lastReportedAt = $this->runtimeErrorReportedAt[$signature] ?? null;
        if (is_numeric($lastReportedAt) && ($now - (float) $lastReportedAt) < $cooldown) {
            return;
        }

        $this->runtimeErrorReportedAt[$signature] = $now;

        try {
            logger()->warning('dozor.runtime.unrecoverable_exception_occurred', [
                'operation' => $operation,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable) {
        }
    }
}
