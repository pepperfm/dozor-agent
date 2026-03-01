<?php

declare(strict_types=1);

namespace Dozor\Tests\Integration;

use Dozor\Ingest;
use Dozor\RecordsBuffer;
use Dozor\SocketStreamFactory;
use Dozor\Tests\TestCase;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function dirname;
use function escapeshellarg;
use function fclose;
use function file;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function in_array;
use function is_array;
use function is_resource;
use function is_string;
use function json_decode;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function sprintf;
use function stream_context_create;
use function trim;
use function usleep;
use function var_export;
use const DIRECTORY_SEPARATOR;
use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const PHP_EOL;

final class DaemonHostedIngestE2ETest extends TestCase
{
    public function test_it_ships_trace_payload_and_retries_after_hosted_recovery(): void
    {
        $scriptsPath = $this->makeTemporaryDirectory('dozor-e2e-scripts');
        $storePath = $this->makeTemporaryDirectory('dozor-e2e-store');
        $spoolPath = $this->makeTemporaryDirectory('dozor-e2e-spool');
        $tokenHash = 'test-token-hash';
        $ingestToken = 'hosted-ingest-token';
        $listenOn = '127.0.0.1:' . $this->findFreePort();
        $hostedAddress = '127.0.0.1:' . $this->findFreePort();
        $hostedIngestUrl = 'http://' . $hostedAddress . '/ingest';
        $hostedRequestsPath = $scriptsPath . DIRECTORY_SEPARATOR . 'hosted-requests.ndjson';
        $hostedCounterPath = $scriptsPath . DIRECTORY_SEPARATOR . 'hosted-counter.txt';
        $hostedRouterPath = $scriptsPath . DIRECTORY_SEPARATOR . 'hosted-router.php';
        $agentScriptPath = $scriptsPath . DIRECTORY_SEPARATOR . 'run-agent.php';

        file_put_contents(
            $hostedRouterPath,
            $this->buildHostedRouterScript($hostedRequestsPath, $hostedCounterPath),
        );
        file_put_contents($agentScriptPath, $this->buildAgentRunnerScript());

        $hostedProcess = null;
        $hostedPipes = [];
        $agentProcess = null;
        $agentPipes = [];

        $hostedCommand = sprintf(
            'php -S %s %s',
            escapeshellarg($hostedAddress),
            escapeshellarg($hostedRouterPath),
        );

        $agentCommand = sprintf(
            'php %s %s %s %s %s %s %s %s',
            escapeshellarg($agentScriptPath),
            escapeshellarg($listenOn),
            escapeshellarg($tokenHash),
            escapeshellarg($storePath),
            escapeshellarg($spoolPath),
            escapeshellarg($hostedIngestUrl),
            escapeshellarg($ingestToken),
            escapeshellarg(dirname(__DIR__, 2)),
        );

        $hostedProcess = proc_open(
            $hostedCommand,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $hostedPipes,
        );

        self::assertTrue(is_resource($hostedProcess));

        $agentProcess = proc_open(
            $agentCommand,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $agentPipes,
        );

        self::assertTrue(is_resource($agentProcess));

        try {
            $this->waitFor(fn(): bool => $this->hostedReady($hostedAddress), 5000);

            $ingest = new Ingest(
                transmitTo: $listenOn,
                connectionTimeout: 0.5,
                timeout: 0.5,
                streamFactory: new SocketStreamFactory()(...),
                buffer: new RecordsBuffer(10),
                tokenHash: $tokenHash,
            );

            $this->waitFor(fn(): bool => $this->pingAgent($ingest), 5000);

            $ingest->write($this->makeRecord('trace-e2e-first', '/first'));
            $ingest->digest();

            $this->waitFor(fn(): bool => $this->countQueueFiles($spoolPath) > 0, 5000);

            $ingest->write($this->makeRecord('trace-e2e-second', '/second'));
            $ingest->digest();

            $this->waitFor(fn(): bool => $this->countLoggedRequests($hostedRequestsPath) >= 3, 7000);
            $this->waitFor(fn(): bool => $this->countQueueFiles($spoolPath) === 0, 7000);

            $requests = $this->readHostedRequests($hostedRequestsPath);
            self::assertGreaterThanOrEqual(3, count($requests));
            self::assertSame(503, $requests[0]['status'] ?? null);

            $successfulSourceTraceIds = [];

            foreach ($requests as $request) {
                self::assertSame('Bearer ' . $ingestToken, $request['auth'] ?? null);

                $payload = json_decode((string) ($request['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

                self::assertTrue(is_array($payload));
                self::assertIsArray($payload['traces'] ?? null);

                if (($request['status'] ?? null) !== 202) {
                    continue;
                }

                foreach ($payload['traces'] as $trace) {
                    if (!is_array($trace)) {
                        continue;
                    }

                    $sourceTraceId = $trace['meta']['source_trace_id'] ?? null;
                    if (is_string($sourceTraceId) && $sourceTraceId !== '') {
                        $successfulSourceTraceIds[] = $sourceTraceId;
                    }
                }
            }

            self::assertTrue(in_array('trace-e2e-first', $successfulSourceTraceIds, true));
            self::assertTrue(in_array('trace-e2e-second', $successfulSourceTraceIds, true));
            self::assertSame(0, $this->countFailedFiles($spoolPath));
        } finally {
            if (is_resource($agentProcess)) {
                $this->stopProcess($agentProcess, $agentPipes);
            }

            if (is_resource($hostedProcess)) {
                $this->stopProcess($hostedProcess, $hostedPipes);
            }
        }
    }

    private function hostedReady(string $hostedAddress): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 0.2,
                'ignore_errors' => true,
            ],
        ]);

        return @file_get_contents('http://' . $hostedAddress . '/health', false, $context) !== false;
    }

    private function pingAgent(Ingest $ingest): bool
    {
        try {
            $ingest->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param callable(): bool $condition
     */
    private function waitFor(callable $condition, int $timeoutMs): void
    {
        $elapsedMs = 0;
        $sleepChunk = 50;

        while ($elapsedMs <= $timeoutMs) {
            if ($condition()) {
                return;
            }

            usleep($sleepChunk * 1000);
            $elapsedMs += $sleepChunk;
        }

        self::fail("Condition was not met within {$timeoutMs} ms");
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function stopProcess($process, array $pipes): void
    {
        @proc_terminate($process, 15);
        usleep(200_000);

        $status = proc_get_status($process);
        if ($status['running'] ?? false) {
            @proc_terminate($process, 9);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($process);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeRecord(string $traceId, string $path): array
    {
        return [
            'type' => 'request',
            'trace_id' => $traceId,
            'happened_at' => '2026-03-02T00:00:00+00:00',
            'payload' => [
                'path' => $path,
                'method' => 'GET',
                'status' => 200,
                'duration_ms' => 12.5,
            ],
        ];
    }

    private function countQueueFiles(string $spoolPath): int
    {
        $files = glob($spoolPath . DIRECTORY_SEPARATOR . 'queue' . DIRECTORY_SEPARATOR . '*.json');

        return is_array($files) ? count($files) : 0;
    }

    private function countFailedFiles(string $spoolPath): int
    {
        $files = glob($spoolPath . DIRECTORY_SEPARATOR . 'failed' . DIRECTORY_SEPARATOR . '*.json');

        return is_array($files) ? count($files) : 0;
    }

    private function countLoggedRequests(string $requestsPath): int
    {
        if (!file_exists($requestsPath)) {
            return 0;
        }

        $lines = file($requestsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? count($lines) : 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readHostedRequests(string $requestsPath): array
    {
        if (!file_exists($requestsPath)) {
            return [];
        }

        $lines = file($requestsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $decoded = array_map(
            static fn(string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );

        return array_values(array_filter($decoded, static fn(mixed $item): bool => is_array($item)));
    }

    private function buildHostedRouterScript(string $requestsPath, string $counterPath): string
    {
        $requestsPathExport = var_export($requestsPath, true);
        $counterPathExport = var_export($counterPath, true);

        return <<<PHP
<?php

declare(strict_types=1);

\$requestsPath = {$requestsPathExport};
\$counterPath = {$counterPathExport};
\$uri = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (\$uri === '/health') {
    http_response_code(204);

    return true;
}

if (\$uri !== '/ingest') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"ok":false}';

    return true;
}

\$attempt = (int) trim((string) @file_get_contents(\$counterPath));
\$attempt++;
file_put_contents(\$counterPath, (string) \$attempt, LOCK_EX);

\$status = \$attempt === 1 ? 503 : 202;
\$entry = [
    'attempt' => \$attempt,
    'status' => \$status,
    'auth' => \$_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'body' => file_get_contents('php://input') ?: '',
];

file_put_contents(
    \$requestsPath,
    json_encode(\$entry, JSON_THROW_ON_ERROR) . PHP_EOL,
    FILE_APPEND | LOCK_EX,
);

http_response_code(\$status);
header('Content-Type: application/json');
echo \$status === 202 ? '{"ok":true}' : '{"ok":false}';

return true;
PHP;
    }

    private function buildAgentRunnerScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

require $argv[7] . '/vendor/autoload.php';

$container = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($container);
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$container->instance('log', new Psr\Log\NullLogger());
$container->instance('http', new Illuminate\Http\Client\Factory());

$queue = new Dozor\Agent\SpoolQueue(
    spoolPath: $argv[4],
    maxAttemptsPerBatch: 3,
    queueBackoffBaseMs: 1,
    queueBackoffCapMs: 2,
);

$shipper = new Dozor\Agent\Transport\HostedIngestTransport(
    ingestUrl: $argv[5],
    ingestToken: $argv[6],
    connectionTimeout: 0.3,
    timeout: 0.3,
    retryAttempts: 1,
    retryBackoffMs: 1,
);

$transformer = new Dozor\Tracing\TraceBatchTransformer(
    appName: 'agent-e2e-app',
    appToken: 'agent-app-token',
    environment: 'testing',
    serverName: 'agent-node',
    maxSpansPerTrace: 50,
);

$server = new Dozor\Agent\Server(
    listenOn: $argv[1],
    tokenHash: $argv[2],
    storePath: $argv[3],
    serverName: 'agent-node',
    appName: 'agent-e2e-app',
    environment: 'testing',
    release: 'release-e2e',
    spoolQueue: $queue,
    shipper: $shipper,
    traceBatchTransformer: $transformer,
    shipBatchSize: 50,
    shipMaxBatchesPerFlush: 10,
    shipFlushIntervalSeconds: 0.01,
    heartbeatIntervalSeconds: 0.0,
);

$server->run();
PHP;
    }
}
