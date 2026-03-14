<?php

declare(strict_types=1);

namespace Dozor\Agent;

use Dozor\Agent\Transport\HostedIngestTransport;
use Dozor\Telemetry\AgentRuntimeState;
use Dozor\Tracing\TraceBatchTransformer;
use JsonException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_chunk;
use function count;
use function defined;
use function Dozor\fclose_safely;
use function Dozor\fread_all;
use function Dozor\fwrite_all;
use function Dozor\stream_configure_read_timeout;
use function function_exists;
use function is_array;
use function is_resource;
use function max;
use function microtime;
use function sprintf;
use function stream_get_meta_data;
use function stream_socket_get_name;
use function strlen;

final class Server
{
    private const MAX_FRAME_BYTES = 1_048_576;

    private bool $running = true;

    private float $lastFlushAt = 0.0;

    private float $startedAt = 0.0;

    private float $lastHeartbeatAt = 0.0;

    private float $nextFlushAt = 0.0;

    public function __construct(
        private readonly string $listenOn,
        private readonly string $tokenHash,
        private readonly string $storePath,
        private ?OutputInterface $output = null,
        private readonly string $serverName = 'dozor-agent',
        private readonly string $appName = 'laravel',
        private readonly string $environment = 'production',
        private readonly ?string $release = null,
        private readonly ?SpoolQueue $spoolQueue = null,
        private readonly ?HostedIngestTransport $shipper = null,
        private readonly ?TraceBatchTransformer $traceBatchTransformer = null,
        private readonly int $shipBatchSize = 100,
        private readonly int $shipMaxBatchesPerFlush = 10,
        private readonly float $shipFlushIntervalSeconds = 2.0,
        private readonly float $heartbeatIntervalSeconds = 15.0,
        private readonly ?AgentRuntimeState $runtimeState = null,
    ) {
    }

    public function run(): void
    {
        $address = str_starts_with($this->listenOn, 'tcp://') ? $this->listenOn : 'tcp://' . $this->listenOn;
        $server = @stream_socket_server($address, $errorCode, $errorMessage);

        if ($server === false) {
            logger()->error('dozor.agent.lifecycle.fatal_exit', [
                'reason' => 'listen_socket_start_failed',
                'listen_on' => $this->listenOn,
                'address' => $address,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

            throw new \RuntimeException(sprintf('Unable to start Dozor agent on %s [%s:%s]', $address, $errorCode, $errorMessage));
        }

        $this->ensureStorePath();
        $this->spoolQueue?->ensureDirectories();
        $this->installSignalHandlers();
        $this->lastFlushAt = microtime(true);
        $this->startedAt = microtime(true);
        $this->lastHeartbeatAt = microtime(true);
        $this->nextFlushAt = $this->nextFlushDeadline($this->lastFlushAt);

        $this->line(sprintf('Dozor agent listening on %s (%s)', $this->listenOn, $this->serverName));
        $this->runtimeState?->markStarted(
            listenOn: $this->listenOn,
            serverName: $this->serverName,
            appName: $this->appName,
            environment: $this->environment,
            shipperEnabled: $this->shipper?->enabled() ?? false,
        );

        while ($this->running) {
            $client = @stream_socket_accept($server, 1);
            if (!is_resource($client)) {
                $this->emitHeartbeatIfDue();
                $this->flushQueuedBatches();

                continue;
            }

            try {
                $this->handleClient($client);
                fwrite_all($client, '2:OK');
            } catch (Throwable $e) {
                logger()->error('dozor.agent.ingest.client_error', [
                    'message' => $e->getMessage(),
                    'class' => $e::class,
                    'max_frame_bytes' => self::MAX_FRAME_BYTES,
                    ...$this->makeClientLogContext($client),
                ]);
                $this->line('Agent error: ' . $e->getMessage());
                @fwrite($client, '5:ER');
            } finally {
                fclose_safely($client);
            }

            $this->emitHeartbeatIfDue();
            $this->flushQueuedBatches(force: $this->shouldForceFlushByQueueDepth());
        }

        $this->flushQueuedBatches(force: true);
        $this->runtimeState?->markStopped((int) max(0, round(microtime(true) - $this->startedAt)));

        fclose_safely($server);
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, function (): void {
                $this->requestShutdown('SIGINT');
            });
        }

        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, function (): void {
                $this->requestShutdown('SIGTERM');
            });
        }
    }

    private function requestShutdown(string $signal): void
    {
        $this->running = false;
        $this->runtimeState?->markShutdownRequested($signal);

        $this->line("Shutdown requested by {$signal}. Draining queued batches...");
    }

    /**
     * @param resource $client
     */
    private function handleClient($client): void
    {
        stream_configure_read_timeout($client, 1.0);

        $length = $this->readFrameLength($client);
        $frame = fread_all($client, $length);
        $parts = explode(':', $frame, 3);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed ingest frame: expected version, token hash and payload');
        }

        [$version, $tokenHash, $payload] = $parts;

        if ($version !== 'v1') {
            throw new \RuntimeException("Unsupported payload version [$version]");
        }
        if ($this->tokenHash !== '' && $tokenHash !== $this->tokenHash) {
            throw new \RuntimeException('Invalid token hash for ingest request');
        }
        if ($payload === 'PING') {
            return;
        }

        try {
            $records = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException(
                sprintf(
                    'Invalid ingest payload JSON [bytes: %d, error: %s]',
                    strlen($payload),
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }

        if (!is_array($records)) {
            throw new \RuntimeException('Decoded payload is not a record list');
        }

        if ($this->shipper?->enabled() === true && $this->spoolQueue !== null) {
            $this->enqueueRecords($records);

            return;
        }

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $this->appendRecord($record);
        }
    }

    /**
     * @param resource $client
     */
    private function readFrameLength($client): int
    {
        $buffer = '';

        while (true) {
            $chunk = fread_all($client, 1);
            if ($chunk === '') {
                throw new \RuntimeException('Unexpected EOF while reading frame length');
            }
            if ($chunk === ':') {
                break;
            }

            $buffer .= $chunk;
        }

        if ($buffer === '0' || !ctype_digit($buffer)) {
            throw new \RuntimeException("Invalid frame length [$buffer]");
        }

        $length = (int) $buffer;
        if ($length > self::MAX_FRAME_BYTES) {
            throw new \RuntimeException(
                sprintf('Frame length exceeds maximum [%d > %d]', $length, self::MAX_FRAME_BYTES),
            );
        }

        return $length;
    }

    /**
     * @param resource $client
     *
     * @return array<string, int|string|bool|null>
     */
    private function makeClientLogContext($client): array
    {
        $meta = stream_get_meta_data($client);
        $peer = @stream_socket_get_name($client, true);

        return [
            'peer' => $peer === false ? null : $peer,
            'timed_out' => (bool) ($meta['timed_out'] ?? false),
            'eof' => (bool) ($meta['eof'] ?? false),
            'unread_bytes' => (int) ($meta['unread_bytes'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function appendRecord(array $record): void
    {
        $file = rtrim($this->storePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ingest-' . date('Y-m-d') . '.ndjson';
        file_put_contents(
            $file,
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * @param array<int, mixed> $records
     */
    private function enqueueRecords(array $records): void
    {
        $batchSize = $this->shipBatchSize > 0 ? $this->shipBatchSize : 100;
        $chunks = array_chunk($records, $batchSize);

        foreach ($chunks as $chunk) {
            $normalized = [];

            foreach ($chunk as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $normalized[] = $record;
            }

            if ($normalized === []) {
                continue;
            }

            $payload = $this->traceBatchTransformer?->transform($normalized)
                ?? $this->makeLegacyOutboundPayload($normalized);

            $this->spoolQueue?->enqueue($payload);
        }

        $forceFlush = count($chunks) >= $this->shipMaxBatchesPerFlush;
        if (!$forceFlush) {
            $forceFlush = $this->shouldForceFlushByQueueDepth();
        }

        $this->flushQueuedBatches(force: $forceFlush);
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return array<string, mixed>
     */
    private function makeLegacyOutboundPayload(array $records): array
    {
        return [
            'v' => 1,
            'contract_version' => 'agent-v0-records',
            'app' => [
                'name' => $this->appName,
            ],
            'environment' => $this->environment,
            'server' => $this->serverName,
            'release' => $this->release,
            'sent_at' => date(DATE_ATOM),
            'records' => $records,
        ];
    }

    private function flushQueuedBatches(bool $force = false): void
    {
        if ($this->spoolQueue === null || $this->shipper === null || !$this->shipper->enabled()) {
            return;
        }

        $now = microtime(true);
        if (!$force && $this->shouldSkipScheduledFlush($now)) {
            return;
        }

        $this->lastFlushAt = $now;
        $this->nextFlushAt = $this->nextFlushDeadline($now);
        $maxBatches = $force ? max($this->shipMaxBatchesPerFlush, 1000) : $this->shipMaxBatchesPerFlush;
        $shipper = $this->shipper;
        $queueDepth = $this->spoolQueue->queuedBatchesCount();
        $failedQueueDepth = $this->spoolQueue->failedBatchesCount();
        $this->runtimeState?->markFlush(
            force: $force,
            maxBatches: $maxBatches,
            queueDepth: $queueDepth,
            failedQueueDepth: $failedQueueDepth,
        );

        $this->spoolQueue->drain(
            static function (array $payload, string $batchId, int $attempt) use ($shipper): bool {
                return $shipper->ship($payload, $batchId, $attempt);
            },
            $maxBatches,
        );
    }

    private function shouldSkipScheduledFlush(float $now): bool
    {
        return $now < $this->nextFlushAt;
    }

    private function nextFlushDeadline(float $now): float
    {
        return $now + max(0.05, $this->shipFlushIntervalSeconds);
    }

    private function shouldForceFlushByQueueDepth(): bool
    {
        if (
            $this->spoolQueue === null
            || $this->shipMaxBatchesPerFlush <= 0
        ) {
            return false;
        }

        return $this->spoolQueue->queuedBatchesCount() >= $this->shipMaxBatchesPerFlush;
    }

    private function emitHeartbeatIfDue(): void
    {
        if (
            $this->spoolQueue === null
            || $this->shipper === null
            || !$this->shipper->enabled()
            || $this->heartbeatIntervalSeconds <= 0
        ) {
            return;
        }

        $now = microtime(true);

        if ($now - $this->lastHeartbeatAt < $this->heartbeatIntervalSeconds) {
            return;
        }

        $this->lastHeartbeatAt = $now;
        $queueDepth = $this->spoolQueue->queuedBatchesCount();
        $failedQueueDepth = $this->spoolQueue->failedBatchesCount();
        $uptimeSeconds = (int) max(0, round($now - $this->startedAt));

        $heartbeatRecord = [
            'type' => 'heartbeat',
            'trace_id' => (string) str()->ulid(),
            'happened_at' => now()->toIso8601String(),
            'app' => $this->appName,
            'environment' => $this->environment,
            'server' => $this->serverName,
            'release' => $this->release,
            'payload' => [
                'status' => 'healthy',
                'uptime_seconds' => $uptimeSeconds,
                'queue_depth' => $queueDepth,
                'failed_queue_depth' => $failedQueueDepth,
                'memory_peak_bytes' => memory_get_peak_usage(true),
            ],
        ];
        $this->runtimeState?->markHeartbeat(
            queueDepth: $queueDepth,
            failedQueueDepth: $failedQueueDepth,
            uptimeSeconds: $uptimeSeconds,
        );

        $this->enqueueRecords([$heartbeatRecord]);
    }

    private function ensureStorePath(): void
    {
        if (
            !is_dir($this->storePath) &&
            !mkdir($concurrentDirectory = $this->storePath, 0775, true) &&
            !is_dir($concurrentDirectory)
        ) {
            throw new \RuntimeException("Unable to create store path [$this->storePath]");
        }
    }

    private function line(string $message): void
    {
        $this->output?->writeln($message);
    }
}
