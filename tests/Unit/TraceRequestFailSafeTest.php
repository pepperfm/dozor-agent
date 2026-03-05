<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Context\TraceContext;
use Dozor\Contracts\DozorContract;
use Dozor\Http\Middleware\TraceRequest;
use Dozor\Tests\TestCase;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class TraceRequestFailSafeTest extends TestCase
{
    public function test_middleware_swallows_telemetry_errors_and_returns_response(): void
    {
        $core = $this->createStub(DozorContract::class);
        $core->method('enabled')->willReturn(true);
        $core->method('beginRequest')->willThrowException(new RuntimeException('beginRequest failed'));
        $core->method('beginLifecycleStage')->willThrowException(new RuntimeException('beginLifecycleStage failed'));
        $core->method('endLifecycleStage')->willThrowException(new RuntimeException('endLifecycleStage failed'));
        $core->method('finishRequest')->willThrowException(new RuntimeException('finishRequest failed'));
        $core->method('digest')->willThrowException(new RuntimeException('digest failed'));
        $core->method('recordException')->willThrowException(new RuntimeException('recordException failed'));

        $middleware = new TraceRequest($core);
        $request = Request::create('/health', 'GET');

        $response = $middleware->handle($request, static fn() => new Response('ok', 200));

        self::assertSame(200, $response->getStatusCode());

        $middleware->terminate($request, $response);
        self::assertTrue(true);
    }

    public function test_middleware_rethrows_business_exception_even_when_telemetry_fails(): void
    {
        $core = $this->createStub(DozorContract::class);
        $core->method('enabled')->willReturn(true);
        $core->method('beginRequest')->willReturn(new TraceContext('trace-1', microtime(true)));
        $core->method('beginLifecycleStage')->willThrowException(new RuntimeException('beginLifecycleStage failed'));
        $core->method('endLifecycleStage')->willThrowException(new RuntimeException('endLifecycleStage failed'));
        $core->method('finishRequest')->willThrowException(new RuntimeException('finishRequest failed'));
        $core->method('digest')->willThrowException(new RuntimeException('digest failed'));
        $core->method('recordException')->willThrowException(new RuntimeException('recordException failed'));

        $middleware = new TraceRequest($core);
        $request = Request::create('/error', 'GET');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('business exception');

        $middleware->handle($request, static function (): Response {
            throw new RuntimeException('business exception');
        });
    }
}
