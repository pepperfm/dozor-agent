<?php

declare(strict_types=1);

namespace Dozor\Watchers;

use Dozor\Contracts\DozorContract;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Throwable;

use function is_numeric;
use function parse_url;
use function round;

final class HttpWatcher
{
    /**
     * @var array<int, float>
     */
    private array $startedAt = [];

    public function __construct(private readonly DozorContract $core)
    {
    }

    public function requestSending(RequestSending $event): void
    {
        $requestId = spl_object_id($event->request);
        $this->startedAt[$requestId] = microtime(true);

        logger()->debug('dozor.instrumentation.outgoing_http.request_sending', [
            'request_id' => $requestId,
            'method' => $event->request->method(),
            'url' => $event->request->url(),
        ]);
    }

    public function responseReceived(ResponseReceived $event): void
    {
        $requestId = spl_object_id($event->request);
        $durationMs = $this->resolveDurationMs($requestId);

        if ($durationMs === null) {
            $totalTime = $event->response->handlerStats()['total_time'] ?? null;
            if (is_numeric($totalTime)) {
                $durationMs = (float) $totalTime * 1000;
            }
        }

        $host = parse_url($event->request->url(), PHP_URL_HOST);

        $payload = [
            'method' => $event->request->method(),
            'url' => $event->request->url(),
            'host' => is_string($host) ? $host : null,
            'status_code' => $event->response->status(),
            'duration_ms' => $durationMs !== null ? max(1, (int) round($durationMs)) : null,
            'failed' => !$event->response->successful(),
        ];

        $this->capture($payload, phase: 'response_received');
    }

    public function connectionFailed(ConnectionFailed $event): void
    {
        $requestId = spl_object_id($event->request);
        $durationMs = $this->resolveDurationMs($requestId);
        $host = parse_url($event->request->url(), PHP_URL_HOST);

        $payload = [
            'method' => $event->request->method(),
            'url' => $event->request->url(),
            'host' => is_string($host) ? $host : null,
            'status_code' => null,
            'duration_ms' => $durationMs !== null ? max(1, (int) round($durationMs)) : null,
            'failed' => true,
            'error_class' => $event->exception::class,
            'error_message' => $event->exception->getMessage(),
        ];

        $this->capture($payload, phase: 'connection_failed');
    }

    private function resolveDurationMs(int $requestId): ?float
    {
        $startedAt = $this->startedAt[$requestId] ?? null;
        unset($this->startedAt[$requestId]);

        if (!is_numeric($startedAt)) {
            return null;
        }

        return (microtime(true) - (float) $startedAt) * 1000;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function capture(array $payload, string $phase): void
    {
        try {
            $this->core->recordOutgoingHttp($payload);
        } catch (Throwable $e) {
            logger()->warning('dozor.instrumentation.outgoing_http.capture_failed', [
                'phase' => $phase,
                'class' => $e::class,
                'message' => $e->getMessage(),
                'method' => $payload['method'] ?? null,
                'url' => $payload['url'] ?? null,
            ]);
        }
    }
}
