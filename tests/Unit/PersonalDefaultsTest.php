<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Tests\TestCase;

final class PersonalDefaultsTest extends TestCase
{
    public function test_private_data_collection_is_opt_in(): void
    {
        self::assertFalse((bool) config('dozor.capture_request_payload'));
        self::assertFalse((bool) config('dozor.instrumentation.capture_request_headers'));
        self::assertFalse((bool) config('dozor.instrumentation.capture_outgoing_http_headers'));
        self::assertFalse((bool) config('dozor.instrumentation.capture_cache'));
        self::assertTrue((bool) config('dozor.enabled'));
    }
}
