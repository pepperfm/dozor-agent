<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\IngestContract;
use Dozor\Dozor;
use Dozor\Http\Middleware\TraceRequestBootstrap;
use Dozor\Tests\TestCase;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Response;

final class RequestFinalizationTest extends TestCase
{
    public function test_artisan_called_inside_http_is_recorded_as_child_event_not_command_root(): void
    {
        $records = [];
        $ingest = $this->createMock(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        $core = new Dozor($ingest, [
            'enabled' => true,
            'app_name' => 'test',
            'instrumentation' => ['capture_framework_events' => true],
        ]);
        $request = Request::create('https://example.test/api', 'GET');
        $request->setRouteResolver(static fn() => null);
        $request->setUserResolver(static fn() => null);
        $start = microtime(true) - 0.05;
        $core->beginRequest($request, $start);
        $core->recordFrameworkEvent('artisan.cache:clear', [
            'exit_code' => 0,
            'duration_ms' => 5,
        ]);
        $core->finishRequest($request, new Response('ok', 200), $start);

        self::assertCount(2, $records);
        self::assertSame('event', $records[0]['type']);
        self::assertSame('request', $records[1]['type']);
        self::assertSame($records[1]['trace_id'], $records[0]['trace_id']);
    }

    public function test_top_level_artisan_command_keeps_its_own_command_root(): void
    {
        $records = [];
        $ingest = $this->createMock(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        $core = new Dozor($ingest, [
            'enabled' => true,
            'instrumentation' => ['capture_framework_events' => true],
        ]);
        $core->startFrameworkCommand('cache:clear');
        $core->recordFrameworkEvent('artisan.cache:clear', [
            'exit_code' => 0,
            'duration_ms' => 5,
        ]);
        $core->finishFrameworkCommand('cache:clear');

        self::assertCount(1, $records);
        self::assertSame('command', $records[0]['type']);
    }

    public function test_begin_request_does_not_resolve_routing_or_authentication_before_middleware(): void
    {
        $records = [];
        $ingest = $this->createMock(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        $dozor = new Dozor($ingest, ['enabled' => true, 'app_name' => 'test']);
        $request = Request::create('https://example.test/profile', 'GET');
        $request->setRouteResolver(static function (): never {
            throw new \RuntimeException('routing not yet available');
        });
        $request->setUserResolver(static function (): never {
            throw new \RuntimeException('authentication not yet available');
        });

        $startedAt = microtime(true) - 0.01;
        $trace = $dozor->beginRequest($request, $startedAt);

        self::assertTrue($trace->requestMeta['sampled']);

        $request->setRouteResolver(static fn() => (new Route('GET', 'profile', static fn() => 'ok'))->name('profile.show'));
        $request->setUserResolver(static fn() => null);

        $dozor->finishRequest($request, new Response('ok', 200), $startedAt);

        self::assertCount(1, $records);
        self::assertSame('profile.show', $records[0]['payload']['route_name']);
    }

    public function test_it_records_actual_404_419_and_500_status_codes_without_fabricating_success(): void
    {
        foreach ([404, 419, 500] as $status) {
            $records = [];
            $ingest = $this->createMock(IngestContract::class);
            $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
                $records[] = $record;
            });

            $core = new Dozor($ingest, ['enabled' => true, 'app_name' => 'test']);
            $request = Request::create('/failing-path', 'POST');
            $startedAt = microtime(true) - 0.015;

            $core->beginRequest($request, $startedAt);
            $core->finishRequest($request, new Response('', $status), $startedAt);

            self::assertCount(1, $records, "Expected exactly one terminal HTTP event for $status");
            self::assertSame('request', $records[0]['type']);
            self::assertSame($status, $records[0]['payload']['status']);
            self::assertGreaterThanOrEqual(0, $records[0]['payload']['duration_ms']);
        }
    }

    public function test_request_exception_summary_strips_signed_urls(): void
    {
        $records = [];
        $ingest = $this->createMock(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        $core = new Dozor($ingest, ['enabled' => true, 'app_name' => 'test']);
        $request = Request::create('/error', 'GET');
        $startedAt = microtime(true) - 0.005;
        $core->beginRequest($request, $startedAt);

        $error = new \RuntimeException('Request failed https://api.example.test/token?password=secret-token');
        $core->finishRequest($request, new Response('error', 500), $startedAt, $error);

        $requestRecords = array_values(array_filter(
            $records,
            static fn(array $record): bool => ($record['type'] ?? null) === 'request',
        ));
        $exceptionRecords = array_values(array_filter(
            $records,
            static fn(array $record): bool => ($record['type'] ?? null) === 'exception',
        ));
        self::assertCount(1, $requestRecords);
        self::assertCount(1, $exceptionRecords);
        self::assertSame(500, $requestRecords[0]['payload']['status']);
        self::assertStringNotContainsString('secret-token', $requestRecords[0]['payload']['exception_message']);
        self::assertStringContainsString('[URL REDACTED]', $requestRecords[0]['payload']['exception_message']);
        self::assertStringNotContainsString('secret-token', $exceptionRecords[0]['payload']['message']);
    }

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

    public function test_second_request_does_not_inherit_an_unfinished_request_trace(): void
    {
        $records = [];
        $ingest = $this->createStub(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        $logger = new class extends AbstractLogger
        {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
        Container::getInstance()->instance('log', $logger);

        $dozor = new Dozor($ingest, [
            'enabled' => true,
            'app_name' => 'test',
        ]);

        $first = Request::create('https://example.test/first', 'GET');
        $firstStartedAt = microtime(true) - 0.05;
        $firstTrace = $dozor->beginRequest($first, $firstStartedAt);
        $dozor->transitionLifecycleStage(RequestLifecycleStage::Controller->value);

        self::assertSame([], $logger->records);
        self::assertContains(
            RequestLifecycleStage::Controller->value,
            array_column($firstTrace->lifecycleStages(), 'name'),
        );

        $second = Request::create('https://example.test/second', 'GET');
        $secondStartedAt = microtime(true);
        $secondTrace = $dozor->beginRequest($second, $secondStartedAt);

        self::assertNotSame($firstTrace->traceId, $secondTrace->traceId);
        self::assertSame($secondTrace->traceId, $dozor->currentTrace?->traceId);
        self::assertSame(
            [RequestLifecycleStage::Bootstrap->value],
            array_column($secondTrace->lifecycleStages(), 'name'),
        );

        $requestRecords = array_values(array_filter(
            $records,
            static fn(array $record): bool => ($record['type'] ?? null) === 'request',
        ));
        self::assertSame([], $requestRecords);

        $dozor->finishRequest($second, new Response('ok', 200), $secondStartedAt);

        $requestRecords = array_values(array_filter(
            $records,
            static fn(array $record): bool => ($record['type'] ?? null) === 'request',
        ));
        self::assertCount(1, $requestRecords);
        self::assertSame($secondTrace->traceId, $requestRecords[0]['trace_id']);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('dozor.trace.stale_request_reset', $logger->records[0]['message']);
        self::assertSame(['reason' => 'stale_request'], $logger->records[0]['context']);
    }

    public function test_bootstrap_replaces_an_unfinished_trace_for_the_next_request(): void
    {
        $ingest = $this->createStub(IngestContract::class);
        $dozor = new Dozor($ingest, [
            'enabled' => true,
            'app_name' => 'test',
        ]);
        $middleware = new TraceRequestBootstrap($dozor);

        $first = Request::create('https://example.test/first', 'GET');
        $first->server->set('REQUEST_TIME_FLOAT', (string) (microtime(true) - 0.05));
        $middleware->handle($first, static fn(): Response => new Response('ok', 200));
        $firstTraceId = $dozor->currentTrace?->traceId;

        $second = Request::create('https://example.test/second', 'GET');
        $second->server->set('REQUEST_TIME_FLOAT', (string) microtime(true));
        $middleware->handle($second, static fn(): Response => new Response('ok', 200));

        self::assertNotNull($firstTraceId);
        self::assertNotSame($firstTraceId, $dozor->currentTrace?->traceId);
        self::assertSame(
            [RequestLifecycleStage::Bootstrap->value, RequestLifecycleStage::BeforeMiddleware->value],
            array_column($dozor->currentTrace?->lifecycleStages() ?? [], 'name'),
        );
    }
}
