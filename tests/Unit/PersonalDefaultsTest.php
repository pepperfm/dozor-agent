<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

final class PersonalDefaultsTest extends TestCase
{
    public function test_private_data_collection_is_opt_in(): void
    {
        // This package uses a minimal PHPUnit container rather than a booted Laravel app.
        // Evaluate the published config against a real Application for storage_path().
        $previous = Container::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        Container::setInstance($app);
        Facade::setFacadeApplication($app);

        try {
            $config = require dirname(__DIR__, 2) . '/config/dozor.php';
        } finally {
            Container::setInstance($previous);
            Facade::setFacadeApplication($previous);
        }

        self::assertFalse((bool) $config['capture_request_payload']);
        self::assertFalse((bool) $config['instrumentation']['capture_request_headers']);
        self::assertFalse((bool) $config['instrumentation']['capture_outgoing_http_headers']);
        self::assertFalse((bool) $config['instrumentation']['capture_cache']);
    }
}
