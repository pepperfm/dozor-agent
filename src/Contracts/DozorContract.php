<?php

declare(strict_types=1);

namespace Dozor\Contracts;

use Dozor\Context\TraceContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;

interface DozorContract
{
    public function enabled(): bool;

    public function beginRequest(Request $request, ?float $startedAt = null): TraceContext;

    public function finishRequest(
        Request $request,
        mixed $response,
        float $startedAt,
        ?\Throwable $e = null
    ): void;

    public function recordQuery(QueryExecuted $event): void;

    public function recordJobStarted(JobProcessing $event): void;

    public function recordQueueLoop(Looping $event): bool;

    public function recordJobFinished(JobProcessed|JobFailed $event): void;

    public function recordJobReleased(JobReleasedAfterException $event): void;

    public function noteJobAttempt(JobAttempted $event): void;

    public function resetQueueWorker(): void;

    /**
     * @param array<string, mixed> $metadata
     */
    public function beginLifecycleStage(string $stage, array $metadata = []): void;

    /**
     * @param array<string, mixed> $metadata
     */
    public function endLifecycleStage(string $stage, array $metadata = []): void;

    /**
     * @param array<string, mixed> $metadata
     */
    public function transitionLifecycleStage(string $stage, array $metadata = []): void;

    public function lifecycleStageIs(string $stage): bool;

    public function hasActiveRequestTrace(): bool;

    public function abandonActiveRequest(): void;

    /**
     * @param list<string> $patterns
     */
    public function exclude(array $patterns): void;

    public function suppressIfExcluded(Request $request): void;

    /**
     * @param array<string, mixed> $context
     */
    public function recordException(\Throwable $e, array $context = []): void;

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

    public function startFrameworkCommand(string $command): void;

    public function finishFrameworkCommand(string $command): void;

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordFrameworkEvent(string $name, array $metadata = []): void;

    public function digest(): void;

    public function ping(): void;
}
