<?php

declare(strict_types=1);

namespace Dozor\Tests\Integration;

use Dozor\Agent\SpoolQueue;
use Dozor\Tests\TestCase;

use function usleep;

final class SpoolQueueRetryTest extends TestCase
{
    public function test_it_retries_then_uploads_batch_successfully(): void
    {
        $queue = new SpoolQueue(
            spoolPath: $this->makeTemporaryDirectory('dozor-spool-success'),
            maxAttemptsPerBatch: 3,
            queueBackoffBaseMs: 1,
            queueBackoffCapMs: 2,
        );

        $queue->enqueue([
            'traces' => [
                ['id' => 'trace-success'],
            ],
        ]);

        $attempts = 0;

        $queue->drain(static function () use (&$attempts): bool {
            $attempts++;

            return $attempts >= 2;
        }, 10);

        usleep(3_000);

        $queue->drain(static function () use (&$attempts): bool {
            $attempts++;

            return $attempts >= 2;
        }, 10);

        self::assertSame(2, $attempts);
        self::assertSame(0, $queue->queuedBatchesCount());
        self::assertSame(0, $queue->failedBatchesCount());
    }

    public function test_it_moves_batch_to_failed_after_max_attempts(): void
    {
        $queue = new SpoolQueue(
            spoolPath: $this->makeTemporaryDirectory('dozor-spool-failed'),
            maxAttemptsPerBatch: 3,
            queueBackoffBaseMs: 1,
            queueBackoffCapMs: 2,
        );

        $queue->enqueue([
            'traces' => [
                ['id' => 'trace-failed'],
            ],
        ]);

        $queue->drain(static fn(): bool => false, 10);
        usleep(3_000);
        $queue->drain(static fn(): bool => false, 10);
        usleep(3_000);
        $queue->drain(static fn(): bool => false, 10);

        self::assertSame(0, $queue->queuedBatchesCount());
        self::assertSame(1, $queue->failedBatchesCount());
    }
}
