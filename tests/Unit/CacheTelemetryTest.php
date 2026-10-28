<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\IngestContract;
use Dozor\Dozor;
use Dozor\Tests\TestCase;

final class CacheTelemetryTest extends TestCase
{
    public function test_cache_is_not_sampled_with_the_unrelated_generic_events_rate(): void
    {
        $records = [];
        $ingest = $this->createMock(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        $core = new Dozor($ingest, [
            'enabled' => true,
            'sampling' => ['events' => 0.0, 'cache' => 1.0],
        ]);

        $core->recordCacheEvent(['operation' => 'hit', 'key' => 'secret-password', 'store' => 'redis']);
        $core->recordCacheEvent(['operation' => 'miss', 'key' => 'another-secret', 'store' => 'redis']);

        self::assertCount(2, $records);
        self::assertSame(['hit', 'miss'], array_column(array_column($records, 'payload'), 'operation'));
        self::assertArrayNotHasKey('key', $records[0]['payload']);
        self::assertNotSame('secret-password', $records[0]['payload']['key_hash']);
    }

    public function test_cache_capture_can_be_disabled_independently_from_other_events(): void
    {
        $ingest = $this->createMock(IngestContract::class);
        $ingest->expects(self::never())->method('write');

        $core = new Dozor($ingest, [
            'enabled' => true,
            'sampling' => ['cache' => 0.0, 'events' => 1.0],
        ]);

        $core->recordCacheEvent(['operation' => 'hit', 'key' => 'private']);
    }
}
