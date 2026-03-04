<?php

declare(strict_types=1);

namespace Dozor;

use Deprecated;
use Dozor\Contracts\IngestContract as IngestContract;
use RuntimeException;
use Throwable;

use function Dozor\fclose_safely;
use function Dozor\fread_all;
use function Dozor\fwrite_all;
use function Dozor\stream_configure_read_timeout;

/**
 * @internal
 */
final class Ingest implements IngestContract
{
    private const DEFAULT_RETRY_COOLDOWN_SECONDS = 2.0;

    private string $transmitTo;

    private bool $shouldDigestWhenBufferIsFull = true;

    private ?float $nextRetryAt = null;

    /**
     * @param (\Closure(string $address, float $timeout): resource) $streamFactory
     */
    public function __construct(
        string $transmitTo,
        private readonly float $connectionTimeout,
        private readonly float $timeout,
        public \Closure $streamFactory,
        public RecordsBuffer $buffer,
        private readonly string $tokenHash,
        private readonly float $retryCooldownSeconds = self::DEFAULT_RETRY_COOLDOWN_SECONDS,
    ) {
        $this->transmitTo = "tcp://$transmitTo";
    }

    public function write(array $record): void
    {
        $this->buffer->write($record);

        if ($this->shouldDigestWhenBufferIsFull && $this->buffer->full) {
            $this->digest();
        }
    }

    public function writeNow(array $record): void
    {
        if ($this->transmit(Payload::json([$record], $this->tokenHash))) {
            return;
        }

        $this->buffer->write($record);

        if ($this->shouldDigestWhenBufferIsFull && $this->buffer->full) {
            $this->digest();
        }
    }

    public function flush(): void
    {
        $this->buffer->flush();
    }

    public function ping(): void
    {
        $this->transmit(Payload::text('PING', $this->tokenHash), strict: true);
    }

    #[Deprecated('Use shouldDigestWhenBufferIsFull instead')]
    public function shouldDigest(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull($bool);
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull = $bool;
    }

    public function digest(): void
    {
        $records = $this->buffer->all();
        if ($records === []) {
            return;
        }

        if ($this->transmit(Payload::json($records, $this->tokenHash))) {
            $this->buffer->flush();
        }
    }

    private function transmit(Payload $payload, bool $strict = false): bool
    {
        if ($payload->isEmpty()) {
            return true;
        }

        if (!$strict && !$this->shouldAttemptNonStrictTransmit()) {
            return false;
        }

        $stream = null;

        try {
            $stream = $this->createStream();
            $this->configureStreamTimeout($stream);
            $this->sendPayload($stream, $payload);
            $this->waitForAcknowledgment($stream);
            $this->nextRetryAt = null;

            return true;
        } catch (Throwable $exception) {
            if ($strict) {
                throw $exception;
            }

            $this->nextRetryAt = \microtime(true) + \max(0.0, $this->retryCooldownSeconds);

            return false;
        } finally {
            if ($stream !== null) {
                fclose_safely($stream);
            }
        }
    }

    private function shouldAttemptNonStrictTransmit(): bool
    {
        return $this->nextRetryAt === null || \microtime(true) >= $this->nextRetryAt;
    }

    /**
     * @return resource
     */
    private function createStream()
    {
        return \call_user_func($this->streamFactory, $this->transmitTo, $this->connectionTimeout);
    }

    /**
     * @param  resource  $stream
     */
    private function configureStreamTimeout($stream): void
    {
        stream_configure_read_timeout($stream, $this->timeout);
    }

    /**
     * @param  resource  $stream
     */
    private function sendPayload($stream, Payload $payload): void
    {
        fwrite_all($stream, $payload->pull(...));
    }

    /**
     * @param  resource  $stream
     */
    private function waitForAcknowledgment($stream): void
    {
        $response = fread_all($stream, 4);
        if ($response !== '2:OK') {
            throw new RuntimeException("Unexpected response from agent [$response]");
        }
    }
}
