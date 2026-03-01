<?php

declare(strict_types=1);

namespace Dozor\Agent;

use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Dozor\fclose_safely;
use function Dozor\fread_all;
use function Dozor\fwrite_all;

final readonly class Server
{
    public function __construct(
        private string $listenOn,
        private string $tokenHash,
        private string $storePath,
        private ?OutputInterface $output = null,
        private string $serverName = 'dozor-agent',
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
        $this->line(sprintf('Dozor agent listening on %s (%s)', $this->listenOn, $this->serverName));

        while ($client = @stream_socket_accept($server, -1)) {
            try {
                $this->handleClient($client);
                fwrite_all($client, '2:OK');
            } catch (Throwable $e) {
                $this->line('Agent error: ' . $e->getMessage());
                @fwrite($client, '5:ER');
            } finally {
                fclose_safely($client);
            }
        }
    }

    /**
     * @param resource $client
     */
    private function handleClient($client): void
    {
        $length = $this->readFrameLength($client);
        $frame = fread_all($client, $length);
        [$version, $tokenHash, $payload] = explode(':', $frame, 3);

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
