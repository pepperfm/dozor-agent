<?php

declare(strict_types=1);

namespace Dozor\Agent;

use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

use function count;
use function glob;
use function is_array;
use function is_string;
use function microtime;
use function mkdir;
use function rtrim;
use function sort;
use function sprintf;
use function str_contains;
use function time;
use function unlink;

final class SpoolQueue
{
    public function __construct(
        private readonly string $spoolPath,
        private readonly int $maxAttemptsPerBatch = 8,
        private readonly int $queueBackoffBaseMs = 500,
        private readonly int $queueBackoffCapMs = 30_000,
    ) {
    }

    public function ensureDirectories(): void
    {
        $this->ensureDirectory($this->queueDirectory());
        $this->ensureDirectory($this->failedDirectory());
    }

    public function queuedBatchesCount(): int
    {
        return $this->countFiles($this->queueDirectory() . DIRECTORY_SEPARATOR . '*.json');
    }

    public function failedBatchesCount(): int
    {
        return $this->countFiles($this->failedDirectory() . DIRECTORY_SEPARATOR . '*.json');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(array $payload): string
    {
        $this->ensureDirectories();

        $batchId = $this->makeBatchId();
        $path = $this->queueDirectory() . DIRECTORY_SEPARATOR . sprintf('%d-%s.json', time(), $batchId);
        $envelope = [
            'id' => $batchId,
            'attempt' => 0,
            'next_attempt_at' => 0.0,
            'created_at' => microtime(true),
            'payload' => $payload,
        ];

        $this->writeEnvelopeAtomically($path, $envelope);

        logger()->debug('dozor.agent.shipper.batch_enqueued', [
            'batch_id' => $batchId,
            'queue_file' => $path,
            'records_count' => $this->recordsCount($payload),
        ]);

        return $batchId;
    }

    /**
     * @param callable(array<string, mixed>, string, int): bool $uploader
     */
    public function drain(callable $uploader, int $maxBatches): void
    {
        if ($maxBatches <= 0) {
            return;
        }

        $this->ensureDirectories();

        $files = glob($this->queueDirectory() . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false || $files === []) {
            return;
        }

        sort($files, SORT_STRING);
        $processed = 0;

        foreach ($files as $file) {
            if ($processed >= $maxBatches) {
                break;
            }

            $envelope = $this->readEnvelope($file);
            if ($envelope === null) {
                $this->moveToFailed($file, 'unknown', 'corrupted');

                continue;
            }

            $nextAttemptAt = (float) Arr::get($envelope, 'next_attempt_at', 0.0);
            if ($nextAttemptAt > microtime(true)) {
                continue;
            }

            $batchId = (string) Arr::get($envelope, 'id', 'unknown');
            $attempt = (int) Arr::get($envelope, 'attempt', 0) + 1;
            $payload = Arr::get($envelope, 'payload');

            if (!is_array($payload)) {
                logger()->error('dozor.agent.shipper.spool_corruption', [
                    'batch_id' => $batchId,
                    'queue_file' => $file,
                    'reason' => 'payload_is_not_array',
                ]);

                $this->moveToFailed($file, $batchId, 'corrupted');

                continue;
            }

            $processed++;

            $uploaded = false;

            try {
                $uploaded = $uploader($payload, $batchId, $attempt);
            } catch (Throwable $e) {
                logger()->error('dozor.agent.shipper.upload_callback_failed', [
                    'batch_id' => $batchId,
                    'attempt' => $attempt,
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }

            if ($uploaded) {
                if (is_string($file) && $file !== '' && file_exists($file)) {
                    @unlink($file);
                }

                logger()->info('dozor.agent.shipper.batch_uploaded', [
                    'batch_id' => $batchId,
                    'attempt' => $attempt,
                    'records_count' => $this->recordsCount($payload),
                ]);

                continue;
            }

            if ($attempt >= $this->maxAttemptsPerBatch) {
                logger()->warning('dozor.agent.shipper.batch_dropped', [
                    'batch_id' => $batchId,
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttemptsPerBatch,
                    'records_count' => $this->recordsCount($payload),
                ]);

                $this->moveToFailed($file, $batchId, 'max-attempts');

                continue;
            }

            $backoffMs = $this->calculateBackoffMs($attempt);
            $envelope['attempt'] = $attempt;
            $envelope['next_attempt_at'] = microtime(true) + ($backoffMs / 1000);
            $envelope['last_failed_at'] = microtime(true);

            $this->writeEnvelopeAtomically($file, $envelope);

            logger()->warning('dozor.agent.shipper.retry_scheduled', [
                'batch_id' => $batchId,
                'attempt' => $attempt,
                'retry_in_ms' => $backoffMs,
                'records_count' => $this->recordsCount($payload),
            ]);
        }
    }

    private function queueDirectory(): string
    {
        return rtrim($this->spoolPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'queue';
    }

    private function failedDirectory(): string
    {
        return rtrim($this->spoolPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'failed';
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException("Unable to create spool directory [$path]");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readEnvelope(string $file): ?array
    {
        try {
            $raw = file_get_contents($file);
            if ($raw === false || $raw === '') {
                throw new RuntimeException('empty_spool_file');
            }

            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('spool_json_is_not_array');
            }

            return $decoded;
        } catch (Throwable $e) {
            logger()->error('dozor.agent.shipper.spool_corruption', [
                'queue_file' => $file,
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function writeEnvelopeAtomically(string $file, array $envelope): void
    {
        $tempFile = $file . '.tmp-' . $this->makeBatchId();

        $json = json_encode(
            $envelope,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        file_put_contents($tempFile, $json . PHP_EOL, LOCK_EX);

        if (!@rename($tempFile, $file)) {
            @unlink($tempFile);

            throw new RuntimeException("Unable to move spool temp file [$tempFile] to [$file]");
        }
    }

    private function moveToFailed(string $file, string $batchId, string $reason): void
    {
        $this->ensureDirectories();

        $safeReason = str_contains($reason, '/') ? 'invalid-reason' : $reason;
        $target = $this->failedDirectory() . DIRECTORY_SEPARATOR . sprintf(
            '%d-%s-%s.json',
            time(),
            $safeReason,
            $batchId !== '' ? $batchId : 'unknown'
        );

        if (@rename($file, $target)) {
            if ($reason === 'max-attempts') {
                logger()->warning('dozor.agent.shipper.batch_moved_to_failed', [
                    'batch_id' => $batchId,
                    'source_file' => $file,
                    'target_file' => $target,
                    'reason' => $reason,
                ]);
            } else {
                logger()->error('dozor.agent.shipper.batch_moved_to_failed', [
                    'batch_id' => $batchId,
                    'source_file' => $file,
                    'target_file' => $target,
                    'reason' => $reason,
                ]);
            }

            return;
        }

        logger()->error('dozor.agent.shipper.failed_move_failed', [
            'batch_id' => $batchId,
            'source_file' => $file,
            'reason' => $reason,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordsCount(array $payload): int
    {
        $records = Arr::get($payload, 'records', []);

        return is_array($records) ? count($records) : 0;
    }

    private function calculateBackoffMs(int $attempt): int
    {
        $raw = $this->queueBackoffBaseMs * (2 ** ($attempt - 1));

        return min($this->queueBackoffCapMs, $raw);
    }

    private function makeBatchId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function countFiles(string $pattern): int
    {
        $files = glob($pattern);

        if ($files === false) {
            return 0;
        }

        return count($files);
    }
}
