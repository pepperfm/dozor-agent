<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Payload;
use Dozor\Tests\TestCase;
use RuntimeException;

use function explode;
use function strlen;
use function substr;

final class PayloadTest extends TestCase
{
    public function test_it_builds_a_framed_payload_with_version_and_token_hash(): void
    {
        $payload = Payload::text('PING', 'hash123');
        $frame = $payload->pull();

        $separatorPosition = strpos($frame, ':');
        self::assertNotFalse($separatorPosition);

        $length = (int) substr($frame, 0, $separatorPosition);
        $body = substr($frame, $separatorPosition + 1);

        self::assertSame(strlen($body), $length);
        self::assertSame(['v1', 'hash123', 'PING'], explode(':', $body, 3));
    }

    public function test_it_cannot_be_pulled_twice(): void
    {
        $payload = Payload::text('PING', 'hash123');
        $payload->pull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Payload has already been read');

        $payload->pull();
    }
}
