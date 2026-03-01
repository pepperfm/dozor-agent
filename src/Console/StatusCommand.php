<?php

declare(strict_types=1);

namespace Dozor\Console;

use Illuminate\Console\Command;
use Dozor\Agent\SpoolQueue;
use Dozor\Contracts\DozorContract;
use Dozor\Telemetry\AgentRuntimeState;
use Illuminate\Support\Arr;
use JsonException;
use Throwable;

final class StatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dozor:status
        {--json : Output status report in JSON}
        {--strict : Return non-zero code when the local agent is unreachable}';

    /**
     * @var string
     */
    protected $description = 'Get the current status of the Dozor agent.';

    public function handle(DozorContract $dozor): int
    {
        if (! $dozor->enabled()) {
            $this->components->error('Dozor is disabled');

            return 1;
        }

        $config = (array) config('dozor', []);
        $spoolPath = (string) Arr::get($config, 'agent.spool_path', storage_path('app/dozor/spool'));
        $statePath = (string) Arr::get($config, 'agent.telemetry.state_path', storage_path('app/dozor/runtime-state.json'));
        $shipperEnabled = (bool) Arr::get($config, 'agent.shipper.enabled', true);
        $ingestUri = (string) Arr::get($config, 'ingest.uri', '127.0.0.1:4815');

        $spoolQueue = new SpoolQueue($spoolPath);
        $runtimeState = (new AgentRuntimeState($statePath))->read();

        $reachable = true;
        $pingError = null;

        try {
            $dozor->ping();
        } catch (Throwable $e) {
            $reachable = false;
            $pingError = $e->getMessage();
        }

        $statusReport = [
            'enabled' => true,
            'reachable' => $reachable,
            'ingest_uri' => $ingestUri,
            'shipper_enabled' => $shipperEnabled,
            'spool_path' => $spoolPath,
            'state_path' => $statePath,
            'lifecycle' => [
                'status' => (string) Arr::get($runtimeState, 'lifecycle.status', 'unknown'),
                'started_at' => Arr::get($runtimeState, 'lifecycle.started_at'),
                'last_heartbeat_at' => Arr::get($runtimeState, 'lifecycle.last_heartbeat_at'),
                'last_flush_at' => Arr::get($runtimeState, 'lifecycle.last_flush_at'),
                'updated_at' => Arr::get($runtimeState, 'lifecycle.updated_at'),
            ],
            'metrics' => [
                'queue_depth' => $spoolQueue->queuedBatchesCount(),
                'failed_queue_depth' => $spoolQueue->failedBatchesCount(),
                'dropped_batches' => (int) Arr::get($runtimeState, 'metrics.dropped_batches', 0),
                'failed_uploads' => (int) Arr::get($runtimeState, 'metrics.failed_uploads', 0),
                'last_upload_success_at' => Arr::get($runtimeState, 'metrics.last_upload_success_at'),
                'last_upload_failure_at' => Arr::get($runtimeState, 'metrics.last_upload_failure_at'),
                'last_upload_failure_reason' => Arr::get($runtimeState, 'metrics.last_upload_failure_reason'),
                'uptime_seconds' => (int) Arr::get($runtimeState, 'metrics.uptime_seconds', 0),
            ],
            'ping_error' => $pingError,
        ];

        logger()->info('dozor.agent.status.reported', $statusReport);

        if ((bool) $this->option('json')) {
            try {
                $this->line((string) json_encode($statusReport, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } catch (JsonException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        } else {
            $rows = [];

            foreach ($this->flattenStatusReport($statusReport) as $metric => $value) {
                $rows[] = [$metric, $this->formatValue($value)];
            }

            $this->table(['Metric', 'Value'], $rows);
        }

        if ($reachable) {
            $this->components->info('Dozor agent is reachable.');

            return self::SUCCESS;
        }

        $this->components->warn('Dozor agent is not reachable from current app process.');

        if ((bool) $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $statusReport
     *
     * @return array<string, mixed>
     */
    private function flattenStatusReport(array $statusReport): array
    {
        return [
            'enabled' => Arr::get($statusReport, 'enabled'),
            'reachable' => Arr::get($statusReport, 'reachable'),
            'ingest_uri' => Arr::get($statusReport, 'ingest_uri'),
            'shipper_enabled' => Arr::get($statusReport, 'shipper_enabled'),
            'spool_path' => Arr::get($statusReport, 'spool_path'),
            'state_path' => Arr::get($statusReport, 'state_path'),
            'lifecycle.status' => Arr::get($statusReport, 'lifecycle.status'),
            'lifecycle.started_at' => Arr::get($statusReport, 'lifecycle.started_at'),
            'lifecycle.last_heartbeat_at' => Arr::get($statusReport, 'lifecycle.last_heartbeat_at'),
            'lifecycle.last_flush_at' => Arr::get($statusReport, 'lifecycle.last_flush_at'),
            'lifecycle.updated_at' => Arr::get($statusReport, 'lifecycle.updated_at'),
            'metrics.queue_depth' => Arr::get($statusReport, 'metrics.queue_depth'),
            'metrics.failed_queue_depth' => Arr::get($statusReport, 'metrics.failed_queue_depth'),
            'metrics.dropped_batches' => Arr::get($statusReport, 'metrics.dropped_batches'),
            'metrics.failed_uploads' => Arr::get($statusReport, 'metrics.failed_uploads'),
            'metrics.last_upload_success_at' => Arr::get($statusReport, 'metrics.last_upload_success_at'),
            'metrics.last_upload_failure_at' => Arr::get($statusReport, 'metrics.last_upload_failure_at'),
            'metrics.last_upload_failure_reason' => Arr::get($statusReport, 'metrics.last_upload_failure_reason'),
            'metrics.uptime_seconds' => Arr::get($statusReport, 'metrics.uptime_seconds'),
            'ping_error' => Arr::get($statusReport, 'ping_error'),
        ];
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || $value === '') {
            return '-';
        }

        return (string) $value;
    }
}
