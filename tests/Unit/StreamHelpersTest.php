<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Tests\TestCase;
use RuntimeException;

use function fclose;
use function fopen;
use function fwrite;
use function rewind;

final class StreamHelpersTest extends TestCase
{
    public function test_fread_all_reads_full_requested_length(): void
    {
        $stream = fopen('php://temp', 'wb+');
        self::assertNotFalse($stream);

        fwrite($stream, 'abcdef');
        rewind($stream);

        $read = \Dozor\fread_all($stream, 6);

        self::assertSame('abcdef', $read);

        fclose($stream);
    }

    public function test_fread_all_throws_when_stream_ends_early(): void
    {
        $stream = fopen('php://temp', 'wb+');
        self::assertNotFalse($stream);

        fwrite($stream, 'abc');
        rewind($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incomplete stream read');

        try {
            \Dozor\fread_all($stream, 6);
        } finally {
            fclose($stream);
        }
    }
}

