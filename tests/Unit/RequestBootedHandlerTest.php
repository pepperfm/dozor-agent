<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Context\TraceContext;
use Dozor\Contracts\DozorContract;
use Dozor\Hooks\RequestBootedHandler;
use Dozor\Tests\TestCase;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

final class RequestBootedHandlerTest extends TestCase
{
    public function test_it_starts_trace_and_transitions_to_before_middleware_on_booted(): void
    {
        $beginRequestCalls = 0;
        $transitionCalls = [];

        $request = Request::create('/booted', 'GET');
        $request->server->set('REQUEST_TIME_FLOAT', microtime(true) - 0.01);

        $app = $this->createMock(Application::class);
        $app->method('bound')->with('request')->willReturn(true);
        $app->method('make')->with('request')->willReturn($request);

        $core = $this->createStub(DozorContract::class);
        $core->method('enabled')->willReturn(true);
        $core->method('hasActiveRequestTrace')->willReturn(false);
        $core->method('lifecycleStageIs')->willReturn(false);
        $core->method('beginRequest')->willReturnCallback(
            static function (Request $requestArg, ?float $startedAt = null) use ($request, &$beginRequestCalls): TraceContext {
                self::assertSame($request, $requestArg);
                self::assertIsFloat($startedAt);
                $beginRequestCalls++;

                return new TraceContext('trace-booted', $startedAt ?? microtime(true));
            }
        );
        $core->method('transitionLifecycleStage')->willReturnCallback(
            static function (string $stage) use (&$transitionCalls): void {
                $transitionCalls[] = $stage;
            }
        );

        $handler = new RequestBootedHandler($core);
        $handler($app);

        self::assertSame(1, $beginRequestCalls);
        self::assertSame([RequestLifecycleStage::BeforeMiddleware->value], $transitionCalls);
    }
}
