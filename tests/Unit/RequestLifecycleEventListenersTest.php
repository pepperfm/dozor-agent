<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\DozorContract;
use Dozor\Hooks\PreparingResponseListener;
use Dozor\Hooks\RequestHandledListener;
use Dozor\Hooks\RequestTerminationFallback;
use Dozor\Hooks\ResponsePreparedListener;
use Dozor\Hooks\TerminatingListener;
use Dozor\Tests\TestCase;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Symfony\Component\HttpFoundation\Response;

final class RequestLifecycleEventListenersTest extends TestCase
{
    public function test_preparing_response_listener_transitions_from_action_to_render(): void
    {
        $core = $this->createMock(DozorContract::class);
        $core->method('lifecycleStageIs')->willReturnCallback(
            static fn(string $stage): bool => $stage === RequestLifecycleStage::Action->value
        );
        $core->expects($this->once())
            ->method('transitionLifecycleStage')
            ->with(RequestLifecycleStage::Render->value);

        $listener = new PreparingResponseListener($core);
        $listener(new PreparingResponse(Request::create('/x', 'GET'), new Response('ok', 200)));
    }

    public function test_response_prepared_listener_transitions_from_render_to_after_middleware(): void
    {
        $core = $this->createMock(DozorContract::class);
        $core->method('lifecycleStageIs')->willReturnCallback(
            static fn(string $stage): bool => $stage === RequestLifecycleStage::Render->value
        );
        $core->expects($this->once())
            ->method('transitionLifecycleStage')
            ->with(RequestLifecycleStage::AfterMiddleware->value);

        $listener = new ResponsePreparedListener($core);
        $listener(new ResponsePrepared(Request::create('/x', 'GET'), new Response('ok', 200)));
    }

    public function test_request_handled_listener_transitions_to_sending(): void
    {
        $core = $this->createMock(DozorContract::class);
        $core->expects($this->once())
            ->method('transitionLifecycleStage')
            ->with(RequestLifecycleStage::Sending->value);

        $listener = new RequestHandledListener($core);
        $listener(new RequestHandled(Request::create('/x', 'GET'), new Response('ok', 200)));
    }

    public function test_unmatched_404_response_is_finalized_once_at_application_termination(): void
    {
        $request = Request::create('/missing', 'GET', server: ['REQUEST_TIME_FLOAT' => (string) (microtime(true) - 0.02)]);
        $response = new Response('Not found', 404);
        $core = $this->createMock(DozorContract::class);
        $core->method('enabled')->willReturn(true);
        $core->method('hasActiveRequestTrace')->willReturn(true);
        $core->expects($this->once())
            ->method('finishRequest')
            ->with(
                $this->identicalTo($request),
                $this->identicalTo($response),
                $this->isType('float'),
            );
        $core->expects($this->once())->method('digest');

        (new RequestHandledListener($core))(new RequestHandled($request, $response));

        $app = $this->createMock(\Illuminate\Contracts\Foundation\Application::class);
        $app->method('bound')->with('request')->willReturn(true);
        $app->method('make')->with('request')->willReturn($request);

        (new RequestTerminationFallback($core, $app))();
    }

    public function test_termination_fallback_does_not_duplicate_already_finished_requests(): void
    {
        $core = $this->createMock(DozorContract::class);
        $core->method('enabled')->willReturn(true);
        $core->method('hasActiveRequestTrace')->willReturn(false);
        $core->expects($this->never())->method('finishRequest');
        $core->expects($this->never())->method('digest');

        $app = $this->createMock(\Illuminate\Contracts\Foundation\Application::class);
        (new RequestTerminationFallback($core, $app))();
    }

    public function test_terminating_listener_transitions_to_terminating(): void
    {
        $core = $this->createMock(DozorContract::class);
        $core->expects($this->once())
            ->method('transitionLifecycleStage')
            ->with(RequestLifecycleStage::Terminating->value);

        $listener = new TerminatingListener($core);
        $listener(new Terminating());
    }
}
