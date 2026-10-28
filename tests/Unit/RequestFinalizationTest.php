<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\IngestContract;
use Dozor\Dozor;
use Dozor\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

final class RequestFinalizationTest extends TestCase
{
    public function test_route_and_authenticated_user_are_resolved_after_request_middleware(): void
    {
        $records = [];

        $ingest = $this->createMock(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        $dozor = new Dozor($ingest, [
            'enabled' => true,
            'app_name' => 'test',
            'environment' => 'testing',
        ]);

        $request = Request::create('https://example.test/orders/123?secret=in-url', 'GET');
        $startedAt = microtime(true) - 0.020;

        $dozor->beginRequest($request, $startedAt);
        $request->setRouteResolver(static fn() => (new Route('GET', 'orders/{order}', static fn() => 'ok'))->name('orders.show'));
        $request->setUserResolver(static fn() => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $dozor->finishRequest($request, new Response('ok', 200), $startedAt);

        self::assertCount(1, $records);
        $payload = $records[0]['payload'];
        self::assertSame('orders.show', $payload['route_name']);
        self::assertSame('orders/{order}', $payload['route_uri']);
        self::assertSame(42, $payload['user_id']);
        self::assertSame('https://example.test/orders/123', $payload['url']);
        self::assertArrayHasKey('request_started_at', $payload);
        self::assertSame(200, $payload['status']);
    }
}
