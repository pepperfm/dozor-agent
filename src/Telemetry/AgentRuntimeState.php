<?php

declare(strict_types=1);

namespace Dozor\Telemetry;

use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

use function bin2hex;
use function dirname;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function random_bytes;
use function sprintf;
use function unlink;

final class AgentRuntimeState
{
    public function __construct(
        private readonly string $statePath,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return $this->withDefaults($this->readRaw());
    }

    public function markStarted(
        string $listenOn,
        string $serverName,
        string $appName,
        string $environment,
        bool $shipperEnabled,
    ): void {
        $this->update(function (array $state) use ($listenOn, $serverName, $appName, $environment, $shipperEnabled): array {
            $state = $this->withDefaults($state);
            $state['lifecycle']['status'] = 'running';
            $state['lifecycle']['started_at'] = $this->nowIso();
            $state['lifecycle']['stopped_at'] = null;
            $state['context']['listen_on'] = $listenOn;
            $state['context']['server'] = $serverName;
            $state['context']['app'] = $appName;
            $state['context']['environment'] = $environment;
            $state['context']['shipper_enabled'] = $shipperEnabled;

            return $state;
        });
    }

    public function markShutdownRequested(string $signal): void
    {
        $this->update(function (array $state) use ($signal): array {
            $state = $this->withDefaults($state);
            $state['lifecycle']['status'] = 'stopping';
            $state['lifecycle']['shutdown_requested_at'] = $this->nowIso();
            $state['lifecycle']['shutdown_signal'] = $signal;

            return $state;
        });
    }

    public function markStopped(int $uptimeSeconds): void
    {
        $this->update(function (array $state) use ($uptimeSeconds): array {
            $state = $this->withDefaults($state);
            $state['lifecycle']['status'] = 'stopped';
            $state['lifecycle']['stopped_at'] = $this->nowIso();
            $state['metrics']['uptime_seconds'] = $uptimeSeconds;

            return $state;
        });
    }

    public function markHeartbeat(int $queueDepth, int $failedQueueDepth, int $uptimeSeconds): void
    {
        $this->update(function (array $state) use ($queueDepth, $failedQueueDepth, $uptimeSeconds): array {
            $state = $this->withDefaults($state);
            $state['lifecycle']['last_heartbeat_at'] = $this->nowIso();
            $state['metrics']['queue_depth'] = $queueDepth;
            $state['metrics']['failed_queue_depth'] = $failedQueueDepth;
            $state['metrics']['uptime_seconds'] = $uptimeSeconds;

            return $state;
        });
    }

    public function markFlush(bool $force, int $maxBatches, int $queueDepth, int $failedQueueDepth): void
    {
        $this->update(function (array $state) use ($force, $maxBatches, $queueDepth, $failedQueueDepth): array {
            $state = $this->withDefaults($state);
            $state['lifecycle']['last_flush_at'] = $this->nowIso();
            $state['metrics']['queue_depth'] = $queueDepth;
            $state['metrics']['failed_queue_depth'] = $failedQueueDepth;
            $state['flush']['force'] = $force;
            $state['flush']['max_batches'] = $maxBatches;

            return $state;
        });
    }

    public function incrementFailedUploads(string $batchId, int $attempt, string $reason): void
    {
        $this->update(function (array $state) use ($batchId, $attempt, $reason): array {
            $state = $this->withDefaults($state);
            $state['metrics']['failed_uploads'] = (int) Arr::get($state, 'metrics.failed_uploads', 0) + 1;
            $state['metrics']['last_upload_failure_at'] = $this->nowIso();
            $state['metrics']['last_upload_failure_reason'] = $reason;
            $state['metrics']['last_upload_batch_id'] = $batchId;
            $state['metrics']['last_upload_attempt'] = $attempt;

            return $state;
        });
    }

    public function markUploadSuccess(string $batchId, int $attempt, int $recordsCount): void
    {
        $this->update(function (array $state) use ($batchId, $attempt, $recordsCount): array {
            $state = $this->withDefaults($state);
            $state['metrics']['last_upload_success_at'] = $this->nowIso();
            $state['metrics']['last_upload_batch_id'] = $batchId;
            $state['metrics']['last_upload_attempt'] = $attempt;
            $state['metrics']['last_upload_records'] = $recordsCount;

            return $state;
        });
    }

    public function incrementDroppedBatches(string $batchId, string $reason): void
    {
        $this->update(function (array $state) use ($batchId, $reason): array {
            $state = $this->withDefaults($state);
            $state['metrics']['dropped_batches'] = (int) Arr::get($state, 'metrics.dropped_batches', 0) + 1;
            $state['metrics']['last_dropped_batch_at'] = $this->nowIso();
            $state['metrics']['last_dropped_batch_id'] = $batchId;
            $state['metrics']['last_drop_reason'] = $reason;

            return $state;
        });
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function update(callable $mutator): void
    {
        try {
            $state = $mutator($this->readRaw());
            $state = $this->withDefaults($state);
            $state['lifecycle']['updated_at'] = $this->nowIso();

            $this->write($state);
        } catch (Throwable) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readRaw(): array
    {
        if (!is_file($this->statePath)) {
            return [];
        }

        try {
            $raw = file_get_contents($this->statePath);

            if (!is_string($raw) || $raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function write(array $state): void
    {
        $directory = dirname($this->statePath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create telemetry directory [$directory]");
        }

        $tempPath = sprintf('%s.tmp-%s', $this->statePath, bin2hex(random_bytes(6)));

        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($tempPath, $json . PHP_EOL, LOCK_EX);

        if (!@rename($tempPath, $this->statePath)) {
            @unlink($tempPath);

            throw new RuntimeException("Unable to persist telemetry state [$this->statePath]");
        }
    }

    private function nowIso(): string
    {
        return now()->toIso8601String();
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function withDefaults(array $state): array
    {
        return [
            'lifecycle' => [
                'status' => (string) Arr::get($state, 'lifecycle.status', 'unknown'),
                'started_at' => Arr::get($state, 'lifecycle.started_at'),
                'stopped_at' => Arr::get($state, 'lifecycle.stopped_at'),
                'shutdown_requested_at' => Arr::get($state, 'lifecycle.shutdown_requested_at'),
                'shutdown_signal' => Arr::get($state, 'lifecycle.shutdown_signal'),
                'last_heartbeat_at' => Arr::get($state, 'lifecycle.last_heartbeat_at'),
                'last_flush_at' => Arr::get($state, 'lifecycle.last_flush_at'),
                'updated_at' => Arr::get($state, 'lifecycle.updated_at'),
            ],
            'context' => [
                'listen_on' => Arr::get($state, 'context.listen_on'),
                'server' => Arr::get($state, 'context.server'),
                'app' => Arr::get($state, 'context.app'),
                'environment' => Arr::get($state, 'context.environment'),
                'shipper_enabled' => (bool) Arr::get($state, 'context.shipper_enabled', false),
            ],
            'metrics' => [
                'queue_depth' => (int) Arr::get($state, 'metrics.queue_depth', 0),
                'failed_queue_depth' => (int) Arr::get($state, 'metrics.failed_queue_depth', 0),
                'dropped_batches' => (int) Arr::get($state, 'metrics.dropped_batches', 0),
                'failed_uploads' => (int) Arr::get($state, 'metrics.failed_uploads', 0),
                'uptime_seconds' => (int) Arr::get($state, 'metrics.uptime_seconds', 0),
                'last_upload_failure_at' => Arr::get($state, 'metrics.last_upload_failure_at'),
                'last_upload_failure_reason' => Arr::get($state, 'metrics.last_upload_failure_reason'),
                'last_upload_success_at' => Arr::get($state, 'metrics.last_upload_success_at'),
                'last_upload_batch_id' => Arr::get($state, 'metrics.last_upload_batch_id'),
                'last_upload_attempt' => Arr::get($state, 'metrics.last_upload_attempt'),
                'last_upload_records' => Arr::get($state, 'metrics.last_upload_records'),
                'last_dropped_batch_at' => Arr::get($state, 'metrics.last_dropped_batch_at'),
                'last_dropped_batch_id' => Arr::get($state, 'metrics.last_dropped_batch_id'),
                'last_drop_reason' => Arr::get($state, 'metrics.last_drop_reason'),
            ],
            'flush' => [
                'force' => (bool) Arr::get($state, 'flush.force', false),
                'max_batches' => (int) Arr::get($state, 'flush.max_batches', 0),
            ],
        ];
    }
}
