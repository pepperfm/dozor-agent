<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\DozorContract;
use Dozor\Hooks\PreparingResponseListener;
use Dozor\Hooks\RequestHandledListener;
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
