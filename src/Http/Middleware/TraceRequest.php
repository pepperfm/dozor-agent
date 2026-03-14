<?php

declare(strict_types=1);

namespace Dozor\Http\Middleware;

use Closure;
use Dozor\Contracts\DozorContract;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Http\Request;
use Throwable;

final readonly class TraceRequest
{
    private const EXCEPTION_RECORDED_ATTRIBUTE = 'dozor.exception_recorded';

    public function __construct(private DozorContract $core)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->core->enabled()) {
            return $next($request);
        }

        $this->capture(fn() => $this->core->transitionLifecycleStage(RequestLifecycleStage::Action->value));

        try {
            $response = $next($request);

            if ($this->core->lifecycleStageIs(RequestLifecycleStage::Action->value)
                || $this->core->lifecycleStageIs(RequestLifecycleStage::Render->value)
            ) {
                $this->capture(fn() => $this->core->transitionLifecycleStage(RequestLifecycleStage::AfterMiddleware->value));
            }

            return $response;
        } catch (Throwable $e) {
            if ($this->core->lifecycleStageIs(RequestLifecycleStage::Action->value)
                || $this->core->lifecycleStageIs(RequestLifecycleStage::Render->value)
            ) {
                $this->capture(fn() => $this->core->transitionLifecycleStage(RequestLifecycleStage::AfterMiddleware->value));
            }

            $this->recordExceptionOnce($request, $e);

            throw $e;
        }
    }

    private function recordExceptionOnce(Request $request, Throwable $e): void
    {
        if ((bool) $request->attributes->get(self::EXCEPTION_RECORDED_ATTRIBUTE, false)) {
            return;
        }

        $this->capture(function () use ($request, $e): void {
            $this->core->recordException($e, [
                'phase' => 'http',
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);

            $request->attributes->set(self::EXCEPTION_RECORDED_ATTRIBUTE, true);
        });
    }

    private function capture(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
        }
    }
}
