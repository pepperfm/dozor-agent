<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Context\TraceContext;
use Dozor\Contracts\DozorContract;
use Dozor\Http\Middleware\TraceRequest;
use Dozor\Http\Middleware\TraceRequestBootstrap;
use Dozor\Tests\TestCase;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class TraceRequestFailSafeTest extends TestCase
{
    public function test_bootstrap_middleware_starts_before_middleware_and_finishes_on_terminate(): void
    {
        $core = $this->createMock(DozorContract::class);
        $core->method('enabled')->willReturn(true);
        $core->method('hasActiveRequestTrace')->willReturn(false);
        $core->method('lifecycleStageIs')->willReturn(false);
        $core->expects($this->once())->method('beginRequest')->willReturn(new TraceContext('trace-1', microtime(true)));
        $core->expects($this->once())
            ->method('transitionLifecycleStage')
            ->with(RequestLifecycleStage::BeforeMiddleware->value);
        $core->expects($this->once())->method('finishRequest');
        $core->expects($this->once())->method('digest');

        $middleware = new TraceRequestBootstrap($core);
        $request = Request::create('/health', 'GET');
        $request->server->set('REQUEST_TIME_FLOAT', microtime(true) - 0.01);
        $response = $middleware->handle($request, static fn() => new Response('ok', 200));
        $middleware->terminate($request, $response);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_action_middleware_transitions_to_action_and_after_middleware(): void
    {
        $transitions = [];

        $core = $this->createMock(DozorContract::class);
        $core->method('enabled')->willReturn(true);
        $core->method('lifecycleStageIs')->willReturnCallback(
            static fn(string $stage): bool => $stage === RequestLifecycleStage::Action->value
        );
        $core->expects($this->exactly(2))
            ->method('transitionLifecycleStage')
            ->willReturnCallback(static function (string $stage) use (&$transitions): void {
                $transitions[] = $stage;
            });

        $middleware = new TraceRequest($core);
        $request = Request::create('/timeline', 'GET');
        $response = $middleware->handle($request, static fn() => new Response('ok', 200));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [RequestLifecycleStage::Action->value, RequestLifecycleStage::AfterMiddleware->value],
            $transitions,
        );
    }

    public function test_action_middleware_rethrows_business_exception_and_records_it(): void
    {
        $transitions = [];

        $core = $this->createMock(DozorContract::class);
        $core->method('enabled')->willReturn(true);
        $core->method('lifecycleStageIs')->willReturnCallback(
            static fn(string $stage): bool => $stage === RequestLifecycleStage::Action->value
        );
        $core->method('transitionLifecycleStage')->willReturnCallback(
            static function (string $stage) use (&$transitions): void {
                $transitions[] = $stage;
            }
        );
        $core->expects($this->once())->method('recordException');

        $middleware = new TraceRequest($core);
        $request = Request::create('/error', 'GET');

        try {
            $middleware->handle($request, static function (): Response {
                throw new RuntimeException('business exception');
            });
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            self::assertSame('business exception', $e->getMessage());
            self::assertSame(
                [RequestLifecycleStage::Action->value, RequestLifecycleStage::AfterMiddleware->value],
                $transitions,
            );
        }
    }

    public function test_nested_middlewares_record_business_exception_once(): void
    {
        $core = $this->createMock(DozorContract::class);
        $core->method('enabled')->willReturn(true);
        $core->method('hasActiveRequestTrace')->willReturn(true);
        $core->method('lifecycleStageIs')->willReturnCallback(
            static fn(string $stage): bool => $stage === RequestLifecycleStage::Action->value
        );
        $core->expects($this->once())->method('recordException');

        $bootstrap = new TraceRequestBootstrap($core);
        $action = new TraceRequest($core);
        $request = Request::create('/error', 'GET');

        try {
            $bootstrap->handle($request, static fn() => $action->handle(
                $request,
                static function (): Response {
                    throw new RuntimeException('business exception');
                },
            ));
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            self::assertSame('business exception', $e->getMessage());
        }
    }

    public function test_middlewares_swallow_telemetry_errors_and_keep_request_flow_alive(): void
    {
        $core = $this->createStub(DozorContract::class);
        $core->method('enabled')->willReturn(true);
        $core->method('hasActiveRequestTrace')->willReturn(false);
        $core->method('beginRequest')->willThrowException(new RuntimeException('beginRequest failed'));
        $core->method('transitionLifecycleStage')->willThrowException(new RuntimeException('transition failed'));
        $core->method('finishRequest')->willThrowException(new RuntimeException('finishRequest failed'));
        $core->method('digest')->willThrowException(new RuntimeException('digest failed'));
        $core->method('recordException')->willThrowException(new RuntimeException('recordException failed'));
        $core->method('lifecycleStageIs')->willReturn(false);

        $bootstrap = new TraceRequestBootstrap($core);
        $action = new TraceRequest($core);
        $request = Request::create('/health', 'GET');

        $response = $bootstrap->handle($request, static fn() => $action->handle(
            $request,
            static fn() => new Response('ok', 200),
        ));
        $bootstrap->terminate($request, $response);

        self::assertSame(200, $response->getStatusCode());
    }
}
