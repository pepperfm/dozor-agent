<?php

declare(strict_types=1);

namespace Dozor\Console;

use Illuminate\Console\Command;
use Dozor\Agent\Server;
use Dozor\Agent\SpoolQueue;
use Dozor\Agent\Transport\HostedIngestTransport;
use Dozor\Tracing\TraceBatchTransformer;
use Illuminate\Support\Arr;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'dozor:agent', description: 'Run the Dozor local ingest agent.')]
final class AgentCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dozor:agent
        {--listen-on= : TCP address the local ingest server should listen on}
        {--store-path= : Directory where local ingest files will be stored}
        {--spool-path= : Directory for durable shipping spool queue}
        {--hosted-ingest-url= : Hosted Dozor ingest URL}
        {--hosted-ingest-token= : Hosted Dozor ingest token}
        {--ship-batch-size= : Records per shipped batch}
        {--ship-max-batches-per-flush= : Maximum queued batches to ship per flush iteration}
        {--ship-flush-interval-seconds= : Background flush interval in seconds}
        {--ship-max-attempts-per-batch= : Maximum attempts before dropping a batch}
        {--ship-queue-backoff-base-ms= : Queue retry base backoff in milliseconds}
        {--ship-queue-backoff-cap-ms= : Queue retry cap backoff in milliseconds}
        {--ship-retry-attempts= : HTTP retry attempts per upload}
        {--ship-retry-backoff-ms= : HTTP retry linear backoff in milliseconds}
        {--ship-connection-timeout= : Hosted ingest connection timeout}
        {--ship-timeout= : Hosted ingest request timeout}
        {--heartbeat-interval-seconds= : Agent heartbeat emission interval in seconds}
        {--trace-max-spans-per-trace= : Maximum spans emitted per transformed trace}
        {--server=}
        {--silent : Do not output startup details}';

    /**
     * @var string
     */
    protected $description = 'Run the Dozor local ingest agent.';

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly ?string $token,
        private readonly array $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $listenOn = $this->stringOption('listen-on', (string) Arr::get($this->config, 'ingest.uri', '127.0.0.1:4815'));
        $storePath = $this->stringOption('store-path', (string) Arr::get($this->config, 'agent.store_path', storage_path('app/dozor')));
        $spoolPath = $this->stringOption('spool-path', (string) Arr::get($this->config, 'agent.spool_path', $storePath . DIRECTORY_SEPARATOR . 'spool'));
        $serverName = $this->stringOption('server', (string) Arr::get($this->config, 'server', gethostname() ?: 'unknown'));
        $appName = (string) Arr::get($this->config, 'app_name', config('app.name', 'laravel'));
        $environment = (string) Arr::get($this->config, 'environment', config('app.env', 'production'));
        $release = (string) Arr::get($this->config, 'release', Arr::get($this->config, 'deployment'));

        $shipperEnabled = (bool) Arr::get($this->config, 'agent.shipper.enabled', true);
        $hostedIngestUrl = $this->stringOption('hosted-ingest-url', (string) Arr::get($this->config, 'agent.shipper.ingest_url', ''));
        $hostedIngestToken = $this->stringOption('hosted-ingest-token', (string) Arr::get($this->config, 'agent.shipper.ingest_token', (string) $this->token));
        $shipBatchSize = $this->intOption('ship-batch-size', (int) Arr::get($this->config, 'agent.shipper.batch_size', 100));
        $shipMaxBatchesPerFlush = $this->intOption('ship-max-batches-per-flush', (int) Arr::get($this->config, 'agent.shipper.max_batches_per_flush', 10));
        $shipFlushIntervalSeconds = $this->floatOption('ship-flush-interval-seconds', (float) Arr::get($this->config, 'agent.shipper.flush_interval_seconds', 2.0));
        $shipMaxAttempts = $this->intOption('ship-max-attempts-per-batch', (int) Arr::get($this->config, 'agent.shipper.max_attempts_per_batch', 8));
        $queueBackoffBaseMs = $this->intOption('ship-queue-backoff-base-ms', (int) Arr::get($this->config, 'agent.shipper.queue_backoff_base_ms', 500));
        $queueBackoffCapMs = $this->intOption('ship-queue-backoff-cap-ms', (int) Arr::get($this->config, 'agent.shipper.queue_backoff_cap_ms', 30000));
        $shipRetryAttempts = $this->intOption('ship-retry-attempts', (int) Arr::get($this->config, 'agent.shipper.retry_attempts', 3));
        $shipRetryBackoffMs = $this->intOption('ship-retry-backoff-ms', (int) Arr::get($this->config, 'agent.shipper.retry_backoff_ms', 250));
        $shipConnectionTimeout = $this->floatOption('ship-connection-timeout', (float) Arr::get($this->config, 'agent.shipper.connection_timeout', 2.0));
        $shipTimeout = $this->floatOption('ship-timeout', (float) Arr::get($this->config, 'agent.shipper.timeout', 5.0));
        $heartbeatIntervalSeconds = $this->floatOption('heartbeat-interval-seconds', (float) Arr::get($this->config, 'agent.tracing.heartbeat_interval_seconds', 15.0));
        $traceMaxSpansPerTrace = $this->intOption('trace-max-spans-per-trace', (int) Arr::get($this->config, 'agent.tracing.max_spans_per_trace', 200));
        $tokenHash = substr(hash('xxh128', (string) $this->token), 0, 7);
        $silent = (bool) $this->option('silent');

        $spoolQueue = null;
        $shipper = null;
        $traceBatchTransformer = null;

        if ($shipperEnabled && $hostedIngestUrl !== '') {
            $spoolQueue = new SpoolQueue(
                spoolPath: $spoolPath,
                maxAttemptsPerBatch: $shipMaxAttempts,
                queueBackoffBaseMs: $queueBackoffBaseMs,
                queueBackoffCapMs: $queueBackoffCapMs,
            );

            $shipper = new HostedIngestTransport(
                ingestUrl: $hostedIngestUrl,
                ingestToken: $hostedIngestToken !== '' ? $hostedIngestToken : null,
                connectionTimeout: $shipConnectionTimeout,
                timeout: $shipTimeout,
                retryAttempts: $shipRetryAttempts,
                retryBackoffMs: $shipRetryBackoffMs,
            );

            $traceBatchTransformer = new TraceBatchTransformer(
                appName: $appName,
                appToken: (string) $this->token,
                environment: $environment,
                serverName: $serverName,
                maxSpansPerTrace: max(10, $traceMaxSpansPerTrace),
            );

            logger()->info('dozor.agent.shipper.enabled', [
                'spool_path' => $spoolPath,
                'ingest_url' => $hostedIngestUrl,
                'batch_size' => $shipBatchSize,
                'max_batches_per_flush' => $shipMaxBatchesPerFlush,
                'trace_max_spans_per_trace' => $traceMaxSpansPerTrace,
                'heartbeat_interval_seconds' => $heartbeatIntervalSeconds,
            ]);
        } else {
            logger()->warning('dozor.agent.shipper.disabled', [
                'shipper_enabled' => $shipperEnabled,
                'ingest_url_present' => $hostedIngestUrl !== '',
            ]);
        }

        $server = new Server(
            listenOn: $listenOn,
            tokenHash: $tokenHash,
            storePath: $storePath,
            output: $silent ? null : $this->output,
            serverName: $serverName,
            appName: $appName,
            environment: $environment,
            release: $release !== '' ? $release : null,
            spoolQueue: $spoolQueue,
            shipper: $shipper,
            traceBatchTransformer: $traceBatchTransformer,
            shipBatchSize: max(1, $shipBatchSize),
            shipMaxBatchesPerFlush: max(1, $shipMaxBatchesPerFlush),
            shipFlushIntervalSeconds: max(0.1, $shipFlushIntervalSeconds),
            heartbeatIntervalSeconds: max(1.0, $heartbeatIntervalSeconds),
        );

        $server->run();

        return self::SUCCESS;
    }

    private function stringOption(string $name, string $default = ''): string
    {
        $option = $this->option($name);

        if (is_string($option) && $option !== '') {
            return $option;
        }

        return $default;
    }

    private function intOption(string $name, int $default): int
    {
        $option = $this->option($name);

        if (is_numeric($option)) {
            return (int) $option;
        }

        return $default;
    }

    private function floatOption(string $name, float $default): float
    {
        $option = $this->option($name);

        if (is_numeric($option)) {
            return (float) $option;
        }

        return $default;
    }
}
