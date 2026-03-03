<?php

declare(strict_types=1);

namespace Dozor\Contracts;

use Dozor\Context\TraceContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

interface DozorContract
{
    public function enabled(): bool;

    public function beginRequest(Request $request, ?float $startedAt = null): TraceContext;

    public function finishRequest(
        Request $request,
        mixed $response,
        float $startedAt,
        ?Throwable $e = null
    ): void;

    public function recordQuery(QueryExecuted $event): void;

    public function recordJobStarted(JobProcessing $event): void;

    public function recordJobFinished(JobProcessed|JobFailed $event): void;

    /**
     * @param array<string, mixed> $metadata
     */
    public function beginLifecycleStage(string $stage, array $metadata = []): void;

    /**
     * @param array<string, mixed> $metadata
     */
    public function endLifecycleStage(string $stage, array $metadata = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function recordException(Throwable $e, array $context = []): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function recordOutgoingHttp(array $payload): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function recordCacheEvent(array $payload): void;

    /**
     * @param array<string, mixed> $context
     */
    public function recordLogMessage(string $level, string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function recordApplicationEvent(string $eventName, array $payload = []): void;

    public function digest(): void;

    public function ping(): void;
}
