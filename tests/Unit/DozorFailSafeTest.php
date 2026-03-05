<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\IngestContract;
use Dozor\Dozor;
use Dozor\Tests\TestCase;
use RuntimeException;

final class DozorFailSafeTest extends TestCase
{
    public function test_report_does_not_throw_when_ingest_write_fails(): void
    {
        $ingest = $this->createStub(IngestContract::class);
        $ingest
            ->method('write')
            ->willThrowException(new RuntimeException('ingest write failed'));

        $dozor = new Dozor($ingest, [
            'enabled' => true,
            'token' => 'token',
        ]);

        $dozor->report([
            'type' => 'request',
            'trace_id' => 'trace-1',
            'happened_at' => now()->toIso8601String(),
            'payload' => ['path' => '/'],
        ]);

        self::assertTrue(true);
    }

    public function test_digest_does_not_throw_when_ingest_digest_fails(): void
    {
        $ingest = $this->createStub(IngestContract::class);
        $ingest
            ->method('digest')
            ->willThrowException(new RuntimeException('ingest digest failed'));

        $dozor = new Dozor($ingest, [
            'enabled' => true,
            'token' => 'token',
        ]);

        $dozor->digest();

        self::assertTrue(true);
    }
}
