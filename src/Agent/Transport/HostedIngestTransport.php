<?php

declare(strict_types=1);

namespace Dozor\Agent\Transport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

use function count;
use function is_array;
use function is_string;
use function mb_substr;
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
            logger()->error('dozor.agent.shipper.upload_failed', [
                'batch_id' => $batchId,
                'attempt' => $attempt,
                'reason' => 'ingest_url_missing',
            ]);

            return false;
        }

        $recordsCount = $this->extractRecordsCount($payload);

        logger()->info('dozor.agent.shipper.upload_attempt', [
            'batch_id' => $batchId,
            'attempt' => $attempt,
            'records_count' => $recordsCount,
            'ingest_url' => $this->ingestUrl,
        ]);

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
                        $delay = $this->retryBackoffMs * $httpAttempt;

                        logger()->warning('dozor.agent.shipper.http_retry_backoff', [
                            'http_attempt' => $httpAttempt,
                            'delay_ms' => $delay,
                            'class' => $exception::class,
                            'message' => $exception->getMessage(),
                        ]);

                        return $delay;
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
                return true;
            }

            logger()->error('dozor.agent.shipper.upload_failed', [
                'batch_id' => $batchId,
                'attempt' => $attempt,
                'records_count' => $recordsCount,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1024),
            ]);

            return false;
        } catch (ConnectionException $e) {
            logger()->error('dozor.agent.shipper.upstream_connection_failed', [
                'batch_id' => $batchId,
                'attempt' => $attempt,
                'records_count' => $recordsCount,
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        } catch (\Throwable $e) {
            logger()->error('dozor.agent.shipper.upload_failed', [
                'batch_id' => $batchId,
                'attempt' => $attempt,
                'records_count' => $recordsCount,
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractRecordsCount(array $payload): int
    {
        $records = Arr::get($payload, 'records', []);

        return is_array($records) ? count($records) : 0;
    }
}
