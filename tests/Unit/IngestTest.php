<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Ingest;
use Dozor\RecordsBuffer;
use Dozor\Tests\TestCase;
use RuntimeException;

final class IngestTest extends TestCase
{
    public function test_digest_is_non_fatal_and_preserves_buffered_records_when_agent_is_unreachable(): void
    {
        $attempts = 0;

        $ingest = new Ingest(
            transmitTo: '127.0.0.1:4815',
            connectionTimeout: 0.01,
            timeout: 0.01,
            streamFactory: static function () use (&$attempts) {
                $attempts++;

                throw new RuntimeException('agent unreachable');
            },
            buffer: new RecordsBuffer(10),
            tokenHash: 'token-hash',
            retryCooldownSeconds: 0.25,
        );

        $record = [
            'type' => 'request',
            'trace_id' => 'trace-digest-non-fatal',
            'payload' => ['path' => '/health'],
        ];

        $ingest->write($record);
        $ingest->digest();

        self::assertSame(1, $attempts);
        self::assertSame(1, $ingest->buffer->count());
        self::assertSame([$record], $ingest->buffer->all());
    }

    public function test_write_now_falls_back_to_buffer_and_respects_retry_cooldown(): void
    {
        $attempts = 0;

        $ingest = new Ingest(
            transmitTo: '127.0.0.1:4815',
            connectionTimeout: 0.01,
            timeout: 0.01,
            streamFactory: static function () use (&$attempts) {
                $attempts++;

                throw new RuntimeException('agent unreachable');
            },
            buffer: new RecordsBuffer(10),
            tokenHash: 'token-hash',
            retryCooldownSeconds: 60.0,
        );

        $first = [
            'type' => 'request',
            'trace_id' => 'trace-write-now-first',
            'payload' => ['path' => '/one'],
        ];
        $second = [
            'type' => 'request',
            'trace_id' => 'trace-write-now-second',
            'payload' => ['path' => '/two'],
        ];

        $ingest->writeNow($first);
        $ingest->writeNow($second);

        self::assertSame(1, $attempts);
        self::assertSame(2, $ingest->buffer->count());
        self::assertSame([$first, $second], $ingest->buffer->all());
    }
}
