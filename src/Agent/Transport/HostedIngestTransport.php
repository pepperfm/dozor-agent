<?php

declare(strict_types=1);

namespace Dozor\Agent\Transport;

use Dozor\Telemetry\AgentRuntimeState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

use function count;
use function is_array;
use function is_string;
use function trim;

final readonly class HostedIngestTransport
{
    public function __construct(
        private string $ingestUrl,
        private ?string $ingestToken,
        private float $connectionTimeout,
        private float $timeout,
        private int $retryAttempts,
        private int $retryBackoffMs,
        private ?AgentRuntimeState $runtimeState = null,
    ) {
    }

    public function enabled(): bool
    {
        return trim($this->ingestUrl) !== '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function ship(array $payload, string $batchId, int $attempt): bool
    {
        if (!$this->enabled()) {
            $this->runtimeState?->incrementFailedUploads($batchId, $attempt, 'ingest_url_missing');

            return false;
        }

        $recordsCount = $this->extractRecordsCount($payload);

        try {
            $request = Http::asJson()
                ->acceptJson()
                ->connectTimeout($this->connectionTimeout)
                ->timeout($this->timeout);

            if (is_string($this->ingestToken) && $this->ingestToken !== '') {
                $request = $request->withToken($this->ingestToken);
            }

            $response = $request
                ->retry(
                    $this->retryAttempts,
                    function (int $httpAttempt, \Exception $exception): int {
                        return $this->retryBackoffMs * $httpAttempt;
                    },
                    function (\Exception $exception, PendingRequest $_request): bool {
                        if ($exception instanceof ConnectionException) {
                            return true;
                        }

                        if ($exception instanceof RequestException) {
                            $status = $exception->response?->status() ?? 0;

                            return $status >= 500 || $status === 429;
                        }

                        return false;
                    },
                    throw: false
                )
                ->post($this->ingestUrl, $payload);

            if ($response->successful()) {
                $this->runtimeState?->markUploadSuccess($batchId, $attempt, $recordsCount);

                return true;
            }

            $this->runtimeState?->incrementFailedUploads(
                $batchId,
                $attempt,
                'http_status_' . $response->status(),
            );

            return false;
        } catch (ConnectionException $e) {
            $this->runtimeState?->incrementFailedUploads($batchId, $attempt, 'connection_exception');

            return false;
        } catch (\Throwable $e) {
            $this->runtimeState?->incrementFailedUploads($batchId, $attempt, 'unexpected_exception');

            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractRecordsCount(array $payload): int
    {
        $traces = Arr::get($payload, 'traces', []);
        if (is_array($traces)) {
            return count($traces);
        }

        $records = Arr::get($payload, 'records', []);

        return is_array($records) ? count($records) : 0;
    }
}
