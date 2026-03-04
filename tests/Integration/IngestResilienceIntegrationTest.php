<?php

declare(strict_types=1);

namespace Dozor\Tests\Integration;

use Dozor\Ingest;
use Dozor\RecordsBuffer;
use Dozor\SocketStreamFactory;
use Dozor\Tests\TestCase;
use RuntimeException;
use Throwable;

final class IngestResilienceIntegrationTest extends TestCase
{
    public function test_digest_retries_and_flushes_buffered_records_after_agent_recovers(): void
    {
        $storePath = $this->makeTemporaryDirectory('dozor-resilience-store');
        $scriptPath = $this->makeTemporaryDirectory('dozor-resilience-script') . DIRECTORY_SEPARATOR . 'run-server.php';
        $listenOn = '127.0.0.1:' . $this->findFreePort();
        $tokenHash = 'resilience-token-hash';

        fwrite(
            fopen($scriptPath, 'wb'),
            <<<'PHP'
<?php

declare(strict_types=1);

require $argv[4] . '/vendor/autoload.php';

$container = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($container);
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$container->instance('log', new Psr\Log\NullLogger());
$container->instance('http', new Illuminate\Http\Client\Factory());

$server = new Dozor\Agent\Server(
    listenOn: $argv[1],
    tokenHash: $argv[2],
    storePath: $argv[3],
);

$server->run();
PHP
        );

        $command = sprintf(
            'php %s %s %s %s %s',
            escapeshellarg($scriptPath),
            escapeshellarg($listenOn),
            escapeshellarg($tokenHash),
            escapeshellarg($storePath),
            escapeshellarg(dirname(__DIR__, 2)),
        );

        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        self::assertTrue(is_resource($process));

        try {
            $socketFactory = new SocketStreamFactory()(...);
            $attempts = 0;

            $ingest = new Ingest(
                transmitTo: $listenOn,
                connectionTimeout: 0.5,
                timeout: 0.5,
                streamFactory: static function (string $address, float $timeout) use (&$attempts, $socketFactory) {
                    $attempts++;

                    if ($attempts === 1) {
                        throw new RuntimeException('Simulated first transport failure');
                    }

                    return $socketFactory($address, $timeout);
                },
                buffer: new RecordsBuffer(10),
                tokenHash: $tokenHash,
                retryCooldownSeconds: 0.05,
            );

            $record = [
                'type' => 'request',
                'trace_id' => 'trace-resilience-1',
                'happened_at' => '2026-03-05T00:00:00+00:00',
                'payload' => [
                    'path' => '/health',
                ],
            ];

            $ingest->write($record);
            $ingest->digest();

            self::assertSame(1, $attempts);
            self::assertSame(1, $ingest->buffer->count());

            usleep(100_000);
            $this->waitFor(fn(): bool => $this->pingAgent($ingest), 4_000);
            $ingest->digest();

            self::assertSame(0, $ingest->buffer->count());
            self::assertGreaterThanOrEqual(2, $attempts);

            $dailyFile = $storePath . DIRECTORY_SEPARATOR . 'ingest-' . date('Y-m-d') . '.ndjson';

            $this->waitFor(static fn(): bool => file_exists($dailyFile) && filesize($dailyFile) > 0, 4_000);

            $stream = fopen($dailyFile, 'rb');
            self::assertNotFalse($stream);

            $line = fgets($stream);
            fclose($stream);

            self::assertIsString($line);

            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue(is_array($decoded));
            self::assertSame('trace-resilience-1', $decoded['trace_id'] ?? null);
        } finally {
            $this->stopProcess($process, $pipes);
        }
    }

    public function test_ping_remains_strict_when_agent_is_unreachable(): void
    {
        $listenOn = '127.0.0.1:' . $this->findFreePort();
        $ingest = new Ingest(
            transmitTo: $listenOn,
            connectionTimeout: 0.01,
            timeout: 0.01,
            streamFactory: new SocketStreamFactory()(...),
            buffer: new RecordsBuffer(10),
            tokenHash: 'strict-token-hash',
            retryCooldownSeconds: 0.05,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to connect to Dozor agent');

        $ingest->ping();
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
}
