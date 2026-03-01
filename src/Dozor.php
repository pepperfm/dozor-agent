<?php

declare(strict_types=1);

namespace Dozor;

use Dozor\Contracts\DozorContract;
use Dozor\Contracts\IngestContract;
use Dozor\Context\TraceContext;
use Dozor\Filters\RequestFilter;
use Dozor\Redaction\PayloadRedactor;
use Dozor\Sampling\DeterministicSampler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Arr;
use Throwable;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function mb_substr;
use function round;
use function str_starts_with;

class Dozor implements DozorContract
{
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

        logger()->info('dozor.security.profile', [
            'capture_request_payload' => $this->config('capture_request_payload', false),
            'capture_exception_source_code' => $this->config('capture_exception_source_code', false),
            'capture_logs' => $this->config('instrumentation.capture_logs', false),
            'capture_events' => $this->config('instrumentation.capture_events', false),
            'limits' => [
                'max_payload_bytes' => (int) Arr::get($this->config, 'limits.max_payload_bytes', 16384),
                'oversize_behavior' => Arr::get($this->config, 'limits.oversize_behavior', 'drop'),
            ],
            'sampling' => [
                'requests' => $this->sampler->resolveRate('requests'),
                'jobs' => $this->sampler->resolveRate('jobs'),
                'exceptions' => $this->sampler->resolveRate('exceptions'),
                'outgoing_http' => $this->sampler->resolveRate('outgoing_http'),
                'logs' => $this->sampler->resolveRate('logs'),
                'events' => $this->sampler->resolveRate('events'),
            ],
        ]);
    }

    public function enabled(): bool
    {
        return (bool) $this->config('enabled', true);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    public function beginRequest(Request $request): TraceContext
    {
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

        logger()->debug('dozor.sampling.request_decision', [
            'trace_id' => $traceId,
            'suppressed' => $this->currentRequestSuppressed,
            'reason' => $this->currentRequestSuppressedReason,
            'path' => $request->path(),
            'method' => $request->method(),
            'sample_rate' => $this->sampler->resolveRate('requests'),
        ]);

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

        return $this->currentTrace = new TraceContext(
            traceId: $traceId,
            startedAt: microtime(true),
            requestMeta: $requestMeta,
        );
    }

    public function finishRequest(
        Request $request,
        mixed $response,
        float $startedAt,
        ?Throwable $e = null
    ): void {
        if (!$this->enabled()) {
            return;
        }

        if ($this->currentRequestSuppressed) {
            logger()->debug('dozor.sampling.request_dropped', [
                'trace_id' => $this->currentTrace?->traceId,
                'reason' => $this->currentRequestSuppressedReason,
                'path' => $request->path(),
            ]);

            $this->currentTrace = null;
            $this->currentRequestSuppressed = false;
            $this->currentRequestSuppressedReason = '';

            return;
        }

        $traceId = $this->currentTrace?->traceId ?? $this->makeTraceId();
        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : ($e ? 500 : 200);

        $payload = array_merge($this->currentTrace?->requestMeta ?? [], [
            'status' => $status,
            'duration_ms' => $durationMs,
            'request_id' => $request->headers->get('X-Request-Id'),
            'memory_peak_bytes' => memory_get_peak_usage(true),
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

        $this->currentTrace = null;
        $this->currentRequestSuppressed = false;
        $this->currentRequestSuppressedReason = '';
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
            logger()->debug('dozor.sampling.query_dropped', [
                'trace_id' => $traceId,
                'sample_rate' => $this->sampler->resolveRate('queries'),
            ]);

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
            'payload' => [
                'sql' => $captureSql ? mb_substr($event->sql, 0, $sqlMaxLength) : '[REDACTED_SQL]',
                'time_ms' => (float) $event->time,
                'connection' => $event->connectionName,
                'bindings_count' => count($event->bindings),
            ],
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
            logger()->debug('dozor.sampling.job_dropped', [
                'trace_id' => $traceId,
                'job' => $event->job->resolveName(),
                'sample_rate' => $this->sampler->resolveRate('jobs'),
            ]);

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
            'payload' => [
                'name' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'status' => $event instanceof JobFailed ? 'failed' : 'processed',
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'exception' => $event instanceof JobFailed && $event->exception ? [
                    'class' => $event->exception::class,
                    'message' => $event->exception->getMessage(),
                ] : null,
            ],
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
            logger()->debug('dozor.sampling.exception_dropped', [
                'trace_id' => $trace,
                'exception_class' => $e::class,
                'sample_rate' => $this->sampler->resolveRate('exceptions'),
            ]);

            return;
        }

        $payload = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'context' => $this->sanitizePayload($context),
        ];

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
            logger()->debug('dozor.sampling.outgoing_http_dropped', [
                'trace_id' => $traceId,
                'sample_rate' => $this->sampler->resolveRate('outgoing_http'),
                'url' => Arr::get($payload, 'url'),
            ]);

            return;
        }

        if (is_array(Arr::get($payload, 'request_headers'))) {
            $payload['request_headers'] = $this->redactor->redactHeaders((array) Arr::get($payload, 'request_headers', []));
        }

        if (is_array(Arr::get($payload, 'response_headers'))) {
            $payload['response_headers'] = $this->redactor->redactHeaders((array) Arr::get($payload, 'response_headers', []));
        }

        logger()->debug('dozor.instrumentation.outgoing_http.recorded', [
            'trace_id' => $traceId,
            'method' => Arr::get($payload, 'method'),
            'url' => Arr::get($payload, 'url'),
            'status_code' => Arr::get($payload, 'status_code'),
            'failed' => Arr::get($payload, 'failed', false),
        ]);

        $this->report([
            'type' => 'outgoing_http',
            'trace_id' => $traceId,
            'happened_at' => $this->isoTime(),
            'app' => $this->config('app_name'),
            'environment' => $this->config('environment'),
            'server' => $this->config('server'),
            'payload' => $payload,
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
            logger()->debug('dozor.sampling.log_dropped', [
                'trace_id' => $traceId,
                'level' => $level,
                'sample_rate' => $this->sampler->resolveRate('logs'),
            ]);

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
                'payload' => [
                    'level' => $level,
                    'message' => mb_substr($message, 0, 3000),
                    'context' => $this->sanitizePayload($context),
                ],
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
            logger()->debug('dozor.sampling.event_dropped', [
                'trace_id' => $traceId,
                'event_name' => $eventName,
                'sample_rate' => $this->sampler->resolveRate('events'),
            ]);

            return;
        }

        $this->report([
            'type' => 'event',
            'trace_id' => $traceId,
            'happened_at' => $this->isoTime(),
            'app' => $this->config('app_name'),
            'environment' => $this->config('environment'),
            'server' => $this->config('server'),
            'payload' => [
                'name' => $eventName,
                'data' => $this->sanitizePayload($payload),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $record
     */
    public function report(array $record, bool $immediate = false): void
    {
        if (!$this->enabled()) {
            return;
        }

        $record['deployment'] ??= $this->config('deployment');
        $record['release'] ??= $this->config('release', $this->config('deployment'));

        $payload = Arr::get($record, 'payload');
        if (is_array($payload)) {
            $payload = $this->redactor->redactPayload($payload);
            $limitedPayload = $this->redactor->enforcePayloadLimit($payload, (string) Arr::get($record, 'type', 'unknown'));

            if ($limitedPayload === null) {
                logger()->warning('dozor.redaction.payload_dropped', [
                    'record_type' => Arr::get($record, 'type', 'unknown'),
                    'trace_id' => Arr::get($record, 'trace_id'),
                ]);

                return;
            }

            $record['payload'] = $limitedPayload;
        }

        if ($immediate) {
            $this->ingest->writeNow($record);

            return;
        }

        $this->ingest->write($record);
    }

    public function digest(): void
    {
        $this->ingest->digest();
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
        return now()->toIso8601String();
    }

    private function jobKey(JobProcessing|JobProcessed|JobFailed $event): string
    {
        $jobId = method_exists($event->job, 'uuid') ?
            ($event->job->uuid() ?: spl_object_id($event->job)) :
            spl_object_id($event->job);

        return $event->connectionName . '|' . $event->job->resolveName() . '|' . $jobId;
    }
}
