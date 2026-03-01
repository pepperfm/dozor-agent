<?php

declare(strict_types=1);

namespace Dozor\Agent;

use Dozor\Agent\Transport\HostedIngestTransport;
use Dozor\Tracing\TraceBatchTransformer;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_chunk;
use function count;
use function defined;
use function Dozor\fclose_safely;
use function Dozor\fread_all;
use function Dozor\fwrite_all;
use function function_exists;
use function is_array;
use function is_resource;
use function max;
use function microtime;

final class Server
{
    private bool $running = true;

    private float $lastFlushAt = 0.0;

    private float $startedAt = 0.0;

    private float $lastHeartbeatAt = 0.0;

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
    ) {
    }

    public function run(): void
    {
        $address = str_starts_with($this->listenOn, 'tcp://') ? $this->listenOn : 'tcp://' . $this->listenOn;
        $server = @stream_socket_server($address, $errorCode, $errorMessage);

        if ($server === false) {
            throw new \RuntimeException(sprintf('Unable to start Dozor agent on %s [%s:%s]', $address, $errorCode, $errorMessage));
        }

        $this->ensureStorePath();
        $this->spoolQueue?->ensureDirectories();
        $this->installSignalHandlers();
        $this->lastFlushAt = microtime(true);
        $this->startedAt = microtime(true);
        $this->lastHeartbeatAt = microtime(true);

        $this->line(sprintf('Dozor agent listening on %s (%s)', $this->listenOn, $this->serverName));

        logger()->info('dozor.agent.lifecycle.started', [
            'listen_on' => $this->listenOn,
            'server' => $this->serverName,
            'app' => $this->appName,
            'environment' => $this->environment,
            'shipper_enabled' => $this->shipper?->enabled() ?? false,
        ]);

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
                $this->line('Agent error: ' . $e->getMessage());
                logger()->error('dozor.agent.lifecycle.client_error', [
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                @fwrite($client, '5:ER');
            } finally {
                fclose_safely($client);
            }

            $this->emitHeartbeatIfDue();
            $this->flushQueuedBatches(force: true);
        }

        $this->flushQueuedBatches(force: true);

        logger()->info('dozor.agent.lifecycle.stopped', [
            'listen_on' => $this->listenOn,
            'server' => $this->serverName,
        ]);

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

        logger()->info('dozor.agent.lifecycle.shutdown_requested', [
            'signal' => $signal,
        ]);

        $this->line("Shutdown requested by {$signal}. Draining queued batches...");
    }

    /**
     * @param resource $client
     */
    private function handleClient($client): void
    {
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

        $records = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
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

        return (int) $buffer;
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

        $this->flushQueuedBatches(force: count($chunks) >= $this->shipMaxBatchesPerFlush);
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
        if (!$force && $now - $this->lastFlushAt < $this->shipFlushIntervalSeconds) {
            return;
        }

        $this->lastFlushAt = $now;
        $maxBatches = $force ? max($this->shipMaxBatchesPerFlush, 1000) : $this->shipMaxBatchesPerFlush;
        $shipper = $this->shipper;

        logger()->info('dozor.agent.shipper.flush_triggered', [
            'force' => $force,
            'max_batches' => $maxBatches,
            'flush_interval_seconds' => $this->shipFlushIntervalSeconds,
        ]);

        $this->spoolQueue->drain(
            static function (array $payload, string $batchId, int $attempt) use ($shipper): bool {
                return $shipper->ship($payload, $batchId, $attempt);
            },
            $maxBatches,
        );
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
                'uptime_seconds' => (int) max(0, round($now - $this->startedAt)),
                'queue_depth' => $this->spoolQueue->queuedBatchesCount(),
                'failed_queue_depth' => $this->spoolQueue->failedBatchesCount(),
                'memory_peak_bytes' => memory_get_peak_usage(true),
            ],
        ];

        logger()->info('dozor.agent.instrumentation.heartbeat_emitted', [
            'server' => $this->serverName,
            'queue_depth' => $heartbeatRecord['payload']['queue_depth'],
            'failed_queue_depth' => $heartbeatRecord['payload']['failed_queue_depth'],
            'uptime_seconds' => $heartbeatRecord['payload']['uptime_seconds'],
        ]);

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
