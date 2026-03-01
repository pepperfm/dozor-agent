<?php

declare(strict_types=1);

namespace Dozor;

use Dozor\Contracts\DozorContract;
use Dozor\Contracts\IngestContract;
use Dozor\Context\TraceContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Arr;
use Throwable;

class Dozor implements DozorContract
{
    public ?TraceContext $currentTrace = null;

    /**
     * @var array<string, float>
     */
    private array $jobStartedAt = [];

    private bool $capturingLogs = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly IngestContract $ingest,
        private readonly array $config,
    ) {
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

        return $this->currentTrace = new TraceContext(
            traceId: $traceId,
            startedAt: microtime(true),
            requestMeta: [
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
            ],
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

        $traceId = $this->currentTrace?->traceId ?? $this->makeTraceId();
        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : ($e ? 500 : 200);

        $payload = array_merge($this->currentTrace?->requestMeta ?? [], [
            'status' => $status,
            'duration_ms' => $durationMs,
            'request_id' => $request->headers->get('X-Request-Id'),
            'memory_peak_bytes' => memory_get_peak_usage(true),
        ]);

        if ($this->config('capture_request_payload', false) && !$this->config('filtering.ignore_request_payload', false)) {
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
            'deployment' => $this->config('deployment'),
            'payload' => $payload,
        ]);

        $this->currentTrace = null;
    }

    public function recordQuery(QueryExecuted $event): void
    {
        if (!$this->enabled() || $this->config('filtering.ignore_queries', false)) {
            return;
        }

        $this->report([
            'type' => 'query',
            'trace_id' => $this->currentTrace?->traceId ?? $this->makeTraceId(),
            'happened_at' => $this->isoTime(),
            'app' => $this->config('app_name'),
            'environment' => $this->config('environment'),
            'server' => $this->config('server'),
            'payload' => [
                'sql' => $event->sql,
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

        $key = $this->jobKey($event);
        $startedAt = $this->jobStartedAt[$key] ?? microtime(true);
        unset($this->jobStartedAt[$key]);

        $this->report([
            'type' => 'job',
            'trace_id' => $this->currentTrace?->traceId ?? $this->makeTraceId(),
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

        $payload = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'context' => $context,
        ];

        if ($this->config('capture_exception_source_code', false)) {
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
        if (!$this->enabled() || $this->config('filtering.ignore_outgoing_http', false)) {
            return;
        }

        logger()->debug('dozor.instrumentation.outgoing_http.recorded', [
            'trace_id' => $this->currentTrace?->traceId,
            'method' => Arr::get($payload, 'method'),
            'url' => Arr::get($payload, 'url'),
            'status_code' => Arr::get($payload, 'status_code'),
            'failed' => Arr::get($payload, 'failed', false),
        ]);

        $this->report([
            'type' => 'outgoing_http',
            'trace_id' => $this->currentTrace?->traceId ?? $this->makeTraceId(),
            'happened_at' => $this->isoTime(),
            'app' => $this->config('app_name'),
            'environment' => $this->config('environment'),
            'server' => $this->config('server'),
            'payload' => $payload,
        ]);
    }

    public function recordLogMessage(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled() || !$this->config('instrumentation.capture_logs', true)) {
            return;
        }

        if ($this->capturingLogs || str_starts_with($message, 'dozor.')) {
            return;
        }

        $this->capturingLogs = true;

        try {
            $this->report([
                'type' => 'log',
                'trace_id' => $this->currentTrace?->traceId ?? $this->makeTraceId(),
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
        if (!$this->enabled() || !$this->config('instrumentation.capture_events', true)) {
            return;
        }

        $this->report([
            'type' => 'event',
            'trace_id' => $this->currentTrace?->traceId ?? $this->makeTraceId(),
            'happened_at' => $this->isoTime(),
            'app' => $this->config('app_name'),
            'environment' => $this->config('environment'),
            'server' => $this->config('server'),
            'payload' => [
                'name' => $eventName,
                'data' => $payload,
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
        $fields = array_values(array_filter(array_map('trim', (array) $this->config('redact_payload_fields', []))));

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sanitizePayload($value);
            }

            if (is_string($key) && in_array($key, $fields, true)) {
                $payload[$key] = '[REDACTED]';
            }
        }

        return $payload;
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
