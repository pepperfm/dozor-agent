<?php

declare(strict_types=1);

namespace Dozor\Tests\Integration;

use Dozor\Ingest;
use Dozor\RecordsBuffer;
use Dozor\SocketStreamFactory;
use Dozor\Tests\TestCase;
use Throwable;

use function date;
use function escapeshellarg;
use function file_exists;
use function file_get_contents;
use function fgets;
use function fclose;
use function fopen;
use function fwrite;
use function is_array;
use function is_resource;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function sprintf;
use function stream_set_timeout;
use function stream_socket_client;
use function usleep;

final class IngestServerIntegrationTest extends TestCase
{
    public function test_package_records_are_persisted_by_local_daemon(): void
    {
        $storePath = $this->makeTemporaryDirectory('dozor-store');
        $scriptPath = $this->makeTemporaryDirectory('dozor-server-script') . DIRECTORY_SEPARATOR . 'run-server.php';
        $listenOn = '127.0.0.1:' . $this->findFreePort();
        $tokenHash = 'test-token-hash';

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
            $ingest = new Ingest(
                transmitTo: $listenOn,
                connectionTimeout: 0.5,
                timeout: 0.5,
                streamFactory: new SocketStreamFactory()(...),
                buffer: new RecordsBuffer(10),
                tokenHash: $tokenHash,
            );

            $this->waitFor(fn(): bool => $this->pingAgent($ingest), 4000);

            $record = [
                'type' => 'request',
                'trace_id' => 'trace-task19',
                'happened_at' => '2026-03-02T00:00:00+00:00',
                'payload' => [
                    'path' => '/health',
                ],
            ];

            $ingest->write($record);
            $ingest->digest();

            $dailyFile = $storePath . DIRECTORY_SEPARATOR . 'ingest-' . date('Y-m-d') . '.ndjson';

            $this->waitFor(static fn(): bool => file_exists($dailyFile) && filesize($dailyFile) > 0, 4000);

            $stream = fopen($dailyFile, 'rb');
            self::assertNotFalse($stream);

            $line = fgets($stream);
            fclose($stream);

            self::assertIsString($line);

            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            self::assertTrue(is_array($decoded));
            self::assertSame('request', $decoded['type'] ?? null);
            self::assertSame('trace-task19', $decoded['trace_id'] ?? null);
            self::assertSame('/health', $decoded['payload']['path'] ?? null);
        } finally {
            $this->stopProcess($process, $pipes);
        }
    }

    public function test_large_frame_payload_is_read_completely_before_json_decode(): void
    {
        $storePath = $this->makeTemporaryDirectory('dozor-large-store');
        $scriptPath = $this->makeTemporaryDirectory('dozor-large-server-script') . DIRECTORY_SEPARATOR . 'run-server.php';
        $listenOn = '127.0.0.1:' . $this->findFreePort();
        $tokenHash = 'test-token-hash';
        $largeBlob = str_repeat('x', 200_000);

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
            $ingest = new Ingest(
                transmitTo: $listenOn,
                connectionTimeout: 0.5,
                timeout: 0.5,
                streamFactory: new SocketStreamFactory()(...),
                buffer: new RecordsBuffer(10),
                tokenHash: $tokenHash,
            );

            $this->waitFor(fn(): bool => $this->pingAgent($ingest), 4000);

            $record = [
                'type' => 'request',
                'trace_id' => 'trace-large-frame',
                'happened_at' => '2026-03-14T00:00:00+00:00',
                'payload' => [
                    'path' => '/large',
                    'blob' => $largeBlob,
                ],
            ];

            $ingest->write($record);
            $ingest->digest();

            $dailyFile = $storePath . DIRECTORY_SEPARATOR . 'ingest-' . date('Y-m-d') . '.ndjson';

            $this->waitFor(static fn(): bool => file_exists($dailyFile) && filesize($dailyFile) > 0, 4000);

            $line = file_get_contents($dailyFile);
            self::assertIsString($line);

            $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

            self::assertTrue(is_array($decoded));
            self::assertSame('trace-large-frame', $decoded['trace_id'] ?? null);
            self::assertSame('/large', $decoded['payload']['path'] ?? null);
            self::assertSame(strlen($largeBlob), strlen((string) ($decoded['payload']['blob'] ?? '')));
        } finally {
            $this->stopProcess($process, $pipes);
        }
    }

    public function test_oversized_frame_payload_is_rejected_without_persisting_records(): void
    {
        $storePath = $this->makeTemporaryDirectory('dozor-oversized-store');
        $scriptPath = $this->makeTemporaryDirectory('dozor-oversized-server-script') . DIRECTORY_SEPARATOR . 'run-server.php';
        $listenOn = '127.0.0.1:' . $this->findFreePort();
        $tokenHash = 'test-token-hash';

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
            $ingest = new Ingest(
                transmitTo: $listenOn,
                connectionTimeout: 0.5,
                timeout: 0.5,
                streamFactory: new SocketStreamFactory()(...),
                buffer: new RecordsBuffer(10),
                tokenHash: $tokenHash,
            );

            $this->waitFor(fn(): bool => $this->pingAgent($ingest), 4000);

            $client = stream_socket_client(
                'tcp://' . $listenOn,
                $errorCode,
                $errorMessage,
                1.0
            );
            self::assertNotFalse($client, "Failed to connect to agent [$errorCode:$errorMessage]");

            stream_set_timeout($client, 1, 0);
            fwrite($client, '1048577:');
            $response = fread($client, 4);
            fclose($client);
            self::assertSame('5:ER', $response);

            $dailyFile = $storePath . DIRECTORY_SEPARATOR . 'ingest-' . date('Y-m-d') . '.ndjson';

            usleep(400_000);
            self::assertFalse(file_exists($dailyFile));

            self::assertTrue($this->pingAgent($ingest));
        } finally {
            $this->stopProcess($process, $pipes);
        }
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
