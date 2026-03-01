<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\RecordsBuffer;
use Dozor\Tests\TestCase;

final class RecordsBufferTest extends TestCase
{
    public function test_it_drops_oldest_records_when_buffer_is_full(): void
    {
        $buffer = new RecordsBuffer(2);

        $buffer->write(['id' => 'first']);
        $buffer->write(['id' => 'second']);
        $buffer->write(['id' => 'third']);

        self::assertTrue($buffer->full);
        self::assertSame(2, $buffer->count());

        $payload = $buffer->pull('token-hash');
        $records = json_decode($payload->rawPayload(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            ['id' => 'second'],
            ['id' => 'third'],
        ], $records);

        self::assertFalse($buffer->full);
        self::assertSame(0, $buffer->count());
    }
}
