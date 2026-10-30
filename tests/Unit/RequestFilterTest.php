<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\IngestContract;
use Dozor\Dozor;
use Dozor\Filters\RequestFilter;
use Dozor\Http\Middleware\ExcludeRequest;
use Dozor\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

final class RequestFilterTest extends TestCase
{
    public function test_wildcard_exclude_and_middleware_drop_the_request(): void
    {
        $filter = new RequestFilter([
            'ignore_paths' => ['/up'],
            'ignore_route_names' => ['api.player.*'],
        ]);
        $player = $this->namedRequest('api.player.commands.poll', '/api/player/commands/poll');

        self::assertTrue($filter->shouldIgnoreRequest($player));
        self::assertTrue($filter->shouldIgnoreRequest(Request::create('/up', 'GET'), false));

        $records = [];
        $dozor = $this->dozor($records);
        $dozor->exclude(['api.player.*']);
        $dozor->exclude(['api.player.*']);
        $dozor->beginRequest($player, microtime(true));
        $dozor->suppressIfExcluded($player);
        $dozor->finishRequest($player, new Response('ok', 200), microtime(true));
        self::assertSame([], $records);

        $records = [];
        $dozor = $this->dozor($records);
        $orders = Request::create('/orders', 'GET');
        $dozor->beginRequest($orders, microtime(true));
        (new ExcludeRequest($dozor))->handle($orders, static fn(): Response => new Response('ok', 200));
        $dozor->finishRequest($orders, new Response('ok', 200), microtime(true));
        self::assertSame([], $records);
    }

    private function namedRequest(string $name, string $path): Request
    {
        $request = Request::create($path, 'GET');
        $route = new Route(['GET'], ltrim($path, '/'), ['uses' => static fn(): string => 'ok']);
        $route->name($name);
        $request->setRouteResolver(static fn(): Route => $route);

        return $request;
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function dozor(array &$records): Dozor
    {
        $ingest = $this->createStub(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        return new Dozor($ingest, ['enabled' => true, 'app_name' => 'test']);
    }
}
